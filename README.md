# Rate Limiter

Atomic sliding-window rate limiting for PHP applications. Supports filesystem, Redis, and APCu storage.

## Installation

Requires PHP 8.0 or later.

```bash
composer require mata-sh/rate-limiter
```

## Basic usage

Pass a storage implementation explicitly. `consume()` atomically checks a limit and consumes one request when it allows it.

```php
use MataSh\RateLimiter\RateLimitIdentifier;
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\Storage\FileStorage;

$limiter = new RateLimiter(new FileStorage('/var/app/cache/rate-limit'));
$identifier = RateLimitIdentifier::fromIp();
$result = $limiter->consume($identifier, 100, 3600);

if (!$result->isAllowed()) {
    $retryAt = $result->getRetryAt();
    // Reject the request. $retryAt is the Unix timestamp when one request can next succeed.
}
```

`RateLimitResult` provides `isAllowed(): bool`, `getCurrent(): int`, and `getRetryAt(): ?int`. `retryAt` is `null` for allowed requests. For denied requests, it is calculated atomically as the oldest request still in the sliding window plus the window duration.

`allow()` remains a compatibility convenience wrapper around `consume()` when only a boolean is needed:

```php
if (!$limiter->allow($identifier, 100, 3600)) {
    // Reject the request.
}
```

`check()` reads the current state without consuming a request:

```php
$status = $limiter->check($identifier, 100, 3600);

if (!$status['allowed']) {
    // Reject the request without adding a request to the window.
}
```

`check()` returns:

| Field | Meaning |
| --- | --- |
| `allowed` | Whether `current` is below `limit`. |
| `current` | Requests currently counted in the sliding window. |
| `limit` | The effective maximum request count. |
| `remaining` | Requests still allowed, never below zero. |
| `storage_type` | The configured storage's `getName()` value (built-ins return `file`, `apcu`, or `redis`). |

`check()` is non-atomic and does not provide retry guidance. When rate limiting is disabled, it returns `allowed: true`, `current: 0`, `limit` and `remaining` as `PHP_INT_MAX`, and `storage_type: 'disabled'`.

## Reset and cleanup

Reset one identifier through the limiter facade. It uses the same storage key translation as `consume()`, `allow()`, and `check()`:

```php
$limiter->reset($identifier);
```

There is no global reset or storage scan API. Reset each identifier explicitly.

Use `cleanup()` to remove expired records from storage backends that require maintenance:

```php
$removed = $limiter->cleanup(100); // Removes at most 100 expired records.
```

`cleanup()` requires a limit greater than zero. Every storage implements the same operation. APCu and Redis manage expiration with TTLs, so they return `0`; filesystem storage actively removes expired files.

## Configuration and logging

Rate limiting starts enabled with defaults of 60 requests per 60 seconds. Set defaults for calls that omit the optional limit arguments:

```php
$limiter->setRateLimitingConfig(120, 60);

$limiter->allow($identifier); // Uses 120 requests / 60 seconds.
$limiter->check($identifier); // Uses the same defaults.
```

Both configuration values must be greater than zero. Inspect the active settings or temporarily bypass limiting:

```php
$limiter->setRateLimitingEnabled(false);

$enabled = $limiter->isRateLimitingEnabled();
$maxRequests = $limiter->getDefaultMaxRequests();
$windowSeconds = $limiter->getDefaultWindow();
$storage = $limiter->getStorage();
```

The constructor accepts an optional PSR-3 logger:

```php
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
$limiter = new RateLimiter(
    new FileStorage('/var/app/cache/rate-limit'),
    $logger,
);
```

Without a logger, the library uses `Psr\Log\NullLogger`.

## Storage backends

All storage implementations implement `MataSh\RateLimiter\Storage\StorageInterface`, so custom backends can be injected into `RateLimiter`.

### FileStorage

```php
use MataSh\RateLimiter\Storage\FileStorage;

$storage = new FileStorage('/var/app/cache/rate-limit');
$limiter = new RateLimiter($storage);
```

The directory must be writable and traversable. If `FileStorage` creates it, it uses mode `0700`. Permissions on an existing directory are caller-managed and remain unchanged. `FileStorage` uses SHA-256-derived `.json` state filenames and locks state updates. If no directory is supplied, it uses `sys_get_temp_dir() . '/cache/rate_limit'`.

Expired files are **not** removed automatically. Schedule bounded cleanup through the limiter facade as appropriate for the application:

```php
$removed = $limiter->cleanup(100); // Removes at most 100 expired files.
```

`FileStorage::cleanup()` performs this maintenance directly.

### ApcuStorage

```php
use MataSh\RateLimiter\Storage\ApcuStorage;

$limiter = new RateLimiter(new ApcuStorage());
```

Install the APCu PHP extension and ensure APCu is enabled. APCu state is local to the PHP environment that shares its APCu cache; it is not a distributed backend. Operations use an APCu lock to make consumption atomic.

### RedisStorage

```php
use MataSh\RateLimiter\Storage\RedisStorage;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$limiter = new RateLimiter(new RedisStorage($redis));
```

Requires the PHP `redis` extension and a connected `Redis` instance. Redis consumption uses optimistic transactions and retries conflicts up to five times.

### Storage failures

Backend initialization and storage operations can throw `MataSh\RateLimiter\Storage\StorageException`, including unavailable APCu, filesystem errors, Redis errors, malformed state, and lock or transaction failures. Handle this exception according to the application's fail-open or fail-closed policy.

## Identifiers

```php
use MataSh\RateLimiter\RateLimitIdentifier;

RateLimitIdentifier::getClientIp();
RateLimitIdentifier::fromIp();
RateLimitIdentifier::fromIpUser($username);
RateLimitIdentifier::fromApiKey($apiKey);
RateLimitIdentifier::fromUserId($userId);
RateLimitIdentifier::fromResource($resourceId);
RateLimitIdentifier::custom($identifier);
```

The identifier factory methods return prefixed SHA-256-based identifiers. `fromIp()` and `getClientIp()` use `$_SERVER['REMOTE_ADDR']` only; they do not inspect or trust proxy headers. When the application is behind a proxy, pass the verified client IP explicitly to `fromIp($ip)` or `fromIpUser($username, $ip)`.

## License

MIT
