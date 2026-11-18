<?php

declare(strict_types=1);

namespace Polaris\Mail;

/**
 * Transactional email contract used by the kernel.
 *
 * Deliberately two methods. An application that sends its own notifications
 * declares a wider interface extending this one, so the kernel never has to
 * know what an application-specific notification is.
 */
interface MailerInterface
{
    public function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool;

    public function sendMagicLink(string $to, string $link): bool;
}
