<?php

declare(strict_types=1);

/*
 * Scaffold for app/Api/<Entity>Api.php.
 *
 * Supplied by CrudGenerator: $class, $singular, $plural, $table, $group,
 * $singularLabel, $key, $properties, $writable.
 */

return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Api;

use App\\Repositories\\{$singular}Repository;
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
    public static function group({$singular}Repository \$repository): ApiGroup
    {
        return new ApiGroup('{$group}', [
            Operation::both(
                'list',
                'List {$plural}.',
                'GET',
                '/api/v1/{$table}',
                Pagination::schema(),
                [],
                static function (array \$args) use (\$repository): array {
                    \$page = \$repository->page(Pagination::fromArgs(\$args));

                    return ['{$table}' => \$page['rows'], 'pagination' => \$page['pagination']];
                },
            ),
            Operation::both(
                'get',
                'Read one {$singularLabel}.',
                'GET',
                '/api/v1/{$table}/{id}',
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
                '/api/v1/{$table}',
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
                '/api/v1/{$table}/{id}',
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
                '/api/v1/{$table}/{id}',
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
