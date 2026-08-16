<?php

declare(strict_types=1);

use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\Storage\FileStorage;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
    fwrite(STDERR, "Worker arguments are unavailable.\n");

    exit(1);
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
if (count($arguments) !== 5) {
    fwrite(STDERR, "Worker received invalid arguments.\n");

    exit(1);
}

[, $directory, $barrierDirectory, $worker, $key] = $arguments;

try {
    $readyFile = $barrierDirectory . '/ready-' . $worker;
    if (file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal readiness.');
    }

    $deadline = microtime(true) + 10;
    $releaseFile = $barrierDirectory . '/release';
    while (!is_file($releaseFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the release barrier.');
        }

        usleep(10_000);
    }

    $allowed = (new RateLimiter(new FileStorage($directory)))->allow($key, 5, 300);
    echo json_encode(['allowed' => $allowed], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);

    exit(1);
}
