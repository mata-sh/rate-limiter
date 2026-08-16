<?php

declare(strict_types=1);

namespace MataSh\RateLimiter\Tests\Storage;

use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\Storage\FileStorage;
use MataSh\RateLimiter\Storage\StorageException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class FileStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mata-rate-limiter-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (scandir($this->directory) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->directory . '/' . $file);
            }
        }
        rmdir($this->directory);
    }

    public function testConfiguredLimitAboveOneHundredRetainsAllRequests(): void
    {
        $limiter = new RateLimiter(new FileStorage($this->directory));

        for ($request = 0; $request < 150; ++$request) {
            self::assertTrue($limiter->allow('user', 150, 60));
        }

        self::assertFalse($limiter->allow('user', 150, 60));
        self::assertSame(150, $limiter->check('user', 150, 60)['current']);
    }

    public function testSharedFileStorageRespectsTheLimit(): void
    {
        $first = new RateLimiter(new FileStorage($this->directory));
        $second = new RateLimiter(new FileStorage($this->directory));

        self::assertTrue($first->allow('user', 1, 60));
        self::assertFalse($second->allow('user', 1, 60));
    }

    public function testExistingDirectoryPermissionsArePreserved(): void
    {
        self::assertTrue(mkdir($this->directory, 0770, true));
        self::assertTrue(chmod($this->directory, 0770));
        clearstatcache(true, $this->directory);

        $mode = fileperms($this->directory);
        self::assertIsInt($mode);
        self::assertSame(0770, $mode & 0777);

        new FileStorage($this->directory);
        clearstatcache(true, $this->directory);

        $mode = fileperms($this->directory);
        self::assertIsInt($mode);
        self::assertSame(0770, $mode & 0777);
    }

    public function testResetRemovesOneFileStorageIdentifier(): void
    {
        $limiter = new RateLimiter(new FileStorage($this->directory));

        self::assertTrue($limiter->allow('user', 1, 60));
        $limiter->reset('user');
        $limiter->reset('user');

        self::assertSame(0, $limiter->check('user', 1, 60)['current']);
    }

    public function testCleanupRemovesOnlyBoundedExpiredFiles(): void
    {
        $storage = new FileStorage($this->directory);
        $now = time() - 1;

        for ($file = 0; $file < 3; ++$file) {
            file_put_contents(
                $this->directory . '/' . hash('sha256', 'rate_limit_expired-' . $file) . '.json',
                json_encode(['timestamps' => [], 'expires_at' => $now], JSON_THROW_ON_ERROR)
            );
        }

        self::assertSame(2, $storage->cleanup(2));
        self::assertCount(1, glob($this->directory . '/*.json') ?: []);
    }

    public function testCleanupUsesDefaultLimitWhenArgumentIsOmitted(): void
    {
        $storage = new FileStorage($this->directory);
        file_put_contents(
            $this->directory . '/' . hash('sha256', 'rate_limit_expired') . '.json',
            json_encode(['timestamps' => [], 'expires_at' => time() - 1], JSON_THROW_ON_ERROR)
        );

        self::assertSame(1, $storage->cleanup());
    }

    public function testInvalidStoredStateThrowsInsteadOfAllowingTheRequest(): void
    {
        $storage = new FileStorage($this->directory);
        file_put_contents(
            $this->directory . '/' . hash('sha256', 'rate_limit_user') . '.json',
            '{invalid'
        );

        $this->expectException(StorageException::class);
        $storage->consume('rate_limit_user', 1, 60);
    }
}
