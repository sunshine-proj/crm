<?php

namespace App\Service\Time;

use Symfony\Component\HttpFoundation\InputBag;

/** Validated time-entry filters shared by the entry list, the report, its CSV export and the workload view. */
final class TimeEntryFilter
{
    public const STATUSES = ['pending_approval', 'approved', 'rejected'];

    public function __construct(
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to = null,
        public readonly ?int $projectId = null,
        public readonly ?int $taskId = null,
        public readonly ?int $userId = null,
        public readonly ?string $status = null,
        public readonly ?bool $billable = null,
    ) {
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('The period start must not be after its end.');
        }
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Approval status must be pending_approval, approved or rejected.');
        }
    }

    /**
     * @param InputBag<string> $query
     * @throws \InvalidArgumentException with a user-facing message
     */
    public static function fromQuery(InputBag $query): self
    {
        $billable = self::text($query, 'billable');
        if ($billable !== null && !in_array($billable, ['true', 'false', '1', '0'], true)) {
            throw new \InvalidArgumentException('Billable must be true or false.');
        }

        return new self(
            self::date($query, 'from'),
            self::date($query, 'to'),
            self::id($query, 'project_id'),
            self::id($query, 'task_id'),
            self::id($query, 'user_id'),
            self::text($query, 'status'),
            $billable === null ? null : in_array($billable, ['true', '1'], true),
        );
    }

    public function forUser(int $userId): self
    {
        return new self($this->from, $this->to, $this->projectId, $this->taskId, $userId, $this->status, $this->billable);
    }

    /** @param InputBag<string> $query */
    private static function text(InputBag $query, string $name): ?string
    {
        $value = $query->get($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a single value.', $name));
        }

        return $value;
    }

    /** @param InputBag<string> $query */
    private static function id(InputBag $query, string $name): ?int
    {
        $value = self::text($query, $name);
        if ($value === null) {
            return null;
        }
        if (!ctype_digit($value) || (int) $value < 1 || strlen($value) > 18) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer.', $name));
        }

        return (int) $value;
    }

    /** @param InputBag<string> $query */
    private static function date(InputBag $query, string $name): ?\DateTimeImmutable
    {
        $value = self::text($query, $name);
        if ($value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('%s must be a date in YYYY-MM-DD form.', $name));
        }

        return $date;
    }
}
