<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Tests;

use InvalidArgumentException;
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\RateLimitResult;
use MataSh\RateLimiter\Storage\StorageException;
use MataSh\RateLimiter\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class RateLimiterTest extends TestCase
{
    public function testStorageFailureIsNotConvertedToAnAllowedRequest(): void
    {
        $limiter = new RateLimiter(new FailingStorage());

        $this->expectException(StorageException::class);
        $limiter->allow('user', 1, 60);
    }

    public function testCheckPropagatesStorageFailures(): void
    {
        $limiter = new RateLimiter(new FailingStorage());

        $this->expectException(StorageException::class);
        $limiter->check('user', 1, 60);
    }

    public function testConsumeTranslatesTheIdentifierAndPropagatesTheStorageResult(): void
    {
        $expected = new RateLimitResult(false, 2, 1234567890);
        $storage = new RecordingStorage($expected);
        $limiter = new RateLimiter($storage);

        $result = $limiter->consume('user', 2, 60);

        self::assertSame($expected, $result);
        self::assertFalse($result->isAllowed());
        self::assertSame(2, $result->getCurrent());
        self::assertSame(1234567890, $result->getRetryAt());
        self::assertSame([['rate_limit_user', 2, 60]], $storage->consumeCalls);
    }

    public function testAllowWrapsConsume(): void
    {
        $storage = new RecordingStorage(new RateLimitResult(false, 1, 1234567890));
        $limiter = new RateLimiter($storage);

        self::assertFalse($limiter->allow('user', 1, 60));
        self::assertSame([['rate_limit_user', 1, 60]], $storage->consumeCalls);
    }

    public function testCheckDoesNotReturnResetAt(): void
    {
        $limiter = new RateLimiter(new RecordingStorage());

        self::assertArrayNotHasKey('reset_at', $limiter->check('user', 1, 60));

        $limiter->setRateLimitingEnabled(false);
        self::assertArrayNotHasKey('reset_at', $limiter->check('user', 1, 60));
    }

    public function testResetTranslatesTheIdentifierBeforeDeleting(): void
    {
        $storage = new RecordingStorage();
        $limiter = new RateLimiter($storage);

        $limiter->reset('user');

        self::assertSame(['rate_limit_user'], $storage->deletedKeys);
    }

    public function testCleanupDelegatesToSupportingStorage(): void
    {
        $storage = new CleanupRecordingStorage();
        $limiter = new RateLimiter($storage);

        self::assertSame(3, $limiter->cleanup(25));
        self::assertSame([25], $storage->cleanupLimits);
    }

    public function testCleanupReturnsZeroWhenNoRecordsAreRemoved(): void
    {
        $limiter = new RateLimiter(new RecordingStorage());

        self::assertSame(0, $limiter->cleanup(25));
    }

    public function testCleanupRejectsAnInvalidLimit(): void
    {
        $limiter = new RateLimiter(new RecordingStorage());

        $this->expectException(InvalidArgumentException::class);
        $limiter->cleanup(0);
    }
}

final class FailingStorage implements StorageInterface
{
    public function consume(string $key, int $maxRequests, int $windowSeconds): RateLimitResult
    {
        throw new StorageException('Storage is unavailable.');
    }

    public function count(string $key, int $windowSeconds): int
    {
        throw new StorageException('Storage is unavailable.');
    }

    public function delete(string $key): void
    {
        throw new StorageException('Storage is unavailable.');
    }

    public function cleanup(int $limit = 100): int
    {
        throw new StorageException('Storage is unavailable.');
    }

    public function getName(): string
    {
        return 'failing';
    }
}

final class RecordingStorage implements StorageInterface
{
    /** @var list<array{0: string, 1: int, 2: int}> */
    public array $consumeCalls = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    private RateLimitResult $consumeResult;

    public function __construct(?RateLimitResult $consumeResult = null)
    {
        $this->consumeResult = $consumeResult ?? new RateLimitResult(true, 1, null);
    }

    public function consume(string $key, int $maxRequests, int $windowSeconds): RateLimitResult
    {
        $this->consumeCalls[] = [$key, $maxRequests, $windowSeconds];

        return $this->consumeResult;
    }

    public function count(string $key, int $windowSeconds): int
    {
        return 0;
    }

    public function delete(string $key): void
    {
        $this->deletedKeys[] = $key;
    }

    public function cleanup(int $limit = 100): int
    {
        return 0;
    }

    public function getName(): string
    {
        return 'recording';
    }
}

final class CleanupRecordingStorage implements StorageInterface
{
    /** @var list<int> */
    public array $cleanupLimits = [];

    public function consume(string $key, int $maxRequests, int $windowSeconds): RateLimitResult
    {
        return new RateLimitResult(true, 1, null);
    }

    public function count(string $key, int $windowSeconds): int
    {
        return 0;
    }

    public function delete(string $key): void {}

    public function cleanup(int $limit = 100): int
    {
        $this->cleanupLimits[] = $limit;

        return 3;
    }

    public function getName(): string
    {
        return 'cleanup-recording';
    }
}
