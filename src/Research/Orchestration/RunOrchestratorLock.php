<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final readonly class RunOrchestratorLock
{
    private const LOCK_KEY_PREFIX = 'research_run';
    private const LOCK_KEY_SUFFIX = 'orchestrator';
    private const LOCK_TTL_SECONDS = 30.0;

    public function __construct(
        private LockFactory $lockFactory,
    ) {
    }

    public function keyForRun(string $runUuid): string
    {
        return self::LOCK_KEY_PREFIX.':'.$runUuid.':'.self::LOCK_KEY_SUFFIX;
    }

    public function acquire(string $runUuid): ?SharedLockInterface
    {
        $lock = $this->lockFactory->createLock($this->keyForRun($runUuid), self::LOCK_TTL_SECONDS, false);
        if (!$lock->acquire(false)) {
            return null;
        }

        return $lock;
    }

    public function release(SharedLockInterface $lock): void
    {
        if ($lock->isAcquired()) {
            $lock->release();
        }
    }
}
