<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Polaris\Mail\Mailer;
use Polaris\Support\Translator;

/**
 * The MIME message, not just the call.
 *
 * A mailer can "send" successfully and still deliver a broken email: the
 * classic failures are an ASCII downgrade that makes the client show the HTML
 * as text, and word wrapping that splits a URL in the middle. Both are checked
 * here by asking PHPMailer for the message it would send.
 */
final class MailerTest extends TestCase
{
    private const LINK = 'https://example.com/auth/0123456789abcdef';

    public function testTheHtmlPartDeclaresUtf8AndUsesQuotedPrintable(): void
    {
        $mailer = new class extends PHPMailer {
            /** @return array{headers:string,body:string} */
            public function capture(): array
            {
                $this->preSend();
                $message = $this->getSentMIMEMessage();

                return ['headers' => $message, 'body' => $message];
            }
        };

        $service = $this->service($mailer);
        $this->assertTrue($service->sendMagicLink('user@example.com', self::LINK));

        $message = $mailer->capture()['body'];

        $this->assertStringContainsString('Content-Type: text/html; charset=utf-8', $message);
        $this->assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $message);
        $this->assertStringNotContainsString('charset=us-ascii', $message);
    }

    public function testTheButtonLinkSurvivesEncoding(): void
    {
        $mailer = new class extends PHPMailer {
            public function message(): string
            {
                $this->preSend();

                return $this->getSentMIMEMessage();
            }
        };

        $this->service($mailer)->sendMagicLink('user@example.com', self::LINK);
        $message = $mailer->message();

        /** @var string $html */
        [$before] = explode('Content-Type: text/html', $message, 2);
        $htmlPart = substr($message, strlen($before));

        // Decode the quoted-printable body the way a client does, then look for
        // the link *and* the markup that makes it clickable.
        $decoded = quoted_printable_decode($htmlPart);

        $this->assertStringContainsString('href="' . self::LINK . '"', $decoded);
        $this->assertStringContainsString('<a href=', $decoded);
        $this->assertStringContainsString('background:#111111', $decoded, 'The button keeps its coloured cell.');
        $this->assertStringNotContainsString("href=\"\n", $decoded, 'The URL must not be wrapped mid-link.');
    }

    public function testTheMessageIsWellFormedHtml(): void
    {
        $mailer = new class extends PHPMailer {
            public function message(): string
            {
                $this->preSend();

                return $this->getSentMIMEMessage();
            }
        };

        $this->service($mailer)->sendMagicLink('user@example.com', self::LINK);
        $message = $mailer->message();

        [$before] = explode('Content-Type: text/html', $message, 2);
        $htmlPart = substr($message, strlen($before));

        // The MIME part starts with its own headers; skip them so libxml sees
        // a document that starts at the doctype.
        $decoded = quoted_printable_decode($htmlPart);
        $decoded = substr($decoded, (int) strpos($decoded, '<!DOCTYPE'));

        libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($decoded);
        $errors = array_filter(libxml_get_errors(), static fn ($error): bool => $error->level >= LIBXML_ERR_ERROR);
        libxml_clear_errors();

        $this->assertSame([], array_map(static fn ($error): string => trim($error->message), $errors));
    }

    public function testThePlainTextAlternativeContainsTheLink(): void
    {
        $mailer = new class extends PHPMailer {
            public function message(): string
            {
                $this->preSend();

                return $this->getSentMIMEMessage();
            }
        };

        $this->service($mailer)->sendMagicLink('user@example.com', self::LINK);

        $this->assertNotEmpty($mailer->AltBody);
        $this->assertStringContainsString(self::LINK, $mailer->AltBody);
    }

    private function service(PHPMailer $mailer): Mailer
    {
        $translator = new Translator(dirname(__DIR__) . '/resources/lang');
        $translator->setLocale('en');

        return new Mailer(
            [
                'app_name' => 'Test',
                'mail' => [
                    'host' => '127.0.0.1',
                    'port' => 1,
                    'secure' => '',
                    'auth' => false,
                    'user' => '',
                    'pass' => '',
                    'from' => 'no-reply@example.com',
                    'from_name' => 'Test',
                ],
            ],
            $translator,
            null,
            static fn (): PHPMailer => $mailer,
        );
    }
}
