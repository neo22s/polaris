<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Deploy\DeployConfig;
use Polaris\Deploy\Packager;
use Polaris\Deploy\RemoteRunner;
use RuntimeException;

/**
 * The deploy and release tooling.
 *
 * These are the pieces that touch a live site and a production database, so
 * their failure modes are tested directly: a release that leaks a secret, an
 * archive that escapes its root, a migration runner that is guessable, and a
 * config that silently falls back to the wrong value.
 */
final class DeployTest extends TestCase
{
    private string $work = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->work = sys_get_temp_dir() . '/polaris_deploy_' . bin2hex(random_bytes(4));
        mkdir($this->work, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->work);

        parent::tearDown();
    }

    public function testTheArchiveExcludesSecretsAndDevelopmentFiles(): void
    {
        $this->write('index.php', '<?php');
        $this->write('app/Controller.php', '<?php');
        $this->write('.env', 'DB_PASSWORD=secret');
        $this->write('.deploy.env', 'FTP_PASS=secret');
        $this->write('tests/FooTest.php', '<?php');
        $this->write('var/cache/x', 'cache');
        $this->write('uploads/polas/a.webp', 'image');
        $this->write('dist/old.zip', 'zip');
        $this->write('.htaccess', 'RewriteEngine On');
        $this->write('debug.log', 'log');

        $path = $this->work . '/release.zip';
        $result = Packager::build($this->work, $path, '1.2.3');

        $names = $this->zipEntries($path);

        $this->assertContains('index.php', $names);
        $this->assertContains('app/Controller.php', $names);
        $this->assertContains('.htaccess', $names, 'The rewrite rules are required on the host.');
        $this->assertContains('MANIFEST.json', $names);
        $this->assertContains('VERSION', $names);

        foreach (['.env', '.deploy.env', 'tests/FooTest.php', 'var/cache/x', 'uploads/polas/a.webp', 'dist/old.zip', 'debug.log'] as $excluded) {
            $this->assertNotContains($excluded, $names, $excluded . ' must never be packaged.');
        }

        $this->assertGreaterThan(0, $result['files']);
        $this->assertSame(hash_file('sha256', $path), $result['sha256']);
    }

    public function testTheManifestListsEveryFileWithItsHash(): void
    {
        $this->write('index.php', '<?php // canonical');
        $this->write('app/A.php', '<?php // a');

        $path = $this->work . '/release.zip';
        Packager::build($this->work, $path, '9.9.9');

        $manifest = json_decode($this->readZipEntry($path, 'MANIFEST.json'), true);

        $this->assertSame('9.9.9', $manifest['version']);
        $this->assertArrayHasKey('index.php', $manifest['files']);
        $this->assertSame(hash('sha256', '<?php // canonical'), $manifest['files']['index.php']);
        $this->assertSame('9.9.9' . "\n", $this->readZipEntry($path, 'VERSION'));
    }

    public function testBuildingTwiceProducesTheSameFileSet(): void
    {
        $project = $this->work . '/project';
        mkdir($project, 0775, true);
        file_put_contents($project . '/a.php', '<?php');

        $first = $this->work . '/one.zip';
        $second = $this->work . '/two.zip';

        Packager::build($project, $first, '1.0.0');
        Packager::build($project, $second, '1.0.0');

        $this->assertSame($this->zipEntries($first), $this->zipEntries($second));
    }

    public function testAMissingDirectoryIsNotAnError(): void
    {
        $this->write('index.php', '<?php');

        $path = $this->work . '/release.zip';
        $result = Packager::build($this->work, $path, '1.0.0', ['index.php', 'does-not-exist']);

        $this->assertSame(1, $result['files']);
    }

    public function testTheDeployConfigNamesMissingValuesInsteadOfGuessing(): void
    {
        $config = DeployConfig::fromArray([
            'FTP_HOST' => 'ftp.example.com',
            'FTP_USER' => 'u123',
            'FTP_ROOT' => 'public_html',
        ]);

        $ftp = $config->ftp();

        $this->assertSame('ftp.example.com', $ftp['host']);
        $this->assertSame('/public_html', $ftp['root'], 'A missing leading slash is added.');
        $this->assertTrue($ftp['passive'], 'Passive mode is the safe default.');
        $this->assertFalse($ftp['ssl']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FTP_PASS');

        DeployConfig::fromArray(['FTP_HOST' => 'h', 'FTP_USER' => 'u'])->required('FTP_PASS');
    }

    public function testAMissingDeployFileFailsWithAnActionableMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.deploy.env');

        DeployConfig::fromFile($this->work . '/.deploy.env');
    }

    public function testTheMigrationRunnerRefusesRequestsWithoutTheToken(): void
    {
        $script = RemoteRunner::build(['0001_core.sql' => 'CREATE TABLE x (id int);'], 'a-secret-token');

        // The token check must come before anything else, including the unlink.
        $this->assertStringContainsString('hash_equals(\'a-secret-token\'', $script);
        $this->assertStringContainsString("http_response_code(403)", $script);

        $tokenPosition = (int) strpos($script, 'hash_equals');
        $unlinkPosition = (int) strpos($script, '@unlink(__FILE__)');

        $this->assertLessThan($unlinkPosition, $tokenPosition, 'The token is checked before anything runs.');
    }

    public function testTheMigrationRunnerCarriesTheSqlAndDeletesItself(): void
    {
        $script = RemoteRunner::build(['0001_core.sql' => "CREATE TABLE polite (id int);"], 'tok');

        $this->assertStringContainsString('CREATE TABLE polite', $script);
        $this->assertStringContainsString('@unlink(__FILE__)', $script);
        // It must not assume the SQL files were uploaded.
        $this->assertStringNotContainsString("glob(", $script);
    }

    public function testCollectReadsOnlySqlFilesAndSortsThem(): void
    {
        $directory = $this->work . '/upgrades';
        mkdir($directory, 0775, true);

        file_put_contents($directory . '/0002_b.sql', 'SELECT 2;');
        file_put_contents($directory . '/0001_a.sql', 'SELECT 1;');
        file_put_contents($directory . '/README.md', 'not sql');

        $migrations = RemoteRunner::collect([$directory]);

        $this->assertSame(['0001_a.sql', '0002_b.sql'], array_keys($migrations));
        $this->assertSame('SELECT 1;', $migrations['0001_a.sql']);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->work . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $contents);
    }

    /** @return array<int,string> */
    private function zipEntries(string $path): array
    {
        $zip = new \ZipArchive();
        $zip->open($path);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }

    private function readZipEntry(string $path, string $entry): string
    {
        $zip = new \ZipArchive();
        $zip->open($path);
        $contents = (string) $zip->getFromName($entry);
        $zip->close();

        return $contents;
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }
}
