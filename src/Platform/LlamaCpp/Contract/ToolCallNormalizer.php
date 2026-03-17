<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Platform\LlamaCpp\Contract;

use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * llama.cpp's Jinja2 chat templates expect tool_call.arguments to be a plain
 * object/dict (so they can iterate with the |items filter), not a JSON-encoded
 * string as the OpenAI spec normally requires.
 *
 * @author ineersa
 */
final class ToolCallNormalizer implements NormalizerInterface
{
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ToolCall;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            ToolCall::class => true,
        ];
    }

    /**
     * @param ToolCall $data
     *
     * @return array{
     *      id: string,
     *      type: 'function',
     *      function: array{
     *          name: string,
     *          arguments: array<string, mixed>
     *      }
     *  }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return [
            'id' => $data->getId(),
            'type' => 'function',
            'function' => [
                'name' => $data->getName(),
                // llama.cpp C++ parser requires arguments as a JSON-encoded string.
                // llama.cpp then parses it back to a dict internally before passing
                // to the Jinja2 template (which uses |items, requiring a mapping).
                // JSON_FORCE_OBJECT ensures empty args become "{}" not "[]",
                // so Jinja2 receives a dict instead of an array.
                'arguments' => json_encode($data->getArguments(), \JSON_FORCE_OBJECT),
            ],
        ];
    }
}
