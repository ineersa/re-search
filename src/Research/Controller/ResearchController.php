<?php

declare(strict_types=1);

namespace App\Research\Controller;

use App\Entity\ResearchRun;
use App\Research\Mercure\ResearchTopicFactory;
use App\Research\Message\ExecuteResearchRun;
use App\Research\Persistence\ResearchRunRepositoryInterface;
use App\Research\Throttle\ResearchThrottle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        ResearchRunRepositoryInterface $runRepository,
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

    #[Route('/research/runs/{id}', name: 'app_research_show', methods: ['GET'])]
    public function show(
        string $id,
        Request $request,
        ResearchRunRepositoryInterface $runRepository,
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
            $throttle->consume($request);
        } catch (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e) {
            $run = new ResearchRun();
            $run->setQuery($query ?: '(throttled)');
            $run->setQueryHash($query ? hash('sha256', $query) : '');
            $run->setClientKey($clientKey);
            $run->setStatus('throttled');
            $run->setFailureReason('Rate limit exceeded. Please try again later.');
            $entityManager->persist($run);
            $entityManager->flush();

            $retryAfter = $e->getHeaders()['Retry-After'] ?? 600;

            return new JsonResponse([
                'status' => 'throttled',
                'runId' => $run->getId()->toRfc4122(),
                'retryAfter' => (int) $retryAfter,
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) $retryAfter,
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

        $runId = $run->getId()->toRfc4122();
        $run->setMercureTopic($topicFactory->forRun($runId));
        $entityManager->flush();

        $bus->dispatch(new ExecuteResearchRun($runId));

        return new JsonResponse([
            'status' => 'accepted',
            'runId' => $runId,
            'mercureTopic' => $run->getMercureTopic(),
        ], Response::HTTP_ACCEPTED);
    }

    private function buildClientKey(Request $request): string
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $sessionId = $request->hasSession() ? $request->getSession()->getId() : 'no-session';

        return $ip.'|'.$sessionId;
    }
}
