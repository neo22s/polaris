<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\Cache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/modelpolas_cache_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testTheSweepMarkerIsNotACacheEntry(): void
    {
        $cache = new Cache($this->dir);
        $cache->put('k', 'v', 60);

        // The marker is not an entry, so it is never served and never pruned.
        $this->assertSame('v', $cache->get('k'));
        $this->assertNull($cache->get('swept.marker'));
    }

    public function testPutAndGet(): void
    {
        $cache = new Cache($this->dir);
        $cache->put('k', ['a' => 1], 60);

        $this->assertSame(['a' => 1], $cache->get('k'));
        $this->assertNull($cache->get('missing'));
        $this->assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function testRememberCallsOnce(): void
    {
        $cache = new Cache($this->dir);
        $calls = 0;
        $callback = function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        $this->assertSame('value', $cache->remember('r', 60, $callback));
        $this->assertSame('value', $cache->remember('r', 60, $callback));
        $this->assertSame(1, $calls);
    }

    public function testForget(): void
    {
        $cache = new Cache($this->dir);
        $cache->put('k', 'v', 60);
        $cache->forget('k');

        $this->assertNull($cache->get('k'));
    }

    public function testVersionAndBump(): void
    {
        $cache = new Cache($this->dir);
        $first = $cache->version('profile.1');
        $this->assertSame('1', $first);
        $this->assertSame($first, $cache->version('profile.1'));

        $cache->bump('profile.1');
        $this->assertNotSame($first, $cache->version('profile.1'));
    }

    public function testTwoBumpsInTheSameSecondChangeTheVersion(): void
    {
        $cache = new Cache($this->dir);
        $cache->bump('profile.1');
        $first = $cache->version('profile.1');

        // A second bump right away must still move the version on, or the
        // payload cached under the first version would survive.
        $cache->bump('profile.1');
        $second = $cache->version('profile.1');

        $this->assertNotSame($first, $second);
    }

    public function testRememberCachesANullValue(): void
    {
        $cache = new Cache($this->dir);
        $calls = 0;
        $callback = function () use (&$calls): ?string {
            $calls++;

            return null;
        };

        $this->assertNull($cache->remember('r', 60, $callback));
        $this->assertNull($cache->remember('r', 60, $callback));
        $this->assertSame(1, $calls, 'A cached null must not be recomputed on every read.');
    }

    public function testGetReturnsACachedNullInsteadOfTheDefault(): void
    {
        $cache = new Cache($this->dir);
        $cache->put('k', null, 60);

        $this->assertNull($cache->get('k', 'fallback'));
    }

    public function testDisabledCache(): void
    {
        $cache = new Cache($this->dir, false);
        $cache->put('k', 'v', 60);

        $this->assertNull($cache->get('k'));
    }

    public function testPruneDropsUntouchedFilesAndKeepsRecentOnes(): void
    {
        $cache = new Cache($this->dir);
        $cache->put('old', 'v', 60);
        $cache->put('new', 'v', 60);

        // Only the "old" entry has gone untouched for longer than the window.
        touch($this->cacheFile('old'), time() - 90000);

        $this->assertSame(1, $cache->prune(86400));
        $this->assertNull($cache->get('old'));
        $this->assertSame('v', $cache->get('new'));
    }

    public function testPruneIgnoresExpiryOfTheEntryItself(): void
    {
        $cache = new Cache($this->dir);
        // Long TTL, but untouched for a week: prune is about writes, not TTL.
        $cache->put('long', 'v', 604800);
        touch($this->cacheFile('long'), time() - 604800);

        $this->assertSame(1, $cache->prune());
        $this->assertNull($cache->get('long'));
    }

    public function testPruneKeepsVersionMarkers(): void
    {
        $cache = new Cache($this->dir);
        $version = $cache->version('profile.7');

        touch($this->cacheFile('version.profile.7'), time() - 86400 * 30);

        $this->assertSame(0, $cache->prune());
        // The marker survives, so the version has not silently reset to "1".
        $this->assertSame($version, $cache->version('profile.7'));
    }

    public function testPruneRemovesHalfWrittenTemporaryFiles(): void
    {
        $cache = new Cache($this->dir);
        $cache->put('k', 'v', 60);

        $leftover = $this->dir . '/deadbeef.tmp';
        file_put_contents($leftover, 'half written');
        touch($leftover, time() - 7200);

        $this->assertSame(1, $cache->prune());
        $this->assertFileDoesNotExist($leftover);
        // The real entry is untouched.
        $this->assertSame('v', $cache->get('k'));
    }

    public function testPruneOnADisabledCacheDoesNothing(): void
    {
        $cache = new Cache($this->dir, false);
        $this->assertSame(0, $cache->prune());
    }

    public function testPruneOnAMissingDirectoryDoesNothing(): void
    {
        $cache = new Cache($this->dir . '/nope');
        $this->assertSame(0, $cache->prune());
    }

    public function testPruneLeavesUnrelatedFilesAlone(): void
    {
        $cache = new Cache($this->dir);
        $keep = $this->dir . '/README.md';
        file_put_contents($keep, 'not ours');
        touch($keep, time() - 86400 * 30);

        $this->assertSame(0, $cache->prune());
        $this->assertFileExists($keep);
    }

    /** Local stand-in for the private Cache::path(), so a test can age a file. */
    private function cacheFile(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.cache';
    }
}
