<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Validates public handles: format, reserved words and uniqueness.
 *
 * The uniqueness check is a callable, so this stays a stateless helper: the
 * caller decides how to ask whether a handle is taken, the same way Handle
 * does when it looks for a free one.
 */
final class HandleValidator
{
    public const RESERVED = [
        'admin', 'administrator', 'login', 'logout', 'register', 'signup', 'signin',
        'auth', 'dashboard', 'api', 'assets', 'uploads', 'upload', 'contact', 'about',
        'terms', 'privacy', 'help', 'support', 'robots', 'sitemap', 'cron', 'bin',
        'vendor', 'app', 'config', 'database', 'var', 'resources', 'favicon', 'static',
        'images', 'img', 'css', 'js', 'public', 'model', 'models', 'profile', 'search',
        'settings', 'account', 'onboarding', 'me', 'user', 'users', 'home', 'index', 'www', 'mail',
        'no-reply', 'noreply', 'set', 'sets', 'locale', 'language', 'lang',
    ];

    public static function isReserved(string $handle): bool
    {
        return in_array(strtolower($handle), self::RESERVED, true);
    }

    public static function isFormatValid(string $handle): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/', $handle) === 1;
    }

    /**
     * Validate a handle and return an error message, or null when valid.
     *
     * @param callable(string):bool $isTaken Whether the handle is already in use.
     */
    public static function check(string $handle, callable $isTaken): ?string
    {
        $handle = strtolower(trim($handle));

        if ($handle === '') {
            return Translator::t('validation.handle_required');
        }

        if (!self::isFormatValid($handle)) {
            return Translator::t('validation.handle_format');
        }

        if (self::isReserved($handle)) {
            return Translator::t('validation.handle_reserved');
        }

        if ($isTaken($handle)) {
            return Translator::t('validation.handle_taken');
        }

        return null;
    }
}
