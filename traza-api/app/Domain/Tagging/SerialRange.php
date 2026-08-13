<?php

declare(strict_types=1);

namespace App\Domain\Tagging;

use Countable;
use IteratorAggregate;
use Traversable;

/** Rango de seriales reservado en exclusiva para una variante. */
final readonly class SerialRange implements Countable, IteratorAggregate
{
    public function __construct(
        public int $from,
        public int $to,
    ) {}

    public function count(): int
    {
        return $this->to - $this->from + 1;
    }

    public function getIterator(): Traversable
    {
        for ($serial = $this->from; $serial <= $this->to; $serial++) {
            yield $serial;
        }
    }

    /** @return list<int> */
    public function toArray(): array
    {
        return range($this->from, $this->to);
    }

    public function overlaps(self $other): bool
    {
        return $this->from <= $other->to && $other->from <= $this->to;
    }
}
