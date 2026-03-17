<?php

declare(strict_types=1);

namespace App\Tests\Research\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ResearchControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        try {
            if (static::$booted ?? false) {
                static::getContainer()->get('cache.rate_limiter')->clear();
            }
        } catch (\Throwable) {
            // Kernel may be shut down
        }
        parent::tearDown();
    }

    public function testSubmitReturns202WhenUnderRateLimit(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs');

        self::assertResponseStatusCodeSame(202);
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testSubmitReturns429WhenRateLimitExceeded(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs');
        self::assertResponseStatusCodeSame(202);

        // Second request within 10 minutes should be throttled (limit: 1 per 10 min)
        $client->request('POST', '/research/runs');
        self::assertResponseStatusCodeSame(429);
    }
}
