<?php

declare(strict_types=1);

namespace App\Research\Renewal;

final readonly class RunRenewalDecision
{
    public function __construct(
        public bool $renewable,
        public ?string $strategy,
        public string $reason,
    ) {
    }

    public static function renewable(string $strategy, string $reason): self
    {
        return new self(true, $strategy, $reason);
    }

    public static function nonRenewable(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
