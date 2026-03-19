<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ResearchRun;
use App\Research\ResearchRunService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:research:test',
    description: 'Test the research orchestrator loop with a specific question',
)]
class ResearchTestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResearchRunService $researchRunService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'The question to research OR an existing run ID (UUID)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $inputValue = $input->getArgument('input');

        if (!is_string($inputValue) || trim($inputValue) === '') {
            $io->error('Please provide a valid question or run ID.');
            return Command::FAILURE;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $inputValue)) {
            $runId = $inputValue;
            $io->info(sprintf('Using existing research run with ID: %s', $runId));

            $run = $this->entityManager->getRepository(ResearchRun::class)->findOneBy(['runUuid' => $runId]);
            if (null === $run) {
                $io->error(sprintf('Run with ID %s not found.', $runId));
                return Command::FAILURE;
            }
        } else {
            $io->info(sprintf('Creating research run for question: "%s"', $inputValue));

            $run = new ResearchRun();
            $run->setQuery($inputValue);
            $run->setQueryHash(hash('sha256', $inputValue));
            $run->setClientKey('cli_test_user');

            $this->entityManager->persist($run);
            $this->entityManager->flush();

            $runId = $run->getRunUuid();
            $io->success(sprintf('Research run created with ID: %s', $runId));
        }

        $io->info('Starting orchestrator loop... Check logs for info output.');

        try {
            $this->researchRunService->execute($runId);

            $this->entityManager->refresh($run);
            $io->success(sprintf('Run completed with status: %s', $run->getStatus()));
            $finalAnswer = $run->getFinalAnswerMarkdown();
            if (null !== $finalAnswer && '' !== $finalAnswer) {
                $io->section('Final Answer');
                $io->writeln($finalAnswer);
            }
        } catch (\Throwable $e) {
            $io->error(sprintf('Error during research run: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
