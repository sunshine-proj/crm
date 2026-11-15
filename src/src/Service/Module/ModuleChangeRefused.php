<?php

namespace App\Service\Module;

/** A module configuration change that cannot be applied, with the HTTP status the API answers with. */
final class ModuleChangeRefused extends \DomainException
{
    /** @param list<string> $blocking modules that prevent the change */
    public function __construct(string $message, private readonly int $status, private readonly array $blocking = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return list<string> */
    public function blocking(): array
    {
        return $this->blocking;
    }
}
