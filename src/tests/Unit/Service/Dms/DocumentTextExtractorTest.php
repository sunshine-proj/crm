<?php

namespace App\Tests\Unit\Service\Dms;

use App\Service\Dms\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;

final class DocumentTextExtractorTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function test_plain_text_is_collapsed_and_latin1_is_converted(): void
    {
        $extractor = new DocumentTextExtractor();
        self::assertSame('Hello world again', $extractor->extract($this->file("Hello\n\n world\tagain\x01"), 'text/plain'));
        self::assertSame('Café', $extractor->extract($this->file("Caf\xE9"), 'text/csv'));
        self::assertNull($extractor->extract($this->file("  \n "), 'text/plain'));
    }

    public function test_word_spreadsheet_and_opendocument_packages(): void
    {
        $extractor = new DocumentTextExtractor();
        $docx = $this->zip('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Travel</w:t></w:r></w:p><w:p><w:r><w:t>policy &amp; rules</w:t></w:r></w:p></w:body></w:document>');
        self::assertSame('Travel policy & rules', $extractor->extract($docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

        $xlsx = $this->zip('xl/sharedStrings.xml', '<sst><si><t>Budget</t></si><si><t>2026</t></si></sst>');
        self::assertSame('Budget 2026', $extractor->extract($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));

        $odt = $this->zip('content.xml', '<office:text><text:h>Title</text:h><text:p>Body</text:p></office:text>');
        self::assertSame('Title Body', $extractor->extract($odt, 'application/vnd.oasis.opendocument.text'));
    }

    public function test_unsupported_broken_or_empty_packages_yield_no_text(): void
    {
        $extractor = new DocumentTextExtractor();
        self::assertNull($extractor->extract($this->file('%PDF-1.4'), 'application/pdf'));
        self::assertNull($extractor->extract($this->file('not a zip'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        self::assertNull($extractor->extract($this->zip('other.xml', '<x/>'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dms-text');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function zip(string $entry, string $xml): string
    {
        $path = $this->file('');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString($entry, $xml);
        $zip->close();

        return $path;
    }
}
