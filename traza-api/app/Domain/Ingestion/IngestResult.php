<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

final readonly class IngestResult
{
    public function __construct(
        public string $batchId,
        public int $accepted,
        public int $rejected,
    ) {}
}
