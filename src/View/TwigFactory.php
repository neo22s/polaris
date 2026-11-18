<?php

declare(strict_types=1);

namespace Polaris\View;

use Polaris\Support\Csrf;
use Polaris\Support\Translator;
use Polaris\Support\Url;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Builds the Twig environment with the package's helpers.
 *
 * The view cascade is explicit and ordered: theme, then application, then
 * kernel. The first directory that holds a template wins, which is what makes
 * "copy layout.twig into your theme" work without touching any PHP.
 */
final class TwigFactory
{
    /**
     * @param array<int,string> $viewPaths Ordered: theme, app, kernel.
     * @param array<string,mixed> $settings
     */
    public static function create(
        array $viewPaths,
        array $settings,
        string $assetsDirectory,
        string $cacheDirectory,
        ?string $themeAssetsDirectory = null,
    ): Environment {
        $loader = new FilesystemLoader(self::existingPaths($viewPaths));

        $twig = new Environment($loader, [
            'cache' => $settings['env'] === 'local' ? false : $cacheDirectory . '/twig',
            'debug' => (bool) $settings['debug'],
            'auto_reload' => true,
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);

        $twig->addGlobal('app_name', $settings['app_name']);
        $twig->addGlobal('app_url', $settings['url']);
        $twig->addGlobal('app_debug', (bool) $settings['debug']);
        $twig->addGlobal('app_env', $settings['env']);

        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => Csrf::field(), ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction(
            'asset',
            static function (string $path) use ($assetsDirectory, $themeAssetsDirectory): string {
                $path = ltrim($path, '/');

                // The theme wins over the application, which wins over the kernel.
                // Fingerprinting with mtime means a deploy always busts the
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
        // A switcher lists names in the language itself: "Español", not
        // "Spanish". The map falls back gracefully on hosts without intl.
        $twig->addFunction(new TwigFunction('language_name', static fn (string $code): string => \Polaris\Support\Languages::name($code)));
        $twig->addFunction(new TwigFunction('language_dir', static fn (string $code): string => \Polaris\Support\Languages::direction($code)));
        $twig->addFunction(new TwigFunction('safe_path', static fn (string $path): ?string => Url::safePath($path)));

        $twig->addFilter(new TwigFilter('ldate', self::localisedDate(...)));

        return $twig;
    }

    /**
     * Twig throws if a configured path does not exist; an application that has
     * no theme yet should not have to create empty directories to boot.
     *
     * @param array<int,string> $paths
     * @return array<int,string>
     */
    private static function existingPaths(array $paths): array
    {
        $existing = [];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $existing[] = $path;
            }
        }

        if ($existing === []) {
            throw new \RuntimeException('No view directory exists. Expected at least the kernel views.');
        }

        return $existing;
    }

    private static function localisedDate(mixed $value, string $dateType = 'medium', string $timeType = 'none'): string
    {
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
    }
}
