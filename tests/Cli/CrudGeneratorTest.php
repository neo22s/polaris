<?php

declare(strict_types=1);

namespace Polaris\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Polaris\Cli\CrudGenerator;
use RuntimeException;

/**
 * The scaffolding generator.
 *
 * Its value is not "it writes files" but "what it writes compiles, is safe, and
 * cannot quietly destroy work". So the tests check the generated PHP parses,
 * the SQL is idempotent and typed, and the generator refuses to overwrite a
 * file somebody edited.
 */
final class CrudGeneratorTest extends TestCase
{
    private string $site = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = sys_get_temp_dir() . '/polaris_gen_' . bin2hex(random_bytes(4));
        mkdir($this->site, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->site);

        parent::tearDown();
    }

    public function testItWritesTheFivePiecesAndAMigration(): void
    {
        $generator = new CrudGenerator(
            $this->site,
            'Invoice',
            'number:string!,client:belongs_to(clients),total:decimal,status:enum(draft|sent|paid),notes:text',
        );

        $files = $generator->files();

        $this->assertArrayHasKey('app/Repositories/InvoiceRepository.php', $files);
        $this->assertArrayHasKey('app/Api/InvoiceApi.php', $files);
        $this->assertArrayHasKey('app/Controllers/Admin/InvoiceController.php', $files);
        $this->assertArrayHasKey('resources/views/admin/invoices/index.twig', $files);
        $this->assertArrayHasKey('resources/views/admin/invoices/form.twig', $files);

        $migrationKey = array_values(array_filter(
            array_keys($files),
            static fn (string $path): bool => str_starts_with($path, 'database/upgrades/'),
        ))[0];

        $this->assertStringEndsWith('_create_invoices.sql', $migrationKey);
    }

    public function testEveryGeneratedPhpFileParses(): void
    {
        $generator = new CrudGenerator(
            $this->site,
            'Invoice',
            'number:string!,total:decimal,status:enum(draft|sent|paid),notes:text,published:bool,due_on:date',
        );

        foreach ($generator->files() as $relative => $contents) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }

            $path = $this->site . '/' . $relative;
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $contents);

            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);

            $this->assertSame(0, $code, $relative . " does not parse:\n" . implode("\n", $output));
        }
    }

    public function testTheMigrationIsIdempotentAndUsesTheDeclaredTypes(): void
    {
        $generator = new CrudGenerator(
            $this->site,
            'Invoice',
            'number:string!,total:decimal,status:enum(draft|sent|paid),published:bool,due_on:date,client:belongs_to(clients)',
        );

        $sql = $this->migrationOf($generator);

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `invoices`', $sql);
        $this->assertStringContainsString('`number` varchar(255) NOT NULL', $sql);
        $this->assertStringContainsString('`total` decimal(12,2) DEFAULT NULL', $sql);
        $this->assertStringContainsString("`status` enum('draft','sent','paid')", $sql);
        $this->assertStringContainsString('`published` tinyint(1)', $sql);
        $this->assertStringContainsString('`due_on` date', $sql);
        $this->assertStringContainsString('`client` int unsigned', $sql);
    }

    public function testTheApiGroupServesBothSurfaces(): void
    {
        $generator = new CrudGenerator($this->site, 'Invoice', 'number:string!');
        $api = $generator->files()['app/Api/InvoiceApi.php'];

        // Each CRUD operation is one `Operation::both`, which is what makes the
        // REST route and the MCP tool exist together.
        $this->assertSame(5, substr_count($api, 'Operation::both('), 'Five CRUD operations, each on both surfaces.');
        $this->assertStringContainsString("new ApiGroup('invoices'", $api);
        $this->assertStringNotContainsString("'request' =>", $api);
        $this->assertStringContainsString('Pagination::fromArgs', $api);
    }

    public function testItRefusesToOverwriteWithoutForce(): void
    {
        $generator = new CrudGenerator($this->site, 'Invoice', 'number:string!');
        $generator->write();

        $path = $this->site . '/app/Api/InvoiceApi.php';
        file_put_contents($path, '<?php // edited by hand');

        try {
            $generator->write();
            $this->fail('A second run must not overwrite the file.');
        } catch (RuntimeException | \InvalidArgumentException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }

        $this->assertSame('<?php // edited by hand', file_get_contents($path));

        $generator->write(true);
        $this->assertStringContainsString('Operation::both', (string) file_get_contents($path));
    }

    public function testAnUnreadableFieldIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CrudGenerator($this->site, 'Invoice', 'this is not a field');
    }

    public function testAnEnumWithoutValuesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CrudGenerator($this->site, 'Invoice', 'status:enum()');
    }

    public function testItDerivesNamesFromTheEntity(): void
    {
        $this->assertSame('InvoiceItem', CrudGenerator::studly('invoice item'));
        $this->assertSame('invoice_items', CrudGenerator::snake(CrudGenerator::pluralize('InvoiceItem')));
        $this->assertSame('categories', CrudGenerator::pluralize('category'));
        $this->assertSame('invoices', CrudGenerator::pluralize('invoice'));
        $this->assertSame('Invoice Item', CrudGenerator::humanize('InvoiceItem'));
    }

    private function migrationOf(CrudGenerator $generator): string
    {
        foreach ($generator->files() as $relative => $contents) {
            if (str_starts_with($relative, 'database/upgrades/')) {
                return $contents;
            }
        }

        $this->fail('The generator produced no migration.');
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
