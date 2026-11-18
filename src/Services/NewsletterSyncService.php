<?php

declare(strict_types=1);

namespace Polaris\Services;

use Polaris\Auth\NewsletterAudience;
use Polaris\Auth\SubscriberSync;

/**
 * Keeps the provider lists aligned with the active accounts.
 *
 * Failures are logged and swallowed: a mailing-list outage must never break
 * registration, account deletion or cron work.
 */
class NewsletterSyncService implements SubscriberSync
{
    public function __construct(
        private NewsletterAudience $audience,
        private AcumbamailService $acumbamail,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->acumbamail->isConfigured();
    }

    public function syncUser(string $email, ?string $locale): bool
    {
        $targetLocale = $this->acumbamail->localeFor($locale);
        $otherLocale = $targetLocale === 'es' ? 'en' : 'es';

        if (!$this->acumbamail->subscribe($email, $targetLocale)) {
            return false;
        }

        if (!$this->acumbamail->unsubscribe($email, $otherLocale)) {
            error_log(sprintf(
                '[Polaris] newsletter unsubscribe failed for %s (list %s)',
                $email,
                $otherLocale,
            ));

            return false;
        }

        return true;
    }

    /** @return array{synced:int,failed:int} */
    public function syncAll(int $maxConsecutiveFailures = 3): array
    {
        $synced = 0;
        $failed = 0;
        $consecutiveFailures = 0;

        foreach ($this->audience->activeSubscribers() as $user) {
            if ($this->syncUser($user['email'], $user['locale'] ?? null)) {
                $synced++;
                $consecutiveFailures = 0;
                continue;
            }

            $failed++;

            if (++$consecutiveFailures >= $maxConsecutiveFailures) {
                break;
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }
}
