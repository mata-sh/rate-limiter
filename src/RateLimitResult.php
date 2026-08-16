<?php

declare(strict_types=1);

namespace MataSh\RateLimiter;

/**
 * The outcome of one atomic rate-limit consumption attempt.
 *
 * A denied result includes the earliest Unix timestamp at which one request can next succeed.
 */
final class RateLimitResult
{
    public function __construct(
        private bool $allowed,
        private int $current,
        private ?int $retryAt
    ) {}

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    public function getRetryAt(): ?int
    {
        return $this->retryAt;
    }
}
