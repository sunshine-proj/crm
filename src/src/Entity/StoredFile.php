<?php

namespace App\Entity;

/** A file placed in document storage: what the uploader called it, its detected type, size and content hash. */
final class StoredFile
{
    public function __construct(
        public readonly string $storedName,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {
    }
}
