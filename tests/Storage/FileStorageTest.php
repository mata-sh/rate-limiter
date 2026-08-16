<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Tests\Storage;

use ErrorException;
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\Storage\FileStorage;
use MataSh\RateLimiter\Storage\StorageException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class FileStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mata-rate-limiter-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (scandir($this->directory) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->directory . '/' . $file);
            }
        }
        rmdir($this->directory);
    }

    public function testConfiguredLimitAboveOneHundredRetainsAllRequests(): void
    {
        $limiter = new RateLimiter(new FileStorage($this->directory));

        for ($request = 0; $request < 150; ++$request) {
            self::assertTrue($limiter->allow('user', 150, 60));
        }

        self::assertFalse($limiter->allow('user', 150, 60));
        self::assertSame(150, $limiter->check('user', 150, 60)['current']);
    }

    public function testInstancesShareState(): void
    {
        $first = new RateLimiter(new FileStorage($this->directory));
        $second = new RateLimiter(new FileStorage($this->directory));

        self::assertTrue($first->allow('user', 1, 60));
        self::assertFalse($second->allow('user', 1, 60));
    }

    public function testConsumeReturnsRetryAtForDeniedRequests(): void
    {
        $limiter = new RateLimiter(new FileStorage($this->directory));
        $windowSeconds = 60;
        $allowed = $limiter->consume('allowed', 1, $windowSeconds);
        $oldest = time() - 30;
        self::assertIsInt(file_put_contents(
            $this->directory . '/' . hash('sha256', 'rate_limit_denied') . '.json',
            json_encode(['timestamps' => [$oldest], 'expires_at' => $oldest + $windowSeconds], JSON_THROW_ON_ERROR)
        ));

        $denied = $limiter->consume('denied', 1, $windowSeconds);

        self::assertTrue($allowed->isAllowed());
        self::assertSame(1, $allowed->getCurrent());
        self::assertNull($allowed->getRetryAt());
        self::assertFalse($denied->isAllowed());
        self::assertSame(1, $denied->getCurrent());
        self::assertSame($oldest + $windowSeconds, $denied->getRetryAt());
    }

    /**
     * @group integration
     * @group concurrency
     */
    public function testConsumeIsAtomicAcrossProcesses(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable or disabled.');
        }

        $workerCount = 20;
        $barrierDirectory = sys_get_temp_dir() . '/mata-rate-limiter-barrier-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($barrierDirectory, 0700));

        /**
         * @var list<array{process: resource, stdout: resource, stderr: resource, worker: int, exitCode: null|int}> $workers
         */
        $workers = [];
        $released = false;

        try {
            new FileStorage($this->directory);

            for ($worker = 0; $worker < $workerCount; ++$worker) {
                $process = $this->startWorker($barrierDirectory, $worker);
                if ($process === null) {
                    break;
                }

                $workers[] = $process;
            }

            $startedWorkers = count($workers);
            $readyWorkers = $this->waitForReadyWorkers($barrierDirectory, $workerCount, 10.0);
            $allWorkersExited = false;
            if ($readyWorkers === $workerCount) {
                file_put_contents($barrierDirectory . '/release', 'release');
                $released = true;
                $allWorkersExited = $this->waitForWorkers($workers, 10.0);
            }
            if (!$allWorkersExited) {
                $this->terminateWorkers($workers);
            }
            $results = $this->collectWorkerResults($workers);

            self::assertSame(
                $workerCount,
                $startedWorkers,
                'Unable to start all worker processes.\n' . $this->formatWorkerResults($results)
            );
            self::assertSame(
                $workerCount,
                $readyWorkers,
                'Not all workers reached the synchronization barrier.\n' . $this->formatWorkerResults($results)
            );
            self::assertTrue(
                $allWorkersExited,
                'Workers did not exit before the timeout.\n' . $this->formatWorkerResults($results)
            );

            $failedWorkers = array_filter(
                $results,
                static fn (array $result): bool => $result['exitCode'] !== 0
            );
            self::assertSame([], $failedWorkers, 'Worker processes failed.\n' . $this->formatWorkerResults($results));

            $allowedWorkers = 0;
            foreach ($results as $result) {
                $output = json_decode($result['stdout'], true);
                self::assertIsArray(
                    $output,
                    sprintf('Worker %d did not produce JSON output.\n%s', $result['worker'], $this->formatWorkerResults($results))
                );
                self::assertArrayHasKey('allowed', $output);
                self::assertIsBool($output['allowed']);

                if ($output['allowed']) {
                    ++$allowedWorkers;
                }
            }

            self::assertSame(5, $allowedWorkers, $this->formatWorkerResults($results));
        } finally {
            if (is_dir($barrierDirectory)) {
                if ($released) {
                    file_put_contents($barrierDirectory . '/release', 'release');
                }
                $this->terminateWorkers($workers);
                $this->collectWorkerResults($workers);
                $this->removeDirectory($barrierDirectory);
            }
        }
    }

    public function testExistingDirectoryPermissionsArePreserved(): void
    {
        self::assertTrue(mkdir($this->directory, 0770, true));
        self::assertTrue(chmod($this->directory, 0770));
        clearstatcache(true, $this->directory);

        $mode = fileperms($this->directory);
        self::assertIsInt($mode);
        self::assertSame(0770, $mode & 0777);

        new FileStorage($this->directory);
        clearstatcache(true, $this->directory);

        $mode = fileperms($this->directory);
        self::assertIsInt($mode);
        self::assertSame(0770, $mode & 0777);
    }

    public function testResetRemovesOneFileStorageIdentifier(): void
    {
        $limiter = new RateLimiter(new FileStorage($this->directory));

        self::assertTrue($limiter->allow('user', 1, 60));
        $limiter->reset('user');
        $limiter->reset('user');

        self::assertSame(0, $limiter->check('user', 1, 60)['current']);
    }

    public function testCleanupRemovesOnlyBoundedExpiredFiles(): void
    {
        $storage = new FileStorage($this->directory);
        $now = time() - 1;

        for ($file = 0; $file < 3; ++$file) {
            file_put_contents(
                $this->directory . '/' . hash('sha256', 'rate_limit_expired-' . $file) . '.json',
                json_encode(['timestamps' => [], 'expires_at' => $now], JSON_THROW_ON_ERROR)
            );
        }

        self::assertSame(2, $storage->cleanup(2));
        self::assertCount(1, glob($this->directory . '/*.json') ?: []);
    }

    public function testCleanupUsesDefaultLimitWhenArgumentIsOmitted(): void
    {
        $storage = new FileStorage($this->directory);
        file_put_contents(
            $this->directory . '/' . hash('sha256', 'rate_limit_expired') . '.json',
            json_encode(['timestamps' => [], 'expires_at' => time() - 1], JSON_THROW_ON_ERROR)
        );

        self::assertSame(1, $storage->cleanup());
    }

    public function testInvalidStoredStateThrowsInsteadOfAllowingTheRequest(): void
    {
        $storage = new FileStorage($this->directory);
        file_put_contents(
            $this->directory . '/' . hash('sha256', 'rate_limit_user') . '.json',
            '{invalid'
        );

        $this->expectException(StorageException::class);
        $storage->consume('rate_limit_user', 1, 60);
    }

    public function testDirectoryCreationWarningIsConvertedWithOperationAndPathContext(): void
    {
        self::assertIsInt(file_put_contents($this->directory, 'not a directory'));
        $path = $this->directory . '/storage';
        $exception = null;

        try {
            new FileStorage($path);
        } catch (StorageException $caught) {
            $exception = $caught;
        } finally {
            self::assertTrue(unlink($this->directory));
        }

        self::assertInstanceOf(StorageException::class, $exception);
        self::assertStringContainsString('create the file storage directory', $exception->getMessage());
        self::assertStringContainsString($path, $exception->getMessage());
        self::assertInstanceOf(ErrorException::class, $exception->getPrevious());
    }

    public function testFileOpenWarningIsConvertedWithOperationAndPathContext(): void
    {
        $storage = new FileStorage($this->directory);
        $path = $this->directory . '/' . hash('sha256', 'user') . '.json';
        self::assertTrue(mkdir($path));
        $exception = null;

        try {
            $storage->consume('user', 1, 60);
        } catch (StorageException $caught) {
            $exception = $caught;
        } finally {
            self::assertTrue(rmdir($path));
        }

        self::assertInstanceOf(StorageException::class, $exception);
        self::assertStringContainsString('open the rate-limit state file', $exception->getMessage());
        self::assertStringContainsString($path, $exception->getMessage());
        self::assertInstanceOf(ErrorException::class, $exception->getPrevious());
    }

    /**
     * @return null|array{process: resource, stdout: resource, stderr: resource, worker: int, exitCode: null|int}
     */
    private function startWorker(string $barrierDirectory, int $worker): ?array
    {
        $command = sprintf(
            '%s %s %s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/Fixtures/file-storage-worker.php'),
            escapeshellarg($this->directory),
            escapeshellarg($barrierDirectory),
            escapeshellarg((string) $worker),
            escapeshellarg('shared-user')
        );
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return null;
        }

        if (!isset($pipes[0], $pipes[1], $pipes[2]) || !is_resource($pipes[0]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);

            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'worker' => $worker,
            'exitCode' => null,
        ];
    }

    private function waitForReadyWorkers(string $barrierDirectory, int $workerCount, float $timeout): int
    {
        $deadline = microtime(true) + $timeout;
        do {
            $readyWorkers = 0;
            for ($worker = 0; $worker < $workerCount; ++$worker) {
                if (is_file($barrierDirectory . '/ready-' . $worker)) {
                    ++$readyWorkers;
                }
            }

            if ($readyWorkers === $workerCount) {
                return $readyWorkers;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return $readyWorkers;
    }

    /**
     * @param list<array{process: resource, stdout: resource, stderr: resource, worker: int, exitCode: null|int}> $workers
     */
    private function waitForWorkers(array &$workers, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            $allExited = true;
            foreach ($workers as &$worker) {
                if ($worker['exitCode'] !== null) {
                    continue;
                }

                $status = proc_get_status($worker['process']);
                if ($status['running']) {
                    $allExited = false;

                    continue;
                }

                $worker['exitCode'] = $status['exitcode'];
            }
            unset($worker);

            if ($allExited) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @param list<array{process: resource, stdout: resource, stderr: resource, worker: int, exitCode: null|int}> $workers
     *
     * @return list<array{worker: int, exitCode: int, stdout: string, stderr: string}>
     */
    private function collectWorkerResults(array &$workers): array
    {
        $results = [];
        foreach ($workers as $worker) {
            $exitCode = $worker['exitCode'];
            $status = proc_get_status($worker['process']);
            if ($exitCode === null && !$status['running']) {
                $exitCode = $status['exitcode'];
            }

            $stdout = stream_get_contents($worker['stdout']);
            $stderr = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            $closedExitCode = proc_close($worker['process']);
            if ($exitCode === null || $exitCode === -1) {
                $exitCode = $closedExitCode;
            }

            $results[] = [
                'worker' => $worker['worker'],
                'exitCode' => $exitCode,
                'stdout' => $stdout === false ? '' : $stdout,
                'stderr' => $stderr === false ? '' : $stderr,
            ];
        }
        $workers = [];

        return $results;
    }

    /**
     * @param list<array{worker: int, exitCode: int, stdout: string, stderr: string}> $results
     */
    private function formatWorkerResults(array $results): string
    {
        $details = [];
        foreach ($results as $result) {
            $details[] = sprintf(
                'worker=%d exit=%d stdout=%s stderr=%s',
                $result['worker'],
                $result['exitCode'],
                json_encode($result['stdout'], JSON_THROW_ON_ERROR),
                json_encode($result['stderr'], JSON_THROW_ON_ERROR)
            );
        }

        return implode(PHP_EOL, $details);
    }

    /**
     * @param list<array{process: resource, stdout: resource, stderr: resource, worker: int, exitCode: null|int}> $workers
     */
    private function terminateWorkers(array &$workers): void
    {
        foreach ($workers as $worker) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
        }

        if (!$this->waitForWorkers($workers, 2.0)) {
            foreach ($workers as $worker) {
                if ($worker['exitCode'] === null) {
                    proc_terminate($worker['process'], 9);
                }
            }
            $this->waitForWorkers($workers, 2.0);
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($directory . '/' . $file);
            }
        }
        rmdir($directory);
    }
}
