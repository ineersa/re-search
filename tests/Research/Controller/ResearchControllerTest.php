<?php

declare(strict_types=1);

namespace App\Tests\Research\Controller;

use App\Entity\Enum\ResearchRunStatus;
use App\Repository\ResearchRunRepository;
use App\Research\Message\Orchestrator\OrchestratorTick;
use App\Research\Message\Orchestrator\OrchestratorTickHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ResearchControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        try {
            if (static::$booted ?? false) {
                static::getContainer()->get('cache.rate_limiter')->clear();
                static::getContainer()->get('cache.research_rate_limiter')->clear();
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
        $this->assertArrayHasKey('runId', $data);
        $this->assertArrayHasKey('mercureTopic', $data);
    }

    public function testThirdAnonymousSubmitIsRateLimitedImmediately(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '203.0.113.77']);

        $client->request('POST', '/research/runs', ['query' => 'first']);
        self::assertResponseStatusCodeSame(202);

        $client->request('POST', '/research/runs', ['query' => 'second']);
        self::assertResponseStatusCodeSame(202);

        $client->request('POST', '/research/runs', ['query' => 'third']);
        self::assertResponseStatusCodeSame(429);
    }

    public function testAbortedRunRefundsAnonymousRateLimitToken(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '203.0.113.78']);

        $client->request('POST', '/research/runs', ['query' => 'cancel me']);
        self::assertResponseStatusCodeSame(202);
        $firstSubmit = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($firstSubmit);
        $this->assertArrayHasKey('runId', $firstSubmit);

        $runId = (string) $firstSubmit['runId'];
        $client->request('POST', '/research/runs/'.$runId.'/stop');
        self::assertResponseStatusCodeSame(202);

        /** @var OrchestratorTickHandler $tickHandler */
        $tickHandler = static::getContainer()->get(OrchestratorTickHandler::class);
        $tickHandler(new OrchestratorTick($runId));

        $client->request('POST', '/research/runs', ['query' => 'after cancel one']);
        self::assertResponseStatusCodeSame(202);

        $client->request('POST', '/research/runs', ['query' => 'after cancel two']);
        self::assertResponseStatusCodeSame(202);

        $client->request('POST', '/research/runs', ['query' => 'after cancel three']);
        self::assertResponseStatusCodeSame(429);
    }

    public function testSubmitReturns400WhenQueryMissing(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs');

        self::assertResponseStatusCodeSame(400);
    }

    public function testSubmitReturns429WhenRateLimitExceeded(): void
    {
        $ip = '203.0.113.10';
        $client = static::createClient([], ['REMOTE_ADDR' => $ip]);

        /** @var RateLimiterFactory $limiterFactory */
        $limiterFactory = static::getContainer()->get('limiter.research_submit');
        $limiter = $limiterFactory->create($ip);
        for ($i = 0; $i < 500; ++$i) {
            $limit = $limiter->consume(1);
            if (!$limit->isAccepted()) {
                break;
            }
        }

        $client->request('POST', '/research/runs', ['query' => 'rate-limit me']);

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('retry-after', '86400');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame(ResearchRunStatus::THROTTLED->value, $data['status'] ?? null);
        $this->assertSame(86400, $data['retryAfter'] ?? null);
        $this->assertArrayHasKey('runId', $data);

        $run = static::getContainer()->get(ResearchRunRepository::class)->findEntity((string) $data['runId']);
        $this->assertNotNull($run);
        $this->assertSame(ResearchRunStatus::THROTTLED, $run->getStatus());
        $this->assertSame('Rate limited - retry tomorrow!', $run->getFailureReason());
    }

    public function testStopReturns202ForActiveRun(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs', ['query' => 'How does Mercure work?']);

        self::assertResponseStatusCodeSame(202);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('runId', $data);

        $client->request('POST', '/research/runs/'.$data['runId'].'/stop');

        self::assertResponseStatusCodeSame(202);
        $stopPayload = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('stopping', $stopPayload['status'] ?? null);
    }

    public function testStopReturns404WhenRunDoesNotExist(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs/'.bin2hex(random_bytes(16)).'/stop');

        self::assertResponseStatusCodeSame(404);
    }

    public function testStopReturns404ForDifferentClientOwner(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs', ['query' => 'owner run'], [], ['REMOTE_ADDR' => '198.51.100.10']);
        self::assertResponseStatusCodeSame(202);
        $ownerPayload = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($ownerPayload);
        $this->assertArrayHasKey('runId', $ownerPayload);

        $client->request('POST', '/research/runs/'.(string) $ownerPayload['runId'].'/stop', [], [], ['REMOTE_ADDR' => '198.51.100.11']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testInspectReturns404WhenNotDev(): void
    {
        $client = static::createClient();
        $client->request('GET', '/research/runs/some-uuid/inspect');

        self::assertResponseStatusCodeSame(404);
    }
}
