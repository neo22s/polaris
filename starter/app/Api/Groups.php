<?php

declare(strict_types=1);

namespace App\Api;

use Polaris\Api\ApiGroup;
use Polaris\Api\Operation;
use Polaris\Api\Pagination;
use Polaris\Api\Registry;
use Polaris\Repositories\UserRepository;
use Psr\Container\ContainerInterface;

/**
 * The operations this site exposes.
 *
 * One entry per group. Each operation becomes a REST route and an MCP tool at
 * the same time, and it is documented from the same schema. When you add an
 * entity with `php bin/make.php crud`, its group registers here too.
 *
 * This starter ships two read-only examples so `/api/v1/docs` is not empty:
 * remove them when the site has its own operations.
 */
final class Groups
{
    public static function registry(ContainerInterface $container): Registry
    {
        $users = $container->get(UserRepository::class);

        return new Registry([
            new ApiGroup('users', [
                Operation::both(
                    'list',
                    'List active accounts.',
                    'GET',
                    '/api/v1/users',
                    Pagination::schema(),
                    [],
                    static function (array $args) use ($users): array {
                        $pagination = Pagination::fromArgs($args);

                        // publicRows() is the seam where a site decides which
                        // columns an API may expose. Never return a raw row.
                        $rows = $users->activePage($pagination->offset, $pagination->fetchLimit());
                        $page = $pagination->apply(self::publicRows($rows));

                        return ['users' => $page['rows'], 'pagination' => $page['pagination']];
                    },
                ),
                Operation::both(
                    'get',
                    'Read one account by id.',
                    'GET',
                    '/api/v1/users/{id}',
                    ['id' => ['type' => 'integer']],
                    ['id'],
                    static function (array $args) use ($users): array {
                        $user = $users->findById((int) $args['id']);

                        if ($user === null) {
                            throw new \InvalidArgumentException('User not found');
                        }

                        return ['user' => self::publicRows([$user])[0]];
                    },
                ),
            ]),
        ]);
    }

    /**
     * Strip the columns a client must never receive.
     *
     * This is a decision about the API, not about storage, so it lives with the
     * API. A new secret column gets added here and every surface inherits the
     * fix.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function publicRows(array $rows): array
    {
        $sensitive = ['password', 'token_hash', 'magic_link_token', 'remember_token'];

        return array_map(static function (array $row) use ($sensitive): array {
            foreach ($sensitive as $column) {
                unset($row[$column]);
            }

            return $row;
        }, $rows);
    }
}
