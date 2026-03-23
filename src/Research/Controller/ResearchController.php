<?php

declare(strict_types=1);

namespace App\Research\Controller;

use App\Entity\Enum\ResearchRunStatus;
use App\Entity\ResearchRun;
use App\Repository\ResearchRunRepository;
use App\Research\Mercure\ResearchTopicFactory;
use App\Research\Message\Orchestrator\OrchestratorTick;
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

final class ResearchController extends AbstractController
{
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
            static fn (array $run): array => ResearchHistoryFormatter::formatRow($run),
            $slice
        );

        return $this->render('research/history_frame.html.twig', [
            'rows' => $rows,
            'page' => $page,
            'total_pages' => $totalPages,
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

        try {
            $throttle->peek($request);
        } catch (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
            $run = new ResearchRun();
            $run->setQuery('' !== $query ? $query : '(throttled)');
            $run->setQueryHash('' !== $query ? hash('sha256', $query) : '');
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

        if ('' === $query) {
            return new JsonResponse(['error' => 'Missing or empty query'], Response::HTTP_BAD_REQUEST);
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

    private function buildClientKey(Request $request): string
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $sessionId = $request->hasSession() ? $request->getSession()->getId() : 'no-session';

        return $ip.'|'.$sessionId;
    }
}
