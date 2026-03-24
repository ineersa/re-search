<?php

declare(strict_types=1);

namespace App\Research\Orchestration;

use App\Entity\ResearchRun;
use App\Research\Message\Llm\Dto\LlmOperationRequest;
use App\Research\Orchestration\Dto\ResearchTurnResult;
use App\Research\Orchestration\Dto\ToolCallDecision;
use App\Research\Serializer\LlmInvocationTraceSerializer;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OrchestratorLlmInvocationRecorder
{
    public function __construct(
        private readonly OrchestratorStepRecorder $stepRecorder,
        private readonly OrchestratorOperationPayloadMapper $payloadMapper,
        private readonly LlmInvocationTraceSerializer $traceSerializer,
        private readonly ToolboxInterface $toolbox,
        #[Autowire('%research.model%')]
        private readonly string $defaultModel,
    ) {
    }

    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $toolCalls
     * @param array<string, mixed> $rawMetadata
     */
    public function record(
        ResearchRun $run,
        int &$sequence,
        int $turnNumber,
        LlmOperationRequest $request,
        string $assistantText,
        array $toolCalls,
        bool $isFinal,
        ?int $promptTokens,
        ?int $completionTokens,
        ?int $totalTokens,
        array $rawMetadata,
    ): void {
        $payloadJson = null;

        try {
            $model = null !== $request->model && '' !== trim($request->model)
                ? $request->model
                : $this->defaultModel;

            $messages = $this->payloadMapper->toMessageBag($request);
            $options = $request->options;
            if ($request->allowTools) {
                $options['tools'] = $this->toolbox->getTools();
            }

            $turnResult = new ResearchTurnResult(
                assistantText: $assistantText,
                toolCalls: array_map(
                    fn (array $toolCall): ToolCallDecision => new ToolCallDecision(
                        name: $toolCall['name'],
                        arguments: $toolCall['arguments'],
                    ),
                    $toolCalls
                ),
                isFinal: $isFinal,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                totalTokens: $totalTokens,
                rawMetadata: $rawMetadata,
            );

            $payload = $this->traceSerializer->buildPayload($model, $messages, $options, $turnResult);
            $payloadJson = $this->payloadMapper->encodeJson($payload);
        } catch (\Throwable) {
            $payloadJson = $this->payloadMapper->encodeJson([
                'request' => [
                    'model' => $request->model,
                    'messages' => $request->messages,
                    'allowTools' => $request->allowTools,
                    'options' => $request->options,
                ],
                'response' => [
                    'assistantText' => $assistantText,
                    'toolCalls' => $toolCalls,
                    'isFinal' => $isFinal,
                    'promptTokens' => $promptTokens,
                    'completionTokens' => $completionTokens,
                    'totalTokens' => $totalTokens,
                    'rawMetadata' => $rawMetadata,
                ],
            ]);
        }

        $this->stepRecorder->persistStep(
            $run,
            $sequence,
            'llm_invocation',
            $turnNumber,
            \sprintf('LLM invocation turn %d', $turnNumber),
            $payloadJson
        );
    }
}
