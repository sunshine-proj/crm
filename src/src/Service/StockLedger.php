<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\StockMovement;
use App\Entity\Warehouse;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

final class StockLedger
{
    public function __construct(
        private readonly StockMovementRepository $movementRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onHand(Product $product, Warehouse $warehouse): int
    {
        return $this->movementRepository->onHand((int) $product->getId(), (int) $warehouse->getId());
    }

    public function record(
        Product $product,
        Warehouse $warehouse,
        string $movementType,
        int $quantityDelta,
        string $sourceType,
        ?int $sourceId,
        \DateTimeImmutable $movedAt,
        string $memo,
    ): StockMovement {
        if ($quantityDelta === 0) {
            throw new \InvalidArgumentException('Stock movement quantity cannot be zero.');
        }
        if ($quantityDelta === PHP_INT_MIN) {
            throw new \InvalidArgumentException('Stock movement quantity is outside the supported range.');
        }
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->lock($warehouse, LockMode::PESSIMISTIC_WRITE);
        }
        $onHand = $this->onHand($product, $warehouse);
        if ($quantityDelta < 0 && $onHand < -$quantityDelta) {
            throw new \DomainException(sprintf('Insufficient stock for %s at %s.', $product->getSku(), $warehouse->getCode()));
        }
        if ($quantityDelta > 0 && $onHand > PHP_INT_MAX - $quantityDelta) {
            throw new \OverflowException('Stock quantity is too large.');
        }
        if ($quantityDelta < 0 && $onHand < PHP_INT_MIN - $quantityDelta) {
            throw new \OverflowException('Stock quantity is too small.');
        }

        $movement = new StockMovement($product, $warehouse, $movementType, $quantityDelta, $sourceType, $sourceId, $movedAt, $memo);
        $this->entityManager->persist($movement);
        return $movement;
    }
}