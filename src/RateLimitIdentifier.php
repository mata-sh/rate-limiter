<?php

declare(strict_types=1);

namespace MataSh\RateLimiter;

class RateLimitIdentifier
{
    /**
     * Generate identifier from IP address only.
     */
    public static function fromIp(?string $ip = null): string
    {
        $ip = $ip ?? self::getClientIp();

        return 'ip_' . hash('sha256', $ip);
    }

    /**
     * Generate identifier from IP + username combination.
     */
    public static function fromIpUser(string $username, ?string $ip = null): string
    {
        $ip = $ip ?? self::getClientIp();

        return 'ipuser_' . hash('sha256', $ip . ':' . $username);
    }

    /**
     * Generate identifier from API key.
     */
    public static function fromApiKey(string $apiKey): string
    {
        return 'api_key_' . hash('sha256', $apiKey);
    }

    /**
     * Generate identifier from user ID.
     */
    public static function fromUserId(string $userId): string
    {
        return 'user_' . hash('sha256', $userId);
    }

    /**
     * Generate identifier from resource ID.
     */
    public static function fromResource(string $resourceId): string
    {
        return 'resource_' . hash('sha256', $resourceId);
    }

    /**
     * Generate custom identifier.
     */
    public static function custom(string $identifier): string
    {
        return 'custom_' . hash('sha256', $identifier);
    }

    /**
     * Get client IP address from request headers.
     */
    public static function getClientIp(array $trustedProxies = []): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }
}
