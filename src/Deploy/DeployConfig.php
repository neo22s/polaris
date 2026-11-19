<?php

declare(strict_types=1);

namespace Polaris\Deploy;

use Polaris\Support\DotenvParser;
use Polaris\Support\SafeFilesystem;
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

        $content = SafeFilesystem::read($path);

        if ($content === false) {
            throw new RuntimeException('Could not read ' . basename($path) . '.');
        }

        return new self(self::parse($content));
    }

    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        return DotenvParser::parse($content);
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
