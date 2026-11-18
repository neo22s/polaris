<?php

declare(strict_types=1);

/*
 * The site's configuration.
 *
 * Only what differs from the kernel defaults lives here. Anything omitted comes
 * from kernel/config/defaults.php, which means a kernel upgrade can improve a
 * default and this file does not have to change.
 */

use Polaris\Support\Env;

$root = dirname(__DIR__);

// The version of this site, shown in the admin panel. Independent from the
// kernel version, so an operator can tell "my site" from "the framework it runs".
$siteVersion = trim((string) @file_get_contents($root . '/VERSION')) ?: '0.0.0';

return [
    'root' => $root,

    'settings' => [
        'app_name' => (string) Env::get('APP_NAME', 'My Polaris site'),
        'site_version' => $siteVersion,
        'env' => (string) Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => rtrim((string) Env::get('APP_URL', 'http://localhost'), '/'),

        // Languages this site ships. Add a code here after adding
        // resources/lang/<code>.php; see docs/ADDING-A-LANGUAGE.md.
        'locales' => array_values(array_filter(
            array_map('trim', explode(',', (string) Env::get('LOCALES', 'en'))),
            static fn (string $code): bool => $code !== '',
        )),

        'admin_emails' => Env::list('ADMIN_EMAILS'),

        'paths' => [
            'views' => $root . '/resources/views',
            'lang' => $root . '/resources/lang',
            'assets' => $root . '/assets',
            'themes' => $root . '/themes',
            'cache' => $root . '/var/cache',
            'sessions' => $root . '/var/sessions',
            'uploads' => $root . '/uploads',
        ],

        // Runtime settings the admin panel may edit, with their rules. The
        // kernel service rejects unknown keys and values that break the rule.
        'writable_settings' => [],
    ],
];
