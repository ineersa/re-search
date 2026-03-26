<?php

declare(strict_types=1);

namespace App\Research\Controller;

use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchRun;
use App\Repository\ResearchRunRepository;
use App\Research\Mercure\ResearchTopicFactory;
use App\Research\Message\Orchestrator\OrchestratorTick;
use App\Research\Renewal\RunRenewalException;
use App\Research\Renewal\RunRenewalService;
use App\Research\Throttle\ResearchThrottle;
use App\Research\View\ResearchHistoryFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

final class ResearchController extends AbstractController
{
    private const CSRF_DELETE_HISTORY_ITEM = 'delete_history_item';
    private const CSRF_DELETE_HISTORY_ALL = 'delete_history_all';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $kernelSecret,
    ) {
    }

    #[Route('/research/runs', name: 'app_research_list', methods: ['GET'])]
    public function list(
        Request $request,
        ResearchRunRepository $runRepository,
    ): Response {
        $clientKey = $this->buildClientKey($request);
        $runs = $runRepository->findRecentByClientKey($clientKey, 20);
        $items = array_map(
            static function (array $run): array {
                $createdAt = $run['createdAt'];
                $completedAt = $run['completedAt'];

                return [
                    'id' => $run['id'],
                    'query' => $run['query'],
                    'status' => $run['status'],
                    'createdAt' => $createdAt->format(\DateTimeInterface::ATOM),
                    'completedAt' => $completedAt?->format(\DateTimeInterface::ATOM),
                    'tokenBudgetUsed' => $run['tokenBudgetUsed'],
                    'tokenBudgetHardCap' => $run['tokenBudgetHardCap'],
                    'loopDetected' => $run['loopDetected'],
                    'answerOnlyTriggered' => $run['answerOnlyTriggered'],
                    'failureReason' => $run['failureReason'],
                ];
            },
            $runs
        );

        return new JsonResponse(['runs' => $items]);
    }

    #[Route('/research/history-frame', name: 'app_research_history_frame', methods: ['GET'])]
    public function historyFrame(
        Request $request,
        ResearchRunRepository $runRepository,
        #[Autowire('%kernel.environment%')] string $kernelEnvironment,
    ): Response {
        $perPage = 5;
        $page = max(0, (int) $request->query->get('page', 0));
        $clientKey = $this->buildClientKey($request);
        $runs = $runRepository->findRecentByClientKey($clientKey, 20);
        $total = \count($runs);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        if ($totalPages > 0) {
            $page = min($page, $totalPages - 1);
        } else {
            $page = 0;
        }
        $slice = \array_slice($runs, $page * $perPage, $perPage);
        $rows = array_map(
            fn (array $run): array => [
                ...ResearchHistoryFormatter::formatRow($run),
                'deleteToken' => $this->buildDeleteItemToken($clientKey, $run['id']),
            ],
            $slice
        );

        return $this->render('research/history_frame.html.twig', [
            'rows' => $rows,
            'page' => $page,
            'total_pages' => $totalPages,
            'delete_all_token' => $this->buildDeleteAllToken($clientKey),
            'show_inspect' => 'dev' === $kernelEnvironment,
        ]);
    }

    #[Route('/research/runs/{id}', name: 'app_research_show', methods: ['GET'])]
    public function show(
        string $id,
        Request $request,
        ResearchRunRepository $runRepository,
    ): Response {
        $clientKey = $this->buildClientKey($request);
        $data = $runRepository->findRunWithStepsForClient($id, $clientKey);

        if (null === $data) {
            throw $this->createNotFoundException('Research run not found.');
        }

        $run = $data['run'];
        $run['createdAt'] = $run['createdAt']->format(\DateTimeInterface::ATOM);
        $run['completedAt'] = $run['completedAt']?->format(\DateTimeInterface::ATOM);

        foreach ($data['steps'] as &$step) {
            $step['createdAt'] = $step['createdAt']->format(\DateTimeInterface::ATOM);
        }

        return new JsonResponse($data);
    }

    #[Route('/research/runs/{id}/delete', name: 'app_research_delete', methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        ResearchRunRepository $runRepository,
    ): Response {
        $clientKey = $this->buildClientKey($request);
        if (!$this->hasValidDeleteItemToken($request, $clientKey, $id)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$runRepository->deleteByRunUuidAndClientKey($id, $clientKey)) {
            throw $this->createNotFoundException('Research run not found.');
        }

        return $this->redirectToRoute('app_research_history_frame', [
            'page' => max(0, (int) $request->query->get('page', 0)),
        ]);
    }

    #[Route('/research/runs/delete-all', name: 'app_research_delete_all', methods: ['POST'])]
    public function deleteAll(
        Request $request,
        ResearchRunRepository $runRepository,
    ): Response {
        $clientKey = $this->buildClientKey($request);
        if (!$this->hasValidDeleteAllToken($request, $clientKey)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $runRepository->deleteAllByClientKey($clientKey);

        return $this->redirectToRoute('app_research_history_frame', [
            'page' => 0,
        ]);
    }

    #[Route('/research/runs', name: 'app_research_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        ResearchThrottle $throttle,
        ResearchTopicFactory $topicFactory,
        MessageBusInterface $bus,
        EntityManagerInterface $entityManager,
    ): Response {
        $query = $request->request->getString('query');
        $clientKey = $this->buildClientKey($request);

        if ('' === $query) {
            return new JsonResponse(['error' => 'Missing or empty query'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $throttle->consumeOnSubmit($request);
        } catch (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
            $run = new ResearchRun();
            $run->setQuery($query);
            $run->setQueryHash(hash('sha256', $query));
            $run->setClientKey($clientKey);
            $run->setStatus(ResearchRunStatus::THROTTLED);
            $run->setFailureReason('Rate limited - retry tomorrow!');
            $entityManager->persist($run);
            $entityManager->flush();

            return new JsonResponse([
                'status' => ResearchRunStatus::THROTTLED->value,
                'runId' => $run->getRunUuid(),
                'retryAfter' => 86400,
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => '86400',
            ]);
        }

        $run = new ResearchRun();
        $run->setQuery($query);
        $run->setQueryHash(hash('sha256', $query));
        $run->setClientKey($clientKey);

        $entityManager->persist($run);
        $entityManager->flush();

        $runId = $run->getRunUuid();
        $run->setMercureTopic($topicFactory->forRun($runId));
        $entityManager->flush();

        $bus->dispatch(new OrchestratorTick($runId));

        return new JsonResponse([
            'status' => 'accepted',
            'runId' => $runId,
            'mercureTopic' => $run->getMercureTopic(),
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/research/runs/{id}/stop', name: 'app_research_stop', methods: ['POST'])]
    public function stop(
        string $id,
        Request $request,
        ResearchRunRepository $runRepository,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
    ): Response {
        $clientKey = $this->buildClientKey($request);
        $run = $runRepository->findOneBy(['runUuid' => $id, 'clientKey' => $clientKey]);

        if (!$run instanceof ResearchRun) {
            throw $this->createNotFoundException('Research run not found.');
        }

        if ($run->getStatus()->isTerminal()) {
            return new JsonResponse([
                'status' => $run->getStatusValue(),
                'runId' => $run->getRunUuid(),
            ]);
        }

        if (null === $run->getCancelRequestedAt()) {
            $run->setCancelRequestedAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        $bus->dispatch(new OrchestratorTick($run->getRunUuid()));

        return new JsonResponse([
            'status' => 'stopping',
            'runId' => $run->getRunUuid(),
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/research/runs/{id}/renew', name: 'app_research_renew', methods: ['POST'])]
    public function renew(
        string $id,
        Request $request,
        ResearchRunRepository $runRepository,
        RunRenewalService $renewalService,
    ): Response {
        $clientKey = $this->buildClientKey($request);
        $run = $runRepository->findOneBy(['runUuid' => $id, 'clientKey' => $clientKey]);

        if (!$run instanceof ResearchRun) {
            throw $this->createNotFoundException('Research run not found.');
        }

        try {
            $strategy = $renewalService->renew($run);
        } catch (RunRenewalException $exception) {
            return new JsonResponse([
                'status' => $run->getStatusValue(),
                'runId' => $run->getRunUuid(),
                'reason' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'status' => 'renewing',
            'runId' => $run->getRunUuid(),
            'strategy' => $strategy,
        ], Response::HTTP_ACCEPTED);
    }

    private function buildClientKey(Request $request): string
    {
        $user = $this->getUser();
        if ($user instanceof UserInterface) {
            return 'user:'.hash('sha256', $user->getUserIdentifier());
        }

        $ip = $request->getClientIp() ?? 'unknown';
        $userAgent = $request->headers->get('User-Agent', 'unknown');
        $anonymousFingerprint = hash('sha256', $ip.'|'.$userAgent);

        return 'anon:'.$anonymousFingerprint;
    }

    private function hasValidDeleteItemToken(Request $request, string $clientKey, string $runId): bool
    {
        return $this->isSubmittedTokenValid(
            $request,
            $this->buildDeleteItemToken($clientKey, $runId)
        );
    }

    private function hasValidDeleteAllToken(Request $request, string $clientKey): bool
    {
        return $this->isSubmittedTokenValid(
            $request,
            $this->buildDeleteAllToken($clientKey)
        );
    }

    private function isSubmittedTokenValid(Request $request, string $expectedToken): bool
    {
        $submittedToken = $request->request->getString('_token');
        if ('' === $submittedToken) {
            return false;
        }

        return hash_equals($expectedToken, $submittedToken);
    }

    private function buildDeleteItemToken(string $clientKey, string $runId): string
    {
        return hash_hmac('sha256', self::CSRF_DELETE_HISTORY_ITEM.'|'.$clientKey.'|'.$runId, $this->kernelSecret);
    }

    private function buildDeleteAllToken(string $clientKey): string
    {
        return hash_hmac('sha256', self::CSRF_DELETE_HISTORY_ALL.'|'.$clientKey, $this->kernelSecret);
    }
}
