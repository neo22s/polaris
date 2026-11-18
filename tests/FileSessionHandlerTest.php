<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\FileSessionHandler;
use PHPUnit\Framework\TestCase;

/**
 * Session storage for the "stay signed in for a year" cookie: lives in
 * var/sessions and expires on its own terms rather than on PHP's 24-minute
 * session.gc_maxlifetime.
 */
final class FileSessionHandlerTest extends TestCase
{
    private string $dir;

    /** A syntactically valid PHP session id. */
    private string $id = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mp_sess_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->dir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }

        foreach (glob($dir . '/*') ?: [] as $path) {
            $this->deleteDir($path);
        }

        @rmdir($dir);
    }

    private function handler(int $lifetime = 31536000): FileSessionHandler
    {
        return new FileSessionHandler($this->dir, $lifetime);
    }

    private function file(string $id): string
    {
        return $this->dir . '/sess_' . $id;
    }

    public function testRoundTripsData(): void
    {
        $this->assertTrue($this->handler()->write($this->id, 'payload'));
        $this->assertSame('payload', $this->handler()->read($this->id));
        $this->assertFileExists($this->file($this->id));
    }

    public function testOpenAndCloseSucceed(): void
    {
        $handler = $this->handler();
        $this->assertTrue($handler->open('/tmp', 'sessions'));
        $this->assertTrue($handler->close());
    }

    public function testDestroyRemovesTheFile(): void
    {
        $handler = $this->handler();
        $handler->write($this->id, 'payload');

        $this->assertTrue($handler->destroy($this->id));
        $this->assertFileDoesNotExist($this->file($this->id));
        // Destroying something that is already gone is not an error.
        $this->assertTrue($handler->destroy($this->id));
    }

    public function testReadsPastTheLifetimeAreDiscarded(): void
    {
        $handler = $this->handler(3600);
        $handler->write($this->id, 'payload');

        // A session idle for longer than the configured window is gone, even
        // though the file is still on disk.
        touch($this->file($this->id), time() - 3601);

        $this->assertSame('', $handler->read($this->id));
        $this->assertFileDoesNotExist($this->file($this->id));
    }

    public function testAZeroLifetimeNeverExpires(): void
    {
        $handler = $this->handler(0);
        $handler->write($this->id, 'payload');
        touch($this->file($this->id), time() - 86400 * 400);

        $this->assertSame('payload', $handler->read($this->id));
    }

    public function testGarbageCollectionRemovesOnlyExpiredFiles(): void
    {
        $handler = $this->handler(3600);
        $handler->write(str_repeat('a', 32), 'old');
        $handler->write(str_repeat('b', 32), 'new');
        touch($this->file(str_repeat('a', 32)), time() - 7200);

        $this->assertSame(1, $handler->gc(3600));
        $this->assertSame(['sess_' . str_repeat('b', 32)], $this->files());
    }

    public function testClearWipesEveryStoredSession(): void
    {
        $handler = $this->handler();
        $handler->write(str_repeat('a', 32), 'x');
        $handler->write(str_repeat('b', 32), 'y');
        $this->assertCount(2, $this->files());

        $this->assertSame(2, $handler->clear());
        $this->assertSame([], $this->files());
    }

    public function testUnexpectedSessionIdsAreRefused(): void
    {
        $handler = $this->handler();

        // The directory lives outside the web root, but never trust an id.
        foreach (['../../etc/passwd', '', 'abc', str_repeat('z', 300)] as $unsafe) {
            $this->assertSame('', $handler->read($unsafe), "read('{$unsafe}')");
            $this->assertFalse($handler->write($unsafe, 'payload'), "write('{$unsafe}')");
        }
    }

    public function testDataIsWrittenWithExclusiveLocking(): void
    {
        $handler = $this->handler();
        $this->assertTrue($handler->write($this->id, 'x'));
        $this->assertTrue($handler->write($this->id, 'y'));
        $this->assertSame('y', $handler->read($this->id));
    }

    public function testSessionFilesAreNotReadableByOtherUsers(): void
    {
        $handler = $this->handler();
        $handler->write($this->id, 'payload');

        $mode = fileperms($this->file($this->id));

        $this->assertNotFalse($mode);
        // 0600: only the owner of the web server may read the session.
        $this->assertSame('0600', substr(sprintf('%o', $mode), -4));
    }

    public function testTheSessionDirectoryIsNotGroupOrWorldAccessible(): void
    {
        $dir = $this->dir . '/nested';
        $handler = new FileSessionHandler($dir, 31536000);

        $this->assertTrue($handler->open('/tmp', 'sessions'));

        $mode = fileperms($dir);
        $this->assertNotFalse($mode);
        $this->assertSame('0700', substr(sprintf('%o', $mode), -4));
    }

    public function testAWriteLeavesNoTemporaryFileBehind(): void
    {
        $handler = $this->handler();
        $handler->write($this->id, 'payload');

        $this->assertSame(['sess_' . $this->id], $this->files());
    }

    public function testAWriteReplacesTheFileInOneStep(): void
    {
        $handler = $this->handler();
        $handler->write($this->id, 'first');

        $before = fileinode($this->file($this->id));
        $handler->write($this->id, 'second');

        // The file was replaced, not truncated in place: a reader that opens
        // it always sees one complete version.
        $this->assertSame('second', $handler->read($this->id));
        $this->assertNotSame($before, fileinode($this->file($this->id)), 'The file was replaced, not written in place.');
        $this->assertSame(['sess_' . $this->id], $this->files());
    }

    private function files(): array
    {
        return array_values(array_filter(
            array_map('basename', glob($this->dir . '/*') ?: []),
            static fn (string $name): bool => $name !== '.gitkeep',
        ));
    }
}
