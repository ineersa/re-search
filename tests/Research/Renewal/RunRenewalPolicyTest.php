<?php

declare(strict_types=1);

namespace App\Tests\Research\Renewal;

use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Research\Renewal\RunRenewalPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunRenewalPolicy::class)]
final class RunRenewalPolicyTest extends TestCase
{
    public function testClassifiesTimeoutFailuresAsRenewable(): void
    {
        $run = $this->createRun(ResearchRunStatus::FAILED, ResearchRunPhase::FAILED, 'LLM operation failed: Idle timeout reached while awaiting response.');
        $operation = $this->createOperation(
            $run,
            ResearchOperationType::LLM_CALL,
            'Idle timeout reached while awaiting response.',
            \RuntimeException::class,
        );

        $policy = new RunRenewalPolicy();
        $decision = $policy->classify($run, $operation);

        self::assertTrue($decision->renewable);
        self::assertSame(RunRenewalPolicy::STRATEGY_RETRY_LAST_OPERATION, $decision->strategy);
    }

    public function testClassifiesTimedOutLoopStoppedAndAbortedAsRenewable(): void
    {
        $policy = new RunRenewalPolicy();

        $timedOutRun = $this->createRun(ResearchRunStatus::TIMED_OUT, ResearchRunPhase::FAILED, 'Research timed out after 900 seconds');
        $timedOutDecision = $policy->classify($timedOutRun, null);

        self::assertTrue($timedOutDecision->renewable);
        self::assertSame(RunRenewalPolicy::STRATEGY_RETRY_LAST_OPERATION, $timedOutDecision->strategy);

        $loopStoppedRun = $this->createRun(ResearchRunStatus::LOOP_STOPPED, ResearchRunPhase::FAILED, 'Duplicate tool call detected.');
        $loopStoppedDecision = $policy->classify($loopStoppedRun, null);

        self::assertTrue($loopStoppedDecision->renewable);
        self::assertSame(RunRenewalPolicy::STRATEGY_RETRY_LAST_OPERATION, $loopStoppedDecision->strategy);

        $abortedRun = $this->createRun(ResearchRunStatus::ABORTED, ResearchRunPhase::ABORTED, 'Cancelled by user');
        $abortedDecision = $policy->classify($abortedRun, null);

        self::assertTrue($abortedDecision->renewable);
        self::assertSame(RunRenewalPolicy::STRATEGY_RESTART_FROM_QUEUE, $abortedDecision->strategy);

        $abortedOperation = $this->createOperation(
            $abortedRun,
            ResearchOperationType::LLM_CALL,
            'Run cancelled by user',
            \RuntimeException::class,
        );
        $abortedWithOperationDecision = $policy->classify($abortedRun, $abortedOperation);

        self::assertTrue($abortedWithOperationDecision->renewable);
        self::assertSame(RunRenewalPolicy::STRATEGY_RETRY_LAST_OPERATION, $abortedWithOperationDecision->strategy);
    }

    public function testRejectsNonTransientFailuresAndNonRenewableStatuses(): void
    {
        $policy = new RunRenewalPolicy();

        $failedRun = $this->createRun(ResearchRunStatus::FAILED, ResearchRunPhase::FAILED, 'Invalid model configuration provided.');
        $nonTransientOperation = $this->createOperation(
            $failedRun,
            ResearchOperationType::LLM_CALL,
            'Model option "foo" is not supported.',
            \InvalidArgumentException::class,
        );

        $failedDecision = $policy->classify($failedRun, $nonTransientOperation);
        self::assertFalse($failedDecision->renewable);

        $completedRun = $this->createRun(ResearchRunStatus::COMPLETED, ResearchRunPhase::COMPLETED, null);
        $completedDecision = $policy->classify($completedRun, null);

        self::assertFalse($completedDecision->renewable);
    }

    private function createRun(ResearchRunStatus $status, ResearchRunPhase $phase, ?string $failureReason): ResearchRun
    {
        $suffix = bin2hex(random_bytes(5));
        $query = sprintf('run renewal policy test %s', $suffix);

        return (new ResearchRun())
            ->setQuery($query)
            ->setQueryHash(hash('sha256', $query))
            ->setClientKey('test-client-'.$suffix)
            ->setMercureTopic('https://tests.example/research/'.$suffix)
            ->setStatus($status)
            ->setPhase($phase)
            ->setFailureReason($failureReason)
            ->setCompletedAt($status->isTerminal() ? new \DateTimeImmutable() : null);
    }

    private function createOperation(ResearchRun $run, ResearchOperationType $type, string $errorMessage, string $errorClass): ResearchOperation
    {
        return (new ResearchOperation())
            ->setRun($run)
            ->setType($type)
            ->setStatus(ResearchOperationStatus::FAILED)
            ->setTurnNumber(2)
            ->setPosition(0)
            ->setIdempotencyKey(sprintf('%s:%s:%s', $run->getRunUuid(), $type->value, bin2hex(random_bytes(6))))
            ->setRequestPayloadJson('{}')
            ->setResultPayloadJson(json_encode([
                'errorClass' => $errorClass,
                'errorMessage' => $errorMessage,
            ], \JSON_THROW_ON_ERROR))
            ->setErrorMessage($errorMessage)
            ->setStartedAt(new \DateTimeImmutable('-1 minute'))
            ->setCompletedAt(new \DateTimeImmutable());
    }
}
