<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * The one `.env` grammar used by both the runtime and the deploy script.
 *
 * Two parsers used to disagree about whether `KEY=value\t# comment` was a
 * comment, so the same file could produce different values in production and
 * on the machine deploying it. The grammar is deliberately small:
 *
 *   KEY=value
 *   KEY="quoted value"     (escapes are expanded, # and = are literal)
 *   KEY='literal value'    (nothing is expanded)
 *   KEY=value # comment
 *   export KEY=value
 *   # whole-line comment
 */
final class DotenvParser
{
    /**
     * @return array<string,string>
     */
    public static function parse(string $content): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $split = self::splitKeyValue($line);

            if ($split === null) {
                continue;
            }

            [$key, $value] = $split;
            $key = trim($key);

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $values[$key] = self::unquote(trim($value));
        }

        return $values;
    }

    /**
     * Split at the first unquoted equals sign, so a password containing several
     * `=` stays intact.
     *
     * @return array{0:string,1:string}|null
     */
    private static function splitKeyValue(string $line): ?array
    {
        $quote = null;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '"' || $char === "'") {
                if ($quote === null) {
                    $quote = $char;
                } elseif ($quote === $char && ($i === 0 || $line[$i - 1] !== '\\')) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '=' && $quote === null) {
                return [substr($line, 0, $i), substr($line, $i + 1)];
            }
        }

        return null;
    }

    private static function unquote(string $value): string
    {
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $length = strlen($value);

            for ($i = 1; $i < $length; $i++) {
                if ($value[$i] !== $quote || $value[$i - 1] === '\\') {
                    continue;
                }

                $inner = substr($value, 1, $i - 1);

                return $quote === '"' ? stripcslashes($inner) : $inner;
            }

            // An unterminated quote must not crash a deploy: keep the usable text.
            return substr($value, 1);
        }

        // A hash is only a comment when whitespace precedes it, so `foo#bar`
        // remains a valid password.
        if (preg_match('/\s#/', $value) === 1) {
            $value = preg_replace('/\s#.*$/', '', $value) ?? $value;
        }

        return trim($value);
    }
}
