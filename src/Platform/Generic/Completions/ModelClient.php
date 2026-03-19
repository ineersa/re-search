<?php

declare(strict_types=1);

namespace App\Platform\Generic\Completions;

use Symfony\AI\Platform\Bridge\Generic\Completions\ModelClient as BaseModelClient;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawHttpResult;

final class ModelClient extends BaseModelClient
{
    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        $stream = $options['stream'] ?? false;
        if (\is_bool($stream) && $stream && !isset($options['stream_options'])) {
            $options['stream_options'] = ['include_usage' => true];
        }

        return parent::request($model, $payload, $options);
    }
}
