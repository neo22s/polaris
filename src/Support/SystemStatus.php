<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Where the daily cron left things, so the admin can tell it is alive.
 *
 * The cron runs on a scheduler nobody can watch, so it drops a small JSON file
 * after each run. Without it the only way to know the job works would be SSH
 * access or reading the log with a file manager, which assumes a technical setup.
 */
final class SystemStatus
{
    public function __construct(
        private string $statusFile,
        private string $cacheDirectory,
    ) {
    }

    /**
     * Record the outcome of a run. Returns false when it could not be written,
     * which the cron reports but never treats as fatal.
     *
     * @param array<string,mixed> $data
     */
    public function record(array $data): bool
    {
        $directory = dirname($this->statusFile);

        if (!SafeFilesystem::makeDirectory($directory)) {
            return false;
        }

        $payload = array_merge($data, [
            'ran_at' => date('c'),
            'php' => PHP_VERSION,
        ]);

        $encoded = json_encode($payload);

        if ($encoded === false) {
            return false;
        }

        return SafeFilesystem::write($this->statusFile, $encoded, LOCK_EX) !== false;
    }

    /**
     * The last recorded run, or null when the cron has never run here.
     *
     * @return array<string,mixed>|null
     */
    public function read(): ?array
    {
        if (!is_file($this->statusFile)) {
            return null;
        }

        $contents = SafeFilesystem::read($this->statusFile);
        if ($contents === false || $contents === '') {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    /**
     * How long ago the cron last ran, in seconds. Null when it never ran.
     */
    public function secondsSinceLastRun(): ?int
    {
        $data = $this->read();

        if ($data === null || !isset($data['ran_at'])) {
            return null;
        }

        $timestamp = strtotime((string) $data['ran_at']);

        return $timestamp === false ? null : max(0, time() - $timestamp);
    }

    /**
     * Rough size of the cache directory, to show the sweep is keeping it bounded.
     *
     * @return array{files:int,bytes:int}
     */
    public function cacheStats(): array
    {
        $files = 0;
        $bytes = 0;

        foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $files++;
            $bytes += (int) SafeFilesystem::size($file);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * The absolute command a scheduler should run, for copy and paste.
     */
    public function cronCommand(string $scriptPath): string
    {
        // PHP_BINARY is the interpreter that is running this script, which is
        // exactly the one the scheduler should use.
        return PHP_BINARY . ' ' . $scriptPath;
    }
}
