<?php

declare(strict_types=1);

namespace App\Research\Message;

use App\Research\ResearchRunService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExecuteResearchRunHandler
{
    public function __construct(
        private ResearchRunService $runService,
    ) {
    }

    public function __invoke(ExecuteResearchRun $message): void
    {
        $this->runService->execute($message->runId);
    }
}
