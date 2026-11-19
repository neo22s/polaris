<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Minimal file-based cache for shared hosting (no Redis/Memcached required).
 * Values are serialized to individual files with an expiry timestamp.
 */
final class Cache
{
    /** A write triggers a sweep at most this often, as a safety net. */
    private const SWEEP_INTERVAL = 3600;

    /** The file that remembers when the last sweep happened. */
    private const SWEEP_MARKER = 'swept.marker';

    public function __construct(
        private string $directory,
        private bool $enabled = true,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->read($key);

        return $entry['found'] ? $entry['value'] : $default;
    }

    /**
     * Read a cached entry.
     *
     * A cached null is a value, not a miss, so the caller can tell the two
     * apart. This is why the payload is read with array_key_exists(): isset()
     * would report a cached null as a corrupt file and delete it.
     *
     * @return array{found:bool,value:mixed}
     */
    private function read(string $key): array
    {
        if (!$this->enabled) {
            return ['found' => false, 'value' => null];
        }

        $file = $this->path($key);
        if (!is_file($file)) {
            return ['found' => false, 'value' => null];
        }

        $contents = SafeFilesystem::read($file);
        if ($contents === false || $contents === '') {
            return ['found' => false, 'value' => null];
        }

        // Only ever deserialize arrays/scalars: never allow object instantiation.
        // The @ is deliberate: a truncated cache file is an expected corrupt
        // entry, not an error worth a warning — it is deleted just below.
        $payload = @unserialize($contents, ['allowed_classes' => false]);
        if (!is_array($payload) || !array_key_exists('expires', $payload) || !array_key_exists('value', $payload)) {
            SafeFilesystem::delete($file);

            return ['found' => false, 'value' => null];
        }

        if ($payload['expires'] !== 0 && $payload['expires'] < time()) {
            SafeFilesystem::delete($file);

            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $payload['value']];
    }

    public function put(string $key, mixed $value, int $ttl = 300): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->ensureDirectory();

        // The daily cron is the main sweep, but a cron that was never configured
        // must not be able to grow the directory forever, so a write cleans up
        // after the ones nobody reads again. At most once an hour, so a write
        // never deletes a file another request is about to read.
        if ($this->sweepIsDue()) {
            $this->prune();
        }

        $payload = serialize([
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value' => $value,
        ]);

        $file = $this->path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (SafeFilesystem::write($tmp, $payload, LOCK_EX) !== false) {
            SafeFilesystem::move($tmp, $file);
        } else {
            SafeFilesystem::delete($tmp);
        }
    }

    /**
     * Whether the directory is due for a sweep.
     *
     * The first write only marks the time, so a fresh directory is never
     * swept in the middle of a request.
     */
    private function sweepIsDue(): bool
    {
        $marker = $this->directory . '/' . self::SWEEP_MARKER;

        if (!is_file($marker)) {
            SafeFilesystem::touch($marker);

            return false;
        }

        $last = (int) SafeFilesystem::modifiedAt($marker);

        if ($last + self::SWEEP_INTERVAL > time()) {
            return false;
        }

        SafeFilesystem::touch($marker);

        return true;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $entry = $this->read($key);

        if ($entry['found']) {
            return $entry['value'];
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function forget(string $key): void
    {
        SafeFilesystem::delete($this->path($key));
    }

    /**
     * A stable per-scope version string used to invalidate groups of keys.
     */
    public function version(string $scope): string
    {
        $key = 'version.' . $scope;
        $entry = $this->read($key);

        if (!$entry['found'] || !is_string($entry['value']) || $entry['value'] === '') {
            $version = '1';
            $this->put($key, $version, 0);

            return $version;
        }

        return $entry['value'];
    }

    /**
     * Move a scope to a new version.
     *
     * The version has to change on every bump, including two bumps in the
     * same second, so a stale payload can never survive an invalidation.
     */
    public function bump(string $scope): void
    {
        $key = 'version.' . $scope;
        $entry = $this->read($key);
        $current = $entry['found'] && is_string($entry['value']) ? $entry['value'] : '';

        $this->put($key, $this->nextVersion($current), 0);
    }

    /**
     * A version string that is strictly greater than the given one.
     *
     * The version is a timestamp plus a counter, so bumping twice inside the
     * same second still produces a different version.
     */
    private function nextVersion(string $current): string
    {
        $seconds = time();
        $counter = 1;

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $current, $matches) === 1) {
            $seconds = max($seconds, (int) $matches[1]);
            $counter = isset($matches[2]) ? (int) $matches[2] + 1 : 1;
        }

        return $seconds . '.' . str_pad((string) $counter, 10, '0', STR_PAD_LEFT);
    }

    /**
     * Drop cached files nobody has touched in a while.
     *
     * An expired file is only unlinked when its exact key is requested again
     * (see get()), so a key that is never asked for again — a newer version of a
     * profile, a one-off search filter — stays on disk forever. This sweep
     * reclaims those.
     *
     * Files are chosen by their last write, not by their expiry, because every
     * entry here is cheap to rebuild: at worst we recompute it. Entries with no
     * expiry at all are left alone: those are the version markers, and resetting
     * one to "1" could make an old payload cached under that same key look
     * current.
     *
     * @param int $olderThan Seconds since the last write after which a file goes.
     * @return int Number of files deleted.
     */
    public function prune(int $olderThan = 86400): int
    {
        if (!$this->enabled || $olderThan < 1) {
            return 0;
        }

        if (!is_dir($this->directory)) {
            return 0;
        }

        $cutoff = time() - $olderThan;
        // A write takes milliseconds, so a temporary file an hour old belongs to
        // a put() that died before its rename and will never be used again.
        $tmpCutoff = time() - 3600;
        $deleted = 0;

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = SafeFilesystem::modifiedAt($file);
            if ($mtime === false) {
                continue;
            }

            $name = basename($file);

            // A put() that died before the rename leaves a temporary behind.
            if (str_contains($name, '.tmp')) {
                if ($mtime < $tmpCutoff && SafeFilesystem::delete($file)) {
                    $deleted++;
                }

                continue;
            }

            if ($mtime >= $cutoff) {
                continue;
            }

            if (!str_ends_with($name, '.cache')) {
                continue;
            }

            $payload = @unserialize((string) SafeFilesystem::read($file), ['allowed_classes' => false]);
            if (is_array($payload) && ($payload['expires'] ?? null) === 0) {
                continue;
            }

            if (SafeFilesystem::delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            SafeFilesystem::makeDirectory($this->directory);
        }
    }
}
