<?php

declare(strict_types=1);

namespace Polaris\Deploy;

use RuntimeException;

/**
 * Uploads a package over FTP, deleting removed files and running the SQL.
 *
 * FTP rather than SSH because the target is shared hosting: the cheapest plans
 * give an FTP account and a file manager, and nothing else. The protocol is
 * old, so the class is defensive about the two things that go wrong on those
 * hosts: the login directory is chrooted (so an absolute path from the
 * developer's machine does not exist) and a folder may disappear when the
 * provider reallocates a domain.
 *
 * The method names are the operations a deploy needs, not a general FTP client.
 */
final class FtpUploader
{
    /** @var resource|\FTP\Connection|null */
    private $connection = null;

    private string $remoteRoot = '';

    /** @param array{host:string,port:int,user:string,pass:string,root:string,passive:bool,ssl:bool} $config */
    public function __construct(private array $config)
    {
    }

    public function connect(): void
    {
        $connection = $this->config['ssl']
            ? @ftp_ssl_connect($this->config['host'], $this->config['port'], 30)
            : @ftp_connect($this->config['host'], $this->config['port'], 30);

        if ($connection === false) {
            throw new RuntimeException(sprintf(
                'Could not connect to %s:%d',
                $this->config['host'],
                $this->config['port'],
            ));
        }

        if (!@ftp_login($connection, $this->config['user'], $this->config['pass'])) {
            throw new RuntimeException('FTP login failed for ' . $this->config['user']);
        }

        ftp_pasv($connection, $this->config['passive']);
        ftp_set_option($connection, FTP_TIMEOUT_SEC, 90);

        $this->connection = $connection;
        $this->remoteRoot = $this->resolveRoot($connection, $this->config['root']);
    }

    public function disconnect(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }

    public function remoteRoot(): string
    {
        return $this->remoteRoot;
    }

    /**
     * @param array<string,string> $files relative path => local path
     * @return array{uploaded:int,failed:array<int,string>}
     */
    public function upload(array $files): array
    {
        $connection = $this->connection();

        $uploaded = 0;
        $failed = [];

        foreach ($files as $relative => $local) {
            $remote = $this->remoteRoot . '/' . ltrim($relative, '/');

            $this->ensureDirectory(dirname($remote));

            if (@ftp_put($connection, $remote, $local, FTP_BINARY)) {
                $uploaded++;
            } else {
                $failed[] = $relative;
            }
        }

        return ['uploaded' => $uploaded, 'failed' => $failed];
    }

    /**
     * @param array<int,string> $paths relative paths to delete
     * @return array<int,string> the paths that could not be deleted
     */
    public function delete(array $paths): array
    {
        $connection = $this->connection();
        $failed = [];

        foreach ($paths as $relative) {
            $remote = $this->remoteRoot . '/' . ltrim($relative, '/');

            if (!@ftp_delete($connection, $remote)) {
                $failed[] = $relative;
            }
        }

        return $failed;
    }

    /**
     * Upload the migration runner, call it over HTTPS, then remove it.
     *
     * @param array<string,string> $migrations
     * @return array{ok:bool,message:string}
     */
    public function runMigrations(array $migrations, string $token, string $siteUrl): array
    {
        if ($migrations === []) {
            return ['ok' => true, 'message' => 'No migrations.'];
        }

        $connection = $this->connection();
        $name = 'polaris-migrate-' . bin2hex(random_bytes(8)) . '.php';
        $temporary = tempnam(sys_get_temp_dir(), 'polaris-runner');

        if ($temporary === false) {
            return ['ok' => false, 'message' => 'Could not create a temporary file for the runner.'];
        }

        file_put_contents($temporary, RemoteRunner::build($migrations, $token));

        if (!@ftp_put($connection, $this->remoteRoot . '/' . $name, $temporary, FTP_BINARY)) {
            @unlink($temporary);

            return ['ok' => false, 'message' => 'Could not upload the migration runner.'];
        }

        @unlink($temporary);

        $url = rtrim($siteUrl, '/') . '/' . $name . '?t=' . rawurlencode($token);

        try {
            $body = $this->fetch($url);
            $ok = str_starts_with($body, 'OK');
            $message = $ok
                ? (trim($body) === 'OK 0' ? 'No new migrations.' : 'Applied ' . substr(trim($body), 3) . ' migration(s).')
                : $body;
        } catch (RuntimeException $e) {
            $ok = false;
            $message = $e->getMessage();
        }

        // The runner deletes itself, but the copy is removed from here too: if
        // that failed, a script carrying the deploy token would stay online.
        $removed = @ftp_delete($connection, $this->remoteRoot . '/' . $name);

        if (!$removed) {
            $message .= ' Delete ' . $name . ' from the server manually: it holds the deploy token.';
            $ok = false;
        }

        return ['ok' => $ok, 'message' => $message];
    }

    /** @return resource|\FTP\Connection */
    private function connection()
    {
        if ($this->connection === null) {
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * Find the document root on a host whose FTP account is chrooted.
     *
     * The configured path, the same path without the `/home/<user>` prefix, and
     * the usual `/public_html` and `/www` are all tried. If none exists, the
     * install is looked for under the per-domain public directory and
     * identified by its own files, so a neighbouring site is never mistaken
     * for ours.
     *
     * @param resource|\FTP\Connection $connection
     */
    private function resolveRoot($connection, string $configured): string
    {
        $login = ftp_pwd($connection) ?: '';
        $configured = '/' . trim($configured, '/');

        $candidates = [$configured];

        if ($login !== '' && $login !== '/' && str_starts_with($configured, $login . '/')) {
            $candidates[] = substr($configured, strlen($login));
        }

        if (preg_match('#^/home/[^/]+(/.*)$#', $configured, $match) === 1) {
            $candidates[] = $match[1];
        }

        $candidates[] = '/public_html';
        $candidates[] = '/www';

        foreach (array_unique($candidates) as $candidate) {
            $candidate = '/' . trim($candidate, '/');

            if (@ftp_chdir($connection, $candidate)) {
                @ftp_chdir($connection, '/');

                return $candidate;
            }
        }

        $found = $this->findInstall($connection);

        if ($found !== null) {
            fwrite(STDERR, "WARNING: {$configured} does not exist. Using {$found}\n");
            fwrite(STDERR, "         Update FTP_ROOT in .deploy.env so this is not needed next time.\n");

            return $found;
        }

        throw new RuntimeException(
            "Could not find the document root. Tried: " . implode(', ', $candidates)
            . '. Set FTP_ROOT in .deploy.env to the path of index.php.',
        );
    }

    /**
     * Look for our own install under /domains.
     *
     * @param resource|\FTP\Connection $connection
     */
    private function findInstall($connection): ?string
    {
        $matches = [];

        foreach (@ftp_nlist($connection, '/domains') ?: [] as $domain) {
            if (!is_string($domain)) {
                continue;
            }

            $base = basename($domain);

            if ($base === '' || $base === '.' || $base === '..') {
                continue;
            }

            $candidate = '/domains/' . $base . '/public_html';
            $listing = @ftp_nlist($connection, $candidate);

            if (!is_array($listing)) {
                continue;
            }

            $names = array_map(static fn ($entry): string => basename((string) $entry), $listing);

            // Fingerprint by the three files a Polaris site always has, so a
            // neighbouring WordPress install cannot match.
            if (in_array('index.php', $names, true)
                && in_array('composer.json', $names, true)
                && (in_array('app', $names, true) || in_array('kernel', $names, true))
            ) {
                $matches[] = $candidate;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function ensureDirectory(string $directory): void
    {
        $connection = $this->connection();
        $directory = rtrim($directory, '/');

        if ($directory === '' || $directory === '/' || $directory === '.') {
            return;
        }

        if (@ftp_chdir($connection, $directory)) {
            @ftp_chdir($connection, '/');

            return;
        }

        $parent = dirname($directory);

        if ($parent !== $directory) {
            $this->ensureDirectory($parent);
        }

        @ftp_mkdir($connection, $directory);
    }

    private function fetch(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required to call the migration runner securely.');
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Polaris-Deploy',
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($handle);
        curl_close($handle);

        if ($body === false || $status !== 200) {
            throw new RuntimeException('HTTP ' . $status . ' ' . $error . ' ' . (is_string($body) ? substr($body, 0, 300) : ''));
        }

        return (string) $body;
    }
}
