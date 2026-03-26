<?php

declare(strict_types=1);

namespace App\Research\Renewal;

use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;

final class RunRenewalPolicy
{
    public const STRATEGY_RETRY_LAST_OPERATION = 'retry_last_operation';
    public const STRATEGY_RESTART_FROM_QUEUE = 'restart_from_queue';

    /**
     * @var list<string>
     */
    private const TRANSIENT_MESSAGE_FRAGMENTS = [
        'idle timeout',
        'timed out',
        'timeout reached',
        'connection timed out',
        'transport error',
        'network error',
        'temporary network',
        'temporarily unavailable',
        'service unavailable',
        'gateway timeout',
        'connection reset',
        'connection refused',
        'connection aborted',
        'connection closed',
        'econnreset',
        'econnrefused',
        'ehostunreach',
        'enotfound',
        'dns',
    ];

    /**
     * @var list<string>
     */
    private const TRANSIENT_ERROR_CLASS_FRAGMENTS = [
        'timeout',
        'transport',
        'network',
        'connectexception',
        'socketexception',
        'httpexception',
        'curl',
    ];

    public function classify(ResearchRun $run, ?ResearchOperation $latestOperation): RunRenewalDecision
    {
        return match ($run->getStatus()) {
            ResearchRunStatus::TIMED_OUT => RunRenewalDecision::renewable(
                self::STRATEGY_RETRY_LAST_OPERATION,
                'Run hit the wall-clock timeout and can retry its last operation.'
            ),
            ResearchRunStatus::LOOP_STOPPED => RunRenewalDecision::renewable(
                self::STRATEGY_RETRY_LAST_OPERATION,
                'Run was stopped by loop protection and can retry its last operation.'
            ),
            ResearchRunStatus::FAILED => $this->classifyFailedRun($run, $latestOperation),
            ResearchRunStatus::COMPLETED => RunRenewalDecision::nonRenewable('Completed runs cannot be renewed.'),
            ResearchRunStatus::THROTTLED => RunRenewalDecision::nonRenewable('Rate-limited runs cannot be renewed.'),
            ResearchRunStatus::ABORTED => $this->classifyAbortedRun($latestOperation),
            ResearchRunStatus::BUDGET_EXHAUSTED => RunRenewalDecision::nonRenewable('Budget-exhausted runs cannot be renewed.'),
            default => RunRenewalDecision::nonRenewable(
                sprintf('Run with status "%s" is not renewable.', $run->getStatusValue())
            ),
        };
    }

    private function classifyFailedRun(ResearchRun $run, ?ResearchOperation $latestOperation): RunRenewalDecision
    {
        if (!$latestOperation instanceof ResearchOperation) {
            return RunRenewalDecision::nonRenewable('Failed run has no operation available to retry.');
        }

        if ($this->hasTransientSignal($run, $latestOperation)) {
            return RunRenewalDecision::renewable(
                self::STRATEGY_RETRY_LAST_OPERATION,
                'Transient failure detected. Retrying the last operation is safe.'
            );
        }

        return RunRenewalDecision::nonRenewable('Failure does not look transient. Retry is disabled.');
    }

    private function classifyAbortedRun(?ResearchOperation $latestOperation): RunRenewalDecision
    {
        if ($latestOperation instanceof ResearchOperation) {
            return RunRenewalDecision::renewable(
                self::STRATEGY_RETRY_LAST_OPERATION,
                'User-stopped run can retry its last operation.'
            );
        }

        return RunRenewalDecision::renewable(
            self::STRATEGY_RESTART_FROM_QUEUE,
            'User-stopped run has no operation yet; restarting from queue is safe.'
        );
    }

    private function hasTransientSignal(ResearchRun $run, ResearchOperation $latestOperation): bool
    {
        $signals = $this->collectMessageSignals($run, $latestOperation);
        foreach ($signals as $signal) {
            $normalized = mb_strtolower($signal);

            foreach (self::TRANSIENT_MESSAGE_FRAGMENTS as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    return true;
                }
            }
        }

        $errorClasses = $this->collectErrorClassSignals($latestOperation);
        foreach ($errorClasses as $errorClass) {
            $normalized = mb_strtolower($errorClass);

            foreach (self::TRANSIENT_ERROR_CLASS_FRAGMENTS as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectMessageSignals(ResearchRun $run, ResearchOperation $latestOperation): array
    {
        $signals = [];

        if (null !== $run->getFailureReason() && '' !== trim($run->getFailureReason())) {
            $signals[] = trim($run->getFailureReason());
        }

        if (null !== $latestOperation->getErrorMessage() && '' !== trim($latestOperation->getErrorMessage())) {
            $signals[] = trim($latestOperation->getErrorMessage());
        }

        $payload = $this->decodePayload($latestOperation->getResultPayloadJson());
        if ([] !== $payload) {
            $signals = [...$signals, ...$this->extractPayloadSignals($payload)];
        }

        return array_values(array_unique($signals));
    }

    /**
     * @return list<string>
     */
    private function collectErrorClassSignals(ResearchOperation $latestOperation): array
    {
        $payload = $this->decodePayload($latestOperation->getResultPayloadJson());
        if ([] === $payload) {
            return [];
        }

        $candidates = [
            $payload['errorClass'] ?? null,
            $payload['exceptionClass'] ?? null,
            $payload['class'] ?? null,
        ];

        $classes = [];
        foreach ($candidates as $candidate) {
            if (!\is_string($candidate) || '' === trim($candidate)) {
                continue;
            }

            $classes[] = trim($candidate);
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function extractPayloadSignals(array $payload): array
    {
        $signals = [];

        array_walk_recursive($payload, static function (mixed $value, mixed $key) use (&$signals): void {
            if (!\is_string($value) || '' === trim($value)) {
                return;
            }

            $normalizedKey = mb_strtolower((string) $key);
            if (
                '' === $normalizedKey
                || str_contains($normalizedKey, 'error')
                || str_contains($normalizedKey, 'exception')
                || str_contains($normalizedKey, 'message')
                || str_contains($normalizedKey, 'reason')
                || str_contains($normalizedKey, 'timeout')
                || str_contains($normalizedKey, 'transport')
                || str_contains($normalizedKey, 'network')
            ) {
                $signals[] = trim($value);
            }
        });

        return array_values(array_unique($signals));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(?string $payloadJson): array
    {
        if (null === $payloadJson || '' === trim($payloadJson)) {
            return [];
        }

        try {
            $decoded = json_decode($payloadJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
