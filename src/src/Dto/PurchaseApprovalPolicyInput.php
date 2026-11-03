<?php

namespace App\Dto;

final class PurchaseApprovalPolicyInput
{
    public function __construct(
        /** Decimal amount in the default currency; null or empty turns purchase-order approvals off. */
        public readonly ?string $threshold = null,
    ) {
    }
}
