<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The version command edits several files at once. A precedence slip there
 * used to make `bump` accept a corrupt VERSION and write an invalid one, with no
 * test to catch it. These tests run the real script against a throwaway copy of
 * the repository layout so the working tree is never touched.
 */
final class VersionCommandTest extends TestCase
{
    private string $sandbox = '';

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->removeDirectory($this->sandbox);
        }
    }

    public function testBumpRejectsACorruptVersionFile(): void
    {
        $this->sandbox = $this->makeSandbox('not-a-version');

        [$code, , $error] = $this->runVersion('bump', 'patch');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not SemVer', $error);
        $this->assertSame('not-a-version', trim((string) file_get_contents($this->sandbox . '/VERSION')));
        $this->assertStringContainsString(
            "VERSION = '0.0.0'",
            (string) file_get_contents($this->sandbox . '/src/Http/Kernel.php'),
        );
    }

    public function testBumpUpdatesEveryMirrorOfTheVersion(): void
    {
        $this->sandbox = $this->makeSandbox('0.1.0');

        [$code, $output] = $this->runVersion('bump', 'minor');

        $this->assertSame(0, $code, $output);
        $this->assertSame('0.2.0', trim((string) file_get_contents($this->sandbox . '/VERSION')));
        $this->assertStringContainsString(
            "VERSION = '0.2.0'",
            (string) file_get_contents($this->sandbox . '/src/Http/Kernel.php'),
        );
        $this->assertStringContainsString(
            "POLARIS_INSTALLER_VERSION = '0.2.0'",
            (string) file_get_contents($this->sandbox . '/install.php'),
        );
    }

    public function testAnInvalidTargetIsRejectedWithoutWritingAnything(): void
    {
        $this->sandbox = $this->makeSandbox('0.1.0');

        [$code, , $error] = $this->runVersion('1.2');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Not a SemVer version', $error);
        $this->assertSame('0.1.0', trim((string) file_get_contents($this->sandbox . '/VERSION')));
    }

    /** @return array{int,string,string} */
    private function runVersion(string ...$args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->sandbox . '/bin/version.php');

        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

        $this->assertIsResource($process);

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [$code, (string) $output, (string) $error];
    }

    private function makeSandbox(string $version): string
    {
        $root = sys_get_temp_dir() . '/polaris-version-' . bin2hex(random_bytes(6));

        mkdir($root . '/bin', 0700, true);
        mkdir($root . '/src/Http', 0700, true);

        file_put_contents($root . '/VERSION', $version . "\n");
        file_put_contents($root . '/src/Http/Kernel.php', "<?php\nclass Kernel { public const VERSION = '0.0.0'; }\n");
        file_put_contents($root . '/install.php', "<?php\nconst POLARIS_INSTALLER_VERSION = '0.0.0';\n");
        copy(dirname(__DIR__) . '/bin/version.php', $root . '/bin/version.php');

        return $root;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
