<?php

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Platform\LlamaCpp;

use App\Platform\Generic\PlatformFactory as CustomGenericPlatformFactory;
use App\Platform\LlamaCpp\Contract\ToolCallNormalizer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PlatformFactory
{
    /** @phpstan-ignore shipmonk.deadMethod */
    public static function create(
        string $baseUrl = 'http://localhost:8052',
        #[\SensitiveParameter] ?string $apiKey = null,
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): Platform {
        $httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);

        // Use a custom Contract that serializes tool call arguments as a plain
        // object instead of a JSON-encoded string, as required by llama.cpp's
        // Jinja2 chat templates (which use the |items filter on arguments).
        $contract ??= Contract::create(new ToolCallNormalizer());

        return CustomGenericPlatformFactory::create(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            modelCatalog: $modelCatalog,
            contract: $contract,
            eventDispatcher: $eventDispatcher,
            enableStreamUsageOption: true,
        );
    }
}
