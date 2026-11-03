<?php

namespace App\Service;

/**
 * Reference data for the supported countries. These are starting points for a qualified adviser to confirm, NOT
 * validated rules: nothing here switches tax calculation or compliant numbering on by itself (see JurisdictionGate).
 * Rates were taken from public sources as seed values and may be out of date.
 */
final class JurisdictionCatalog
{
    /** @var array<string, array{name: string, tax_name: string, standard_rate_bp: int, authority: string, tax_code: string}> */
    private const COUNTRIES = [
        'LV' => ['name' => 'Latvia', 'tax_name' => 'PVN (VAT)', 'standard_rate_bp' => 2100, 'authority' => 'State Revenue Service (VID)', 'tax_code' => 'LV-STD'],
        'LT' => ['name' => 'Lithuania', 'tax_name' => 'PVM (VAT)', 'standard_rate_bp' => 2100, 'authority' => 'State Tax Inspectorate (VMI)', 'tax_code' => 'LT-STD'],
        'EE' => ['name' => 'Estonia', 'tax_name' => 'KM (VAT)', 'standard_rate_bp' => 2400, 'authority' => 'Tax and Customs Board (EMTA)', 'tax_code' => 'EE-STD'],
        'DE' => ['name' => 'Germany', 'tax_name' => 'USt / MwSt (VAT)', 'standard_rate_bp' => 1900, 'authority' => 'Federal Central Tax Office / local Finanzamt', 'tax_code' => 'DE-STD'],
        'GB' => ['name' => 'United Kingdom', 'tax_name' => 'VAT', 'standard_rate_bp' => 2000, 'authority' => 'HM Revenue & Customs (HMRC)', 'tax_code' => 'GB-STD'],
    ];

    /** Content an invoice normally needs (EU VAT Directive art. 226 and equivalents) — a checklist for the adviser. */
    public const INVOICE_CONTENT_CHECKLIST = [
        'Issue date and a unique sequential number',
        'Supplier name, address and VAT identification number',
        'Customer name and address (and VAT number for business customers where required)',
        'Description, quantity and date of the goods or services',
        'Taxable amount per rate, rate applied, and tax amount in the invoice currency',
        'Exemption or reverse-charge wording where no tax is charged',
        'Currency and, for foreign-currency invoices, the tax amount in the local currency where required',
    ];

    public const ROUNDING_MODES = ['per_rate_group', 'per_line'];
    public const SCOPES = ['tax', 'numbering'];

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    public function supports(string $code): bool
    {
        return isset(self::COUNTRIES[$code]);
    }

    /** @return array{name: string, tax_name: string, standard_rate_bp: int, authority: string, tax_code: string}|null */
    public function profile(string $code): ?array
    {
        return self::COUNTRIES[$code] ?? null;
    }
}
