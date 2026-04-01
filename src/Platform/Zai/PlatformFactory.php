<?php

declare(strict_types=1);

namespace App\Platform\Zai;

use App\Platform\Generic\PlatformFactory as CustomGenericPlatformFactory;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PlatformFactory
{
    /** @phpstan-ignore shipmonk.deadMethod */
    public static function create(
        string $baseUrl = 'https://api.z.ai/api/paas/v4/',
        #[\SensitiveParameter] ?string $apiKey = null,
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): Platform {
        $httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);

        return CustomGenericPlatformFactory::create(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            modelCatalog: $modelCatalog,
            contract: $contract,
            eventDispatcher: $eventDispatcher,
            completionsPath: '/chat/completions',
            enableStreamUsageOption: true,
        );
    }
}
