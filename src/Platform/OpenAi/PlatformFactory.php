<?php

declare(strict_types=1);

namespace App\Platform\OpenAi;

use App\Platform\OpenAi\Gpt\ResultConverter as OpenAiGptResultConverter;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\AI\Platform\Bridge\OpenAi\Contract\OpenAiContract;
use Symfony\AI\Platform\Bridge\OpenAi\DallE;
use Symfony\AI\Platform\Bridge\OpenAi\Embeddings;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\TextToSpeech;
use Symfony\AI\Platform\Bridge\OpenAi\Whisper;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlatformFactory
{
    public static function create(
        #[\SensitiveParameter] string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?string $region = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): Platform {
        $httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);

        return new Platform(
            [
                new Gpt\ModelClient($httpClient, $apiKey, $region),
                new Embeddings\ModelClient($httpClient, $apiKey, $region),
                new DallE\ModelClient($httpClient, $apiKey, $region),
                new TextToSpeech\ModelClient($httpClient, $apiKey, $region),
                new Whisper\ModelClient($httpClient, $apiKey, $region),
            ],
            [
                new OpenAiGptResultConverter(),
                new Embeddings\ResultConverter(),
                new DallE\ResultConverter(),
                new TextToSpeech\ResultConverter(),
                new Whisper\ResultConverter(),
            ],
            $modelCatalog,
            $contract ?? OpenAiContract::create(),
            $eventDispatcher,
        );
    }
}
