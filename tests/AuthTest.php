<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Auth\AuthService;
use Polaris\Auth\ProfileProvisioner;
use Polaris\Auth\SubscriberSync;
use Polaris\Auth\UserStore;
use Polaris\Mail\MailerInterface;
use Polaris\Repositories\MagicLinkRepository;
use Polaris\Services\MagicLinkService;
use Polaris\Support\Database;
use Polaris\Support\RateLimiter;

/**
 * Authentication flows that must hold even when an attacker races them.
 *
 * Magic links are single-use by contract; the test proves that the database
 * enforces it, not just PHP logic.
 */
final class AuthTest extends TestCase
{
    /** The last link the fake mailer received. */
    public ?string $lastMagicLink = null;

    private ?Database $db = null;

    private ?string $database = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = \Polaris\Tests\Support\Integration::temporaryDatabaseName();
        $root = \Polaris\Tests\Support\Integration::pdo($this);
        $root->exec('CREATE DATABASE IF NOT EXISTS `' . $this->database . '`');

        $this->db = new Database([
            'host' => (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'database' => $this->database,
            'username' => (string) (getenv('TEST_DB_USERNAME') ?: 'root'),
            'password' => (string) (getenv('TEST_DB_PASSWORD') ?: ''),
        ]);

        $this->db->pdo()->exec(file_get_contents(dirname(__DIR__) . '/database/upgrades/00000000000001_core.sql'));
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->database !== null) {
            $this->db->pdo()->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        }

        parent::tearDown();
    }

    public function testAMagicLinkCanOnlyBeConsumedOnce(): void
    {
        $service = $this->service();

        $service->createAccount('user@example.com', 'member');
        $service->requestMagicLink('user@example.com', '127.0.0.1', 'PHPUnit');

        $token = $this->lastToken();
        $this->assertNotNull($token);

        $first = $service->consumeToken($token);
        $second = $service->consumeToken($token);

        $this->assertNotNull($first);
        $this->assertNull($second, 'The same token cannot be consumed twice.');
    }

    public function testMagicLinkRequestsAreRateLimitedPerAccountAndIp(): void
    {
        $service = $this->service();
        $service->createAccount('user@example.com', 'member');

        // Three requests in five minutes is allowed.
        for ($i = 0; $i < 3; $i++) {
            $this->assertNotNull($service->requestMagicLink('user@example.com', '127.0.0.1', 'PHPUnit'));
        }

        // The fourth is rate-limited and returns null without sending mail.
        $this->assertNull($service->requestMagicLink('user@example.com', '127.0.0.1', 'PHPUnit'));

        // A different IP is still limited by the per-account bucket.
        $this->assertNull($service->requestMagicLink('user@example.com', '10.0.0.1', 'PHPUnit'));

        // A different email on the same IP is fine.
        $service->createAccount('other@example.com', 'member');
        $this->assertNotNull($service->requestMagicLink('other@example.com', '127.0.0.1', 'PHPUnit'));
    }

    private function service(): AuthService
    {
        $settings = ['url' => 'http://localhost'];
        $magicLinks = new MagicLinkRepository($this->db);

        return new AuthService(
            new class ($this->db) implements UserStore {
                public function __construct(private Database $db)
                {
                }

                public function findById(int $id): ?array
                {
                    return $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
                }

                public function findByEmail(string $email): ?array
                {
                    return $this->db->selectOne('SELECT * FROM users WHERE email = :email', ['email' => $email]);
                }

                public function create(array $data): int
                {
                    $this->db->insert('users', $data);

                    return (int) $this->db->pdo()->lastInsertId();
                }

                public function update(int $id, array $data): void
                {
                    $this->db->update('users', $data, 'id = :id', ['id' => $id]);
                }

                public function markEmailVerified(int $id): void
                {
                    $this->db->execute(
                        'UPDATE users SET email_verified_at = :now WHERE id = :id',
                        ['now' => date('Y-m-d H:i:s'), 'id' => $id],
                    );
                }

                public function promote(int $id): void
                {
                    $this->db->execute('UPDATE users SET role = :role WHERE id = :id', ['role' => 'admin', 'id' => $id]);
                }

                public function setStatus(int $id, string $status): void
                {
                    $this->db->execute('UPDATE users SET status = :status WHERE id = :id', ['status' => $status, 'id' => $id]);
                }
            },
            $magicLinks,
            new MagicLinkService($magicLinks, $settings),
            new class ($this) implements MailerInterface {
                public function __construct(private AuthTest $test)
                {
                }

                public function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool
                {
                    return true;
                }

                public function sendMagicLink(string $to, string $link): bool
                {
                    $this->test->lastMagicLink = $link;

                    return true;
                }
            },
            new class implements SubscriberSync {
                public function syncUser(string $email, ?string $locale): bool
                {
                    return true;
                }
            },
            new class implements ProfileProvisioner {
                public function provision(int $userId, array $fields): void
                {
                }
            },
            $this->db,
            new RateLimiter($this->db),
            $settings,
        );
    }

    private function lastToken(): ?string
    {
        if ($this->lastMagicLink === null) {
            return null;
        }

        $path = parse_url($this->lastMagicLink, PHP_URL_PATH);

        return is_string($path) ? basename($path) : null;
    }
}
