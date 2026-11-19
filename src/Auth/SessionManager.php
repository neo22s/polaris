<?php

declare(strict_types=1);

namespace Polaris\Auth;

use Polaris\Support\SafeFilesystem;

/**
 * Session id rotation with an explicit cleanup of the old session file.
 *
 * `session_regenerate_id(true)` does not reliably call destroy() on a custom
 * save handler, so the old file is removed here as well: a rotated id must not
 * leave a second, still-valid identity on disk.
 */
final class SessionManager
{
    public function regenerate(string $sessionDirectory): void
    {
        $oldId = session_id();

        if (!session_regenerate_id(true) || !is_string($oldId) || $oldId === '') {
            return;
        }

        $file = rtrim($sessionDirectory, '/') . '/sess_' . $oldId;

        if (is_file($file)) {
            SafeFilesystem::delete($file);
        }
    }
}
