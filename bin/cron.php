<?php

declare(strict_types=1);

/*
 * The daily runner that docs/INSTALL.md and docs/RECIPES.md point at.
 *
 * A site registers callables in config/cron.php:
 *
 *   return [
 *       ' reminders' => static fn (ContainerInterface $c): mixed => $c->get(ReminderService::class)->sendDue(),
 *   ];
 *
 * Each task receives the container. One failing task must not stop the next, so
 * every task runs in its own try/catch; the process still exits non-zero so the
 * scheduler reports the failure. The outcome goes to SystemStatus, which is
 * what the admin health panel reads.
 *
 * Usage:
 *   php bin/cron.php
 */

use DI\ContainerBuilder;
use Polaris\Support\Cache;
use Polaris\Support\Config;
use Polaris\Support\Env;
use Polaris\Support\SystemStatus;
use Psr\Container\ContainerInterface;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// Inside a site, ./kernel holds the package; inside the package, we are it.
$kernelRoot = is_dir($root . '/kernel/src') ? $root . '/kernel' : $root;

Env::load($root . '/.env');

$defaults = require $kernelRoot . '/config/defaults.php';
$overrides = is_file($root . '/config/settings.php') ? (require $root . '/config/settings.php') : [];
$settings = Config::merge($defaults, $overrides)['settings'];

$siteDefinitions = is_file($root . '/config/definitions.php') ? (require $root . '/config/definitions.php') : [];

$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->addDefinitions(array_merge([
    'settings' => $settings,
    'root' => $root,
    Cache::class => static fn (): Cache => new Cache(
        (string) $settings['cache']['path'],
        (bool) $settings['cache']['enabled'],
    ),
    SystemStatus::class => \DI\autowire()
        ->constructorParameter('statusFile', $root . '/var/logs/cron-status.json')
        ->constructorParameter('cacheDirectory', (string) $settings['cache']['path']),
], $siteDefinitions));

$container = $builder->build();

/** @var array<string,mixed> $tasks */
$tasks = is_file($root . '/config/cron.php') ? (require $root . '/config/cron.php') : [];

// Cache pruning needs no site registration: it is the cleanup the docs promise
// and the reason SystemStatus shows cache statistics in the admin panel.
$tasks = ['cache.prune' => static fn (ContainerInterface $c): int => $c->get(Cache::class)->prune()] + $tasks;

$results = [];
$failed = [];

foreach ($tasks as $name => $task) {
    if (!is_callable($task)) {
        $failed[] = (string) $name;
        error_log('[Polaris] cron task is not callable: ' . $name);
        continue;
    }

    try {
        $results[(string) $name] = $task($container);
    } catch (\Throwable $e) {
        $failed[] = (string) $name;
        error_log('[Polaris] cron task failed: ' . $name . ' — ' . $e->getMessage());
    }
}

$container->get(SystemStatus::class)->record([
    'tasks' => array_keys($results),
    'failed' => $failed,
]);

foreach ($results as $name => $result) {
    fwrite(STDOUT, $name . ': ' . (is_scalar($result) ? (string) $result : 'ok') . "\n");
}

if ($failed !== []) {
    fwrite(STDERR, 'Failed cron tasks: ' . implode(', ', $failed) . "\n");
    exit(1);
}

exit(0);
