<?php

namespace App\Service\Dms;

/**
 * Plain text for search from text files and Office/OpenDocument packages (read from the ZIP container with a size
 * cap). PDFs and images return null: there is no PDF text parser or OCR in the stack yet.
 */
final class DocumentTextExtractor
{
    public const MAX_CHARS = 100000;
    private const MAX_XML_BYTES = 5000000;
    private const PACKAGE_PARTS = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word/document.xml',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xl/sharedStrings.xml',
        'application/vnd.oasis.opendocument.text' => 'content.xml',
    ];

    public function extract(string $path, string $mimeType): ?string
    {
        if (in_array($mimeType, ['text/plain', 'text/csv'], true)) {
            $text = (string) file_get_contents($path, false, null, 0, self::MAX_CHARS * 4);

            return $this->clean(mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252'));
        }
        $part = self::PACKAGE_PARTS[$mimeType] ?? null;
        if ($part === null || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            $xml = $zip->getFromName($part, self::MAX_XML_BYTES);
        } finally {
            $zip->close();
        }
        if (!is_string($xml) || $xml === '') {
            return null;
        }
        // Paragraph, cell and line ends become spaces so words do not run together, then markup is dropped.
        $xml = (string) preg_replace('#</(?:w:p|text:p|text:h|si|w:tab)>|<(?:w:br|w:tab|text:tab|text:line-break)\b[^>]*/?>#', ' ', $xml);

        return $this->clean(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function clean(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $text)));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_CHARS);
    }
}
