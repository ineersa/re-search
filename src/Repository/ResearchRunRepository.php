<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResearchRun>
 */
class ResearchRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchRun::class);
    }

    public function findEntity(string $runUuid): ?ResearchRun
    {
        $run = $this->findOneBy(['runUuid' => $runUuid]);

        return $run instanceof ResearchRun ? $run : null;
    }

    /**
     * @return list<array{id: string, query: string, status: string, createdAt: \DateTimeInterface|null, completedAt: \DateTimeInterface|null, tokenBudgetUsed: int|null, tokenBudgetHardCap: int|null, loopDetected: bool, answerOnlyTriggered: bool, failureReason: string|null}>
     */
    public function findRecentByClientKey(string $clientKey, int $limit = 20): array
    {
        $runs = $this->findBy(
            ['clientKey' => $clientKey],
            ['createdAt' => 'DESC'],
            $limit
        );

        return array_map(
            static fn (ResearchRun $run) => [
                'id' => $run->getRunUuid(),
                'query' => $run->getQuery(),
                'status' => $run->getStatus(),
                'createdAt' => $run->getCreatedAt(),
                'completedAt' => $run->getCompletedAt(),
                'tokenBudgetUsed' => $run->getTokenBudgetUsed(),
                'tokenBudgetHardCap' => $run->getTokenBudgetHardCap(),
                'loopDetected' => $run->isLoopDetected(),
                'answerOnlyTriggered' => $run->isAnswerOnlyTriggered(),
                'failureReason' => $run->getFailureReason(),
            ],
            $runs
        );
    }

    /**
     * @return array{run: array{id: string, query: string, status: string, finalAnswerMarkdown: string|null, tokenBudgetUsed: int|null, tokenBudgetHardCap: int|null, tokenBudgetEstimated: bool, loopDetected: bool, answerOnlyTriggered: bool, failureReason: string|null, createdAt: \DateTimeInterface|null, completedAt: \DateTimeInterface|null}, steps: list<array{id: int|null, sequence: int, type: string, turnNumber: int|null, toolName: string|null, summary: string|null, payloadJson: string|null, createdAt: \DateTimeInterface|null}>}|null
     */
    public function findRunWithSteps(string $runUuid): ?array
    {
        $run = $this->findOneBy(['runUuid' => $runUuid]);
        if (!$run instanceof ResearchRun) {
            return null;
        }

        $steps = array_map(
            static fn (ResearchStep $step) => [
                'id' => $step->getId(),
                'sequence' => $step->getSequence(),
                'type' => $step->getType(),
                'turnNumber' => $step->getTurnNumber(),
                'toolName' => $step->getToolName(),
                'toolArgumentsJson' => $step->getToolArgumentsJson(),
                'summary' => $step->getSummary(),
                'payloadJson' => $step->getPayloadJson(),
                'createdAt' => $step->getCreatedAt(),
            ],
            $run->getSteps()->toArray()
        );

        return [
            'run' => [
                'id' => $run->getRunUuid(),
                'query' => $run->getQuery(),
                'status' => $run->getStatus(),
                'finalAnswerMarkdown' => $run->getFinalAnswerMarkdown(),
                'tokenBudgetUsed' => $run->getTokenBudgetUsed(),
                'tokenBudgetHardCap' => $run->getTokenBudgetHardCap(),
                'tokenBudgetEstimated' => $run->isTokenBudgetEstimated(),
                'loopDetected' => $run->isLoopDetected(),
                'answerOnlyTriggered' => $run->isAnswerOnlyTriggered(),
                'failureReason' => $run->getFailureReason(),
                'createdAt' => $run->getCreatedAt(),
                'completedAt' => $run->getCompletedAt(),
            ],
            'steps' => $steps,
        ];
    }

    /**
     * @return array{run: array{id: string, query: string, status: string, finalAnswerMarkdown: string|null, tokenBudgetUsed: int|null, tokenBudgetHardCap: int|null, tokenBudgetEstimated: bool, loopDetected: bool, answerOnlyTriggered: bool, failureReason: string|null, createdAt: \DateTimeInterface|null, completedAt: \DateTimeInterface|null}, steps: list<array{id: int|null, sequence: int, type: string, turnNumber: int|null, toolName: string|null, summary: string|null, payloadJson: string|null, createdAt: \DateTimeInterface|null}>}|null
     */
    public function findRunWithStepsForClient(string $runUuid, string $clientKey): ?array
    {
        $run = $this->findOneBy(['runUuid' => $runUuid, 'clientKey' => $clientKey]);
        if (!$run instanceof ResearchRun) {
            return null;
        }

        $steps = array_map(
            static fn (ResearchStep $step) => [
                'id' => $step->getId(),
                'sequence' => $step->getSequence(),
                'type' => $step->getType(),
                'turnNumber' => $step->getTurnNumber(),
                'toolName' => $step->getToolName(),
                'toolArgumentsJson' => $step->getToolArgumentsJson(),
                'summary' => $step->getSummary(),
                'payloadJson' => $step->getPayloadJson(),
                'createdAt' => $step->getCreatedAt(),
            ],
            $run->getSteps()->toArray()
        );

        return [
            'run' => [
                'id' => $run->getRunUuid(),
                'query' => $run->getQuery(),
                'status' => $run->getStatus(),
                'finalAnswerMarkdown' => $run->getFinalAnswerMarkdown(),
                'tokenBudgetUsed' => $run->getTokenBudgetUsed(),
                'tokenBudgetHardCap' => $run->getTokenBudgetHardCap(),
                'tokenBudgetEstimated' => $run->isTokenBudgetEstimated(),
                'loopDetected' => $run->isLoopDetected(),
                'answerOnlyTriggered' => $run->isAnswerOnlyTriggered(),
                'failureReason' => $run->getFailureReason(),
                'createdAt' => $run->getCreatedAt(),
                'completedAt' => $run->getCompletedAt(),
            ],
            'steps' => $steps,
        ];
    }
}
