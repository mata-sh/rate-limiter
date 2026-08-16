<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Storage;

final class ConsumeResult
{
    public function __construct(
        private bool $allowed,
        private int $current
    ) {}

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getCurrent(): int
    {
        return $this->current;
    }
}
