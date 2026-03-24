<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a local auth user.',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain text password')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Assign ROLE_ADMIN')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $emailValue = $input->getArgument('email');
        $passwordValue = $input->getArgument('password');

        if (!is_string($emailValue) || '' === trim($emailValue)) {
            $io->error('Email is required.');

            return Command::INVALID;
        }

        if (!is_string($passwordValue) || '' === trim($passwordValue)) {
            $io->error('Password is required.');

            return Command::INVALID;
        }

        $email = mb_strtolower(trim($emailValue));
        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            $io->error(sprintf('User with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles((bool) $input->getOption('admin') ? ['ROLE_ADMIN'] : ['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $passwordValue));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('User "%s" created.', $email));

        return Command::SUCCESS;
    }
}
