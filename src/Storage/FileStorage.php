<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Storage;

use DirectoryIterator;
use ErrorException;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class FileStorage implements StorageInterface
{
    private const CLEANUP_LOCK_FILE = '.cleanup.lock';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?: $this->performFilesystemOperation(
            'determine the system temporary directory',
            'system temporary directory',
            static fn (): string => sys_get_temp_dir()
        ) . '/cache/rate_limit';
        $this->createSecureDirectory();
    }

    public function consume(string $key, int $maxRequests, int $windowSeconds): ConsumeResult
    {
        return $this->withCleanupReadLock(function () use ($key, $maxRequests, $windowSeconds): ConsumeResult {
            $path = $this->statePath($key);
            $handle = $this->openAndLock($path);
            $primaryException = null;

            try {
                $now = time();
                $timestamps = $this->readTimestamps($handle, $path, $now, $windowSeconds);
                $current = count($timestamps);

                if ($current >= $maxRequests) {
                    return new ConsumeResult(false, $current);
                }

                $timestamps[] = $now;
                $this->writeState($handle, $path, $timestamps, $now + $windowSeconds);

                return new ConsumeResult(true, $current + 1);
            } catch (Throwable $exception) {
                $primaryException = $exception;

                throw $exception;
            } finally {
                $this->unlockAndClose($handle, $path, $primaryException);
            }
        });
    }

    public function count(string $key, int $windowSeconds): int
    {
        return $this->withCleanupReadLock(function () use ($key, $windowSeconds): int {
            $path = $this->statePath($key);
            $handle = $this->openAndLock($path);
            $primaryException = null;

            try {
                return count($this->readTimestamps($handle, $path, time(), $windowSeconds));
            } catch (Throwable $exception) {
                $primaryException = $exception;

                throw $exception;
            } finally {
                $this->unlockAndClose($handle, $path, $primaryException);
            }
        });
    }

    /**
     * Delete a key's state while excluding consumers and cleanup.
     */
    public function delete(string $key): void
    {
        $lockPath = $this->cleanupLockPath();
        $lock = $this->openCleanupLock(LOCK_EX);
        $path = $this->statePath($key);
        $primaryException = null;

        try {
            $exists = $this->performFilesystemOperation(
                'check whether the rate-limit state file exists',
                $path,
                static fn (): bool => file_exists($path)
            );

            if ($exists) {
                $removed = $this->performFilesystemOperation(
                    'remove the rate-limit state file',
                    $path,
                    static fn (): bool => unlink($path)
                );

                if (!$removed) {
                    throw $this->filesystemFailure('remove the rate-limit state file', $path);
                }
            }
        } catch (Throwable $exception) {
            $primaryException = $exception;

            throw $exception;
        } finally {
            $this->unlockAndClose($lock, $lockPath, $primaryException);
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

        $lockPath = $this->cleanupLockPath();
        $lock = $this->openCleanupLock(LOCK_EX);
        $removed = 0;
        $scanned = 0;
        $scanLimit = $limit * 10;
        $primaryException = null;

        try {
            $iterator = $this->performFilesystemOperation(
                'open the file storage directory for cleanup',
                $this->directory,
                fn (): DirectoryIterator => new DirectoryIterator($this->directory)
            );
            $this->performFilesystemOperation(
                'rewind the file storage directory iterator',
                $this->directory,
                static function () use ($iterator): void {
                    $iterator->rewind();
                }
            );

            while ($this->directoryIteratorIsValid($iterator)) {
                if ($removed >= $limit || $scanned >= $scanLimit) {
                    break;
                }

                if (!$this->directoryIteratorEntryIsFile($iterator)) {
                    $this->advanceDirectoryIterator($iterator);

                    continue;
                }

                $filename = $this->directoryIteratorFilename($iterator);
                if (!str_ends_with($filename, '.json')) {
                    $this->advanceDirectoryIterator($iterator);

                    continue;
                }

                ++$scanned;
                $path = $this->directoryIteratorPathname($iterator);
                $handle = $this->openSecureFile($path, 'open the rate-limit state file for cleanup');
                $fileException = null;

                try {
                    $locked = $this->performFilesystemOperation(
                        'lock the rate-limit state file for cleanup',
                        $path,
                        static fn (): bool => flock($handle, LOCK_EX)
                    );
                    if (!$locked) {
                        throw $this->filesystemFailure('lock the rate-limit state file for cleanup', $path);
                    }

                    $state = $this->readState($handle, $path);
                    if ($state !== null && isset($state['expires_at']) && is_int($state['expires_at']) && $state['expires_at'] <= time()) {
                        $wasRemoved = $this->performFilesystemOperation(
                            'remove the expired rate-limit state file',
                            $path,
                            static fn (): bool => unlink($path)
                        );
                        if (!$wasRemoved) {
                            throw $this->filesystemFailure('remove the expired rate-limit state file', $path);
                        }
                        ++$removed;
                    }
                } catch (Throwable $exception) {
                    $fileException = $exception;

                    throw $exception;
                } finally {
                    $this->unlockAndClose($handle, $path, $fileException);
                }

                $this->advanceDirectoryIterator($iterator);
            }
        } catch (Throwable $exception) {
            $primaryException = $exception;

            throw $exception;
        } finally {
            $this->unlockAndClose($lock, $lockPath, $primaryException);
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
        $isDirectory = $this->performFilesystemOperation(
            'check the file storage directory',
            $this->directory,
            fn (): bool => is_dir($this->directory)
        );

        if (!$isDirectory) {
            $created = $this->performFilesystemOperation(
                'create the file storage directory',
                $this->directory,
                fn (): bool => mkdir($this->directory, 0700, true)
            );
            $isDirectory = $this->performFilesystemOperation(
                'check the created file storage directory',
                $this->directory,
                fn (): bool => is_dir($this->directory)
            );
            if (!$created && !$isDirectory) {
                throw $this->filesystemFailure('create the file storage directory', $this->directory);
            }
        }

        if ($created) {
            $secured = $this->performFilesystemOperation(
                'set permissions on the file storage directory',
                $this->directory,
                fn (): bool => chmod($this->directory, 0700)
            );
            if (!$secured) {
                throw $this->filesystemFailure('set permissions on the file storage directory', $this->directory);
            }
        }

        if (!$isDirectory) {
            throw new StorageException('File storage path is not a directory: ' . $this->directory);
        }

        $isWritable = $this->performFilesystemOperation(
            'check whether the file storage directory is writable',
            $this->directory,
            fn (): bool => is_writable($this->directory)
        );
        $isTraversable = $this->performFilesystemOperation(
            'check whether the file storage directory is traversable',
            $this->directory,
            fn (): bool => is_executable($this->directory)
        );
        if (!$isWritable || !$isTraversable) {
            throw new StorageException('File storage directory is not writable and traversable: ' . $this->directory);
        }
    }

    /**
     * @return resource
     */
    private function openAndLock(string $path)
    {
        $handle = $this->openSecureFile($path, 'open the rate-limit state file');

        try {
            $secured = $this->performFilesystemOperation(
                'set permissions on the rate-limit state file',
                $path,
                static fn (): bool => chmod($path, 0600)
            );
            if (!$secured) {
                throw $this->filesystemFailure('set permissions on the rate-limit state file', $path);
            }

            $locked = $this->performFilesystemOperation(
                'lock the rate-limit state file',
                $path,
                static fn (): bool => flock($handle, LOCK_EX)
            );
            if (!$locked) {
                throw $this->filesystemFailure('lock the rate-limit state file', $path);
            }
        } catch (Throwable $exception) {
            $this->unlockAndClose($handle, $path, $exception);

            throw $exception;
        }

        return $handle;
    }

    /**
     * @return resource
     */
    private function openCleanupLock(int $mode)
    {
        $path = $this->cleanupLockPath();
        $handle = $this->openSecureFile($path, 'open the rate-limit cleanup lock');

        try {
            $secured = $this->performFilesystemOperation(
                'set permissions on the rate-limit cleanup lock',
                $path,
                static fn (): bool => chmod($path, 0600)
            );
            if (!$secured) {
                throw $this->filesystemFailure('set permissions on the rate-limit cleanup lock', $path);
            }

            $locked = $this->performFilesystemOperation(
                'lock the rate-limit cleanup lock',
                $path,
                static fn (): bool => flock($handle, $mode)
            );
            if (!$locked) {
                throw $this->filesystemFailure('lock the rate-limit cleanup lock', $path);
            }
        } catch (Throwable $exception) {
            $this->unlockAndClose($handle, $path, $exception);

            throw $exception;
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
        $path = $this->cleanupLockPath();
        $lock = $this->openCleanupLock(LOCK_SH);
        $primaryException = null;

        try {
            return $operation();
        } catch (Throwable $exception) {
            $primaryException = $exception;

            throw $exception;
        } finally {
            $this->unlockAndClose($lock, $path, $primaryException);
        }
    }

    /**
     * @return resource
     */
    private function openSecureFile(string $path, string $operation)
    {
        $previousUmask = umask(0077);

        try {
            $handle = $this->performFilesystemOperation(
                $operation,
                $path,
                static fn () => fopen($path, 'c+')
            );
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw $this->filesystemFailure($operation, $path);
        }

        return $handle;
    }

    /**
     * @param resource $handle
     *
     * @return list<int>
     */
    private function readTimestamps($handle, string $path, int $now, int $windowSeconds): array
    {
        $state = $this->readState($handle, $path);
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
    private function readState($handle, string $path): ?array
    {
        $rewound = $this->performFilesystemOperation(
            'rewind the rate-limit state file for reading',
            $path,
            static fn (): bool => rewind($handle)
        );
        if (!$rewound) {
            throw $this->filesystemFailure('rewind the rate-limit state file for reading', $path);
        }

        $contents = $this->performFilesystemOperation(
            'read the rate-limit state file',
            $path,
            static fn () => stream_get_contents($handle)
        );
        if ($contents === false) {
            throw $this->filesystemFailure('read the rate-limit state file', $path);
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
    private function writeState($handle, string $path, array $timestamps, int $expiresAt): void
    {
        try {
            $contents = json_encode([
                'timestamps' => $timestamps,
                'expires_at' => $expiresAt,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StorageException('Unable to encode rate-limit state.', 0, $exception);
        }

        $rewound = $this->performFilesystemOperation(
            'rewind the rate-limit state file for writing',
            $path,
            static fn (): bool => rewind($handle)
        );
        if (!$rewound) {
            throw $this->filesystemFailure('rewind the rate-limit state file for writing', $path);
        }

        $truncated = $this->performFilesystemOperation(
            'truncate the rate-limit state file',
            $path,
            static fn (): bool => ftruncate($handle, 0)
        );
        if (!$truncated) {
            throw $this->filesystemFailure('truncate the rate-limit state file', $path);
        }

        $bytesWritten = $this->performFilesystemOperation(
            'write the rate-limit state file',
            $path,
            static fn () => fwrite($handle, $contents)
        );
        if ($bytesWritten !== strlen($contents)) {
            throw $this->filesystemFailure('write the complete rate-limit state file', $path);
        }

        $flushed = $this->performFilesystemOperation(
            'flush the rate-limit state file',
            $path,
            static fn (): bool => fflush($handle)
        );
        if (!$flushed) {
            throw $this->filesystemFailure('flush the rate-limit state file', $path);
        }
    }

    /**
     * @param resource $handle
     */
    private function unlockAndClose($handle, string $path, ?Throwable $primaryException = null): void
    {
        $cleanupException = null;

        try {
            $unlocked = $this->performFilesystemOperation(
                'unlock the filesystem resource',
                $path,
                static fn (): bool => flock($handle, LOCK_UN)
            );
            if (!$unlocked) {
                throw $this->filesystemFailure('unlock the filesystem resource', $path);
            }
        } catch (Throwable $exception) {
            $cleanupException = $exception;
        }

        try {
            $closed = $this->performFilesystemOperation(
                'close the filesystem resource',
                $path,
                static fn (): bool => fclose($handle)
            );
            if (!$closed) {
                throw $this->filesystemFailure('close the filesystem resource', $path);
            }
        } catch (Throwable $exception) {
            if ($cleanupException === null) {
                $cleanupException = $exception;
            }
        }

        if ($primaryException === null && $cleanupException !== null) {
            throw $cleanupException;
        }
    }

    private function directoryIteratorIsValid(DirectoryIterator $iterator): bool
    {
        return $this->performFilesystemOperation(
            'validate the current file storage directory entry',
            $this->directory,
            static fn (): bool => $iterator->valid()
        );
    }

    private function directoryIteratorEntryIsFile(DirectoryIterator $iterator): bool
    {
        return $this->performFilesystemOperation(
            'inspect the current file storage directory entry',
            $this->directory,
            static fn (): bool => $iterator->isFile()
        );
    }

    private function directoryIteratorFilename(DirectoryIterator $iterator): string
    {
        return $this->performFilesystemOperation(
            'read the current file storage directory entry name',
            $this->directory,
            static fn (): string => $iterator->getFilename()
        );
    }

    private function directoryIteratorPathname(DirectoryIterator $iterator): string
    {
        return $this->performFilesystemOperation(
            'read the current file storage directory entry path',
            $this->directory,
            static fn (): string => $iterator->getPathname()
        );
    }

    private function advanceDirectoryIterator(DirectoryIterator $iterator): void
    {
        $this->performFilesystemOperation(
            'advance the file storage directory iterator',
            $this->directory,
            static function () use ($iterator): void {
                $iterator->next();
            }
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function performFilesystemOperation(string $description, string $path, callable $operation)
    {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): bool {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
            E_WARNING | E_NOTICE
        );

        try {
            return $operation();
        } catch (StorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->filesystemFailure($description, $path, $exception);
        } finally {
            restore_error_handler();
        }
    }

    private function filesystemFailure(string $description, string $path, ?Throwable $previous = null): StorageException
    {
        return new StorageException(
            sprintf('Filesystem operation failed (%s): %s', $description, $path),
            0,
            $previous
        );
    }

    private function statePath(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    private function cleanupLockPath(): string
    {
        return $this->directory . '/' . self::CLEANUP_LOCK_FILE;
    }
}
