<?php

namespace App\Service;

use App\Entity\InventoryValuation;
use App\Entity\PurchaseOrder;
use App\Repository\GoodsReceiptRepository;
use App\Repository\InventoryReturnRepository;
use App\Repository\InventoryValuationRepository;
use App\Repository\PayableBillRepository;

/**
 * Three-way match of a supplier invoice (vendor bill) against its purchase order and goods receipts.
 *
 * A bill is "matched" when, together with earlier bills for the same order, it does not exceed the value of goods
 * actually received at purchase-order prices (net of supplier returns) plus landed costs. Anything above that needs an
 * explicit reason and is recorded as an "exception". When receipts were valued, the bill clears the goods-received-
 * not-invoiced accrual and any excess over that accrual is expensed as purchase price variance.
 */
final class PurchaseMatchingService
{
    public function __construct(
        private readonly GoodsReceiptRepository $receiptRepository,
        private readonly InventoryReturnRepository $returnRepository,
        private readonly InventoryValuationRepository $valuationRepository,
        private readonly PayableBillRepository $billRepository,
    ) {
    }

    /** Value received at purchase-order prices, less supplier returns, plus landed costs. */
    public function receivedValueMinor(PurchaseOrder $order): int
    {
        $prices = [];
        foreach ($order->getItems() as $item) {
            $prices[$item['product_id']] = $item['unit_cost_minor'];
        }
        $value = 0;
        foreach ($this->receiptRepository->findForPurchaseOrder((int) $order->getId()) as $receipt) {
            foreach ($receipt->getItems() as $line) {
                $value += ($prices[$line['product_id']] ?? 0) * $line['quantity'];
            }
            $value += $receipt->getLandedTotalMinor();
        }
        foreach ($this->returnRepository->findBy(['returnType' => 'supplier', 'sourceType' => 'purchase_order', 'sourceId' => $order->getId()]) as $return) {
            foreach ($return->getItems() as $line) {
                $value -= ($prices[$line['product_id']] ?? 0) * $line['quantity'];
            }
        }

        return max(0, $value);
    }

    public function billedMinor(PurchaseOrder $order): int
    {
        $total = 0;
        foreach ($this->billRepository->findBy(['purchaseOrder' => $order]) as $bill) {
            $total += $bill->getTotalMinor();
        }

        return $total;
    }

    /** Goods-received-not-invoiced accrual still open for the order, from valued receipts and supplier returns. */
    public function accrualRemainingMinor(PurchaseOrder $order): int
    {
        $receiptIds = array_map(static fn ($receipt): int => (int) $receipt->getId(), $this->receiptRepository->findForPurchaseOrder((int) $order->getId()));
        $returnIds = array_map(static fn ($return): int => (int) $return->getId(), $this->returnRepository->findBy(['returnType' => 'supplier', 'sourceType' => 'purchase_order', 'sourceId' => $order->getId()]));
        $accrued = $this->valuationRepository->sumValue(InventoryValuation::RECEIPT, $receiptIds) + $this->valuationRepository->sumValue(InventoryValuation::SUPPLIER_RETURN, $returnIds);
        $cleared = 0;
        foreach ($this->billRepository->findBy(['purchaseOrder' => $order]) as $bill) {
            $cleared += $bill->getAccrualClearedMinor();
        }

        return max(0, $accrued - $cleared);
    }

    public function hasValuedReceipts(PurchaseOrder $order): bool
    {
        $receiptIds = array_map(static fn ($receipt): int => (int) $receipt->getId(), $this->receiptRepository->findForPurchaseOrder((int) $order->getId()));

        return $this->valuationRepository->sumValue(InventoryValuation::RECEIPT, $receiptIds) > 0;
    }

    /**
     * @return array{status: 'matched'|'exception', note: string|null, received_minor: int, billed_before_minor: int, allowed_minor: int}
     * @throws \DomainException when the bill exceeds what was received and no reason was given
     */
    public function evaluate(PurchaseOrder $order, int $billTotalMinor, ?string $overrideReason): array
    {
        $received = $this->receivedValueMinor($order);
        $billed = $this->billedMinor($order);
        $allowed = max(0, $received - $billed);
        $reason = $overrideReason !== null && trim($overrideReason) !== '' ? trim($overrideReason) : null;
        if ($billTotalMinor <= $allowed) {
            return ['status' => 'matched', 'note' => null, 'received_minor' => $received, 'billed_before_minor' => $billed, 'allowed_minor' => $allowed];
        }
        if ($reason === null) {
            throw new \DomainException(sprintf('The bill exceeds the received, not yet invoiced value of this purchase order (%d minor units available). Receive the goods first, or give an override reason.', $allowed));
        }

        return ['status' => 'exception', 'note' => $reason, 'received_minor' => $received, 'billed_before_minor' => $billed, 'allowed_minor' => $allowed];
    }
}
