<?php

namespace App\Service\Time;

/** A time entry that cannot be recorded; carries the HTTP status the API answers with. */
final class TimeEntryRejected extends \DomainException
{
    public function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
