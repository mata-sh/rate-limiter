<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Storage;

use InvalidArgumentException;
use JsonException;
use MataSh\RateLimiter\RateLimitResult;
use Redis;
use Throwable;

final class RedisStorage implements StorageInterface
{
    private const MAX_RETRIES = 5;

    public function __construct(private Redis $redis) {}

    public function consume(string $key, int $maxRequests, int $windowSeconds): RateLimitResult
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; ++$attempt) {
            $watched = false;
            $inTransaction = false;

            try {
                if ($this->redis->watch($key) !== true) {
                    throw new StorageException('Unable to watch Redis rate-limit key.');
                }
                $watched = true;

                $now = time();
                $timestamps = $this->readTimestamps($key, $now, $windowSeconds);
                $current = count($timestamps);

                if ($current >= $maxRequests) {
                    if ($this->redis->unwatch() !== true) {
                        throw new StorageException('Unable to unwatch Redis rate-limit key.');
                    }
                    $watched = false;

                    return new RateLimitResult(false, $current, min($timestamps) + $windowSeconds);
                }

                $timestamps[] = $now;
                $payload = $this->encode($timestamps);

                if ($this->redis->multi() === false) {
                    throw new StorageException('Unable to begin Redis rate-limit transaction.');
                }
                $inTransaction = true;

                if ($this->redis->setex($key, $windowSeconds, $payload) === false) {
                    throw new StorageException('Unable to queue Redis rate-limit state.');
                }

                $result = $this->redis->exec();
                $inTransaction = false;
                $watched = false;

                if ($result === false) {
                    continue;
                }

                if (!is_array($result) || $result[0] !== true) {
                    throw new StorageException('Unable to write Redis rate-limit state.');
                }

                return new RateLimitResult(true, $current + 1, null);
            } catch (StorageException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new StorageException('Redis rate-limit operation failed.', 0, $exception);
            } finally {
                if ($inTransaction) {
                    $this->redis->discard();
                } elseif ($watched) {
                    $this->redis->unwatch();
                }
            }
        }

        throw new StorageException('Redis rate-limit transaction conflicted too many times.');
    }

    public function count(string $key, int $windowSeconds): int
    {
        try {
            return count($this->readTimestamps($key, time(), $windowSeconds));
        } catch (StorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StorageException('Redis rate-limit read failed.', 0, $exception);
        }
    }

    public function delete(string $key): void
    {
        try {
            if (!is_int($this->redis->del($key))) {
                throw new StorageException('Unable to remove Redis rate-limit state.');
            }
        } catch (StorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StorageException('Redis rate-limit delete failed.', 0, $exception);
        }
    }

    /**
     * Redis expires state through TTLs, so no active cleanup is required.
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
        return 'redis';
    }

    /**
     * @return list<int>
     */
    private function readTimestamps(string $key, int $now, int $windowSeconds): array
    {
        if ($this->supportsMethod('clearLastError')) {
            $this->redis->clearLastError();
        }

        $payload = $this->redis->get($key);
        if ($payload === false || $payload === null) {
            if ($this->supportsMethod('getLastError')) {
                $error = $this->redis->getLastError();
                if (is_string($error) && $error !== '') {
                    throw new StorageException('Unable to read Redis rate-limit state: ' . $error);
                }
            }

            $exists = $this->redis->exists($key);
            if ($exists === false || !is_int($exists)) {
                throw new StorageException('Unable to determine whether Redis rate-limit state exists.');
            }

            return [];
        }

        if (!is_string($payload)) {
            throw new StorageException('Redis rate-limit state is invalid.');
        }

        try {
            $state = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StorageException('Unable to decode Redis rate-limit state.', 0, $exception);
        }

        if (!is_array($state)) {
            throw new StorageException('Redis rate-limit state is invalid.');
        }

        $windowStart = $now - $windowSeconds;
        $timestamps = [];
        foreach ($state as $timestamp) {
            if (!is_int($timestamp)) {
                throw new StorageException('Redis rate-limit state contains an invalid timestamp.');
            }

            if ($timestamp > $windowStart) {
                $timestamps[] = $timestamp;
            }
        }

        return $timestamps;
    }

    private function supportsMethod(string $method): bool
    {
        return method_exists($this->redis, $method);
    }

    /**
     * @param list<int> $timestamps
     */
    private function encode(array $timestamps): string
    {
        try {
            return json_encode($timestamps, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StorageException('Unable to encode Redis rate-limit state.', 0, $exception);
        }
    }
}
