<?php

declare(strict_types=1);

namespace App\Research\Maintenance\Message;

final readonly class PruneResearchTracesMessage
{
    public function __construct(
        public int $keepPerClient = 10,
    ) {
    }
}
