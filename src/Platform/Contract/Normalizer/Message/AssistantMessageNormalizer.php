<?php

declare(strict_types=1);

namespace App\Platform\Contract\Normalizer\Message;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class AssistantMessageNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof AssistantMessage;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            AssistantMessage::class => true,
        ];
    }

    /**
     * @param AssistantMessage $data
     *
     * @return array{role: 'assistant', content: string|null, tool_calls?: array<array<string, mixed>>, reasoning_content?: string}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $array = [
            'role' => $data->getRole()->value,
            'content' => $data->getContent(),
        ];

        if ($data->hasToolCalls()) {
            $array['tool_calls'] = $this->normalizer->normalize($data->getToolCalls(), $format, $context);
        }

        if ($data->hasThinkingContent()) {
            $thinkingContent = $data->getThinkingContent();
            if (null !== $thinkingContent && '' !== trim($thinkingContent)) {
                $array['reasoning_content'] = $thinkingContent;
            }
        }

        return $array;
    }
}
