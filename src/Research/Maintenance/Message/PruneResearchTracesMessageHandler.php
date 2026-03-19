<?php

declare(strict_types=1);

namespace App\Research\Maintenance\Message;

use App\Research\Maintenance\ResearchTracePruner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneResearchTracesMessageHandler
{
    public function __construct(
        private readonly ResearchTracePruner $pruner,
    ) {
    }

    public function __invoke(PruneResearchTracesMessage $message): void
    {
        $this->pruner->prune($message->keepPerClient, false);
    }
}
