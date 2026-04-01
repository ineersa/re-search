<?php

declare(strict_types=1);

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}

// Test DATABASE_URL (see .env.test): run migrations once per test process
if (($_SERVER['APP_ENV'] ?? '') === 'test') {
    $testDatabasePath = dirname(__DIR__).'/var/test.db';
    foreach ([$testDatabasePath, $testDatabasePath.'-journal', $testDatabasePath.'-wal', $testDatabasePath.'-shm'] as $artifactPath) {
        if (!is_file($artifactPath)) {
            continue;
        }

        if (!@unlink($artifactPath)) {
            throw new \RuntimeException(sprintf('Unable to remove stale test database artifact: %s', $artifactPath));
        }
    }

    $kernel = new \App\Kernel('test', true);
    $kernel->boot();
    $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
    $exitCode = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:migrate',
        '--no-interaction' => true,
    ]));

    if (0 !== $exitCode) {
        throw new \RuntimeException(sprintf('Failed to migrate test database (exit code %d).', $exitCode));
    }
}
