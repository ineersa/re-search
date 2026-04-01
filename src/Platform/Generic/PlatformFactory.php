<?php

declare(strict_types=1);

namespace App\Platform\Generic;

use App\Platform\Generic\Completions\ModelClient as CustomCompletionsModelClient;
use App\Platform\Generic\Completions\ResultConverter as CustomCompletionsResultConverter;
use Symfony\AI\Platform\Bridge\Generic\Embeddings;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlatformFactory
{
    public static function create(
        string $baseUrl,
        ?string $apiKey = null,
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new FallbackModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        bool $supportsCompletions = true,
        bool $supportsEmbeddings = true,
        string $completionsPath = '/v1/chat/completions',
        string $embeddingsPath = '/v1/embeddings',
        bool $enableStreamUsageOption = false,
    ): Platform {
        $httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);

        $modelClients = [];
        $resultConverters = [];
        if ($supportsCompletions) {
            $modelClients[] = new CustomCompletionsModelClient(
                $httpClient,
                $baseUrl,
                $apiKey,
                $completionsPath,
                includeUsageOnStream: $enableStreamUsageOption,
            );
            $resultConverters[] = new CustomCompletionsResultConverter();
        }
        if ($supportsEmbeddings) {
            $modelClients[] = new Embeddings\ModelClient($httpClient, $baseUrl, $apiKey, $embeddingsPath);
            $resultConverters[] = new Embeddings\ResultConverter();
        }

        return new Platform($modelClients, $resultConverters, $modelCatalog, $contract, $eventDispatcher);
    }
}
