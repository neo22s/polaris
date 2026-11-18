<?php

declare(strict_types=1);

/*
 * Polaris view helpers.
 *
 * These are the functions and filters available to every template. They are
 * registered here instead of inside TwigFactory so the file reads like a list
 * of what a theme author can call, and so a test can build the environment
 * without booting the application.
 */

declare(strict_types=1);

use Polaris\Support\Csrf;
use Polaris\Support\Languages;
use Polaris\Support\Translator;
use Polaris\Support\Url;
use Twig\Environment;
use Twig\TwigFilter;
use Twig\TwigFunction;

return static function (Environment $twig, string $assetsDirectory, ?string $themeAssetsDirectory = null): void {
    $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => Csrf::field(), ['is_safe' => ['html']]));
    $twig->addFunction(new TwigFunction(
        'asset',
        static function (string $path) use ($assetsDirectory, $themeAssetsDirectory): string {
            $path = ltrim($path, '/');

            // The theme wins over the application, which wins over the kernel.
            // Fingerprinting with the file mtime means a deploy always busts the
            // browser cache, so nobody keeps last week's CSS for a week.
            $candidates = array_filter([
                $themeAssetsDirectory === null ? null : $themeAssetsDirectory . '/' . $path,
                $assetsDirectory . '/' . $path,
            ]);

            $file = null;
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $file = $candidate;
                    break;
                }
            }

            $version = $file === null ? '' : '?v=' . filemtime($file);

            return '/assets/' . $path . $version;
        },
    ));
    $twig->addFunction(new TwigFunction('t', static fn (string $key, array $params = []): string => Translator::t($key, $params)));
    $twig->addFunction(new TwigFunction('safe_path', static fn (mixed $path): ?string => Url::safePath($path)));
    $twig->addFunction(new TwigFunction('language_name', static fn (string $code): string => Languages::name($code)));
    $twig->addFunction(new TwigFunction('language_dir', static fn (string $code): string => Languages::direction($code)));

    $twig->addFilter(new TwigFilter('ldate', static function (mixed $value, string $dateType = 'medium', string $timeType = 'none'): string {
        if ($value === null || $value === '') {
            return '';
        }

        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);

        if ($timestamp === false) {
            return '';
        }

        // Without intl the date is shown in a plain format rather than the
        // localised one: a missing extension must not take the page down.
        if (!class_exists(\IntlDateFormatter::class)) {
            return date($dateType === 'none' ? 'Y-m-d' : 'j M Y', $timestamp);
        }

        $map = [
            'none' => \IntlDateFormatter::NONE,
            'short' => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            'long' => \IntlDateFormatter::LONG,
            'full' => \IntlDateFormatter::FULL,
        ];

        $formatter = new \IntlDateFormatter(
            Translator::instance()->getLocale(),
            $map[$dateType] ?? \IntlDateFormatter::MEDIUM,
            $map[$timeType] ?? \IntlDateFormatter::NONE,
        );

        return $formatter->format($timestamp) ?: '';
    }));
};
