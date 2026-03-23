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
        $client->request('POST', '/research/runs', ['query' => 'What is Symfony?']);

        self::assertResponseStatusCodeSame(202);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('runId', $data);
        self::assertArrayHasKey('mercureTopic', $data);
    }

    public function testSubmitReturns400WhenQueryMissing(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs');

        self::assertResponseStatusCodeSame(400);
    }

    public function testStopReturns202ForActiveRun(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs', ['query' => 'How does Mercure work?']);

        self::assertResponseStatusCodeSame(202);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('runId', $data);

        $client->request('POST', '/research/runs/'.$data['runId'].'/stop');

        self::assertResponseStatusCodeSame(202);
        $stopPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('stopping', $stopPayload['status'] ?? null);
    }

    public function testInspectReturns404WhenNotDev(): void
    {
        $client = static::createClient();
        $client->request('GET', '/research/runs/some-uuid/inspect');

        self::assertResponseStatusCodeSame(404);
    }
}
