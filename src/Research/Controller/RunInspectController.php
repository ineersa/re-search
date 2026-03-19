<?php

declare(strict_types=1);

namespace App\Research\Controller;

use App\Research\Persistence\ResearchRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only run inspection UI. Returns 404 when APP_ENV !== dev.
 */
final class RunInspectController extends AbstractController
{
    public function __construct(
        private readonly ResearchRunRepository $runRepository,
        private readonly string $kernelEnvironment,
    ) {
    }

    #[Route('/research/runs/{id}/inspect', name: 'app_research_inspect', methods: ['GET'])]
    public function inspect(string $id): Response
    {
        if ('dev' !== $this->kernelEnvironment) {
            throw $this->createNotFoundException('Run inspection is only available in dev environment.');
        }

        $run = $this->runRepository->findEntity($id);
        if (null === $run) {
            throw $this->createNotFoundException('Research run not found.');
        }

        $stepsForView = [];
        foreach ($run->getSteps() as $step) {
            $payloadJson = $step->getPayloadJson();
            $payload = null !== $payloadJson ? json_decode($payloadJson, true) : null;
            $prettyMessages = '';
            $prettyTools = '';
            $prettyRawMetadata = '';
            if ('llm_invocation' === $step->getType() && \is_array($payload)) {
                if (isset($payload['request']['messages'])) {
                    $messagesRaw = $payload['request']['messages'];
                    $prettyMessages = \is_string($messagesRaw) ? $this->prettyJson($messagesRaw) : $this->prettyJson(json_encode($messagesRaw));
                }
                if (isset($payload['request']['tools']) && [] !== $payload['request']['tools']) {
                    $prettyTools = $this->prettyJson(json_encode($payload['request']['tools']));
                }
                if (isset($payload['response']['rawMetadata'])) {
                    $prettyRawMetadata = $this->prettyJson(json_encode($payload['response']['rawMetadata']));
                }
            }
            if ('assistant_empty' === $step->getType() && \is_array($payload) && isset($payload['rawMetadata'])) {
                $prettyRawMetadata = $this->prettyJson(json_encode($payload['rawMetadata']));
            }
            $stepsForView[] = [
                'step' => $step,
                'prettyPayload' => $this->prettyJson($payloadJson),
                'prettyToolArgs' => $this->prettyJson($step->getToolArgumentsJson()),
                'prettyMessages' => $prettyMessages,
                'prettyTools' => $prettyTools,
                'prettyRawMetadata' => $prettyRawMetadata,
            ];
        }

        return $this->render('research/run_inspect.html.twig', [
            'run' => $run,
            'stepsForView' => $stepsForView,
        ]);
    }

    private function prettyJson(?string $json): string
    {
        if (null === $json || '' === $json) {
            return '';
        }

        $decoded = json_decode($json);
        if (null === $decoded && \JSON_ERROR_NONE !== json_last_error()) {
            return $json;
        }

        $encoded = json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false !== $encoded ? $encoded : $json;
    }
}
