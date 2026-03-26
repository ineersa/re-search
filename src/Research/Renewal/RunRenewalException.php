<?php

declare(strict_types=1);

namespace App\Research\Renewal;

final class RunRenewalException extends \DomainException
{
    public static function nonRenewable(string $reason): self
    {
        return new self($reason);
    }

    public static function missingLatestOperation(string $runId): self
    {
        return new self(sprintf('Run "%s" has no operation to retry.', $runId));
    }

    public static function lockUnavailable(string $runId): self
    {
        return new self(sprintf('Run "%s" is currently being processed. Retry in a few seconds.', $runId));
    }
}
