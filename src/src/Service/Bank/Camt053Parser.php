<?php

namespace App\Service\Bank;

/**
 * Reads ISO 20022 camt.053 (bank-to-customer statement) XML, the format SEPA banks in LV, LT, EE and DE and many UK
 * banks export. DOCTYPE declarations are rejected outright and network access is disabled, so external entities cannot
 * be used to read files or call out.
 */
final class Camt053Parser
{
    public function __construct(private readonly AmountParser $amounts)
    {
    }

    public function parse(string $xml): ParsedStatement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException('The statement must not contain a DOCTYPE or entity declarations.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new \InvalidArgumentException('The file is not valid XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        if ($xpath->query("//*[local-name()='BkToCstmrStmt']")->length === 0) {
            throw new \InvalidArgumentException('This is not a camt.053 bank statement (no BkToCstmrStmt element).');
        }

        $lines = [];
        foreach ($xpath->query("//*[local-name()='Ntry']") as $entry) {
            $status = $this->text($xpath, ".//*[local-name()='Sts']", $entry);
            if ($status !== null && !in_array(strtoupper($status), ['BOOK', 'BOOKED'], true)) {
                continue;
            }
            $amountNode = $xpath->query("*[local-name()='Amt']", $entry)->item(0);
            $direction = $this->text($xpath, "*[local-name()='CdtDbtInd']", $entry);
            $dateText = $this->text($xpath, "*[local-name()='BookgDt']/*", $entry) ?? $this->text($xpath, "*[local-name()='ValDt']/*", $entry);
            if (!$amountNode instanceof \DOMElement || !in_array($direction, ['CRDT', 'DBIT'], true) || $dateText === null) {
                throw new \InvalidArgumentException('A statement entry is missing its amount, credit/debit indicator or booking date.');
            }
            $minor = $this->amounts->parseMinor($amountNode->textContent);
            if ($minor === null || $minor === 0) {
                continue;
            }
            $remittance = [];
            foreach ($xpath->query(".//*[local-name()='RmtInf']/*[local-name()='Ustrd']", $entry) as $node) {
                $remittance[] = trim($node->textContent);
            }
            $reference = trim(implode(' ', array_filter($remittance)));
            if ($reference === '') {
                $reference = $this->text($xpath, ".//*[local-name()='RmtInf']//*[local-name()='Ref']", $entry) ?? $this->text($xpath, "*[local-name()='AddtlNtryInf']", $entry) ?? '';
            }
            $party = $direction === 'CRDT' ? 'Dbtr' : 'Cdtr';
            $lines[] = [
                'date' => $this->date($dateText),
                'amount_minor' => $direction === 'CRDT' ? $minor : -$minor,
                'currency' => strtoupper($amountNode->getAttribute('Ccy')),
                'reference' => $reference,
                'counterparty' => $this->text($xpath, ".//*[local-name()='RltdPties']/*[local-name()='".$party."']//*[local-name()='Nm']", $entry),
                'external_id' => $this->text($xpath, "*[local-name()='AcctSvcrRef']", $entry) ?? $this->text($xpath, "*[local-name()='NtryRef']", $entry),
            ];
        }

        return new ParsedStatement(
            $lines,
            $this->balance($xpath, 'OPBD'),
            $this->balance($xpath, 'CLBD'),
            $this->text($xpath, "//*[local-name()='Stmt']/*[local-name()='Acct']/*[local-name()='Id']/*[local-name()='IBAN']"),
        );
    }

    private function balance(\DOMXPath $xpath, string $code): ?int
    {
        foreach ($xpath->query("//*[local-name()='Bal']") as $balance) {
            if ($this->text($xpath, ".//*[local-name()='Cd']", $balance) !== $code) {
                continue;
            }
            $amount = $this->text($xpath, "*[local-name()='Amt']", $balance);
            $minor = $amount !== null ? $this->amounts->parseMinor($amount) : null;
            if ($minor === null) {
                return null;
            }

            return $this->text($xpath, "*[local-name()='CdtDbtInd']", $balance) === 'DBIT' ? -$minor : $minor;
        }

        return null;
    }

    private function text(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?string
    {
        $node = $context === null ? $xpath->query($query)->item(0) : $xpath->query($query, $context)->item(0);
        $value = $node === null ? null : trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($date === false) {
            throw new \InvalidArgumentException('A statement entry has an invalid date.');
        }

        return $date;
    }
}
