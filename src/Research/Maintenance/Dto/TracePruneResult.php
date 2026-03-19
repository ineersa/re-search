<?php

declare(strict_types=1);

namespace App\Research\Maintenance\Dto;

final readonly class TracePruneResult
{
    public function __construct(
        public int $scannedRuns,
        public int $eligibleRuns,
        public int $prunedRuns,
        public int $alreadyPrunedRuns,
        public int $stepsDeleted,
        public bool $dryRun,
    ) {
    }
}
