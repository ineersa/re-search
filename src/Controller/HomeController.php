<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(#[Autowire('%kernel.environment%')] string $kernelEnvironment): Response
    {
        return $this->render('home/index.html.twig', [
            'inspectUrl' => 'dev' === $kernelEnvironment ? $this->generateUrl('app_research_inspect', ['id' => '__ID__']) : null,
        ]);
    }
}
