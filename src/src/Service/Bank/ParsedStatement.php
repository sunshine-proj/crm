<?php

namespace App\Service\Bank;

/** Format-independent result of reading a bank statement file. */
final class ParsedStatement
{
    /** @param list<array{date: \DateTimeImmutable, amount_minor: int, currency: string, reference: string, counterparty: string|null, external_id: string|null}> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly ?int $openingBalanceMinor = null,
        public readonly ?int $closingBalanceMinor = null,
        public readonly ?string $accountIban = null,
    ) {
    }
}
