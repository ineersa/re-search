<?php

declare(strict_types=1);

namespace App\Command;

use App\Research\Maintenance\ResearchTracePruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:research:prune-traces',
    description: 'Compacts research traces and keeps only the most recent runs per client fully detailed.',
)]
final class ResearchPruneTracesCommand extends Command
{
    public function __construct(
        private readonly ResearchTracePruner $pruner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Number of most recent runs to keep with full trace per client.', 10)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be pruned without writing changes.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keep = (int) $input->getOption('keep');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($keep < 1) {
            $io->error('--keep must be greater than 0.');

            return Command::INVALID;
        }

        $result = $this->pruner->prune($keep, $dryRun);

        $io->definitionList(
            ['Mode' => $result->dryRun ? 'dry-run' : 'apply'],
            ['Scanned runs' => (string) $result->scannedRuns],
            ['Eligible runs' => (string) $result->eligibleRuns],
            ['Pruned runs' => (string) $result->prunedRuns],
            ['Already pruned' => (string) $result->alreadyPrunedRuns],
            ['Deleted steps' => (string) $result->stepsDeleted],
        );

        $io->success($result->dryRun ? 'Dry-run completed.' : 'Trace pruning completed.');

        return Command::SUCCESS;
    }
}
