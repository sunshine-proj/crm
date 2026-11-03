<?php

namespace App\Service;

use App\Entity\GoodsReceipt;
use App\Entity\InventoryValuation;
use App\Entity\Product;
use App\Entity\Warehouse;
use App\Repository\InventoryValuationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Weighted-average inventory valuation per product and warehouse, with the matching general-ledger postings.
 *
 * Costing method: perpetual weighted average. A receipt adds its goods cost plus allocated landed costs; every issue
 * (dispatch, supplier return, negative adjustment) leaves at the pool's current average cost, rounded half-up to a
 * minor unit, and the last unit always takes the exact remaining value so a pool empties to zero. Customer returns go
 * back in at the cost their dispatch removed. Each pool holds a single currency; no FX conversion exists.
 *
 * The feature is inert until the inventory, cost-of-sales, goods-received-not-invoiced and inventory-adjustment
 * accounts are mapped, so installations that only track quantities keep working. Methods must run inside the same
 * database transaction as the stock movement they value.
 */
final class InventoryValuationService
{
    private const REQUIRED_ROLES = ['inventory', 'cost_of_sales', 'goods_received_not_invoiced', 'inventory_adjustment'];

    public function __construct(
        private readonly InventoryValuationRepository $valuationRepository,
        private readonly FinancePostingService $postingService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isEnabled(): bool
    {
        $mappings = $this->postingService->accountMappings();
        foreach (self::REQUIRED_ROLES as $role) {
            if (($mappings[$role] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /** @return array{quantity: int, value: int, currency: string|null} */
    public function pool(Product $product, Warehouse $warehouse): array
    {
        return $this->valuationRepository->pool((int) $product->getId(), (int) $warehouse->getId());
    }

    /**
     * @param list<array{product: Product, quantity: int, goods_minor: int, landed_minor: int}> $lines
     * @return list<InventoryValuation>
     */
    public function recordReceipt(GoodsReceipt $receipt, string $currency, array $lines): array
    {
        $entries = [];
        foreach ($lines as $line) {
            $warehouse = $receipt->getWarehouse();
            $this->assertPoolCurrency($line['product'], $warehouse, $currency);
            $value = $this->add($line['goods_minor'], $line['landed_minor']);
            $entry = $this->persistEntry($line['product'], $warehouse, InventoryValuation::RECEIPT, 'goods_receipt', (int) $receipt->getId(), $line['quantity'], $value, $currency, $receipt->getReceivedAt());
            $this->post('inventory_receipt', $entry, 'Goods receipt #'.$receipt->getId().' '.$line['product']->getSku(), 'inventory', 'goods_received_not_invoiced');
            $entries[] = $entry;
        }

        return $entries;
    }

    public function recordDispatch(int $shipmentId, Product $product, Warehouse $warehouse, int $quantity, \DateTimeImmutable $date): InventoryValuation
    {
        [$value, $currency] = $this->issueValue($product, $warehouse, $quantity);
        $entry = $this->persistEntry($product, $warehouse, InventoryValuation::DISPATCH, 'sales_shipment', $shipmentId, -$quantity, -$value, $currency, $date);
        $this->post('inventory_dispatch', $entry, 'Cost of sales, shipment #'.$shipmentId.' '.$product->getSku(), 'cost_of_sales', 'inventory');

        return $entry;
    }

    /** Returns stock at the cost its dispatch removed; null when that dispatch was never valued. */
    public function recordCustomerReturn(int $returnId, int $shipmentId, Product $product, Warehouse $warehouse, int $quantity, \DateTimeImmutable $date): ?InventoryValuation
    {
        $dispatchedQuantity = 0;
        $dispatchedValue = 0;
        $currency = null;
        foreach ($this->valuationRepository->forSource('sales_shipment', $shipmentId, InventoryValuation::DISPATCH) as $dispatch) {
            if ($dispatch->getProduct()->getId() === $product->getId()) {
                $dispatchedQuantity += -$dispatch->getQuantityDelta();
                $dispatchedValue += -$dispatch->getValueMinor();
                $currency = $dispatch->getCurrency();
            }
        }
        if ($dispatchedQuantity < 1 || $currency === null) {
            return null;
        }
        $this->assertPoolCurrency($product, $warehouse, $currency);
        $value = $quantity >= $dispatchedQuantity ? $dispatchedValue : $this->proportion($dispatchedValue, $quantity, $dispatchedQuantity);
        $entry = $this->persistEntry($product, $warehouse, InventoryValuation::CUSTOMER_RETURN, 'inventory_return', $returnId, $quantity, $value, $currency, $date);
        $this->post('inventory_customer_return', $entry, 'Customer return #'.$returnId.' '.$product->getSku(), 'inventory', 'cost_of_sales');

        return $entry;
    }

    public function recordSupplierReturn(int $returnId, Product $product, Warehouse $warehouse, int $quantity, \DateTimeImmutable $date): InventoryValuation
    {
        [$value, $currency] = $this->issueValue($product, $warehouse, $quantity);
        $entry = $this->persistEntry($product, $warehouse, InventoryValuation::SUPPLIER_RETURN, 'inventory_return', $returnId, -$quantity, -$value, $currency, $date);
        $this->post('inventory_supplier_return', $entry, 'Supplier return #'.$returnId.' '.$product->getSku(), 'goods_received_not_invoiced', 'inventory');

        return $entry;
    }

    /** A counted loss leaves at average cost; a gain enters at the given unit cost, or at average cost when the pool has stock. */
    public function recordAdjustment(int $movementId, Product $product, Warehouse $warehouse, int $quantityDelta, \DateTimeImmutable $date, ?int $unitCostMinor, ?string $unitCostCurrency): InventoryValuation
    {
        if ($quantityDelta < 0) {
            [$value, $currency] = $this->issueValue($product, $warehouse, -$quantityDelta);
            $entry = $this->persistEntry($product, $warehouse, InventoryValuation::ADJUSTMENT, 'stock_movement', $movementId, $quantityDelta, -$value, $currency, $date);
            $this->post('inventory_adjustment', $entry, 'Stock count loss '.$product->getSku(), 'inventory_adjustment', 'inventory');

            return $entry;
        }
        $pool = $this->pool($product, $warehouse);
        if ($unitCostMinor !== null) {
            $currency = $unitCostCurrency ?? $pool['currency'];
            if ($currency === null) {
                throw new \DomainException('Choose the currency of the unit cost.');
            }
            $value = $this->multiply($unitCostMinor, $quantityDelta);
        } elseif ($pool['quantity'] > 0 && $pool['currency'] !== null) {
            $currency = $pool['currency'];
            $value = $this->proportion($pool['value'], $quantityDelta, $pool['quantity']);
        } else {
            throw new \DomainException('Enter a unit cost: this product has no recorded cost in the warehouse to value the extra stock.');
        }
        $this->assertPoolCurrency($product, $warehouse, $currency);
        $entry = $this->persistEntry($product, $warehouse, InventoryValuation::ADJUSTMENT, 'stock_movement', $movementId, $quantityDelta, $value, $currency, $date);
        $this->post('inventory_adjustment', $entry, 'Stock count gain '.$product->getSku(), 'inventory', 'inventory_adjustment');

        return $entry;
    }

    /**
     * Gives a cost to stock that was received before valuation was switched on. The offset goes to the inventory
     * adjustment account and should be reclassified by the accountant.
     */
    public function recordOpening(Product $product, Warehouse $warehouse, int $onHandQuantity, int $unitCostMinor, string $currency, \DateTimeImmutable $date): InventoryValuation
    {
        $pool = $this->pool($product, $warehouse);
        $unvalued = $onHandQuantity - $pool['quantity'];
        if ($unvalued < 1) {
            throw new \DomainException('This product has no unvalued stock in the warehouse.');
        }
        if ($this->hasOpening($product, $warehouse)) {
            throw new \DomainException('An opening valuation already exists for this product and warehouse; use a stock adjustment for further corrections.');
        }
        $this->assertPoolCurrency($product, $warehouse, $currency);
        $entry = $this->persistEntry($product, $warehouse, InventoryValuation::OPENING, 'opening', (int) $warehouse->getId(), $unvalued, $this->multiply($unitCostMinor, $unvalued), $currency, $date);
        $this->post('inventory_opening', $entry, 'Opening valuation '.$product->getSku(), 'inventory', 'inventory_adjustment');

        return $entry;
    }

    /** @return array{0: int, 1: string} value removed and the pool currency */
    private function issueValue(Product $product, Warehouse $warehouse, int $quantity): array
    {
        $pool = $this->pool($product, $warehouse);
        if ($pool['currency'] === null || $pool['quantity'] < $quantity) {
            throw new \DomainException(sprintf('Only %d unit(s) of %s have a recorded cost in %s; set an opening valuation for the legacy stock first.', max(0, $pool['quantity']), $product->getSku(), $warehouse->getCode()));
        }
        $value = $quantity === $pool['quantity'] ? $pool['value'] : $this->proportion($pool['value'], $quantity, $pool['quantity']);

        return [$value, $pool['currency']];
    }

    private function hasOpening(Product $product, Warehouse $warehouse): bool
    {
        foreach ($this->valuationRepository->forSource('opening', (int) $warehouse->getId(), InventoryValuation::OPENING) as $entry) {
            if ($entry->getProduct()->getId() === $product->getId()) {
                return true;
            }
        }

        return false;
    }

    private function assertPoolCurrency(Product $product, Warehouse $warehouse, string $currency): void
    {
        $pool = $this->pool($product, $warehouse);
        if ($pool['quantity'] > 0 && $pool['currency'] !== null && $pool['currency'] !== $currency) {
            throw new \DomainException(sprintf('%s in %s is valued in %s; stock in %s cannot be mixed in because no currency conversion exists.', $product->getSku(), $warehouse->getCode(), $pool['currency'], $currency));
        }
    }

    private function persistEntry(Product $product, Warehouse $warehouse, string $kind, string $sourceType, int $sourceId, int $quantityDelta, int $valueMinor, string $currency, \DateTimeImmutable $date): InventoryValuation
    {
        $entry = new InventoryValuation($product, $warehouse, $kind, $sourceType, $sourceId, $quantityDelta, $valueMinor, $currency, $date);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function post(string $sourceType, InventoryValuation $entry, string $description, string $debitRole, string $creditRole): void
    {
        $amount = abs($entry->getValueMinor());
        if ($amount === 0) {
            return;
        }
        $this->postingService->post($sourceType, (int) $entry->getId(), $entry->getMovedAt(), $entry->getCurrency(), $description, [
            ['role' => $debitRole, 'debit_minor' => $amount, 'credit_minor' => 0, 'memo' => $description],
            ['role' => $creditRole, 'debit_minor' => 0, 'credit_minor' => $amount, 'memo' => $description],
        ]);
    }

    /**
     * Splits landed costs across receipt lines in proportion to their goods value (largest-remainder, so the shares
     * always add up to the total exactly). Lines are keyed by any array key; zero-value receipts split evenly.
     *
     * @param array<int|string, int> $goodsValueByLine
     * @return array<int|string, int>
     */
    public function allocateLandedCosts(array $goodsValueByLine, int $landedTotalMinor): array
    {
        if ($goodsValueByLine === [] || $landedTotalMinor < 0) {
            throw new \InvalidArgumentException('Landed costs need at least one receipt line and a non-negative total.');
        }
        $weights = array_sum($goodsValueByLine) > 0 ? $goodsValueByLine : array_fill_keys(array_keys($goodsValueByLine), 1);
        $weightTotal = array_sum($weights);
        $shares = [];
        $remainders = [];
        foreach ($weights as $key => $weight) {
            if ($weight > 0 && $landedTotalMinor > intdiv(PHP_INT_MAX, $weight)) {
                throw new \OverflowException('Landed cost is too large to allocate.');
            }
            $shares[$key] = intdiv($landedTotalMinor * $weight, $weightTotal);
            $remainders[$key] = ($landedTotalMinor * $weight) % $weightTotal;
        }
        $left = $landedTotalMinor - array_sum($shares);
        $order = array_keys($remainders);
        usort($order, static fn ($a, $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        foreach (array_slice($order, 0, $left) as $key) {
            ++$shares[$key];
        }

        return $shares;
    }

    /** value × part ÷ whole for non-negative integers, rounded half up. */
    public function proportion(int $value, int $part, int $whole): int
    {
        if ($value < 0 || $part < 0 || $whole < 1) {
            throw new \InvalidArgumentException('Proportional cost needs non-negative amounts and a positive whole.');
        }
        if ($part > 0 && $value > intdiv(PHP_INT_MAX - intdiv($whole, 2), $part)) {
            throw new \OverflowException('Inventory value is too large to calculate.');
        }

        return intdiv($value * $part + intdiv($whole, 2), $whole);
    }

    private function multiply(int $unitCostMinor, int $quantity): int
    {
        if ($unitCostMinor < 0 || $quantity < 1 || $unitCostMinor > intdiv(PHP_INT_MAX, $quantity)) {
            throw new \OverflowException('Inventory value is too large to calculate.');
        }

        return $unitCostMinor * $quantity;
    }

    private function add(int $a, int $b): int
    {
        if ($a > PHP_INT_MAX - $b) {
            throw new \OverflowException('Inventory value is too large to calculate.');
        }

        return $a + $b;
    }
}
