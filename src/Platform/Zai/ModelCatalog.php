<?php

declare(strict_types=1);

namespace App\Platform\Zai;

use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

final class ModelCatalog extends AbstractModelCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const MODEL_DEFAULT_OPTIONS = [
        'glm-5-turbo' => [
            'thinking' => ['type' => 'enabled', 'clear_thinking' => false],
            'tool_stream' => true,
            'preserve_reasoning_history' => true,
        ],
        'glm-5' => [
            'thinking' => ['type' => 'enabled', 'clear_thinking' => false],
            'tool_stream' => true,
            'preserve_reasoning_history' => true,
        ],
        'glm-4.7' => [
            'thinking' => ['type' => 'enabled', 'clear_thinking' => false],
            'tool_stream' => true,
            'preserve_reasoning_history' => true,
        ],
        'glm-4.7-flash' => [
            'thinking' => ['type' => 'enabled', 'clear_thinking' => false],
            'tool_stream' => true,
            'preserve_reasoning_history' => true,
        ],
        'glm-4.7-flashx' => [
            'thinking' => ['type' => 'enabled', 'clear_thinking' => false],
            'tool_stream' => true,
            'preserve_reasoning_history' => true,
        ],
        'glm-4.6' => [
            'thinking' => ['type' => 'enabled', 'clear_thinking' => false],
            'tool_stream' => true,
        ],
    ];

    public function __construct()
    {
        $this->models = [
            'glm-5-turbo' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-5' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.7' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.7-flash' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.7-flashx' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.6' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.5' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.5-air' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.5-x' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.5-airx' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4.5-flash' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
            'glm-4-32b-0414-128k' => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::INPUT_TEXT,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::OUTPUT_STRUCTURED,
                    Capability::TOOL_CALLING,
                ],
            ],
        ];
    }

    public function getModel(string $modelName): Model
    {
        $model = parent::getModel($modelName);
        $baseModelName = explode('?', $model->getName(), 2)[0];
        $defaults = self::MODEL_DEFAULT_OPTIONS[$baseModelName] ?? [];

        if ([] === $defaults) {
            return $model;
        }

        $mergedOptions = array_replace_recursive($defaults, $model->getOptions());

        return new CompletionsModel($model->getName(), $model->getCapabilities(), $mergedOptions);
    }
}
