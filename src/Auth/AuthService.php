<?php

declare(strict_types=1);

namespace Polaris\Auth;

use Polaris\Repositories\MagicLinkRepository;
use Polaris\Mail\MailerInterface;
use Polaris\Services\MagicLinkService;
use Polaris\Support\Database;
use Polaris\Support\RateLimiter;
use Polaris\Support\Session;
use Polaris\Support\Translator;
use RuntimeException;

/**
 * Passwordless authentication: magic links, sessions, roles and impersonation.
 *
 * Account creation is deliberately *not* here. Registration policy (which
 * fields an account needs, which handles are reserved, what a profile looks
 * like) belongs to the application; this class only knows about users,
 * tokens and sessions. An application extends it when it needs to create
 * accounts, which keeps the kernel free of domain rules.
 *
 * @phpstan-type UserRow array<string,mixed>
 */
class AuthService
{
    /** Session key of the signed-in user id. */
    public const SESSION_KEY = '_user_id';

    /** Session key of the admin behind an impersonated session. */
    public const IMPERSONATOR_KEY = '_impersonator_id';

    /** @var array<string,mixed>|null */
    private ?array $cachedUser = null;

    private bool $userResolved = false;

    /** @param array<string,mixed> $settings */
    public function __construct(
        private UserStore $users,
        private MagicLinkRepository $magicLinks,
        private MagicLinkService $linkService,
        private MailerInterface $mail,
        private SubscriberSync $newsletter,
        private ProfileProvisioner $provisioner,
        private Database $db,
        private RateLimiter $limiter,
        private array $settings,
    ) {
    }

    /**
     * Create the account row, run the profile hook and return it, all in one
     * transaction. The application calls this from its own registration code.
     *
     * @param array<string,mixed> $attributes Extra user columns (handle, ...).
     * @param array<string,mixed> $profile Extra columns for the profile hook.
     * @return array<string,mixed>
     */
    public function createAccount(
        string $email,
        string $role,
        array $attributes = [],
        array $profile = [],
    ): array {
        $userId = $this->db->transaction(function () use ($email, $role, $attributes, $profile): int {
            $row = array_merge([
                'email' => strtolower(trim($email)),
                'role' => $role,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], $attributes);

            $userId = $this->users->create($row);

            // The profile rows belong to the same transaction: an account
            // must never exist half-created.
            $this->provisioner->provision($userId, $profile);

            return $userId;
        });

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new RuntimeException('The new account could not be reloaded.');
        }

        return $user;
    }

    public function requestMagicLink(string $email, ?string $ip, ?string $userAgent): ?array
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));

        if ($user === null) {
            return null;
        }

        if ($user['status'] !== 'active') {
            return null;
        }

        // Rate-limit per account and per IP. The limit is intentionally tight:
        // a magic link is a delivered email, and an attacker should not be able
        // to spam a victim's inbox or burn the mail quota.
        $bucket = 'magic-link:' . $user['id'];
        $ipBucket = 'magic-link-ip:' . ($ip ?? 'unknown');

        if (!$this->limiter->attempt($bucket, 3, 300)) {
            return null;
        }

        if (!$this->limiter->attempt($ipBucket, 5, 60)) {
            return null;
        }

        $this->maybePromoteAdmin($user);
        $this->sendMagicLink($user, $ip, $userAgent);

        return $user;
    }

    /** @param array<string,mixed> $user */
    public function sendMagicLink(array $user, ?string $ip, ?string $userAgent): void
    {
        $this->magicLinks->invalidateForUser((int) $user['id']);

        $link = $this->linkService->create($user, null, MagicLinkService::TTL_LOGIN, $ip, $userAgent);
        $locale = Translator::isSupported($user['locale'] ?? null)
            ? (string) $user['locale']
            : Translator::instance()->getLocale();

        Translator::instance()->withLocale($locale, function () use ($user, $link): void {
            $this->mail->sendMagicLink((string) $user['email'], $link);
        });
    }

    /** @return array<string,mixed>|null */
    public function consumeToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        // The repository UPDATEs with a WHERE that only matches an unused,
        // unexpired row. MySQL serializes the write, so the same token cannot
        // be consumed twice, even between requests.
        $link = $this->magicLinks->consume(hash('sha256', $token));

        if ($link === null) {
            return null;
        }

        $user = $this->users->findById((int) $link['user_id']);

        if ($user === null || $user['status'] !== 'active') {
            return null;
        }

        if ($user['email_verified_at'] === null) {
            $storedLocale = $user['locale'] ?? null;
            $locale = Translator::isSupported($storedLocale)
                ? (string) $storedLocale
                : Translator::instance()->getLocale();

            $this->db->transaction(function () use ($user, $storedLocale, $locale): void {
                if (!Translator::isSupported($storedLocale)) {
                    $this->users->update((int) $user['id'], ['locale' => $locale]);
                }

                $this->users->markEmailVerified((int) $user['id']);
            });

            $this->newsletter->syncUser((string) $user['email'], $locale);
        }

        return $this->maybePromoteAdmin($user);
    }

    /** @param array<string,mixed> $user */
    public function login(array $user): void
    {
        $this->regenerateSession();
        Session::set(self::SESSION_KEY, (int) $user['id']);
        $this->cachedUser = $user;
        $this->userResolved = true;
    }

    public function logout(): void
    {
        // Keep cosmetic choices (the language) but drop every trace of the
        // account, then move to a brand new session id.
        Session::remove(self::SESSION_KEY);
        Session::keepOnly(['locale']);

        $this->regenerateSession();

        $this->cachedUser = null;
        $this->userResolved = true;
    }

    /**
     * New session id with the old one removed from storage. Done explicitly
     * (instead of relying on session_regenerate_id's $delete_old_session)
     * because the custom save handler does not get destroy() called reliably.
     */
    private function regenerateSession(): void
    {
        $oldId = session_id();

        if (!session_regenerate_id(true)) {
            return;
        }

        if (is_string($oldId) && $oldId !== '') {
            $file = $this->sessionDirectory() . '/sess_' . $oldId;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function sessionDirectory(): string
    {
        return is_string($this->settings['paths']['sessions'] ?? null)
            ? (string) $this->settings['paths']['sessions']
            : session_save_path();
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        if ($this->userResolved) {
            return $this->cachedUser;
        }

        $this->userResolved = true;
        $id = Session::get(self::SESSION_KEY);

        if (!is_int($id) && !ctype_digit((string) $id)) {
            return $this->cachedUser = null;
        }

        $user = $this->users->findById((int) $id);

        if ($user === null || $user['status'] !== 'active') {
            Session::remove(self::SESSION_KEY);

            return $this->cachedUser = null;
        }

        return $this->cachedUser = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function isAdmin(): bool
    {
        $user = $this->user();

        return $user !== null && $user['role'] === 'admin';
    }

    /**
     * Whether the current session is an admin managing another account.
     *
     * While impersonating, the session user is the managed account, so
     * isAdmin() is false and has to be checked separately.
     */
    public function isImpersonating(): bool
    {
        return $this->actingAdminId() !== null;
    }

    /**
     * The id of the admin responsible for what this session does: the
     * signed-in admin, or the admin this session is impersonating for. Null
     * when the user is acting on their own account.
     */
    public function actingAdminId(): ?int
    {
        $admin = $this->user();

        if ($admin !== null && $admin['role'] === 'admin') {
            return (int) $admin['id'];
        }

        $impersonator = $this->impersonator();

        return $impersonator === null ? null : (int) $impersonator['id'];
    }

    /**
     * The admin behind the current session, when an admin is acting as someone
     * else.
     *
     * A missing, demoted or suspended admin is not an impersonator any more:
     * the stale session marker is dropped so the banner and the way back
     * disappear with it.
     *
     * @return array<string,mixed>|null
     */
    public function impersonator(): ?array
    {
        $impersonatorId = Session::get(self::IMPERSONATOR_KEY);

        if ($impersonatorId === null || (!is_int($impersonatorId) && !ctype_digit((string) $impersonatorId))) {
            return null;
        }

        $impersonator = $this->users->findById((int) $impersonatorId);

        if ($impersonator === null || $impersonator['role'] !== 'admin' || $impersonator['status'] !== 'active') {
            Session::remove(self::IMPERSONATOR_KEY);

            return null;
        }

        return $impersonator;
    }

    /**
     * Hand the session to another user, keeping the admin id so they can step
     * back. The session id is rotated and the cached identity refreshed, so the
     * rest of the request sees the target account and not the admin.
     */
    public function impersonate(int $userId): void
    {
        $admin = $this->user();

        if ($admin === null || $admin['role'] !== 'admin') {
            throw new RuntimeException('Only an admin can impersonate another user.');
        }

        $target = $this->users->findById($userId);

        if ($target === null || $target['role'] === 'admin' || $target['status'] !== 'active') {
            throw new RuntimeException('That account cannot be impersonated.');
        }

        $this->regenerateSession();

        Session::set(self::IMPERSONATOR_KEY, (int) $admin['id']);
        Session::set(self::SESSION_KEY, $userId);

        $this->cachedUser = $target;
        $this->userResolved = true;
    }

    /**
     * Give the session back to the admin who started the impersonation.
     *
     * @return bool False when there is no usable admin to step back to.
     */
    public function stopImpersonating(): bool
    {
        $impersonator = $this->impersonator();

        Session::remove(self::IMPERSONATOR_KEY);

        if ($impersonator === null) {
            return false;
        }

        $this->regenerateSession();

        Session::set(self::SESSION_KEY, (int) $impersonator['id']);

        $this->cachedUser = $impersonator;
        $this->userResolved = true;

        return true;
    }

    public function shouldBeAdmin(string $email): bool
    {
        $allow = array_map('strtolower', (array) ($this->settings['admin_emails'] ?? []));

        return in_array(strtolower($email), $allow, true);
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function maybePromoteAdmin(array $user): array
    {
        if ($user['role'] !== 'admin' && $this->shouldBeAdmin((string) ($user['email'] ?? ''))) {
            $this->users->promote((int) $user['id']);
            $user['role'] = 'admin';
        }

        return $user;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->users->findById($id);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->users->findByEmail(strtolower(trim($email)));
    }

    public function setStatus(int $id, string $status): void
    {
        $this->users->setStatus($id, $status);
    }
}
