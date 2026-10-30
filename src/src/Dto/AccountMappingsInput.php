<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AccountMappingsInput
{
    public function __construct(
        #[Assert\Positive]
        public ?int $accounts_receivable = null,
        #[Assert\Positive]
        public ?int $revenue = null,
        #[Assert\Positive]
        public ?int $cash = null,
        #[Assert\Positive]
        public ?int $accounts_payable = null,
        #[Assert\Positive]
        public ?int $project_expense = null,
        #[Assert\Positive]
        public ?int $expense_payable = null,
    ) {
    }

    /** @return array<string, int|null> */
    public function accountIds(): array
    {
        return [
            'accounts_receivable' => $this->accounts_receivable,
            'revenue' => $this->revenue,
            'cash' => $this->cash,
            'accounts_payable' => $this->accounts_payable,
            'project_expense' => $this->project_expense,
            'expense_payable' => $this->expense_payable,
        ];
    }
}
