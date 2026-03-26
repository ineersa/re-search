<?php

declare(strict_types=1);

namespace App\Tests\Research\Controller;

use App\Entity\User;
use App\Entity\Enum\ResearchOperationStatus;
use App\Entity\Enum\ResearchOperationType;
use App\Entity\Enum\ResearchRunPhase;
use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchOperation;
use App\Entity\ResearchRun;
use App\Repository\ResearchRunRepository;
use App\Research\Message\Orchestrator\OrchestratorTick;
use App\Research\Message\Orchestrator\OrchestratorTickHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
        $this->assertArrayNotHasKey('runId', $data);

        /** @var ResearchRunRepository $runRepository */
        $runRepository = static::getContainer()->get(ResearchRunRepository::class);
        $this->assertNull($runRepository->findOneBy(['query' => 'rate-limit me']));
    }

    public function testDeleteRunWithInvalidCsrfTokenDoesNotDeleteRun(): void
    {
        $client = static::createClient();
        $runId = $this->submitRunAndGetId($client, 'delete me safely');

        $client->request('POST', '/research/runs/'.$runId.'/delete', ['_token' => 'invalid-token']);
        self::assertTrue($client->getResponse()->isRedirection());

        $client->request('GET', '/research/runs/'.$runId);
        self::assertResponseIsSuccessful();
    }

    public function testDeleteRunDeletesOwnedRunWhenCsrfTokenIsValid(): void
    {
        $client = static::createClient();
        $runId = $this->submitRunAndGetId($client, 'delete me now');
        $csrfToken = $this->csrfTokenFromHistoryFrame($client, '/research/runs/'.$runId.'/delete?page=0');

        $client->request('POST', '/research/runs/'.$runId.'/delete?page=0', ['_token' => $csrfToken]);
        self::assertResponseStatusCodeSame(302);

        $client->request('GET', '/research/runs/'.$runId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteAllRemovesOnlyCurrentClientHistory(): void
    {
        $client = static::createClient();
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');

        $client->loginUser($owner);
        $ownerRunOne = $this->submitRunAndGetId($client, 'owner run one');
        $ownerRunTwo = $this->submitRunAndGetId($client, 'owner run two');

        $client->loginUser($other);
        $otherRun = $this->submitRunAndGetId($client, 'other run');

        $client->loginUser($owner);
        $csrfToken = $this->csrfTokenFromHistoryFrame($client, '/research/runs/delete-all?page=0');
        $client->request('POST', '/research/runs/delete-all?page=0', ['_token' => $csrfToken]);
        self::assertResponseStatusCodeSame(302);

        $client->request('GET', '/research/runs/'.$ownerRunOne);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/research/runs/'.$ownerRunTwo);
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($other);
        $client->request('GET', '/research/runs/'.$otherRun);
        self::assertResponseIsSuccessful();
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

    public function testRenewReturns202ForEligibleFailedRun(): void
    {
        $client = static::createClient();
        $runId = $this->submitRunAndGetId($client, 'renew failed run');
        $this->prepareRunForRenewal(
            $runId,
            ResearchRunStatus::FAILED,
            ResearchRunPhase::FAILED,
            'LLM operation failed: Idle timeout reached while awaiting response.',
            true,
            ResearchOperationType::LLM_CALL,
        );

        $client->request('POST', '/research/runs/'.$runId.'/renew');

        self::assertResponseStatusCodeSame(202);
        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame('renewing', $payload['status'] ?? null);
        $this->assertSame($runId, $payload['runId'] ?? null);
        $this->assertSame('retry_last_operation', $payload['strategy'] ?? null);

        $run = static::getContainer()->get(ResearchRunRepository::class)->findEntity($runId);
        $this->assertNotNull($run);
        $this->assertSame(ResearchRunStatus::RUNNING, $run->getStatus());
        $this->assertSame(ResearchRunPhase::WAITING_LLM, $run->getPhase());
    }

    public function testRenewReturns202ForLoopStoppedRun(): void
    {
        $client = static::createClient();
        $runId = $this->submitRunAndGetId($client, 'renew loop-stopped run');
        $this->prepareRunForRenewal(
            $runId,
            ResearchRunStatus::LOOP_STOPPED,
            ResearchRunPhase::FAILED,
            'Duplicate tool call detected (third identical call).',
            true,
            ResearchOperationType::TOOL_CALL,
        );

        $client->request('POST', '/research/runs/'.$runId.'/renew');

        self::assertResponseStatusCodeSame(202);
        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame('renewing', $payload['status'] ?? null);
        $this->assertSame('retry_last_operation', $payload['strategy'] ?? null);

        $run = static::getContainer()->get(ResearchRunRepository::class)->findEntity($runId);
        $this->assertNotNull($run);
        $this->assertSame(ResearchRunStatus::RUNNING, $run->getStatus());
        $this->assertSame(ResearchRunPhase::WAITING_TOOLS, $run->getPhase());
    }

    public function testRenewReturns202ForTimedOutRun(): void
    {
        $client = static::createClient();
        $runId = $this->submitRunAndGetId($client, 'renew timed-out run');
        $this->prepareRunForRenewal(
            $runId,
            ResearchRunStatus::TIMED_OUT,
            ResearchRunPhase::FAILED,
            'Research timed out after 900 seconds',
            true,
            ResearchOperationType::LLM_CALL,
        );

        $client->request('POST', '/research/runs/'.$runId.'/renew');

        self::assertResponseStatusCodeSame(202);
        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame('renewing', $payload['status'] ?? null);
        $this->assertSame('retry_last_operation', $payload['strategy'] ?? null);
    }

    public function testRenewReturns409ForCompletedRun(): void
    {
        $client = static::createClient();
        $runId = $this->submitRunAndGetId($client, 'completed run should not renew');
        $this->prepareRunForRenewal(
            $runId,
            ResearchRunStatus::COMPLETED,
            ResearchRunPhase::COMPLETED,
            null,
            false,
        );

        $client->request('POST', '/research/runs/'.$runId.'/renew');

        self::assertResponseStatusCodeSame(409);
    }

    public function testRenewReturns409ForThrottledRun(): void
    {
        $client = static::createClient();
        $runId = $this->submitRunAndGetId($client, 'throttled run should not renew');
        $this->prepareRunForRenewal(
            $runId,
            ResearchRunStatus::THROTTLED,
            ResearchRunPhase::FAILED,
            'Rate limited - retry tomorrow!',
            false,
        );

        $client->request('POST', '/research/runs/'.$runId.'/renew');

        self::assertResponseStatusCodeSame(409);
    }

    public function testRenewReturns202ForAbortedRun(): void
    {
        $client = static::createClient();
        $runId = $this->submitRunAndGetId($client, 'aborted run should renew');
        $this->prepareRunForRenewal(
            $runId,
            ResearchRunStatus::ABORTED,
            ResearchRunPhase::ABORTED,
            'Cancelled by user',
            false,
        );

        $client->request('POST', '/research/runs/'.$runId.'/renew');

        self::assertResponseStatusCodeSame(202);
        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame('renewing', $payload['status'] ?? null);
        $this->assertSame('restart_from_queue', $payload['strategy'] ?? null);

        $run = static::getContainer()->get(ResearchRunRepository::class)->findEntity($runId);
        $this->assertNotNull($run);
        $this->assertSame(ResearchRunStatus::RUNNING, $run->getStatus());
        $this->assertSame(ResearchRunPhase::QUEUED, $run->getPhase());
    }

    public function testRenewReturns404ForDifferentClientOwner(): void
    {
        $client = static::createClient();
        $client->request('POST', '/research/runs', ['query' => 'owner renew run'], [], ['REMOTE_ADDR' => '198.51.100.20']);
        self::assertResponseStatusCodeSame(202);
        $ownerPayload = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($ownerPayload);
        $runId = (string) ($ownerPayload['runId'] ?? '');
        $this->assertNotSame('', $runId);

        $this->prepareRunForRenewal(
            $runId,
            ResearchRunStatus::FAILED,
            ResearchRunPhase::FAILED,
            'LLM operation failed: Idle timeout reached while awaiting response.',
            true,
            ResearchOperationType::LLM_CALL,
        );

        $client->request('POST', '/research/runs/'.$runId.'/renew', [], [], ['REMOTE_ADDR' => '198.51.100.21']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testInspectReturns404WhenNotDev(): void
    {
        $client = static::createClient();
        $client->request('GET', '/research/runs/some-uuid/inspect');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $server
     */
    private function submitRunAndGetId(KernelBrowser $client, string $query, array $server = []): string
    {
        $client->request('POST', '/research/runs', ['query' => $query], [], $server);
        self::assertResponseStatusCodeSame(202);

        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('runId', $payload);

        return (string) $payload['runId'];
    }

    /**
     * @param array<string, mixed> $server
     */
    private function csrfTokenFromHistoryFrame(KernelBrowser $client, string $formAction, array $server = []): string
    {
        $client->request('GET', '/research/history-frame?page=0', [], [], $server);
        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        $pattern = sprintf(
            '#<form[^>]*action="%s"[^>]*>.*?<input type="hidden" name="_token" value="([^"]+)"#s',
            preg_quote($formAction, '#')
        );

        $this->assertSame(1, preg_match($pattern, $content, $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function prepareRunForRenewal(
        string $runId,
        ResearchRunStatus $status,
        ResearchRunPhase $phase,
        ?string $failureReason,
        bool $createLatestOperation,
        ResearchOperationType $operationType = ResearchOperationType::LLM_CALL,
    ): void {
        /** @var ResearchRunRepository $runRepository */
        $runRepository = static::getContainer()->get(ResearchRunRepository::class);
        $run = $runRepository->findEntity($runId);
        $this->assertInstanceOf(ResearchRun::class, $run);

        $run->setStatus($status);
        $run->setPhase($phase);
        $run->setFailureReason($failureReason);
        $run->setCompletedAt($status->isTerminal() ? new \DateTimeImmutable() : null);
        $run->setCancelRequestedAt(ResearchRunStatus::ABORTED === $status ? new \DateTimeImmutable() : null);
        $run->setLoopDetected(ResearchRunStatus::LOOP_STOPPED === $status);
        $run->setOrchestratorStateJson(json_encode([
            'turnNumber' => 2,
            'attemptStartedAtUnix' => time() - 300,
            'messageWindow' => [],
        ], \JSON_THROW_ON_ERROR));

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        if ($createLatestOperation) {
            $operationError = $failureReason ?? 'Idle timeout reached while awaiting response.';
            $operation = (new ResearchOperation())
                ->setRun($run)
                ->setType($operationType)
                ->setStatus(ResearchOperationStatus::FAILED)
                ->setTurnNumber(2)
                ->setPosition(0)
                ->setIdempotencyKey(sprintf('%s:%s:%s', $run->getRunUuid(), $operationType->value, bin2hex(random_bytes(6))))
                ->setRequestPayloadJson('{}')
                ->setResultPayloadJson(json_encode([
                    'errorClass' => \RuntimeException::class,
                    'errorMessage' => $operationError,
                ], \JSON_THROW_ON_ERROR))
                ->setErrorMessage($operationError)
                ->setStartedAt(new \DateTimeImmutable('-1 minute'))
                ->setCompletedAt(new \DateTimeImmutable());
            $entityManager->persist($operation);
        }

        $entityManager->flush();
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('password');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
