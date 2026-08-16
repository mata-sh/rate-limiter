<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Storage;

interface StorageInterface
{
    /**
     * Atomically record one request when the limit has not been reached.
     */
    public function consume(string $key, int $maxRequests, int $windowSeconds): ConsumeResult;

    /**
     * Return the number of requests currently in the sliding window.
     */
    public function count(string $key, int $windowSeconds): int;

    /**
     * Delete a key's state. This operation must be idempotent.
     */
    public function delete(string $key): void;

    /**
     * Perform bounded storage maintenance and return the number of records actively removed.
     */
    public function cleanup(int $limit = 100): int;

    public function getName(): string;
}
