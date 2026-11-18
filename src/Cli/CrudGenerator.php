<?php

declare(strict_types=1);

namespace Polaris\Cli;

use InvalidArgumentException;

/**
 * Generates the five files an entity needs, all from one field list.
 *
 * The generated code is ordinary code: a repository, an API group, an admin
 * controller, two Twig templates and an idempotent SQL file. Nothing is
 * registered in a hidden place, nothing is discovered at runtime. You can read
 * it, edit it, and `git diff` it.
 *
 * The generator is deliberately boring: it writes files, refuses to overwrite
 * without `--force`, and prints what it would do with `--diff`. A generator that
 * silently rewrites a file somebody hand-edited is worse than no generator.
 *
 * Fields are declared as `name:type`:
 *
 *   title:string!          required string
 *   body:text              long text
 *   price:decimal          DECIMAL(10,2)
 *   published:bool
 *   status:enum(draft|live)
 *   client:belongs_to(clients)
 */
final class CrudGenerator
{
    /** @var array<int,array{name:string,type:string,required:bool,options:array<int,string>,target:?string}> */
    private array $fields = [];

    private string $table;

    private string $singular;

    private string $plural;

    public function __construct(
        private string $siteRoot,
        string $name,
        string $fields,
    ) {
        $this->singular = self::studly($name);
        $this->plural = self::pluralize($name);
        $this->table = self::snake($this->plural);
        $this->fields = self::parseFields($fields);
    }

    /**
     * Every file the generator would write, relative path => contents.
     *
     * @return array<string,string>
     */
    public function files(): array
    {
        return [
            'app/Repositories/' . $this->singular . 'Repository.php' => $this->repository(),
            'app/Api/' . $this->singular . 'Api.php' => $this->apiGroup(),
            'app/Controllers/Admin/' . $this->singular . 'Controller.php' => $this->controller(),
            'resources/views/admin/' . $this->table . '/index.twig' => $this->indexView(),
            'resources/views/admin/' . $this->table . '/form.twig' => $this->formView(),
            'database/upgrades/' . date('YmdHis') . '_create_' . $this->table . '.sql' => $this->migration(),
        ];
    }

    /**
     * Write the files.
     *
     * @return array<int,string> The relative paths written.
     */
    public function write(bool $force = false): array
    {
        $written = [];

        foreach ($this->files() as $relative => $contents) {
            $path = rtrim($this->siteRoot, '/') . '/' . $relative;

            if (is_file($path) && !$force) {
                throw new InvalidArgumentException('Refusing to overwrite ' . $relative . ' (use --force).');
            }

            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                throw new InvalidArgumentException('Could not create ' . dirname($path));
            }

            file_put_contents($path, $contents);
            $written[] = $relative;
        }

        return $written;
    }

    // -----------------------------------------------------------------------
    // Field parsing
    // -----------------------------------------------------------------------

    /**
     * @return array<int,array{name:string,type:string,required:bool,options:array<int,string>,target:?string}>
     */
    public static function parseFields(string $spec): array
    {
        $fields = [];

        foreach (array_filter(array_map('trim', explode(',', $spec))) as $declaration) {
            if (preg_match('/^([a-z][a-z0-9_]*):([a-z_]+)(?:\((.*?)\))?(!)?$/i', $declaration, $match) !== 1) {
                throw new InvalidArgumentException('Unreadable field: ' . $declaration);
            }

            // Optional groups leave a gap when they do not match.
            $match = array_pad($match, 5, '');

            $type = strtolower($match[2]);
            $options = [];

            if (($match[3] ?? '') !== '') {
                $options = array_values(array_filter(array_map('trim', explode('|', $match[3]))));
            }

            $target = null;

            if ($type === 'belongs_to') {
                $target = $options[0] ?? '';
                $options = [];

                if ($target === '') {
                    throw new InvalidArgumentException('belongs_to needs a table: ' . $declaration);
                }
            }

            if ($type === 'enum' && $options === []) {
                throw new InvalidArgumentException('enum needs its values: ' . $declaration);
            }

            $fields[] = [
                'name' => strtolower($match[1]),
                'type' => $type,
                'required' => ($match[4] ?? '') === '!',
                'options' => $options,
                'target' => $target,
            ];
        }

        if ($fields === []) {
            throw new InvalidArgumentException('At least one field is required.');
        }

        return $fields;
    }

    // -----------------------------------------------------------------------
    // SQL
    // -----------------------------------------------------------------------

    private function migration(): string
    {
        $columns = [];

        foreach ($this->fields as $field) {
            $columns[] = '  ' . $this->column($field);
        }

        $table = $this->table;
        $lines = implode(",\n", $columns);

        return "-- Create {$table}.\n"
            . "-- Idempotent: the deploy runner may run it against a database that\n"
            . "-- already has the table, and a fresh install runs it on an empty one.\n\n"
            . "CREATE TABLE IF NOT EXISTS `{$table}` (\n"
            . "  `id` int unsigned NOT NULL AUTO_INCREMENT,\n"
            . "{$lines},\n"
            . "  `created_at` datetime DEFAULT NULL,\n"
            . "  `updated_at` datetime DEFAULT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;' . "\n";
    }

    /** @param array{name:string,type:string,required:bool,options:array<int,string>,target:?string} $field */
    private function column(array $field): string
    {
        $null = $field['required'] ? 'NOT NULL' : 'DEFAULT NULL';

        $type = match ($field['type']) {
            'text' => 'text',
            'int', 'belongs_to' => 'int unsigned',
            'decimal' => 'decimal(12,2)',
            'bool' => 'tinyint(1)',
            'date' => 'date',
            'datetime' => 'datetime',
            'enum' => "enum('" . implode("','", array_map([self::class, 'escape'], $field['options'])) . "')",
            default => 'varchar(255)',
        };

        return sprintf('`%s` %s %s', $field['name'], $type, $null);
    }

    // -----------------------------------------------------------------------
    // Repository
    // -----------------------------------------------------------------------

    private function repository(): string
    {
        $class = $this->singular . 'Repository';
        $table = $this->table;

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Repositories;

use Polaris\\Api\\Pagination;
use Polaris\\Support\\Database;

/**
 * Database access for `{$table}`.
 *
 * Generated by `bin/make.php crud`. Edit it freely: nothing regenerates over it
 * unless you pass --force.
 */
final class {$class}
{
    public function __construct(private Database \$db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int \$id): ?array
    {
        return \$this->db->selectOne('SELECT * FROM {$table} WHERE id = :id', ['id' => \$id]);
    }

    /**
     * One page of rows, newest first.
     *
     * @return array{rows:array<int,array<string,mixed>>,pagination:array<string,mixed>}
     */
    public function page(Pagination \$pagination): array
    {
        \$rows = \$this->db->select(
            'SELECT * FROM {$table} ORDER BY id DESC LIMIT :limit OFFSET :offset',
            ['limit' => \$pagination->fetchLimit(), 'offset' => \$pagination->offset],
        );

        return \$pagination->apply(\$rows);
    }

    /** @param array<string,mixed> \$data */
    public function create(array \$data): int
    {
        \$data['created_at'] = date('Y-m-d H:i:s');
        \$data['updated_at'] = date('Y-m-d H:i:s');

        return \$this->db->insert('{$table}', \$data);
    }

    /** @param array<string,mixed> \$data */
    public function update(int \$id, array \$data): void
    {
        \$data['updated_at'] = date('Y-m-d H:i:s');
        \$this->db->update('{$table}', \$data, 'id = :where_id', ['where_id' => \$id]);
    }

    public function delete(int \$id): void
    {
        \$this->db->execute('DELETE FROM {$table} WHERE id = :id', ['id' => \$id]);
    }

    public function count(): int
    {
        return (int) (\$this->db->selectOne('SELECT COUNT(*) AS c FROM {$table}')['c'] ?? 0);
    }
}
PHP;
    }

    // -----------------------------------------------------------------------
    // API group
    // -----------------------------------------------------------------------

    private function apiGroup(): string
    {
        $class = $this->singular . 'Api';
        $group = self::snake($this->plural);
        $singularLabel = self::humanize($this->singular);
        $key = self::snake($this->singular);
        $lower = strtolower($singularLabel);
        $properties = $this->schemaProperties();
        $writable = $this->writableProperties();

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Api;

use App\\Repositories\\{$this->singular}Repository;
use Polaris\\Api\\ApiGroup;
use Polaris\\Api\\Operation;
use Polaris\\Api\\Pagination;

/**
 * {$singularLabel} operations.
 *
 * One definition per operation: it becomes a REST route, an MCP tool and an
 * OpenAPI entry at the same time. Do not write a tool by hand for this entity.
 */
final class {$class}
{
    public static function group({$this->singular}Repository \$repository): ApiGroup
    {
        return new ApiGroup('{$group}', [
            Operation::both(
                'list',
                'List {$this->plural}.',
                'GET',
                '/api/v1/{$this->table}',
                Pagination::schema(),
                [],
                static function (array \$args) use (\$repository): array {
                    \$page = \$repository->page(Pagination::fromArgs(\$args));

                    return ['{$this->table}' => \$page['rows'], 'pagination' => \$page['pagination']];
                },
            ),
            Operation::both(
                'get',
                'Read one {$singularLabel}.',
                'GET',
                '/api/v1/{$this->table}/{id}',
                ['id' => ['type' => 'integer']],
                ['id'],
                static function (array \$args) use (\$repository): array {
                    \$row = \$repository->find((int) \$args['id']);

                    if (\$row === null) {
                        throw new \\InvalidArgumentException('{$singularLabel} not found');
                    }

                    return ['{$key}' => \$row];
                },
            ),
            Operation::both(
                'create',
                'Create {$singularLabel}.',
                'POST',
                '/api/v1/{$this->table}',
                {$properties},
                {$writable},
                static function (array \$args) use (\$repository): array {
                    \$id = \$repository->create(\$args);

                    return ['{$key}' => \$repository->find(\$id)];
                },
            ),
            Operation::both(
                'update',
                'Update {$singularLabel}.',
                'PATCH',
                '/api/v1/{$this->table}/{id}',
                ['id' => ['type' => 'integer']] + {$properties},
                ['id'],
                static function (array \$args) use (\$repository): array {
                    \$id = (int) \$args['id'];
                    unset(\$args['id']);

                    if (\$repository->find(\$id) === null) {
                        throw new \\InvalidArgumentException('{$singularLabel} not found');
                    }

                    \$repository->update(\$id, \$args);

                    return ['{$key}' => \$repository->find(\$id)];
                },
            ),
            Operation::both(
                'delete',
                'Delete {$singularLabel}.',
                'DELETE',
                '/api/v1/{$this->table}/{id}',
                ['id' => ['type' => 'integer'], 'confirm' => ['type' => 'boolean']],
                ['id', 'confirm'],
                static function (array \$args) use (\$repository): array {
                    if ((\$args['confirm'] ?? false) !== true) {
                        throw new \\InvalidArgumentException('Pass confirm: true to delete.');
                    }

                    \$repository->delete((int) \$args['id']);

                    return ['deleted' => true];
                },
            ),
        ]);
    }
}
PHP;
    }

    /** @return string A PHP array literal of the JSON Schema properties. */
    private function schemaProperties(): string
    {
        $lines = [];

        foreach ($this->fields as $field) {
            $lines[] = sprintf(
                "                    '%s' => %s,",
                $field['name'],
                $this->schemaFor($field),
            );
        }

        return "[\n" . implode("\n", $lines) . "\n                ]";
    }

    /** @return string A PHP array literal of the required field names. */
    private function writableProperties(): string
    {
        $required = [];

        foreach ($this->fields as $field) {
            if ($field['required']) {
                $required[] = "'" . $field['name'] . "'";
            }
        }

        return '[' . implode(', ', $required) . ']';
    }

    /** @param array{name:string,type:string,required:bool,options:array<int,string>,target:?string} $field */
    private function schemaFor(array $field): string
    {
        return match ($field['type']) {
            'int', 'belongs_to' => "['type' => 'integer']",
            'decimal' => "['type' => 'number']",
            'bool' => "['type' => 'boolean']",
            'date', 'datetime' => "['type' => 'string', 'format' => 'date-time']",
            'enum' => "['type' => 'string', 'enum' => ['" . implode("', '", $field['options']) . "']]",
            default => "['type' => 'string']",
        };
    }

    // -----------------------------------------------------------------------
    // Admin controller
    // -----------------------------------------------------------------------

    private function controller(): string
    {
        $class = $this->singular . 'Controller';
        $label = self::humanize($this->singular);
        $view = $this->table;
        $repo = $this->singular . 'Repository';

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Controllers\\Admin;

use App\\Repositories\\{$repo};
use Polaris\\Api\\Pagination;
use Polaris\\Support\\Flash;
use Polaris\\Support\\Translator;
use Polaris\\Support\\Validator;
use Polaris\\View\\View;
use Psr\\Http\\Message\\ResponseInterface as Response;
use Psr\\Http\\Message\\ServerRequestInterface as Request;

/**
 * Admin CRUD for {$this->plural}.
 *
 * Generated by `bin/make.php crud`: list, create, edit and delete. The API and
 * the MCP tools for the same entity live in App\\Api\\{$this->singular}Api, so
 * this controller never duplicates their rules.
 */
final class {$class}
{
    public function __construct(
        private View \$view,
        private Flash \$flash,
        private {$repo} \$repository,
    ) {
    }

    /** @param array<string,mixed> \$data */
    private function render(Response \$response, string \$template, array \$data): Response
    {
        return \$this->view->render(\$response, \$template, \$data);
    }

    private function redirect(Response \$response, string \$url): Response
    {
        return \$response->withHeader('Location', \$url)->withStatus(302);
    }

    public function index(Request \$request, Response \$response): Response
    {
        \$page = \$this->repository->page(Pagination::fromArgs(\$request->getQueryParams()));

        return \$this->render(\$response, 'admin/{$view}/index.twig', [
            'page_title' => Translator::t('admin.{$view}_title'),
            'rows' => \$page['rows'],
            'pagination' => \$page['pagination'],
        ]);
    }

    public function newForm(Request \$request, Response \$response): Response
    {
        return \$this->render(\$response, 'admin/{$view}/form.twig', [
            'page_title' => Translator::t('admin.{$view}_new'),
            'row' => ['id' => 0],
            'errors' => [],
        ]);
    }

    public function edit(Request \$request, Response \$response, array \$args): Response
    {
        \$row = \$this->repository->find((int) \$args['id']);

        if (\$row === null) {
            throw new \\Slim\\Exception\\HttpNotFoundException(\$request);
        }

        return \$this->render(\$response, 'admin/{$view}/form.twig', [
            'page_title' => Translator::t('admin.{$view}_edit'),
            'row' => \$row,
            'errors' => [],
        ]);
    }

    public function save(Request \$request, Response \$response, array \$args = []): Response
    {
        \$data = (array) \$request->getParsedBody();
        \$id = (int) (\$args['id'] ?? 0);

        // Validation is the same rule set the API declares; a field the API
        // rejects must not be accepted by the form.
        \$validator = new Validator(\$data, [
{$this->validationRules()}
        ]);

        if (\$validator->fails()) {
            return \$this->render(\$response, 'admin/{$view}/form.twig', [
                'page_title' => Translator::t(\$id > 0 ? 'admin.{$view}_edit' : 'admin.{$view}_new'),
                'row' => \$data + ['id' => \$id],
                'errors' => \$validator->errors(),
            ])->withStatus(422);
        }

        \$values = \$this->payload(\$data);

        if (\$id > 0) {
            \$this->repository->update(\$id, \$values);
        } else {
            \$this->repository->create(\$values);
        }

        \$this->flash->success(Translator::t('flash.saved'));

        return \$this->redirect(\$response, '/admin/{$view}');
    }

    public function delete(Request \$request, Response \$response, array \$args): Response
    {
        \$this->repository->delete((int) \$args['id']);
        \$this->flash->success(Translator::t('flash.deleted'));

        return \$this->redirect(\$response, '/admin/{$view}');
    }

    /**
     * Only the declared fields are written: a form post cannot invent a column.
     *
     * @param array<string,mixed> \$data
     * @return array<string,mixed>
     */
    private function payload(array \$data): array
    {
        \$values = [];

        foreach ([{$this->fieldNameList()}] as \$field) {
            if (array_key_exists(\$field, \$data)) {
                \$values[\$field] = \$data[\$field];
            }
        }

        return \$values;
    }
}
PHP;
    }

    private function validationRules(): string
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $rule = match ($field['type']) {
                'int', 'belongs_to' => 'integer',
                'decimal' => 'numeric',
                'bool' => 'in:0,1',
                'date', 'datetime' => 'date',
                'enum' => 'in:' . implode(',', $field['options']),
                'text' => 'max:20000',
                default => 'max:255',
            };

            if ($field['required']) {
                $rule = 'required|' . $rule;
            }

            $rules[] = sprintf("            '%s' => '%s',", $field['name'], $rule);
        }

        return implode("\n", $rules);
    }

    private function fieldNameList(): string
    {
        return implode(', ', array_map(
            static fn (array $field): string => "'" . $field['name'] . "'",
            $this->fields,
        ));
    }

    // -----------------------------------------------------------------------
    // Views
    // -----------------------------------------------------------------------

    private function indexView(): string
    {
        $headers = '';
        $cells = '';

        foreach ($this->fields as $field) {
            $headers .= "\n                <th>{{ t('field.{$field['name']}') }}</th>";

            // A null should read as an empty cell, not as the word "null".
            $cells .= "\n                <td>{{ row.{$field['name']}|default('') }}</td>";
        }

        return <<<TWIG
{% extends 'layout.twig' %}

{% block content %}
<div class="mx-auto max-w-5xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold tracking-tight">{{ page_title }}</h1>
        <a href="/admin/{$this->table}/new" class="bg-ink text-white px-4 py-2 text-sm">{{ t('common.new') }}</a>
    </div>

    <table class="mt-8 w-full text-sm">
        <thead>
            <tr>
                <th class="text-left px-3 py-2">{{ t('field.id') }}</th>{$headers}
                <th></th>
            </tr>
        </thead>
        <tbody>
        {% for row in rows %}
            <tr class="border-t border-neutral-200">
                <td class="px-3 py-2">{{ row.id }}</td>{$cells}
                <td class="px-3 py-2 text-right">
                    <a href="/admin/{$this->table}/{{ row.id }}/edit" class="underline">{{ t('common.edit') }}</a>
                    <form method="post" action="/admin/{$this->table}/{{ row.id }}/delete" class="inline"
                          onsubmit="return confirm('{{ t('common.confirm_delete')|e('js') }}')">
                        {{ csrf_field() }}
                        <button type="submit" class="underline text-red-600">{{ t('common.delete') }}</button>
                    </form>
                </td>
            </tr>
        {% else %}
            <tr><td colspan="99" class="px-3 py-6 text-neutral-500">{{ t('common.empty') }}</td></tr>
        {% endfor %}
        </tbody>
    </table>

    {% if pagination.has_more %}
        <a class="mt-6 inline-block underline"
           href="?offset={{ pagination.next_offset }}&limit={{ pagination.limit }}">{{ t('common.next_page') }}</a>
    {% endif %}
</div>
{% endblock %}
TWIG;
    }

    private function formView(): string
    {
        $fields = '';

        foreach ($this->fields as $field) {
            $fields .= $this->formField($field);
        }

        return <<<TWIG
{% extends 'layout.twig' %}

{% block content %}
<div class="mx-auto max-w-2xl px-4 py-10">
    <h1 class="text-2xl font-semibold tracking-tight">{{ page_title }}</h1>

    <form method="post" action="{% if row.id %}/admin/{$this->table}/{{ row.id }}{% else %}/admin/{$this->table}{% endif %}"
          class="mt-8 space-y-5">
        {{ csrf_field() }}
{$fields}
        <div class="flex gap-3">
            <button type="submit" class="bg-ink text-white px-5 py-2">{{ t('common.save') }}</button>
            <a href="/admin/{$this->table}" class="px-5 py-2">{{ t('common.cancel') }}</a>
        </div>
    </form>
</div>
{% endblock %}
TWIG;
    }

    /** @param array{name:string,type:string,required:bool,options:array<int,string>,target:?string} $field */
    private function formField(array $field): string
    {
        $name = $field['name'];
        $label = "{{ t('field.{$name}') }}";
        $error = "{% if errors.{$name} %}<p class=\"text-xs text-red-600\">{{ errors.{$name} }}</p>{% endif %}";
        $required = $field['required'] ? ' required' : '';

        $input = match ($field['type']) {
            'text' => "\n                <textarea id=\"{$name}\" name=\"{$name}\" rows=\"6\" class=\"w-full border border-neutral-300 p-2\"{$required}>{{ row.{$name}|default('') }}</textarea>",
            'bool' => "\n                <input type=\"checkbox\" id=\"{$name}\" name=\"{$name}\" value=\"1\"{% if row.{$name} %} checked{% endif %}>",
            'int', 'belongs_to', 'decimal' => "\n                <input type=\"number\" id=\"{$name}\" name=\"{$name}\" value=\"{{ row.{$name}|default('') }}\" class=\"w-full border border-neutral-300 p-2\"{$required}>",
            'date' => "\n                <input type=\"date\" id=\"{$name}\" name=\"{$name}\" value=\"{{ row.{$name}|default('') }}\" class=\"w-full border border-neutral-300 p-2\"{$required}>",
            'datetime' => "\n                <input type=\"datetime-local\" id=\"{$name}\" name=\"{$name}\" value=\"{{ row.{$name}|default('') }}\" class=\"w-full border border-neutral-300 p-2\"{$required}>",
            'enum' => "\n                <select id=\"{$name}\" name=\"{$name}\" class=\"w-full border border-neutral-300 p-2\"{$required}>"
                . "\n                    {% for option in [" . implode(', ', array_map(static fn (string $o): string => "'" . $o . "'", $field['options'])) . "] %}"
                . "\n                        <option value=\"{{ option }}\"{% if row.{$name}|default('') == option %} selected{% endif %}>{{ option }}</option>"
                . "\n                    {% endfor %}"
                . "\n                </select>",
            default => "\n                <input type=\"text\" id=\"{$name}\" name=\"{$name}\" value=\"{{ row.{$name}|default('') }}\" class=\"w-full border border-neutral-300 p-2\"{$required}>",
        };

        return <<<TWIG

        <div>
            <label for="{$name}" class="block text-sm font-medium">{$label}</label>{$input}
            {$error}
        </div>
TWIG;
    }

    // -----------------------------------------------------------------------
    // Naming helpers
    // -----------------------------------------------------------------------

    public static function studly(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9]+/', ' ', $value) ?? $value;

        return str_replace(' ', '', ucwords(strtolower(trim($value))));
    }

    public static function snake(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;
        $value = preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? $value;

        return strtolower(trim($value, '_'));
    }

    public static function pluralize(string $value): string
    {
        if (str_ends_with($value, 'y') && !str_ends_with($value, 'ay')) {
            return substr($value, -1) === 'y' ? substr($value, 0, -1) . 'ies' : $value . 's';
        }

        if (str_ends_with($value, 's')) {
            return $value . 'es';
        }

        return $value . 's';
    }

    public static function humanize(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?? $value;

        return ucwords(strtolower(trim($value)));
    }

    private static function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
