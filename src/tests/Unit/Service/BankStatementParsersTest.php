<?php

namespace App\Tests\Unit\Service;

use App\Service\Bank\AmountParser;
use App\Service\Bank\Camt053Parser;
use App\Service\Bank\CsvStatementParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BankStatementParsersTest extends TestCase
{
    public static function amounts(): iterable
    {
        yield 'plain' => ['125.50', 12550];
        yield 'comma decimal' => ['125,5', 12550];
        yield 'thousands dot, decimal comma' => ['1.234,56', 123456];
        yield 'thousands comma, decimal dot' => ['1,234.56', 123456];
        yield 'negative' => ['-40.00', -4000];
        yield 'accounting negative' => ['(12,30)', -1230];
        yield 'whole' => ['7', 700];
        yield 'spaces' => ["1 000.01", 100001];
        yield 'thousands only' => ['1,234', 123400];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function amounts_are_parsed_to_minor_units_without_floats(string $text, int $minor): void
    {
        self::assertSame($minor, (new AmountParser())->parseMinor($text));
    }

    #[Test]
    public function malformed_amounts_are_rejected(): void
    {
        foreach (['', 'abc', '1.2.3.4', '12,345,67', '1e5', '--5', '9999999999999999999'] as $bad) {
            self::assertNull((new AmountParser())->parseMinor($bad), $bad);
        }
    }

    private function camt(string $entries, string $extra = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'
            .'<Acct><Id><IBAN>LV80BANK0000435195001</IBAN></Id></Acct>'
            .'<Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">1000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>'
            .'<Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">1055.00</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>'
            .$entries.$extra.'</Stmt></BkToCstmrStmt></Document>';
    }

    #[Test]
    public function a_camt053_statement_yields_signed_lines_references_and_balances(): void
    {
        $xml = $this->camt(
            '<Ntry><Amt Ccy="EUR">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-03-05</Dt></BookgDt><AcctSvcrRef>REF1</AcctSvcrRef>'
            .'<NtryDtls><TxDtls><RltdPties><Dbtr><Nm>Acme SIA</Nm></Dbtr></RltdPties><RmtInf><Ustrd>Invoice INV-2026-000007</Ustrd><Ustrd>thanks</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
            .'<Ntry><Amt Ccy="EUR">45.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-03-06</Dt></BookgDt></Ntry>'
            .'<Ntry><Amt Ccy="EUR">9.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>PDNG</Sts><BookgDt><Dt>2026-03-06</Dt></BookgDt></Ntry>',
        );

        $result = (new Camt053Parser(new AmountParser()))->parse($xml);

        self::assertCount(2, $result->lines, 'pending entries are skipped');
        self::assertSame(10000, $result->lines[0]['amount_minor']);
        self::assertSame('Invoice INV-2026-000007 thanks', $result->lines[0]['reference']);
        self::assertSame('Acme SIA', $result->lines[0]['counterparty']);
        self::assertSame('REF1', $result->lines[0]['external_id']);
        self::assertSame(-4500, $result->lines[1]['amount_minor']);
        self::assertSame('2026-03-05', $result->lines[0]['date']->format('Y-m-d'));
        self::assertSame(100000, $result->openingBalanceMinor);
        self::assertSame(105500, $result->closingBalanceMinor);
        self::assertSame('LV80BANK0000435195001', $result->accountIban);
    }

    #[Test]
    public function xml_with_entity_declarations_is_refused_to_block_external_entity_attacks(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><Document><BkToCstmrStmt>&x;</BkToCstmrStmt></Document>';

        $this->expectException(\InvalidArgumentException::class);
        (new Camt053Parser(new AmountParser()))->parse($xml);
    }

    #[Test]
    public function files_that_are_not_camt053_or_are_malformed_are_rejected(): void
    {
        $parser = new Camt053Parser(new AmountParser());
        foreach (['not xml at all', '<Document><Other/></Document>', $this->camt('<Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>XXXX</CdtDbtInd><BookgDt><Dt>2026-03-05</Dt></BookgDt></Ntry>')] as $bad) {
            try {
                $parser->parse($bad);
                self::fail('Should have been rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function csv_columns_are_found_by_name_with_either_delimiter_and_date_style(): void
    {
        $csv = "Booking Date;Amount;Description;Name\n05.03.2026;1.250,50;Invoice 7;Acme\n06.03.2026;-40,00;Rent;Landlord\n\n";

        $result = (new CsvStatementParser(new AmountParser()))->parse($csv, 'EUR');

        self::assertCount(2, $result->lines);
        self::assertSame(125050, $result->lines[0]['amount_minor']);
        self::assertSame('EUR', $result->lines[0]['currency']);
        self::assertSame('Acme', $result->lines[0]['counterparty']);
        self::assertSame(-4000, $result->lines[1]['amount_minor']);
        self::assertSame('2026-03-06', $result->lines[1]['date']->format('Y-m-d'));
    }

    #[Test]
    public function csv_without_the_required_columns_or_with_bad_rows_is_rejected(): void
    {
        $parser = new CsvStatementParser(new AmountParser());
        foreach (["foo,bar\n1,2", "date,amount\n2026-03-05,abc", "date,amount\n05-03-2026,1.00", 'only a header'] as $bad) {
            try {
                $parser->parse($bad, 'EUR');
                self::fail('Should have been rejected: '.$bad);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
