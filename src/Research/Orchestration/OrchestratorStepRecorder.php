<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Entity\ResearchRun;
use App\Entity\ResearchStep;
use Doctrine\ORM\EntityManagerInterface;

final class OrchestratorStepRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function persistTokenSnapshot(
        ResearchRun $run,
        int &$sequence,
        int $turnNumber,
        ?int $promptTokens,
        ?int $completionTokens,
        ?int $totalTokens,
        int $cumulativeUsed,
    ): void {
        $payload = $this->encodeJson([
            'promptTokens' => $promptTokens,
            'completionTokens' => $completionTokens,
            'totalTokens' => $totalTokens,
            'totalUsed' => $cumulativeUsed,
        ]);

        $step = new ResearchStep();
        $step->setRun($run);
        $step->setSequence($sequence);
        ++$sequence;
        $step->setType('token_snapshot');
        $step->setTurnNumber($turnNumber);
        $step->setSummary(\sprintf('Tokens: %d total used', $cumulativeUsed));
        $step->setPayloadJson($payload);
        $step->setPromptTokens($promptTokens);
        $step->setCompletionTokens($completionTokens);
        $step->setTotalTokens($totalTokens);
        $step->setEstimatedTokens(false);
        $run->addStep($step);

        $this->entityManager->persist($step);
    }

    public function persistStep(
        ResearchRun $run,
        int &$sequence,
        string $type,
        int $turnNumber,
        string $summary,
        ?string $payloadJson,
        ?string $toolName = null,
        ?string $toolArgumentsJson = null,
        ?string $toolSignature = null,
    ): int {
        $step = new ResearchStep();
        $step->setRun($run);
        $step->setSequence($sequence);
        $step->setType($type);
        $step->setTurnNumber($turnNumber);
        $step->setSummary($summary);
        $step->setPayloadJson($payloadJson);
        $step->setToolName($toolName);
        $step->setToolArgumentsJson($toolArgumentsJson);
        $step->setToolSignature($toolSignature);
        $run->addStep($step);

        $this->entityManager->persist($step);

        $persistedSequence = $sequence;
        ++$sequence;

        return $persistedSequence;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function encodeJson(?array $payload): string
    {
        try {
            return json_encode($payload ?? [], \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{}';
        }
    }
}
