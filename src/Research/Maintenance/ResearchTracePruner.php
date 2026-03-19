<?php

declare(strict_types=1);

namespace App\Research\Maintenance;

use App\Research\Maintenance\Dto\TracePruneResult;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class ResearchTracePruner
{
    public const string PRUNED_STEP_TYPE = 'trace_pruned';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prune(int $keepPerClient = 10, bool $dryRun = false): TracePruneResult
    {
        if ($keepPerClient < 1) {
            throw new \InvalidArgumentException('keepPerClient must be greater than 0.');
        }

        $runs = $this->connection->fetchAllAssociative(
            'SELECT id, client_key FROM research_run WHERE status <> :queued AND status <> :running ORDER BY client_key ASC, COALESCE(completed_at, created_at) DESC, id DESC',
            ['queued' => 'queued', 'running' => 'running']
        );

        $scannedRuns = \count($runs);
        $eligibleRunIds = $this->eligibleRunIds($runs, $keepPerClient);

        $prunedRuns = 0;
        $alreadyPrunedRuns = 0;
        $stepsDeleted = 0;

        foreach ($eligibleRunIds as $runId) {
            $stepStats = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS total_steps, SUM(CASE WHEN type = :prunedType THEN 1 ELSE 0 END) AS pruned_steps FROM research_step WHERE run_id = :runId',
                ['prunedType' => self::PRUNED_STEP_TYPE, 'runId' => $runId]
            );

            $totalSteps = (int) ($stepStats['total_steps'] ?? 0);
            $prunedSteps = (int) ($stepStats['pruned_steps'] ?? 0);

            if (1 === $totalSteps && 1 === $prunedSteps) {
                ++$alreadyPrunedRuns;

                continue;
            }

            if ($dryRun) {
                ++$prunedRuns;
                $stepsDeleted += $totalSteps;

                continue;
            }

            $now = new \DateTimeImmutable();
            $payload = json_encode([
                'keepPerClient' => $keepPerClient,
                'prunedAt' => $now->format(\DateTimeInterface::ATOM),
            ], \JSON_THROW_ON_ERROR);

            $summary = \sprintf(
                'Trace was pruned. Full trace is available only for the most recent %d runs.',
                $keepPerClient
            );

            $this->connection->beginTransaction();

            try {
                $deleted = $this->connection->executeStatement('DELETE FROM research_step WHERE run_id = :runId', ['runId' => $runId]);

                $this->connection->insert('research_step', [
                    'run_id' => $runId,
                    'sequence' => 1,
                    'type' => self::PRUNED_STEP_TYPE,
                    'turn_number' => 0,
                    'tool_name' => null,
                    'tool_arguments_json' => null,
                    'tool_signature' => null,
                    'summary' => $summary,
                    'payload_json' => $payload,
                    'prompt_tokens' => null,
                    'completion_tokens' => null,
                    'total_tokens' => null,
                    'estimated_tokens' => false,
                    'created_at' => $now->format('Y-m-d H:i:s'),
                ]);

                $this->connection->commit();

                ++$prunedRuns;
                $stepsDeleted += $deleted;
            } catch (\Throwable $e) {
                $this->connection->rollBack();

                throw $e;
            }
        }

        $result = new TracePruneResult(
            scannedRuns: $scannedRuns,
            eligibleRuns: \count($eligibleRunIds),
            prunedRuns: $prunedRuns,
            alreadyPrunedRuns: $alreadyPrunedRuns,
            stepsDeleted: $stepsDeleted,
            dryRun: $dryRun,
        );

        $this->logger->info('Research trace pruning completed', [
            'scannedRuns' => $result->scannedRuns,
            'eligibleRuns' => $result->eligibleRuns,
            'prunedRuns' => $result->prunedRuns,
            'alreadyPrunedRuns' => $result->alreadyPrunedRuns,
            'stepsDeleted' => $result->stepsDeleted,
            'dryRun' => $result->dryRun,
        ]);

        return $result;
    }

    /**
     * @param list<array{id: int|string, client_key: string}> $runs
     *
     * @return list<int>
     */
    private function eligibleRunIds(array $runs, int $keepPerClient): array
    {
        $positions = [];
        $eligible = [];

        foreach ($runs as $run) {
            $clientKey = (string) $run['client_key'];
            $positions[$clientKey] = ($positions[$clientKey] ?? 0) + 1;

            if ($positions[$clientKey] > $keepPerClient) {
                $eligible[] = (int) $run['id'];
            }
        }

        return $eligible;
    }
}
