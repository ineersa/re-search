<?php

declare(strict_types=1);

namespace App\Research\Throttle;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Enforces research submit rate limits per client IP.
 */
final class ResearchThrottle
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $researchSubmitLimiter,
    ) {
    }

    /**
     * Check if one token is available for the current request without consuming it. Throws TooManyRequestsHttpException if limit exceeded.
     */
    public function peek(Request $request): void
    {
        $identifier = $this->clientIp($request);
        $limiter = $this->researchSubmitLimiter->create($identifier);

        $limit = $limiter->consume(0);
        if ($limit->getRemainingTokens() < 1) {
            // Coarse hint for daily sliding window; UI copy matches ("retry tomorrow").
            throw new TooManyRequestsHttpException(86400, 'Research request rate limit exceeded. Please try again later.');
        }
    }

    /**
     * Consume one token for the given client key.
     */
    public function consumeByClientKey(string $clientKey): void
    {
        $ip = explode('|', $clientKey)[0];
        $limiter = $this->researchSubmitLimiter->create($ip);
        $limiter->consume(1);
    }

    private function clientIp(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
