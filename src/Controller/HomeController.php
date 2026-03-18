<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly string $kernelEnvironment,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig', [
            'inspectUrl' => 'dev' === $this->kernelEnvironment ? $this->generateUrl('app_research_inspect', ['id' => '__ID__']) : null,
        ]);
    }
}
