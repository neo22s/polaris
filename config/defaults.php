<?php

declare(strict_types=1);

/*
 * Polaris defaults.
 *
 * Keep this file free of application knowledge: it is the baseline every
 * Polaris site inherits. Anything a specific site needs goes in its own
 * config/settings.php, which is merged on top of this array (application wins
 * at the leaf level, lists are replaced).
 *
 * Values are read through Env::get() so a .env file, or real environment
 * variables on the hosting, configure the whole site.
 */

use Polaris\Support\Env;

return [
    // Project root on disk (used to build absolute paths).
    'root' => dirname(__DIR__),

    'settings' => [
        // Identity
        'app_name' => (string) Env::get('APP_NAME', 'Polaris'),
        'env' => (string) Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => rtrim((string) Env::get('APP_URL', 'http://localhost'), '/'),
        'theme' => (string) Env::get('THEME', 'base'),
        // Languages this site ships. Only these are offered, detected and
        // listed in the switcher; a site adds one by shipping
        // resources/lang/<code>.php and adding the code here.
        'locales' => array_values(array_filter(
            array_map('trim', explode(',', (string) Env::get('LOCALES', 'en'))),
            static fn (string $code): bool => $code !== '',
        )),

        // Access
        'admin_emails' => Env::list('ADMIN_EMAILS'),

        // Session
        'session_name' => (string) Env::get('SESSION_NAME', 'polaris_session'),
        'session_lifetime' => Env::int('SESSION_LIFETIME', 31536000),
        'session_regenerate_minutes' => Env::int('SESSION_REGENERATE_MINUTES', 30),

        // Reverse proxies that may set X-Forwarded-Proto. Empty means "do not
        // trust the header", which is the safe default for shared hosting.
        'trusted_proxies' => Env::list('TRUSTED_PROXIES'),

        // Database
        'db' => [
            'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
            'port' => Env::int('DB_PORT', 3306),
            'database' => (string) Env::get('DB_DATABASE', 'polaris'),
            'username' => (string) Env::get('DB_USERNAME', 'root'),
            'password' => (string) Env::get('DB_PASSWORD', ''),
            'persistent' => Env::bool('DB_PERSISTENT', false),
        ],

        // Outgoing email
        'mail' => [
            'host' => (string) Env::get('MAIL_HOST', 'localhost'),
            'port' => Env::int('MAIL_PORT', 587),
            'secure' => (string) Env::get('MAIL_SECURE', ''),
            'auth' => Env::bool('MAIL_AUTH', true),
            'user' => (string) Env::get('MAIL_USER', ''),
            'pass' => (string) Env::get('MAIL_PASS', ''),
            'from' => (string) Env::get('MAIL_FROM', 'no-reply@localhost'),
            'from_name' => (string) Env::get('MAIL_FROM_NAME', 'Polaris'),
        ],

        // Newsletter provider (optional; disabled when no token is set)
        'acumbamail' => [
            'enabled' => Env::bool('ACUMBAMAIL_ENABLED', false),
            'auth_token' => (string) Env::get('ACUMBAMAIL_AUTH_TOKEN', ''),
            'list_en' => Env::int('ACUMBAMAIL_LIST_EN', 0),
            'list_es' => Env::int('ACUMBAMAIL_LIST_ES', 0),
            'default_locale' => (string) Env::get('ACUMBAMAIL_DEFAULT_LOCALE', 'en'),
            'double_optin' => Env::bool('ACUMBAMAIL_DOUBLE_OPTIN', false),
        ],

        // Filesystem paths
        'paths' => [
            'views' => dirname(__DIR__) . '/resources/views',
            'lang' => dirname(__DIR__) . '/resources/lang',
            'themes' => dirname(__DIR__) . '/themes',
            'cache' => dirname(__DIR__) . '/var/cache',
            'uploads' => dirname(__DIR__) . '/uploads',
            'sessions' => dirname(__DIR__) . '/var/sessions',
            'assets' => dirname(__DIR__) . '/assets',
        ],

        // File cache
        'cache' => [
            'enabled' => Env::bool('CACHE_ENABLED', true),
            'path' => dirname(__DIR__) . '/var/cache/data',
        ],

        // Security headers. The default policy is strict; a site that needs
        // CDNs or inline scripts replaces it here, directive by directive.
        'security' => [
            'csp' => [],
        ],

        // REST API for the operations declared in app/Api (optional, and off
        // without a key: an API that is on by default is an API nobody chose).
        'api' => [
            'enabled' => Env::bool('API_ENABLED', true),
            'api_key' => (string) Env::get('API_KEY', ''),
            'rate_limit' => [120, 60],
            // The generated documentation lists every operation, so it is off
            // unless the site asks for it. With it on, it is still behind the
            // API key.
            'docs' => Env::bool('API_DOCS', false),
        ],

        // MCP HTTP access (optional; disabled without a key)
        'mcp' => [
            'enabled' => Env::bool('MCP_ENABLED', true),
            'api_key' => (string) Env::get('MCP_API_KEY', ''),
            'rate_limit' => [60, 60],
            'max_bytes' => Env::int('MCP_MAX_BYTES', 4 * 1024 * 1024),
        ],
    ],
];
