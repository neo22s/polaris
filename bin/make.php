<?php

declare(strict_types=1);

/*
 * The scaffolding tool.
 *
 * Usage:
 *   php bin/make.php crud Invoice --fields="number:string!unique,client:belongs_to(clients),total:decimal,status:enum(draft|sent|paid),notes:text"
 *   php bin/make.php crud Invoice --fields="..." --diff      show the files, write nothing
 *   php bin/make.php crud Invoice --fields="..." --force     overwrite existing files
 *
 * Field types: string, text, int, belongs_to(table), decimal, bool, date,
 * datetime, enum(a|b). A trailing `!` marks the field as required.
 *
 * What it writes, from that one list:
 *   - a repository (SQL, paging, create/update/delete)
 *   - an API group (list/get/create/update/delete as REST *and* MCP tools)
 *   - an admin controller (list, form, save, delete)
 *   - two Twig templates
 *   - one idempotent SQL migration
 *
 * The output is ordinary code: readable, editable and diffable. Nothing is
 * registered anywhere else, and `--force` is the only way a file is replaced.
 */

use Polaris\Cli\CrudGenerator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$args = array_slice($argv ?? [], 1);
$command = $args[0] ?? '';

/*
 * Where to write.
 *
 * A kernel checkout is not a site: scaffolding controllers and templates into
 * `kernel/` would be lost on the next upgrade and would violate the one rule
 * the project has. The generator therefore writes to --site, or to the current
 * directory when it looks like a site, and refuses the kernel repository.
 */
$site = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--site=')) {
        $site = substr($arg, 7);
    }
}

$siteRoot = $site !== null ? (realpath($site) ?: $site) : getcwd();

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    fwrite(STDOUT, <<<TXT
    make crud — scaffold one entity

      php bin/make.php crud Invoice --fields="number:string!unique,client:belongs_to(clients),total:decimal,status:enum(draft|sent|paid),notes:text"

    Field types
      string            VARCHAR(255)              text      TEXT
      int               INT UNSIGNED              decimal   DECIMAL(12,2)
      bool              TINYINT(1)                date      DATE
      datetime          DATETIME                  enum(a|b) ENUM
      belongs_to(t)     INT UNSIGNED + relation marker

    A trailing ! makes the field required. The generator does not create the
    related table: belongs_to only records the intent.

    Flags
      --diff    print the files and exit
      --force   overwrite existing files

    TXT);
    exit(0);
}

// A kernel checkout has src/ and no app/: scaffolding there would be lost on
// the next upgrade and would break the one rule the project has.
$isKernelCheckout = is_dir($siteRoot . '/src') && !is_dir($siteRoot . '/app');

if ($siteRoot === false || $isKernelCheckout) {
    fwrite(STDERR, "Refusing to scaffold into the kernel repository.\n");
    fwrite(STDERR, "Run this from a site, or pass --site=/path/to/site.\n");
    exit(1);
}

$name = $args[1] ?? null;

if ($name === null || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
    fwrite(STDERR, "A valid entity name is required, for example Invoice.\n");
    exit(1);
}

$fields = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--fields=')) {
        $fields = substr($arg, 9);
    }
}

if ($fields === '') {
    fwrite(STDERR, "Missing --fields. Run with --help for the syntax.\n");
    exit(1);
}

try {
    $generator = new CrudGenerator($siteRoot, $name, $fields);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$diff = in_array('--diff', $args, true);
$force = in_array('--force', $args, true);

foreach ($generator->files() as $relative => $contents) {
    $exists = is_file($siteRoot . '/' . $relative);
    $marker = $exists ? ' [exists]' : '';

    if ($diff) {
        fwrite(STDOUT, "===== {$relative}{$marker}\n" . $contents . "\n");
        continue;
    }

    fwrite(STDOUT, ($exists ? 'overwrite ' : 'create    ') . $relative . "\n");
}

if ($diff) {
    exit(0);
}

try {
    $written = $generator->write($force);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("\n%d file(s) written.\n\nNext steps:\n", count($written)));
fwrite(STDOUT, "  1. Add the routes to app/routes.php:\n\n");
$plural = Polaris\Cli\CrudGenerator::snake(Polaris\Cli\CrudGenerator::pluralize($name));
$singular = Polaris\Cli\CrudGenerator::studly($name);
fwrite(STDOUT, "     \$app->group('/admin/{$plural}', function (RouteCollectorProxy \$group): void {\n");
fwrite(STDOUT, "         \$group->get('', \\App\\Controllers\\Admin\\{$singular}Controller::class . ':index');\n");
fwrite(STDOUT, "         \$group->get('/new', \\App\\Controllers\\Admin\\{$singular}Controller::class . ':newForm');\n");
fwrite(STDOUT, "         \$group->post('', \\App\\Controllers\\Admin\\{$singular}Controller::class . ':save');\n");
fwrite(STDOUT, "         \$group->get('/{id:[0-9]+}/edit', \\App\\Controllers\\Admin\\{$singular}Controller::class . ':edit');\n");
fwrite(STDOUT, "         \$group->post('/{id:[0-9]+}', \\App\\Controllers\\Admin\\{$singular}Controller::class . ':save');\n");
fwrite(STDOUT, "         \$group->post('/{id:[0-9]+}/delete', \\App\\Controllers\\Admin\\{$singular}Controller::class . ':delete');\n");
fwrite(STDOUT, "     })->add(AdminMiddleware::class);\n\n");
fwrite(STDOUT, "  2. Register the API group in app/Api/Groups.php:\n\n");
fwrite(STDOUT, "     {$singular}Api::group(\$container->get({$singular}Repository::class)),\n\n");
fwrite(STDOUT, "  3. Add the translation keys and run:\n\n     composer check\n");

