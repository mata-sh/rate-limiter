<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Storage;

use InvalidArgumentException;
use Throwable;

final class ApcuStorage implements StorageInterface
{
    private const LOCK_RETRIES = 50;
    private const LOCK_TTL = 10;

    public function __construct()
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_add') || !function_exists('apcu_cas') || !function_exists('apcu_delete') || !function_exists('apcu_store') || !function_exists('apcu_touch') || !function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new StorageException('APCu is not available or enabled.');
        }
    }

    public function consume(string $key, int $maxRequests, int $windowSeconds): ConsumeResult
    {
        return $this->withLock($key, function () use ($key, $maxRequests, $windowSeconds): ConsumeResult {
            $now = time();
            $timestamps = $this->readTimestamps($key, $now, $windowSeconds);
            $current = count($timestamps);

            if ($current >= $maxRequests) {
                return new ConsumeResult(false, $current);
            }

            $timestamps[] = $now;
            $this->store($key, $timestamps, $windowSeconds);

            return new ConsumeResult(true, $current + 1);
        });
    }

    public function count(string $key, int $windowSeconds): int
    {
        return $this->withLock($key, function () use ($key, $windowSeconds): int {
            return count($this->readTimestamps($key, time(), $windowSeconds));
        });
    }

    public function delete(string $key): void
    {
        $this->withLock($key, function () use ($key): void {
            $success = false;
            apcu_fetch($key, $success);
            if (!$success) {
                return;
            }

            if (apcu_delete($key)) {
                return;
            }

            apcu_fetch($key, $success);
            if ($success) {
                throw new StorageException('Unable to remove APCu rate-limit state.');
            }
        });
    }

    /**
     * APCu expires state through TTLs, so no active cleanup is required.
     */
    public function cleanup(int $limit = 100): int
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Cleanup limit must be greater than zero.');
        }

        return 0;
    }

    public function getName(): string
    {
        return 'apcu';
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withLock(string $key, callable $operation)
    {
        $lockKey = 'mata_rate_limiter_lock_' . hash('sha256', $key);
        $lease = 0;

        for ($attempt = 0; $attempt < self::LOCK_RETRIES; ++$attempt) {
            $success = false;
            $current = apcu_fetch($lockKey, $success);
            if (!$success) {
                if (!apcu_add($lockKey, 0)) {
                    usleep(1000);

                    continue;
                }
                $current = 0;
            }

            if (!is_int($current)) {
                throw new StorageException('APCu rate-limit lock is invalid.');
            }

            $now = (int) (microtime(true) * 1000000);
            if ($current !== 0 && $current > $now) {
                usleep(1000);

                continue;
            }

            $lease = $now + (self::LOCK_TTL * 1000000);
            if (apcu_cas($lockKey, $current, $lease)) {
                if (!apcu_touch($lockKey, self::LOCK_TTL)) {
                    apcu_cas($lockKey, $lease, 0);

                    throw new StorageException('Unable to set APCu rate-limit lock expiry.');
                }

                break;
            }

            $lease = 0;
        }

        if ($lease === 0) {
            throw new StorageException('Unable to acquire APCu rate-limit lock.');
        }

        try {
            return $operation();
        } finally {
            try {
                apcu_cas($lockKey, $lease, 0);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @return list<int>
     */
    private function readTimestamps(string $key, int $now, int $windowSeconds): array
    {
        $success = false;
        $state = apcu_fetch($key, $success);
        if (!$success) {
            return [];
        }

        if (!is_array($state) || !isset($state['timestamps']) || !is_array($state['timestamps'])) {
            throw new StorageException('APCu rate-limit state is invalid.');
        }

        $windowStart = $now - $windowSeconds;
        $timestamps = [];
        foreach ($state['timestamps'] as $timestamp) {
            if (!is_int($timestamp)) {
                throw new StorageException('APCu rate-limit state contains an invalid timestamp.');
            }

            if ($timestamp > $windowStart) {
                $timestamps[] = $timestamp;
            }
        }

        return $timestamps;
    }

    /**
     * @param list<int> $timestamps
     */
    private function store(string $key, array $timestamps, int $windowSeconds): void
    {
        $state = [
            'timestamps' => $timestamps,
        ];

        if (!apcu_store($key, $state, $windowSeconds)) {
            throw new StorageException('Unable to write APCu rate-limit state.');
        }
    }
}
