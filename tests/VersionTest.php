<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The version has to agree with itself.
 *
 * `VERSION` is what the packager and the installer read; `Kernel::VERSION` is
 * what the running site reports to the admin. If they disagree, an operator
 * looking at a broken deploy reads the wrong version and debugs the wrong code.
 * This test is the guard that makes editing one place and forgetting the other
 * impossible to ship.
 */
final class VersionTest extends TestCase
{
    public function testTheVersionFileIsSemver(): void
    {
        $version = $this->version();

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $version,
            'VERSION must be MAJOR.MINOR.PATCH. Pre-release suffixes are added to the tag, not here.',
        );
    }

    public function testTheRuntimeConstantMatchesTheVersionFile(): void
    {
        $this->assertSame(
            $this->version(),
            \Polaris\Http\Kernel::VERSION,
            'VERSION and Kernel::VERSION disagree. Run `php bin/version.php <version>`.',
        );
    }

    public function testNoOtherFileHardcodesTheVersion(): void
    {
        $version = $this->version();
        $root = dirname(__DIR__);

        // These files cannot read VERSION at runtime, so they either read
        // Kernel::VERSION or are rewritten by bin/version.php. A literal here
        // is a version that will still be old after the next bump.
        // Values that happen to look like SemVer but are not the product
        // version: the OpenAPI specification and the minimum PHP version.
        $files = [
            'src/Api/Registry.php' => ['3.1.0'],
            'src/Api/DocsController.php' => [],
            'src/Api/McpController.php' => [],
            'starter/config/definitions.php' => [],
            'install.php' => ['8.1.0'],
        ];

        foreach ($files as $relative => $allowed) {
            $contents = (string) file_get_contents($root . '/' . $relative);
            $this->assertNotSame('', $contents, "{$relative} is empty or unreadable.");

            // Only quoted literals: IP addresses like 127.0.0.1 are not
            // versions, and a bare number in an expression is not either.
            preg_match_all('/([\'"])(\d+\.\d+\.\d+)\1/', $contents, $matches);

            foreach ($matches[2] as $found) {
                if ($found === $version || in_array($found, $allowed, true)) {
                    continue;
                }

                $this->fail("{$relative} hardcodes version {$found}; expected {$version} or Kernel::VERSION.");
            }
        }
    }

    public function testTheChangelogMentionsTheCurrentVersion(): void
    {
        $changelog = dirname(__DIR__) . '/CHANGELOG.md';

        $this->assertFileExists($changelog, 'A release without a changelog entry is a release nobody can audit.');

        $contents = (string) file_get_contents($changelog);

        $this->assertStringContainsString(
            '## [' . $this->version() . ']',
            $contents,
            'Add a CHANGELOG entry for ' . $this->version() . ' before releasing.',
        );
    }

    public function testTheInstallerChecksumMatchesTheShippedRelease(): void
    {
        $root = dirname(__DIR__);
        $archive = $root . '/dist/polaris-site.zip';

        // `dist/` is git-ignored and only exists after packaging. CI runs the
        // suite before it builds the archive, so this guard is local-only.
        if (!is_file($archive)) {
            $this->markTestSkipped('No built release in dist/; run bin/package.php first.');
        }

        $installer = (string) file_get_contents($root . '/install.php');
        preg_match("/POLARIS_RELEASE_SHA256 = '([a-f0-9]{64})'/", $installer, $matches);

        $this->assertNotEmpty($matches, 'The installer must pin the release checksum.');
        $this->assertSame(
            (string) hash_file('sha256', $archive),
            $matches[1],
            'Rebuild the release with bin/package.php and repin POLARIS_RELEASE_SHA256 together.',
        );
    }

    private function version(): string
    {
        return trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'));
    }
}
