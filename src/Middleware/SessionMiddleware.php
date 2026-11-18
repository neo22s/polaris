<?php

declare(strict_types=1);

namespace Polaris\Middleware;

use Polaris\Support\FileSessionHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts the PHP session with a long, self-renewing ("stay signed in") cookie.
 *
 * The cookie is set to `$lifetime` seconds (a year by default) and is re-sent
 * on every request, so the expiry slides forward each time the person comes
 * back: it really means "a year since your last visit". The server-side copy
 * lives in var/sessions under the same lifetime, so the cookie and the stored
 * data always expire together and never fall back to PHP's 24-minute default.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    /** Session key holding the last time the id was rotated. */
    private const ROTATED_AT = '_rotated_at';

    private ?FileSessionHandler $store = null;

    public function __construct(
        private string $name,
        private int $lifetime,
        private int $regenerateMinutes,
        private string $savePath,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->start();
        }

        return $handler->handle($request);
    }

    private function start(): void
    {
        session_name($this->name);

        // The host must not expire a session earlier than our cookie (PHP's
        // default is 24 minutes), and the session must be written on every
        // request so its "last activity" timestamp keeps moving forward.
        ini_set('session.gc_maxlifetime', (string) max(1, $this->lifetime));
        ini_set('session.lazy_write', '0');

        $secure = ($_SERVER['HTTPS'] ?? '') === 'on'
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => $this->lifetime,
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
        ]);

        // Per-app storage: survives the host's /tmp cleanup, and PHP's garbage
        // collector calls our own gc() with the lifetime we configured above.
        $this->store = new FileSessionHandler($this->savePath, $this->lifetime);
        session_set_save_handler($this->store, true);

        $resuming = isset($_COOKIE[$this->name]);

        session_start();

        $rotated = $this->rotateIdIfStale();

        // PHP only sends the session cookie when it mints a NEW id. On every
        // other request the cookie would keep the expiry of the day the person
        // originally signed in, which is what makes sessions die "randomly".
        if ($resuming && !$rotated) {
            $this->renew();
        }
    }

    /**
     * Re-send the session cookie with a fresh expiry.
     */
    private function renew(): void
    {
        setcookie(session_name(), session_id(), [
            'expires' => time() + $this->lifetime,
            'path' => '/',
            'httponly' => true,
            'secure' => ($_SERVER['HTTPS'] ?? '') === 'on'
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Rotate the session id when the current one is older than the configured
     * window, which bounds what a stolen cookie is worth. Returns true when the
     * id was rotated (PHP then emits a fresh cookie itself).
     */
    private function rotateIdIfStale(): bool
    {
        if ($this->regenerateMinutes <= 0) {
            return false;
        }

        $rotatedAt = $_SESSION[self::ROTATED_AT] ?? null;

        if (is_int($rotatedAt) && $rotatedAt + $this->regenerateMinutes * 60 >= time()) {
            return false;
        }

        $oldId = session_id();

        if (!session_regenerate_id(true)) {
            return false;
        }

        // session_regenerate_id() does not reliably call destroy() on the old id
        // when a custom save handler is registered, so drop it explicitly.
        $this->store?->destroy($oldId);

        $_SESSION[self::ROTATED_AT] = time();

        return true;
    }
}
