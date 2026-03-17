<?php

declare(strict_types=1);

namespace App\Research\Throttle;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Enforces research submit rate limits using IP + session identity.
 */
final class ResearchThrottle
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $researchSubmitLimiter,
    ) {
    }

    /**
     * Consume one token for the current request. Throws TooManyRequestsHttpException if limit exceeded.
     */
    public function consume(Request $request): void
    {
        $identifier = $this->buildIdentifier($request);
        $limiter = $this->researchSubmitLimiter->create($identifier);

        $limit = $limiter->consume(1);
        if (false === $limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp();
            throw new TooManyRequestsHttpException($retryAfter - time(), 'Research request rate limit exceeded. Please try again later.');
        }
    }

    private function buildIdentifier(Request $request): string
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $sessionId = $request->hasSession() ? $request->getSession()->getId() : 'no-session';

        return $ip.'|'.$sessionId;
    }
}
