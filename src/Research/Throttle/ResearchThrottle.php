<?php

declare(strict_types=1);

namespace App\Research\Throttle;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Enforces research submit rate limits per anonymous client IP.
 */
final class ResearchThrottle
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $researchSubmitLimiter,
        private readonly Security $security,
    ) {
    }

    /**
     * Consume one submit token for the current request (anonymous only).
     */
    public function consumeOnSubmit(Request $request): void
    {
        $user = $this->security->getUser();
        if ($user instanceof UserInterface) {
            return;
        }

        $identifier = $this->clientIp($request);
        $limiter = $this->researchSubmitLimiter->create($identifier);

        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            // Coarse hint for daily sliding window; UI copy matches ("retry tomorrow").
            throw new TooManyRequestsHttpException(86400, 'Research request rate limit exceeded. Please try again later.');
        }
    }

    /**
     * Refund one submit token for failed or cancelled anonymous runs.
     */
    public function refundByClientKey(string $clientKey): void
    {
        if (str_starts_with($clientKey, 'user:')) {
            return;
        }

        $ip = explode('|', $clientKey, 2)[0] ?? 'unknown';
        $limiter = $this->researchSubmitLimiter->create($ip);

        $current = $limiter->consume(0);
        if ($current->getRemainingTokens() >= $current->getLimit()) {
            return;
        }

        $limiter->consume(-1);
    }

    private function clientIp(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
