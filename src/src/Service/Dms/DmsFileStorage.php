<?php

namespace App\Service\Dms;

use App\Entity\StoredFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Local storage for managed document files and attachments. Files get server-generated names, their type is detected
 * from the content (never from the client), and their SHA-256 is recorded. Same limits as project documents plus
 * OpenDocument text. No virus scanning yet (Phase 5).
 */
final class DmsFileStorage
{
    public const MAX_BYTES = 10485760;
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'text/plain',
        'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text',
    ];
    /** Types a browser can show inline safely; everything else is served as a download. */
    public const PREVIEWABLE_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'];

    public function __construct(#[Autowire('%app.dms_storage_dir%')] private readonly string $directory)
    {
    }

    public function store(UploadedFile $file): StoredFile
    {
        $size = $file->isValid() ? $file->getSize() : false;
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The file must be between 1 byte and 10 MB.');
        }
        $detected = (new \finfo(\FILEINFO_MIME_TYPE))->file($file->getPathname());
        $mimeType = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('This file type is not supported (PDF, Word, Excel, OpenDocument text, plain text, CSV, JPEG or PNG).');
        }
        $hash = hash_file('sha256', $file->getPathname());
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to prepare document storage.');
        }
        $storedName = bin2hex(random_bytes(24));
        $file->move($this->directory, $storedName);
        $name = trim(mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255));

        return new StoredFile($storedName, $name === '' ? 'document' : $name, $mimeType, $size, (string) $hash);
    }

    public function path(string $storedName): string
    {
        if (preg_match('/^[a-f0-9]{48}$/D', $storedName) !== 1) {
            throw new \InvalidArgumentException('Invalid stored document identifier.');
        }

        return $this->directory.'/'.$storedName;
    }

    public function delete(string $storedName): void
    {
        $path = $this->path($storedName);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Unable to remove a document file from storage.');
        }
    }

    public static function isPreviewable(string $mimeType): bool
    {
        return in_array($mimeType, self::PREVIEWABLE_MIME_TYPES, true);
    }
}
