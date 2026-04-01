<?php

declare(strict_types=1);

namespace App\Research\Throttle;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Enforces research submit rate limits per anonymous client fingerprint.
 */
final class ResearchThrottle
{
    private const ANON_CLIENT_PREFIX = 'anon:';

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

        $identifier = $this->anonymousLimiterIdentifierFromRequest($request);
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

        $identifier = $this->anonymousLimiterIdentifierFromClientKey($clientKey);
        $limiter = $this->researchSubmitLimiter->create($identifier);

        $current = $limiter->consume(0);
        if ($current->getRemainingTokens() >= $current->getLimit()) {
            return;
        }

        $limiter->consume(-1);
    }

    private function anonymousLimiterIdentifierFromRequest(Request $request): string
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $userAgent = $request->headers->get('User-Agent', 'unknown');

        return self::ANON_CLIENT_PREFIX.hash('sha256', $ip.'|'.$userAgent);
    }

    private function anonymousLimiterIdentifierFromClientKey(string $clientKey): string
    {
        if (str_starts_with($clientKey, self::ANON_CLIENT_PREFIX)) {
            return $clientKey;
        }

        $identifier = explode('|', $clientKey, 2)[0] ?? 'unknown';

        return '' !== trim($identifier) ? $identifier : 'unknown';
    }
}
