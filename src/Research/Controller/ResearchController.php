<?php

declare(strict_types=1);

namespace App\Research\Controller;

use App\Entity\ResearchRun;
use App\Research\Mercure\ResearchTopicFactory;
use App\Research\Message\ExecuteResearchRun;
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
    #[Route('/research/runs', name: 'app_research_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        ResearchThrottle $throttle,
        ResearchTopicFactory $topicFactory,
        MessageBusInterface $bus,
        EntityManagerInterface $entityManager,
    ): Response {
        $throttle->consume($request);

        $query = $request->request->getString('query');
        if ('' === $query) {
            return new JsonResponse(['error' => 'Missing or empty query'], Response::HTTP_BAD_REQUEST);
        }

        $clientKey = $this->buildClientKey($request);

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
