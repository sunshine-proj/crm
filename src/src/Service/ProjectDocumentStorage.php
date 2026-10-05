<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ProjectDocumentStorage
{
    private const MAX_BYTES = 10485760;
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'text/plain',
        'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /** @return array{stored_name: string, original_name: string, mime_type: string, size_bytes: int} */
    public function store(UploadedFile $file): array
    {
        $size = $file->getSize();
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Document size must be between 1 byte and 10 MB.');
        }
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Document type is not supported.');
        }

        $storedName = bin2hex(random_bytes(24));
        $directory = $this->projectDir.'/var/project-documents';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to prepare project document storage.');
        }
        $file->move($directory, $storedName);

        return [
            'stored_name' => $storedName,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime_type' => $mimeType,
            'size_bytes' => $size,
        ];
    }

    public function path(string $storedName): string
    {
        if (preg_match('/^[a-f0-9]{48}$/D', $storedName) !== 1) {
            throw new \InvalidArgumentException('Invalid stored document identifier.');
        }
        return $this->projectDir.'/var/project-documents/'.$storedName;
    }

    public function delete(string $storedName): void
    {
        $path = $this->path($storedName);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Unable to remove project document from storage.');
        }
    }
}