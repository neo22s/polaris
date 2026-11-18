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

    private function version(): string
    {
        return trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'));
    }
}
