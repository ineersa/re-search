<?php

declare(strict_types=1);

// User's service configuration file
// This file is loaded into the Symfony DI container

use App\Mate\Command\ToolsCallCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\AI\Mate\Container\MateHelper;
use Symfony\AI\Mate\Command\ToolsCallCommand as VendorToolsCallCommand;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // Override default parameters here
        // ->set('mate.cache_dir', sys_get_temp_dir().'/mate')
        // ->set('mate.env_file', ['.env']) // This will load mate/.env and mate/.env.local
    ;

    MateHelper::disableFeatures($container, [
        // 'symfony/ai-mate' => ['php-version', 'operating-system', 'operating-system-family'],
    ]);

    $container->services()
        // Override the vendor ToolsCallCommand with our custom one
        ->set(VendorToolsCallCommand::class)
            ->class(ToolsCallCommand::class)
            ->autowire()
            ->autoconfigure()
            ->public()
    ;
};
