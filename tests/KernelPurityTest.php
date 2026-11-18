<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards the boundary between the kernel and an application.
 *
 * Two leaks matter:
 *
 * 1. The kernel referencing an application namespace. If kernel code can call
 *    `App\...`, then "the framework" only works with one site.
 * 2. The kernel knowing a specific site's brand or vocabulary.
 *
 * Generic concepts that applications also use (a profile hook, a reminder)
 * are fine: an extension point with that name is part of the architecture. The
 * test therefore only fails on brand/domain words and on App namespace usage.
 */
final class KernelPurityTest extends TestCase
{
    /**
     * Words that belong to a specific site, not to the kernel.
     *
     * @var array<int,string>
     */
    private const FORBIDDEN = [
        'modelpolas',
        'pola',
        'polas',
    ];

    /**
     * Files whose job is to emit application code.
     *
     * The scaffolder contains `App\...` inside string templates on purpose:
     * the output belongs to the site. It is the one place where referencing
     * that namespace is correct, so it is named here rather than loosening the
     * check for everything.
     *
     * @var array<int,string>
     */
    private const EMITS_APPLICATION_CODE = [
        'src/Cli/CrudGenerator.php',
    ];

    public function testKernelDoesNotReferenceTheApplicationNamespace(): void
    {
        $root = dirname(__DIR__);
        $violations = [];

        foreach ($this->phpFiles($root . '/src') as $file) {
            $relative = str_replace($root . '/', '', $file);

            if (in_array($relative, self::EMITS_APPLICATION_CODE, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            // `use App\...` or a fully qualified `\App\...` reference.
            if (preg_match('/^(?:use|\s*\\\\?)\s*App\\\\/m', $contents) === 1
                || str_contains($contents, '\\App\\')) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "The kernel must not depend on the application namespace:\n" . implode("\n", $violations),
        );
    }

    public function testKernelDoesNotMentionTheProductBrand(): void
    {
        $root = dirname(__DIR__);
        $violations = [];

        foreach ($this->phpFiles($root . '/src') as $file) {
            $relative = str_replace($root . '/', '', $file);
            $contents = (string) file_get_contents($file);
            $word = $this->mentionsForbiddenWord($contents);

            if ($word !== null) {
                $violations[] = $relative . ' mentions "' . $word . '"';
            }
        }

        $this->assertSame(
            [],
            $violations,
            "The kernel must stay brand free:\n" . implode("\n", $violations),
        );
    }

    public function testKernelViewsAndLanguageFilesShipWithThePackage(): void
    {
        $root = dirname(__DIR__);

        $this->assertDirectoryExists($root . '/resources/views');
        $this->assertFileExists($root . '/resources/views/layout.twig');
        $this->assertFileExists($root . '/resources/views/errors/404.twig');
        $this->assertFileExists($root . '/resources/lang/en.php');
    }

    public function testEveryLanguageFileHasTheSameKeys(): void
    {
        $root = dirname(__DIR__);
        $files = glob($root . '/resources/lang/*.php') ?: [];
        $this->assertNotEmpty($files);

        $reference = null;
        $referenceName = '';

        foreach ($files as $file) {
            /** @var array<string,string> $messages */
            $messages = require $file;
            $keys = array_keys($messages);
            sort($keys);

            if ($reference === null) {
                $reference = $keys;
                $referenceName = basename($file);
                continue;
            }

            $this->assertSame(
                $reference,
                $keys,
                sprintf('%s and %s have different translation keys.', $referenceName, basename($file)),
            );
        }
    }

    /** @return array<int,string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function mentionsForbiddenWord(string $contents): ?string
    {
        foreach (self::FORBIDDEN as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $contents) === 1) {
                return $word;
            }
        }

        return null;
    }
}
