# Rate Limiter

Robust rate limiting library for PHP with automatic fallback storage (Redis → APCu → Filesystem → Session).

Part of 🐢 [mata-sh](https://github.com/mata-sh).

## Features

- Supports Redis, APCu, Filesystem, and Session storage backends
- Zero configuration - works out of the box
- Flexible identifiers - IP-based, user-based, or custom
- PSR-3 logging support
- Sliding window rate limiting algorithm
- Proxy-aware IP detection (Cloudflare, X-Forwarded-For, etc.)

## Installation

```bash
composer require mata-sh/rate-limiter
```

Requires PHP 8.1+. Redis or APCu recommended for production.

## Usage

```php
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\RateLimitIdentifier;

// Initialize with explicit storage backend
$limiter = new RateLimiter('apcu');

// Simple identifier
if ($limiter->allow('api_user_123', 10, 60)) {
    // Process request (10 requests per 60 seconds)
}

// IP-based limiting
$identifier = RateLimitIdentifier::fromIp();
if (!$limiter->allow($identifier, 100, 3600)) {
    http_response_code(429);
    exit('Rate limit exceeded');
}

// IP + username combined
$identifier = RateLimitIdentifier::fromIpUser($username);
$limiter->allow($identifier, 20, 60);

// Check without consuming
$status = $limiter->check($identifier, 100, 3600);
header('X-RateLimit-Remaining: ' . $status['remaining']);
```

### Identifiers

```php
RateLimitIdentifier::fromIp();              // Auto-detect IP
RateLimitIdentifier::fromIpUser($username); // IP + username
RateLimitIdentifier::getClientIp();         // Get IP directly

// Or create custom identifiers
'user_' . $userId
'api_key_' . hash('sha256', $apiKey)
```

### Configuration

```php
// Disable rate limiting (allows all requests)
$limiter = new RateLimiter('apcu');
$limiter->setRateLimitingEnabled(false);

// Set default limits
$limiter->setRateLimitingConfig(100, 3600);
```

## Storage Backends

Explicitly configure the storage backend on initialization:

### APCu (Recommended)

```php
$limiter = new RateLimiter('apcu');
```

Fast in-memory cache. Install: `pecl install apcu`

### Redis

```php
use MataSh\RateLimiter\RateLimiter;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth('password');

$limiter = new RateLimiter('redis', null, $redis);
```

Best for distributed systems.

### Filesystem

```php
$limiter = new RateLimiter('file', '/var/app/cache/rate_limit');
```

JSON files with auto-cleanup. Default path: `sys_get_temp_dir() . '/cache/rate_limit'`

### Session

```php
$limiter = new RateLimiter('session');
```

Per-user limits only (not suitable for global IP-based limiting).

## License

MIT

## Related

Used by [mata-dashboard](https://github.com/mata-sh/mata-dashboard) and [mata-node](https://github.com/mata-sh/mata-node).
