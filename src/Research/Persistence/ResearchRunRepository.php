<?php

declare(strict_types=1);

namespace App\Research\Persistence;

use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use App\Repository\ResearchRunRepository as DoctrineResearchRunRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Doctrine-backed implementation of research run queries.
 */
final class ResearchRunRepository implements ResearchRunRepositoryInterface
{
    public function __construct(
        private readonly DoctrineResearchRunRepository $doctrineRepository,
    ) {
    }

    public function findRecentByClientKey(string $clientKey, int $limit = 20): array
    {
        $runs = $this->doctrineRepository->findBy(
            ['clientKey' => $clientKey],
            ['createdAt' => 'DESC'],
            $limit
        );

        return array_map(
            fn (ResearchRun $run) => [
                'id' => $run->getId()->toRfc4122(),
                'query' => $run->getQuery(),
                'status' => $run->getStatus(),
                'createdAt' => $run->getCreatedAt(),
            ],
            $runs
        );
    }

    public function findRunWithSteps(string $runId): ?array
    {
        try {
            $uuid = Uuid::fromString($runId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $run = $this->doctrineRepository->find($uuid);
        if (!$run instanceof ResearchRun) {
            return null;
        }

        $steps = array_map(
            fn (ResearchStep $step) => [
                'id' => $step->getId()->toRfc4122(),
                'sequence' => $step->getSequence(),
                'type' => $step->getType(),
                'turnNumber' => $step->getTurnNumber(),
                'toolName' => $step->getToolName(),
                'summary' => $step->getSummary(),
                'payloadJson' => $step->getPayloadJson(),
                'createdAt' => $step->getCreatedAt(),
            ],
            $run->getSteps()->toArray()
        );

        return [
            'run' => [
                'id' => $run->getId()->toRfc4122(),
                'query' => $run->getQuery(),
                'status' => $run->getStatus(),
                'finalAnswerMarkdown' => $run->getFinalAnswerMarkdown(),
                'tokenBudgetUsed' => $run->getTokenBudgetUsed(),
                'loopDetected' => $run->isLoopDetected(),
                'createdAt' => $run->getCreatedAt(),
                'completedAt' => $run->getCompletedAt(),
            ],
            'steps' => $steps,
        ];
    }
}
