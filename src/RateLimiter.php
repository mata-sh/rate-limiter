<?php

declare(strict_types=1);

namespace MataSh\RateLimiter;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use RuntimeException;
use Throwable;

class RateLimiter
{
    private string $storageType;
    private string $prefix = 'rate_limit_';
    private string $fileStoragePath;
    private ?Redis $redis = null;
    private bool $isRateLimitingEnabled = true;
    private int $defaultMaxRequests = 60;
    private int $defaultWindow = 60;
    private LoggerInterface $logger;

    public function __construct(
        string $storageType = 'apcu',
        ?string $fileStoragePath = null,
        ?Redis $redis = null,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->storageType = $storageType;
        $this->redis = $redis;
        $this->fileStoragePath = $fileStoragePath ?: sys_get_temp_dir() . '/cache/rate_limit';

        // Validate storage configuration
        if ($storageType === 'redis' && !$redis instanceof Redis) {
            throw new InvalidArgumentException('Redis storage type requires a Redis instance');
        }

        if ($storageType === 'apcu' && !function_exists('apcu_fetch')) {
            throw new RuntimeException('APCu extension not available');
        }

        if ($storageType === 'file') {
            if (!is_dir($this->fileStoragePath)) {
                mkdir($this->fileStoragePath, 0777, true);
            }
            if (!is_writable($this->fileStoragePath)) {
                throw new RuntimeException('File storage path is not writable: ' . $this->fileStoragePath);
            }
        }

        $this->logger->info("RateLimiter initialized with {$storageType} storage");
    }

    /**
     * Check if request is allowed.
     */
    public function allow(
        string $identifier,
        ?int $maxRequests = null,
        ?int $windowSeconds = null
    ): bool {
        // If rate limiting is disabled, allow all requests
        if (!$this->isRateLimitingEnabled) {
            return true;
        }

        // Use defaults from class if not provided
        $maxRequests = $maxRequests ?? $this->defaultMaxRequests;
        $windowSeconds = $windowSeconds ?? $this->defaultWindow;

        $key = $this->prefix . $identifier;
        $now = time();
        $windowStart = $now - $windowSeconds;

        $requests = $this->getRequests($key, $windowStart);

        if (count($requests) >= $maxRequests) {
            $this->logger->warning("Rate limit exceeded for {$identifier}: " . count($requests) . "/{$maxRequests}");

            return false;
        }

        // Add current request
        $requests[] = $now;
        $this->storeRequests($key, $requests, $windowSeconds);

        return true;
    }

    /**
     * Get requests from storage.
     */
    private function getRequests(string $key, int $windowStart): array
    {
        $requests = [];

        switch ($this->storageType) {
            case 'redis':
                $requests = $this->getFromRedis($key);

                break;

            case 'apcu':
                $requests = apcu_fetch($key) ?: [];

                break;

            case 'file':
                $requests = $this->getFromFile($key);

                break;

            case 'session':
                $requests = $_SESSION[$key] ?? [];

                break;
        }

        return array_filter($requests, fn ($timestamp) => $timestamp > $windowStart);
    }

    /**
     * Store requests.
     */
    private function storeRequests(string $key, array $requests, int $ttl): void
    {
        // Keep only recent requests to prevent memory bloat
        $requests = array_slice($requests, -100);

        switch ($this->storageType) {
            case 'redis':
                $this->storeToRedis($key, $requests, $ttl);

                break;

            case 'apcu':
                apcu_store($key, $requests, $ttl);

                break;

            case 'file':
                $this->storeToFile($key, $requests, $ttl);

                break;

            case 'session':
                $_SESSION[$key] = $requests;
                $_SESSION[$key . '_expires'] = time() + $ttl;

                break;
        }
    }

    /**
     * File-based storage operations.
     */
    private function getFromFile(string $key): array
    {
        $file = $this->fileStoragePath . '/' . md5($key) . '.json';

        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);

        // Check if expired
        if (isset($data['expires']) && $data['expires'] < time()) {
            unlink($file);

            return [];
        }

        return $data['requests'] ?? [];
    }

    private function storeToFile(string $key, array $requests, int $ttl): void
    {
        $file = $this->fileStoragePath . '/' . md5($key) . '.json';

        $data = [
            'requests' => $requests,
            'expires' => time() + $ttl,
        ];

        file_put_contents($file, json_encode($data), LOCK_EX);

        // Cleanup old files occasionally (1% chance)
        if (mt_rand(1, 100) === 1) {
            $this->cleanupFileStorage();
        }
    }

    private function cleanupFileStorage(): void
    {
        $files = glob($this->fileStoragePath . '/*.json');
        $now = time();

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (isset($data['expires']) && $data['expires'] < $now) {
                unlink($file);
            }
        }
    }

    /**
     * Redis-based storage operations.
     */
    private function getFromRedis(string $key): array
    {
        if (!$this->redis instanceof Redis) {
            return [];
        }

        try {
            $data = $this->redis->get($key);
        } catch (Throwable $e) {
            $this->logger->warning('RateLimiter Redis get failed: ' . $e->getMessage());

            return [];
        }

        if (!is_string($data) || $data === '') {
            return [];
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function storeToRedis(string $key, array $requests, int $ttl): void
    {
        if (!$this->redis instanceof Redis) {
            return;
        }

        $payload = json_encode(array_values($requests));
        if ($payload === false) {
            $this->logger->warning('RateLimiter Redis encode failed');

            return;
        }

        try {
            $this->redis->setex($key, $ttl, $payload);
        } catch (Throwable $e) {
            $this->logger->warning('RateLimiter Redis set failed: ' . $e->getMessage());
        }
    }

    /**
     * Check limit without consuming (for info).
     */
    public function check(
        string $identifier,
        ?int $maxRequests = null,
        ?int $windowSeconds = null
    ): array {
        // If rate limiting is disabled, return unlimited status
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

        // Use defaults from class if not provided
        $maxRequests = $maxRequests ?? $this->defaultMaxRequests;
        $windowSeconds = $windowSeconds ?? $this->defaultWindow;

        $key = $this->prefix . $identifier;
        $now = time();
        $windowStart = $now - $windowSeconds;

        $requests = $this->getRequests($key, $windowStart);
        $count = count($requests);

        return [
            'allowed' => $count < $maxRequests,
            'current' => $count,
            'limit' => $maxRequests,
            'remaining' => max(0, $maxRequests - $count),
            'reset_at' => $windowStart + $windowSeconds,
            'storage_type' => $this->storageType,
        ];
    }

    public function setRateLimitingEnabled(bool $enabled): self
    {
        $this->isRateLimitingEnabled = $enabled;

        return $this;
    }

    public function setRateLimitingConfig(int $maxRequests, int $window): self
    {
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
}
