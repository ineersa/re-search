<?php

declare(strict_types=1);

namespace App\Research\Platform;

use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Resolves the platform from the container based on AI_PLATFORM env.
 *
 * Platforms are configured in ai.yaml; this factory selects the active one.
 */
final class ResearchPlatformFactory implements PlatformInterface
{
    private ?PlatformInterface $platform = null;

    public function __construct(
        private readonly ServiceLocator $platforms,
        private readonly string $platformName,
    ) {
    }

    public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
    {
        return $this->getPlatform()->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->getPlatform()->getModelCatalog();
    }

    private function getPlatform(): PlatformInterface
    {
        if (null === $this->platform) {
            $name = $this->platforms->has($this->platformName)
                ? $this->platformName
                : 'llama';
            $this->platform = $this->platforms->get($name);
        }

        return $this->platform;
    }
}
