<?php

declare(strict_types=1);

namespace Polaris\Mail;

use Polaris\Support\Translator;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

/**
 * Sends transactional email over SMTP (PHPMailer).
 *
 * Not final on purpose: the starter kit ships the login email, and every
 * application adds its own messages by extending this class (see the
 * "Send a custom email" recipe in docs/RECIPES.md). Keeping it open is what
 * stops the kernel from growing a method per project.
 *
 * Two settings are deliberate, because the defaults break in real inboxes:
 *
 * - `Encoding = quoted-printable`. With the default `8bit`, PHPMailer
 *   "downgrades" an ASCII-only body to `charset=us-ascii`, and some relays and
 *   clients then render the HTML as text. Quoted-printable keeps the declared
 *   charset and survives line-length limits, so the markup arrives as HTML.
 * - `CharSet = UTF-8` always. A subject or body with an accent must not depend
 *   on the relay guessing the charset.
 */
class Mailer implements MailerInterface
{
    /**
     * @param array<string,mixed> $settings
     * @param (\Closure():PHPMailer)|null $transport Builds the mailer. A test
     *        replaces it so no message ever reaches a real server.
     */
    public function __construct(
        protected array $settings,
        protected Translator $translator,
        protected ?LoggerInterface $logger = null,
        protected ?\Closure $transport = null,
    ) {
    }

    public function send(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool
    {
        $config = $this->settings['mail'];
        $mail = $this->mailer();
        $mail->isSMTP();
        $mail->Host = (string) $config['host'];
        $mail->Port = (int) $config['port'];
        $mail->SMTPAuth = (bool) $config['auth'];
        $mail->Username = (string) $config['user'];
        $mail->Password = (string) $config['pass'];
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        // No automatic word wrap: it inserts line breaks inside tags and
        // attributes, which is exactly how "the button does not work" emails
        // are made.
        $mail->WordWrap = 0;

        if (($config['secure'] ?? '') === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (($config['secure'] ?? '') === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        try {
            $mail->setFrom((string) $config['from'], (string) $config['from_name']);
            $mail->addAddress($to);

            if ($replyTo !== null && $replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $this->toPlainText($htmlBody);

            $mail->send();

            return true;
        } catch (\Throwable $e) {
            $context = ['to' => $to, 'subject' => $subject];

            if ($this->logger !== null) {
                $this->logger->error('[Polaris] mail send failed: ' . $e->getMessage(), $context);
            } else {
                error_log('[Polaris] mail send failed: ' . $e->getMessage() . ' ' . json_encode($context));
            }

            return false;
        }
    }

    public function sendMagicLink(string $to, string $link): bool
    {
        $appName = $this->appName();

        $content = $this->paragraph($this->translator->trans('mail.magic_greeting'))
            . $this->paragraph($this->translator->trans('mail.magic_body', ['app' => htmlspecialchars($appName)]))
            . $this->button($link, $this->translator->trans('mail.magic_button'))
            . $this->paragraph($this->translator->trans('mail.magic_expires'))
            . $this->fallbackLink($link, $this->translator->trans('mail.magic_fallback'))
            . $this->small($this->translator->trans('mail.magic_ignore'));

        return $this->send(
            $to,
            $this->translator->trans('mail.magic_subject', ['app' => $appName]),
            $this->layout($this->translator->trans('mail.magic_heading'), $content),
        );
    }

    protected function appName(): string
    {
        return (string) ($this->settings['app_name'] ?? 'Polaris');
    }

    /**
     * The HTML shell shared by every message.
     *
     * Table-based on purpose: Outlook ignores padding and background colours on
     * inline elements, so a styled `<a>` is not a button there. A table cell
     * with a background colour and a plain anchor is the shape that works
     * everywhere, including Gmail's clipping.
     *
     * An application overrides this to use its own branding and colours, which
     * keeps every email in the project looking the same without editing the
     * kernel.
     */
    protected function layout(string $heading, string $content): string
    {
        $app = htmlspecialchars($this->appName());

        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" '
            . '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" lang="' . htmlspecialchars($this->translator->getLocale()) . '">' . "\n"
            . '<head>' . "\n"
            . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="x-apple-disable-message-reformatting">' . "\n"
            . '<title>' . htmlspecialchars($heading) . '</title>' . "\n"
            . '</head>' . "\n"
            . '<body style="margin:0;padding:0;background:#f5f5f5;">' . "\n"
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">' . "\n"
            . '<tr><td align="center" style="padding:32px 16px;">' . "\n"
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" '
            . 'style="max-width:560px;width:100%;">' . "\n"
            . '<tr><td style="padding:0 0 16px 0;font-size:20px;letter-spacing:1px;text-transform:uppercase;">'
            . htmlspecialchars($heading) . '</td></tr>' . "\n"
            . '<tr><td style="background:#ffffff;border:1px solid #eeeeee;padding:24px;">' . "\n"
            . $content
            . '</td></tr>' . "\n"
            . '<tr><td style="padding:16px 0;text-align:center;color:#aaaaaa;font-size:12px;">'
            . $app . '</td></tr>' . "\n"
            . '</table>' . "\n"
            . '</td></tr></table>' . "\n"
            . '</body></html>';
    }

    /** A paragraph with the shared spacing. */
    protected function paragraph(string $html): string
    {
        return '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;">' . $html . '</p>';
    }

    /** A subdued paragraph, used for the small print. */
    protected function small(string $html): string
    {
        return '<p style="margin:16px 0 0 0;color:#888888;font-size:12px;line-height:1.5;">' . $html . '</p>';
    }

    /**
     * A button that survives Outlook and Gmail.
     *
     * The anchor is inside the coloured cell, not styled itself, so a client
     * that drops inline styles on anchors still shows a usable link.
     */
    protected function button(string $url, string $label): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">' . "\n"
            . '<tr><td align="center" style="background:#111111;border-radius:4px;">'
            . '<a href="' . $safeUrl . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;padding:14px 28px;color:#ffffff;text-decoration:none;'
            . 'font-size:15px;font-weight:bold;">' . $label . '</a>'
            . '</td></tr></table>';
    }

    /** The plain-text copy of a link, for clients that refuse the button. */
    protected function fallbackLink(string $url, string $lead): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<p style="margin:16px 0 0 0;color:#888888;font-size:12px;line-height:1.5;">' . $lead . '<br>'
            . '<a href="' . $safeUrl . '" style="color:#888888;word-break:break-all;">' . $safeUrl . '</a>'
            . '</p>';
    }

    /**
     * The mailer used for a message.
     *
     * The transport is replaceable, so a test never has to reach a server.
     */
    protected function mailer(): PHPMailer
    {
        if ($this->transport !== null) {
            return ($this->transport)();
        }

        return new PHPMailer(true);
    }

    protected function toPlainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/(p|div|h1|h2|h3|li|tr|td|table)>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<a[^>]+href=(["\'])(.+?)\1[^>]*>/i', ' $2 ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
