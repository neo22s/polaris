<?php

declare(strict_types=1);

namespace Polaris\Deploy;

use RuntimeException;

/**
 * The site's deployment settings, read from `.deploy.env`.
 *
 * Two rules shape this class:
 *
 * - **The kernel never ships credentials.** `.deploy.env` lives in the site,
 *   is git-ignored, and is uploaded by hand. A release archive that contains an
 *   FTP password is a breach waiting for a mirror.
 * - **Missing values are named, not guessed.** A deploy that silently falls back
 *   to `localhost` is worse than one that stops.
 *
 * The file is parsed here rather than through `Env`: the deploy script runs on
 * the developer's machine, where the site's `.env` describes production and must
 * not be confused with the FTP account.
 *
 * The parser supports:
 *
 *   KEY=value
 *   KEY="value with spaces"
 *   KEY='literal "quotes" here'
 *   KEY="password=with=equals"
 *   KEY=value # inline comment
 *   # line comment
 *
 * Quoted values preserve spaces and `=` characters. Unquoted values end at the
 * first ` #` that is not escaped. The surrounding quotes are removed.
 */
final class DeployConfig
{
    /** @var array<string,string> */
    private array $values;

    /** @param array<string,string> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                'Missing ' . basename($path) . '. Copy .deploy.env.example to .deploy.env and fill it in.',
            );
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Could not read ' . basename($path) . '.');
        }

        return new self(self::parse($content));
    }

    /**
     * Parse a `.env`-style string into key/value pairs.
     *
     * @return array<string,string>
     */
    public static function parse(string $content): array
    {
        $values = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip a leading "export " so the file is also valid shell.
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            // Split only on the first unquoted =. This keeps passwords with
            // several = intact and still allows inline comments.
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
     * Split a line into key and value at the first unquoted equals sign.
     *
     * @return array{0:string,1:string}|null
     */
    private static function splitKeyValue(string $line): ?array
    {
        $length = strlen($line);
        $inQuote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '"' || $char === "'") {
                if ($inQuote === null) {
                    $inQuote = $char;
                } elseif ($inQuote === $char) {
                    // Skip escaped quotes: "foo\"bar" keeps the quote literal.
                    $prev = $i > 0 ? $line[$i - 1] : '';

                    if ($prev !== '\\') {
                        $inQuote = null;
                    }
                }

                continue;
            }

            if ($char === '=' && $inQuote === null) {
                return [substr($line, 0, $i), substr($line, $i + 1)];
            }
        }

        return null;
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);

        // A quoted value ends at the first unescaped matching quote; anything
        // after it, such as an inline comment, is discarded.
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

            // Unterminated quote: keep the text rather than crashing a deploy,
            // but drop the opening quote so the value is still usable.
            return substr($value, 1);
        }

        // Unquoted value: drop a trailing inline comment, but only where a
        // space precedes the hash, so a password like "foo#bar" stays intact.
        if (preg_match('/\s#/', $value) === 1) {
            $value = preg_replace('/\s#.*$/', '', $value) ?? $value;
        }

        return trim($value);
    }

    /** @param array<string,string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function required(string $key): string
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new RuntimeException(sprintf('%s is required in .deploy.env.', $key));
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return !in_array(strtolower($value), ['false', '0', 'no', 'off'], true);
    }

    /**
     * The FTP settings as one array, so a caller cannot mix up units.
     *
     * @return array{host:string,port:int,user:string,pass:string,root:string,passive:bool,ssl:bool}
     */
    public function ftp(): array
    {
        return [
            'host' => $this->required('FTP_HOST'),
            'port' => $this->int('FTP_PORT', 21),
            'user' => $this->required('FTP_USER'),
            'pass' => (string) $this->get('FTP_PASS', ''),
            'root' => '/' . trim((string) $this->get('FTP_ROOT', '/public_html'), '/'),
            'passive' => $this->bool('FTP_PASSIVE', true),
            'ssl' => $this->bool('FTP_SSL', false),
        ];
    }
}
