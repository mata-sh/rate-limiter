<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Storage;

use DirectoryIterator;
use InvalidArgumentException;
use JsonException;

final class FileStorage implements StorageInterface
{
    private const CLEANUP_LOCK_FILE = '.cleanup.lock';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?: sys_get_temp_dir() . '/cache/rate_limit';
        $this->createSecureDirectory();
    }

    public function consume(string $key, int $maxRequests, int $windowSeconds): ConsumeResult
    {
        return $this->withCleanupReadLock(function () use ($key, $maxRequests, $windowSeconds): ConsumeResult {
            $handle = $this->openAndLock($key);

            try {
                $now = time();
                $timestamps = $this->readTimestamps($handle, $now, $windowSeconds);
                $current = count($timestamps);

                if ($current >= $maxRequests) {
                    return new ConsumeResult(false, $current);
                }

                $timestamps[] = $now;
                $this->writeState($handle, $timestamps, $now + $windowSeconds);

                return new ConsumeResult(true, $current + 1);
            } finally {
                $this->unlockAndClose($handle);
            }
        });
    }

    public function count(string $key, int $windowSeconds): int
    {
        return $this->withCleanupReadLock(function () use ($key, $windowSeconds): int {
            $handle = $this->openAndLock($key);

            try {
                return count($this->readTimestamps($handle, time(), $windowSeconds));
            } finally {
                $this->unlockAndClose($handle);
            }
        });
    }

    /**
     * Delete a key's state while excluding consumers and cleanup.
     */
    public function delete(string $key): void
    {
        $lock = $this->openCleanupLock(LOCK_EX);
        $path = $this->directory . '/' . hash('sha256', $key) . '.json';

        try {
            if (file_exists($path) && !unlink($path)) {
                throw new StorageException('Unable to remove rate-limit state file.');
            }
        } finally {
            $this->unlockAndClose($lock);
        }
    }

    /**
     * Remove at most $limit expired state files. The cleanup lock excludes consumers.
     */
    public function cleanup(int $limit = 100): int
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Cleanup limit must be greater than zero.');
        }

        $lock = $this->openCleanupLock(LOCK_EX);
        $removed = 0;
        $scanned = 0;
        $scanLimit = $limit * 10;

        try {
            $iterator = new DirectoryIterator($this->directory);
            foreach ($iterator as $file) {
                if ($removed >= $limit || $scanned >= $scanLimit) {
                    break;
                }

                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
                    continue;
                }

                ++$scanned;
                $path = $file->getPathname();
                $handle = $this->openSecureFile($path, 'rate-limit state file for cleanup');

                try {
                    if (!flock($handle, LOCK_EX)) {
                        throw new StorageException('Unable to lock rate-limit state file for cleanup.');
                    }

                    $state = $this->readState($handle);
                    if ($state !== null && isset($state['expires_at']) && is_int($state['expires_at']) && $state['expires_at'] <= time()) {
                        if (!unlink($path)) {
                            throw new StorageException('Unable to remove expired rate-limit state file.');
                        }
                        ++$removed;
                    }
                } finally {
                    $this->unlockAndClose($handle);
                }
            }
        } finally {
            $this->unlockAndClose($lock);
        }

        return $removed;
    }

    public function getName(): string
    {
        return 'file';
    }

    private function createSecureDirectory(): void
    {
        $created = false;
        if (!is_dir($this->directory)) {
            $created = mkdir($this->directory, 0700, true);
            if (!$created && !is_dir($this->directory)) {
                throw new StorageException('Unable to create file storage directory: ' . $this->directory);
            }
        }

        if ($created && !chmod($this->directory, 0700)) {
            throw new StorageException('Unable to secure file storage directory: ' . $this->directory);
        }

        if (!is_dir($this->directory)) {
            throw new StorageException('File storage path is not a directory: ' . $this->directory);
        }

        if (!is_writable($this->directory) || !is_executable($this->directory)) {
            throw new StorageException('File storage directory is not writable and traversable: ' . $this->directory);
        }
    }

    /**
     * @return resource
     */
    private function openAndLock(string $key)
    {
        $path = $this->directory . '/' . hash('sha256', $key) . '.json';
        $handle = $this->openSecureFile($path, 'rate-limit state');

        if (!chmod($path, 0600)) {
            $this->unlockAndClose($handle);

            throw new StorageException('Unable to secure rate-limit state file.');
        }

        if (!flock($handle, LOCK_EX)) {
            $this->unlockAndClose($handle);

            throw new StorageException('Unable to lock rate-limit state file.');
        }

        return $handle;
    }

    /**
     * @return resource
     */
    private function openCleanupLock(int $mode)
    {
        $handle = $this->openSecureFile($this->directory . '/' . self::CLEANUP_LOCK_FILE, 'rate-limit cleanup lock');

        if (!chmod($this->directory . '/' . self::CLEANUP_LOCK_FILE, 0600)) {
            $this->unlockAndClose($handle);

            throw new StorageException('Unable to secure rate-limit cleanup lock.');
        }

        if (!flock($handle, $mode)) {
            $this->unlockAndClose($handle);

            throw new StorageException('Unable to lock rate-limit cleanup lock.');
        }

        return $handle;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withCleanupReadLock(callable $operation)
    {
        $lock = $this->openCleanupLock(LOCK_SH);

        try {
            return $operation();
        } finally {
            $this->unlockAndClose($lock);
        }
    }

    /**
     * @return resource
     */
    private function openSecureFile(string $path, string $name)
    {
        $previousUmask = umask(0077);

        try {
            $handle = fopen($path, 'c+');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw new StorageException('Unable to open ' . $name . '.');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     *
     * @return list<int>
     */
    private function readTimestamps($handle, int $now, int $windowSeconds): array
    {
        $state = $this->readState($handle);
        if ($state === null) {
            return [];
        }

        if (!isset($state['expires_at']) || !is_int($state['expires_at'])) {
            throw new StorageException('Rate-limit state has an invalid expiration.');
        }

        if ($state['expires_at'] <= $now) {
            return [];
        }

        if (!isset($state['timestamps']) || !is_array($state['timestamps'])) {
            throw new StorageException('Rate-limit state is invalid.');
        }

        $windowStart = $now - $windowSeconds;
        $timestamps = [];
        foreach ($state['timestamps'] as $timestamp) {
            if (!is_int($timestamp)) {
                throw new StorageException('Rate-limit state contains an invalid timestamp.');
            }

            if ($timestamp > $windowStart) {
                $timestamps[] = $timestamp;
            }
        }

        return $timestamps;
    }

    /**
     * @param resource $handle
     *
     * @return null|array<string, mixed>
     */
    private function readState($handle): ?array
    {
        if (!rewind($handle)) {
            throw new StorageException('Unable to read rate-limit state file.');
        }

        $contents = stream_get_contents($handle);
        if ($contents === false) {
            throw new StorageException('Unable to read rate-limit state file.');
        }

        if ($contents === '') {
            return null;
        }

        try {
            $state = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StorageException('Unable to decode rate-limit state file.', 0, $exception);
        }

        if (!is_array($state)) {
            throw new StorageException('Rate-limit state is invalid.');
        }

        return $state;
    }

    /**
     * @param resource  $handle
     * @param list<int> $timestamps
     */
    private function writeState($handle, array $timestamps, int $expiresAt): void
    {
        try {
            $contents = json_encode([
                'timestamps' => $timestamps,
                'expires_at' => $expiresAt,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StorageException('Unable to encode rate-limit state.', 0, $exception);
        }

        if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new StorageException('Unable to write rate-limit state file.');
        }
    }

    /**
     * @param resource $handle
     */
    private function unlockAndClose($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
