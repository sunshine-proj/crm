<?php

namespace App\Controller;

use App\Dto\OpeningValuationInput;
use App\Repository\InventoryValuationRepository;
use App\Repository\ProductRepository;
use App\Repository\StockMovementRepository;
use App\Repository\WarehouseRepository;
use App\Service\InventoryValuationService;
use App\Service\InvoiceMoney;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Stock value under the weighted-average method. Visible to finance users: unit costs are commercially sensitive. */
#[Route('/api/inventory', name: 'api_inventory_valuation_')]
#[IsGranted('ROLE_FINANCE')]
final class InventoryValuationController extends AbstractController
{
    public function __construct(
        private readonly InventoryValuationRepository $valuationRepository,
        private readonly InventoryValuationService $valuation,
        private readonly ProductRepository $productRepository,
        private readonly WarehouseRepository $warehouseRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
    ) {
    }

    #[Route('/valuation', name: 'report', methods: ['GET'])]
    public function report(): JsonResponse
    {
        $products = [];
        foreach ($this->productRepository->findAll() as $product) $products[$product->getId()] = $product;
        $warehouses = [];
        foreach ($this->warehouseRepository->findAll() as $warehouse) $warehouses[$warehouse->getId()] = $warehouse;

        $rows = [];
        $totals = [];
        foreach ($this->valuationRepository->pools() as $pool) {
            $product = $products[$pool['product_id']] ?? null;
            $warehouse = $warehouses[$pool['warehouse_id']] ?? null;
            if ($product === null || $warehouse === null || ($pool['quantity'] === 0 && $pool['value'] === 0)) continue;
            $onHand = $this->movementRepository->onHand($pool['product_id'], $pool['warehouse_id']);
            $rows[] = [
                'product_id' => $pool['product_id'], 'sku' => $product->getSku(), 'product_name' => $product->getName(),
                'warehouse_id' => $pool['warehouse_id'], 'warehouse_code' => $warehouse->getCode(),
                'currency' => $pool['currency'], 'valued_quantity' => $pool['quantity'], 'value' => $this->money->formatMinor($pool['value']),
                'average_unit_cost' => $pool['quantity'] > 0 ? $this->money->formatMinor($this->valuation->proportion($pool['value'], 1, $pool['quantity'])) : null,
                'on_hand_quantity' => $onHand, 'unvalued_quantity' => max(0, $onHand - $pool['quantity']),
            ];
            $totals[$pool['currency']] = ($totals[$pool['currency']] ?? 0) + $pool['value'];
        }
        ksort($totals);

        return $this->json([
            'enabled' => $this->valuation->isEnabled(),
            'method' => 'weighted_average',
            'rows' => $rows,
            'totals' => array_map(fn (string $currency): array => ['currency' => $currency, 'value' => $this->money->formatMinor($totals[$currency])], array_keys($totals)),
            'basis' => 'Perpetual weighted-average cost per product and warehouse, in the currency of the purchase orders; no currency conversion. Stock received before valuation was enabled shows as unvalued until an opening valuation is recorded. Operational figures, not statutory inventory accounts.',
        ]);
    }

    #[Route('/opening-valuations', name: 'opening', methods: ['POST'])]
    public function opening(#[MapRequestPayload] OpeningValuationInput $input): JsonResponse
    {
        if (!$this->valuation->isEnabled()) {
            return $this->json(['error' => 'Map the inventory, cost of sales, goods received not invoiced and inventory adjustment accounts first.'], JsonResponse::HTTP_CONFLICT);
        }
        $product = $input->product_id !== null ? $this->productRepository->find($input->product_id) : null;
        $warehouse = $input->warehouse_id !== null ? $this->warehouseRepository->find($input->warehouse_id) : null;
        $unitCost = $input->unit_cost !== null ? $this->money->parseMinor($input->unit_cost) : null;
        if ($product === null || $warehouse === null || $unitCost === null || $input->currency === null) {
            return $this->json(['error' => 'Product, warehouse, unit cost and currency are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $date = $input->valuation_date !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $input->valuation_date) : new \DateTimeImmutable('today');
        if ($date === false) {
            return $this->json(['error' => 'Valuation date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $entry = $this->entityManager->wrapInTransaction(fn () => $this->valuation->recordOpening($product, $warehouse, $this->movementRepository->onHand((int) $product->getId(), (int) $warehouse->getId()), $unitCost, $input->currency, $date));
        } catch (\DomainException|\OverflowException|UniqueConstraintViolationException $exception) {
            return $this->json(['error' => $exception instanceof UniqueConstraintViolationException ? 'An opening valuation already exists for this product and warehouse.' : $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }

        return $this->json(['id' => $entry->getId(), 'quantity' => $entry->getQuantityDelta(), 'value' => $this->money->formatMinor($entry->getValueMinor()), 'currency' => $entry->getCurrency()], JsonResponse::HTTP_CREATED);
    }
}
