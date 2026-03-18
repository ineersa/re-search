<?php

declare(strict_types=1);

namespace App\Research\Controller;

use App\Research\Mercure\ResearchTopicFactory;
use App\Research\Persistence\ResearchRunRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Returns Mercure authorization for a specific run topic.
 * Sets the mercureAuthorization cookie so the client can subscribe to private run updates.
 */
final class ResearchMercureController extends AbstractController
{
    #[Route('/research/runs/{id}/mercure-auth', name: 'app_research_mercure_auth', methods: ['GET'])]
    public function __invoke(
        string $id,
        Request $request,
        ResearchRunRepositoryInterface $runRepository,
        ResearchTopicFactory $topicFactory,
        Authorization $authorization,
    ): Response {
        $run = $runRepository->findEntity($id);
        if (null === $run) {
            throw $this->createNotFoundException('Research run not found.');
        }

        $topic = $topicFactory->forRun($id);
        $authorization->setCookie($request, [$topic], []);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
