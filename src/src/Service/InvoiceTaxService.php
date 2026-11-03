<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Repository\TaxCodeRepository;

/**
 * Applies tax to an invoice's net line amounts when the organisation's tax rules are validated; otherwise leaves the
 * invoice untaxed (total = net) exactly as before. Rates are snapshotted onto the invoice, so later changes to a tax
 * code never rewrite history.
 */
final class InvoiceTaxService
{
    public function __construct(
        private readonly JurisdictionGate $gate,
        private readonly TaxCodeRepository $taxCodeRepository,
        private readonly TaxCalculator $calculator,
        private readonly InvoiceMoney $money,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items lines whose `amount` is the net amount; `tax_code` optionally picks a code
     * @return array{items: list<array<string, mixed>>, net_minor: int, tax_minor: int, gross_minor: int, breakdown: list<array{code: string|null, rate_bp: int, net_minor: int, tax_minor: int}>, rounding: string|null}
     * @throws \DomainException when a tax code is unknown or inactive
     */
    public function calculate(array $items): array
    {
        $net = 0;
        $minors = [];
        foreach ($items as $index => $item) {
            $minor = $this->money->parseMinor((string) $item['amount']);
            if ($minor === null) {
                throw new \InvalidArgumentException('Line amounts must be valid decimal amounts.');
            }
            $minors[$index] = $minor;
            $net += $minor;
        }
        $profile = $this->gate->taxProfile();
        if ($profile === null) {
            return ['items' => $items, 'net_minor' => $net, 'tax_minor' => 0, 'gross_minor' => $net, 'breakdown' => [], 'rounding' => null];
        }

        $lines = [];
        $taxed = [];
        foreach ($items as $index => $item) {
            $code = isset($item['tax_code']) && is_string($item['tax_code']) && $item['tax_code'] !== '' ? strtoupper($item['tax_code']) : $profile['tax_code'];
            $taxCode = $this->taxCodeRepository->findOneBy(['code' => $code]);
            if ($taxCode === null || !$taxCode->isActive()) {
                throw new \DomainException(sprintf('Tax code %s is not configured or inactive. An administrator can create the standard code under Settings → Jurisdiction.', $code));
            }
            $lines[$index] = ['net_minor' => $minors[$index], 'rate_bp' => $taxCode->getRateBasisPoints(), 'code' => $taxCode->getCode()];
            $taxed[$index] = $item + ['tax_code' => $taxCode->getCode()];
            $taxed[$index]['tax_code'] = $taxCode->getCode();
            $taxed[$index]['tax_rate_bp'] = $taxCode->getRateBasisPoints();
        }
        $result = $this->calculator->calculate(array_values($lines), $profile['rounding']);

        return ['items' => array_values($taxed), 'net_minor' => $result['net_minor'], 'tax_minor' => $result['tax_minor'], 'gross_minor' => $result['gross_minor'], 'breakdown' => $result['breakdown'], 'rounding' => $profile['rounding']];
    }

    /** Recalculates tax for the invoice's current lines and stores the result on it. */
    public function applyTo(Invoice $invoice): void
    {
        $result = $this->calculate($invoice->getItems());
        $invoice->applyTaxation($result['items'], $result['net_minor'], $result['tax_minor'], $result['breakdown'], $result['rounding']);
    }
}
