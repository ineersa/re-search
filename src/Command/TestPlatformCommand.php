<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'test:platform',
    description: 'Test AI platform configuration and connectivity',
)]
final class TestPlatformCommand extends Command
{
    public function __construct(
        private readonly PlatformInterface $platform,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('AI Platform Test');

        try {
            $catalog = $this->platform->getModelCatalog();

            $modelIds = array_keys($catalog->getModels());

            $io->success(sprintf('Platform successfully loaded. Found %d models:', \count($modelIds)));

            foreach ($modelIds as $modelId) {
                $io->writeln(sprintf('  - %s', $modelId));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to load platform: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
