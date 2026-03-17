<?php

declare(strict_types=1);

namespace App\Research\Guardrail;

use App\Research\Guardrail\Exception\BudgetExhaustedException;
use App\Research\Guardrail\Exception\LoopDetectedException;

/**
 * Enforces token budget and duplicate-call loop detection.
 *
 * Token budget: hard cap 75_000 tokens per run.
 * Duplicate detection: allow same normalized tool call twice, stop on third.
 */
final class ResearchBudgetEnforcer implements ResearchBudgetEnforcerInterface
{
    private const HARD_CAP_TOKENS = 75_000;
    private const DUPLICATE_CALL_LIMIT = 2;

    /**
     * @var array<string, int> runId -> total tokens used
     */
    private array $tokenUsageByRun = [];

    /**
     * @var array<string, array<string, int>> runId -> signature -> call count
     */
    private array $signatureCountByRun = [];

    public function recordTokenUsage(string $runId, int $tokens): void
    {
        $this->tokenUsageByRun[$runId] = ($this->tokenUsageByRun[$runId] ?? 0) + $tokens;
    }

    public function beforeToolCall(string $runId, string $toolName, array $arguments): void
    {
        $used = $this->tokenUsageByRun[$runId] ?? 0;
        if ($used >= self::HARD_CAP_TOKENS) {
            throw new BudgetExhaustedException(\sprintf('Token budget exhausted: %d / %d tokens used.', $used, self::HARD_CAP_TOKENS));
        }

        $signature = $this->normalizeSignature($toolName, $arguments);
        $count = $this->signatureCountByRun[$runId][$signature] ?? 0;
        if ($count >= self::DUPLICATE_CALL_LIMIT) {
            throw new LoopDetectedException(\sprintf('Duplicate tool call detected (third identical call): %s', $signature));
        }
    }

    public function afterToolCall(string $runId, string $toolName, array $arguments, mixed $result): void
    {
        $signature = $this->normalizeSignature($toolName, $arguments);
        $this->signatureCountByRun[$runId] ??= [];
        $this->signatureCountByRun[$runId][$signature] = ($this->signatureCountByRun[$runId][$signature] ?? 0) + 1;
    }

    /**
     * Normalize tool name + arguments into a stable signature for duplicate detection.
     * Uses toolName + sorted JSON of normalized args (query, url, etc.).
     *
     * @param array<string, mixed> $arguments
     */
    private function normalizeSignature(string $toolName, array $arguments): string
    {
        $normalized = [];
        foreach ($arguments as $key => $value) {
            $normalized[$key] = \is_scalar($value) ? (string) $value : json_encode($value, \JSON_THROW_ON_ERROR);
        }
        ksort($normalized);

        return $toolName.':'.json_encode($normalized, \JSON_THROW_ON_ERROR);
    }
}
