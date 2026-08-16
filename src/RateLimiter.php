<?php

declare(strict_types=1);

namespace MataSh\RateLimiter;

use InvalidArgumentException;
use MataSh\RateLimiter\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RateLimiter
{
    private string $prefix = 'rate_limit_';
    private bool $isRateLimitingEnabled = true;
    private int $defaultMaxRequests = 60;
    private int $defaultWindow = 60;
    private LoggerInterface $logger;
    private StorageInterface $storage;

    public function __construct(StorageInterface $storage, ?LoggerInterface $logger = null)
    {
        $this->storage = $storage;
        $this->logger = $logger ?? new NullLogger();

        $this->logger->info('RateLimiter initialized with ' . $this->storage->getName() . ' storage');
    }

    /**
     * Check and atomically consume one request when allowed.
     */
    public function allow(
        string $identifier,
        ?int $maxRequests = null,
        ?int $windowSeconds = null
    ): bool {
        if (!$this->isRateLimitingEnabled) {
            return true;
        }

        [$maxRequests, $windowSeconds] = $this->resolveLimit($maxRequests, $windowSeconds);
        $result = $this->storage->consume($this->key($identifier), $maxRequests, $windowSeconds);

        if (!$result->isAllowed()) {
            $this->logger->warning('Rate limit exceeded for ' . $identifier . ': ' . $result->getCurrent() . '/' . $maxRequests);
        }

        return $result->isAllowed();
    }

    /**
     * Check a limit without consuming a request.
     *
     * @return array{allowed: bool, current: int, limit: int, remaining: int, reset_at: int, storage_type: string}
     */
    public function check(
        string $identifier,
        ?int $maxRequests = null,
        ?int $windowSeconds = null
    ): array {
        if (!$this->isRateLimitingEnabled) {
            return [
                'allowed' => true,
                'current' => 0,
                'limit' => PHP_INT_MAX,
                'remaining' => PHP_INT_MAX,
                'reset_at' => 0,
                'storage_type' => 'disabled',
            ];
        }

        [$maxRequests, $windowSeconds] = $this->resolveLimit($maxRequests, $windowSeconds);
        $current = $this->storage->count($this->key($identifier), $windowSeconds);

        return [
            'allowed' => $current < $maxRequests,
            'current' => $current,
            'limit' => $maxRequests,
            'remaining' => max(0, $maxRequests - $current),
            'reset_at' => time(),
            'storage_type' => $this->storage->getName(),
        ];
    }

    /**
     * Reset one identifier's rate-limit state.
     */
    public function reset(string $identifier): void
    {
        $this->storage->delete($this->key($identifier));
    }

    /**
     * Perform bounded storage maintenance.
     */
    public function cleanup(int $limit = 100): int
    {
        $this->assertValidCleanupLimit($limit);

        return $this->storage->cleanup($limit);
    }

    public function setRateLimitingEnabled(bool $enabled): self
    {
        $this->isRateLimitingEnabled = $enabled;

        return $this;
    }

    public function setRateLimitingConfig(int $maxRequests, int $window): self
    {
        $this->assertValidLimit($maxRequests, $window);
        $this->defaultMaxRequests = $maxRequests;
        $this->defaultWindow = $window;

        return $this;
    }

    public function isRateLimitingEnabled(): bool
    {
        return $this->isRateLimitingEnabled;
    }

    public function getDefaultMaxRequests(): int
    {
        return $this->defaultMaxRequests;
    }

    public function getDefaultWindow(): int
    {
        return $this->defaultWindow;
    }

    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveLimit(?int $maxRequests, ?int $windowSeconds): array
    {
        $maxRequests = $maxRequests ?? $this->defaultMaxRequests;
        $windowSeconds = $windowSeconds ?? $this->defaultWindow;
        $this->assertValidLimit($maxRequests, $windowSeconds);

        return [$maxRequests, $windowSeconds];
    }

    private function assertValidLimit(int $maxRequests, int $windowSeconds): void
    {
        if ($maxRequests < 1) {
            throw new InvalidArgumentException('Maximum requests must be greater than zero.');
        }

        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('Window seconds must be greater than zero.');
        }
    }

    private function assertValidCleanupLimit(int $limit): void
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Cleanup limit must be greater than zero.');
        }
    }

    private function key(string $identifier): string
    {
        return $this->prefix . $identifier;
    }
}
