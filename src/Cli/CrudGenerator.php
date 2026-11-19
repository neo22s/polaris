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
        return $this->stub('repository', [
            'class' => $this->singular . 'Repository',
            'table' => $this->table,
        ]);
    }

    // -----------------------------------------------------------------------
    // API group
    // -----------------------------------------------------------------------

    private function apiGroup(): string
    {
        return $this->stub('api_group', [
            'class' => $this->singular . 'Api',
            'singular' => $this->singular,
            'plural' => $this->plural,
            'table' => $this->table,
            'group' => self::snake($this->plural),
            'singularLabel' => self::humanize($this->singular),
            'key' => self::snake($this->singular),
            'properties' => $this->schemaProperties(),
            'writable' => $this->writableProperties(),
        ]);
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
        return $this->stub('controller', [
            'class' => $this->singular . 'Controller',
            'label' => self::humanize($this->singular),
            'view' => $this->table,
            'repo' => $this->singular . 'Repository',
            'plural' => $this->plural,
            'singular' => $this->singular,
            'rules' => $this->validationRules(),
            'fieldList' => $this->fieldNameList(),
        ]);
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

        return $this->stub('index_view', [
            'table' => $this->table,
            'headers' => $headers,
            'cells' => $cells,
        ]);
    }

    private function formView(): string
    {
        $fields = '';

        foreach ($this->fields as $field) {
            $fields .= $this->formField($field);
        }

        return $this->stub('form_view', [
            'table' => $this->table,
            'fields' => $fields,
        ]);
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

        return $this->stub('form_field', [
            'name' => $name,
            'label' => $label,
            'error' => $error,
            'required' => $required,
            'input' => $input,
        ]);
    }

    /**
     * Render a scaffold file. The caller's variables are made available to the
     * template's heredoc, so the templates stay plain PHP files that can be
     * reviewed and edited without touching the generator.
     *
     * @param array<string,mixed> $variables
     */
    private function stub(string $template, array $variables): string
    {
        $path = dirname(__DIR__, 2) . '/resources/stubs/' . $template . '.php';

        if (!is_file($path)) {
            throw new InvalidArgumentException('Missing scaffold: ' . $template);
        }

        extract($variables, EXTR_SKIP);

        return (string) require $path;
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
