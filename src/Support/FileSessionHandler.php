<?php

declare(strict_types=1);

namespace Polaris\Support;

use \SessionHandlerInterface;

/**
 * Session storage in var/sessions with an explicit, long lifetime.
 *
 * PHP's default file handler keeps sessions in a shared /tmp that the host may
 * clean at any time, and it is tied to session.gc_maxlifetime (24 min by
 * default). Neither suits "stay signed in for a year", so sessions are stored
 * per application and expire on our own terms.
 */
final class FileSessionHandler implements SessionHandlerInterface
{
    private const PREFIX = 'sess_';

    /** Session ids are PHP-generated: hex, base64 or the "read()" separator. */
    private const ID_PATTERN = '/^[A-Za-z0-9,-]{16,128}$/';

    public function __construct(
        private string $directory,
        private int $lifetime,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return $this->ensureDirectory();
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        if (!$this->isValidId($id)) {
            return '';
        }

        $file = $this->path($id);

        if (!is_file($file)) {
            return '';
        }

        // Expired by our own clock, not by the host's cron.
        if ($this->lifetime > 0 && (int) SafeFilesystem::modifiedAt($file) + $this->lifetime < time()) {
            SafeFilesystem::delete($file);

            return '';
        }

        // Shared locking, so a reader never sees a half written session. The
        // @ on the stream calls is deliberate: a file can vanish between the
        // is_file() above and fopen(), and locking is best-effort on some
        // network filesystems. Every one of these results is already checked.
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            @flock($handle, LOCK_SH);
            $contents = stream_get_contents($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }

        return $contents === false ? '' : $contents;
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        $file = $this->path($id);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        // Write to a temporary file and rename it into place: a reader either
        // sees the previous session or the new one, never a partial write.
        if (SafeFilesystem::write($tmp, $data, LOCK_EX) === false) {
            SafeFilesystem::delete($tmp);

            return false;
        }

        SafeFilesystem::chmod($tmp, 0600);

        if (!SafeFilesystem::move($tmp, $file)) {
            SafeFilesystem::delete($tmp);

            return false;
        }

        SafeFilesystem::chmod($file, 0600);

        return true;
    }

    public function destroy(string $id): bool
    {
        if (!$this->isValidId($id)) {
            return true;
        }

        $file = $this->path($id);

        return !is_file($file) || SafeFilesystem::delete($file);
    }

    /**
     * @param int $maxLifetime Provided by PHP from session.gc_maxlifetime.
     */
    public function gc(int $maxLifetime): int
    {
        $lifetime = $maxLifetime > 0 ? $maxLifetime : $this->lifetime;
        $cutoff = time() - $lifetime;
        $deleted = 0;

        foreach (glob($this->directory . '/' . self::PREFIX . '*') ?: [] as $file) {
            if (is_file($file) && SafeFilesystem::modifiedAt($file) < $cutoff && SafeFilesystem::delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Remove every stored session (used by the cache log cleaner and tests).
     *
     * @return int Files deleted.
     */
    public function clear(): int
    {
        $deleted = 0;

        foreach (glob($this->directory . '/' . self::PREFIX . '*') ?: [] as $file) {
            if (is_file($file) && SafeFilesystem::delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . self::PREFIX . $id;
    }

    private function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            $this->restrictDirectory();

            return true;
        }

        // The session files hold the signed-in identity and the CSRF token,
        // so the directory is only readable by the web server user.
        if (!SafeFilesystem::makeDirectory($this->directory, 0700)) {
            return false;
        }

        $this->restrictDirectory();

        return true;
    }

    /**
     * Tighten an existing directory that was created with looser permissions.
     */
    private function restrictDirectory(): void
    {
        $mode = SafeFilesystem::permissions($this->directory);

        if ($mode !== false && ($mode & 0777) !== 0700) {
            SafeFilesystem::chmod($this->directory, 0700);
        }
    }
}
