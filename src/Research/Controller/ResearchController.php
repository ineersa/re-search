<?php

declare(strict_types=1);

namespace App\Research\Controller;

use App\Research\Throttle\ResearchThrottle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ResearchController extends AbstractController
{
    #[Route('/research/runs', name: 'app_research_submit', methods: ['POST'])]
    public function submit(Request $request, ResearchThrottle $throttle): Response
    {
        $throttle->consume($request);

        // Stub: full orchestration will be wired in task 5
        return new JsonResponse([
            'status' => 'accepted',
            'message' => 'Research run queued (stub)',
        ], Response::HTTP_ACCEPTED);
    }
}
