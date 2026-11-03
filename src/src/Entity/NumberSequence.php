<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Counter for one document series and year. Rows are only ever locked and incremented inside the issuing transaction. */
#[ORM\Entity]
#[ORM\Table(name: 'number_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_number_sequence_series_year', columns: ['series', 'year'])]
class NumberSequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $series;

    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Column]
    private int $lastNumber = 0;

    public function __construct(string $series, int $year)
    {
        $this->series = $series;
        $this->year = $year;
    }

    public function increment(): int
    {
        return ++$this->lastNumber;
    }

    public function getLastNumber(): int { return $this->lastNumber; }
}
