<?php

declare(strict_types=1);

namespace Polaris\Services;

/**
 * Minimal Acumbamail client for the subscriber lists.
 *
 * Failures are logged and swallowed so mail-provider outages never break
 * registration, account deletion or cron work.
 */
final class AcumbamailService
{
    private const ENDPOINT = 'https://acumbamail.com/api/1/';
    private const DEFAULT_LOCALE = 'es';

    /** The provider says the address is already on the list. */
    private const ALREADY_SUBSCRIBED = [
        'already exists', 'already subscribed', 'ya existe', 'ya está', 'ya esta',
    ];

    /** The provider says the address is not on the list. */
    private const NOT_SUBSCRIBED = [
        'not found', 'does not exist', "doesn't exist", 'not subscribed', 'no existe', 'no está', 'no esta',
    ];

    /** The provider reports a problem with the request. */
    private const ERRORS = ['error', 'invalid', 'inválido', 'invalido'];

    /** @var (\Closure(string,array<string,scalar>):(string|array<string,mixed>))|null */
    private ?\Closure $transport;

    /** @param array<string,mixed> $settings */
    public function __construct(
        private array $settings,
        ?\Closure $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->settings['enabled'] ?? true)
            && trim((string) ($this->settings['auth_token'] ?? '')) !== ''
            && ($this->listId('en') !== null || $this->listId('es') !== null);
    }

    public function listIdFor(?string $locale): ?int
    {
        return $this->listId($locale);
    }

    public function localeFor(?string $locale): string
    {
        $locale = $locale !== null ? strtolower(trim($locale)) : null;

        if ($locale === 'en' || $locale === 'es') {
            return $locale;
        }

        $default = strtolower(trim((string) ($this->settings['default_locale'] ?? self::DEFAULT_LOCALE)));

        return $default === 'en' ? 'en' : self::DEFAULT_LOCALE;
    }

    public function subscribe(string $email, ?string $locale = null): bool
    {
        $email = strtolower(trim($email));
        $listId = $this->listId($locale);

        if (!$this->isConfigured() || $listId === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $response = $this->call('addSubscriber', [
            'list_id' => $listId,
            'merge_fields[email]' => $email,
            'double_optin' => ($this->settings['double_optin'] ?? false) ? 1 : 0,
            'update_subscriber' => 0,
            'complete_json' => 1,
        ]);

        if (!$response['ok']) {
            error_log(sprintf('[Polaris] Acumbamail addSubscriber failed for %s (HTTP error)', $email));

            return false;
        }

        $body = mb_strtolower($response['body'], 'UTF-8');

        if ($this->mentions($body, self::ALREADY_SUBSCRIBED)) {
            return true;
        }

        if ($this->mentions($body, self::ERRORS)) {
            error_log(sprintf(
                '[Polaris] Acumbamail addSubscriber failed for %s: %s',
                $email,
                $this->excerpt($response['body']),
            ));

            return false;
        }

        return true;
    }

    public function unsubscribe(string $email, ?string $locale = null): bool
    {
        $email = strtolower(trim($email));
        $listId = $this->listId($locale);

        if (!$this->isConfigured() || $listId === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $response = $this->call('deleteSubscriber', [
            'list_id' => $listId,
            'email' => $email,
        ]);

        if (!$response['ok']) {
            error_log(sprintf('[Polaris] Acumbamail deleteSubscriber failed for %s (HTTP error)', $email));

            return false;
        }

        $body = mb_strtolower($response['body'], 'UTF-8');

        if ($this->mentions($body, self::NOT_SUBSCRIBED)) {
            return true;
        }

        if ($this->mentions($body, self::ERRORS)) {
            error_log(sprintf(
                '[Polaris] Acumbamail deleteSubscriber failed for %s: %s',
                $email,
                $this->excerpt($response['body']),
            ));

            return false;
        }

        return true;
    }

    private function listId(?string $locale): ?int
    {
        $key = $this->localeFor($locale) === 'en' ? 'list_en' : 'list_es';
        $id = (int) ($this->settings[$key] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Ask the provider.
     *
     * @param array<string,scalar> $fields
     * @return array{ok:bool,body:string}
     */
    private function call(string $method, array $fields): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $fields);

            return [
                'ok' => true,
                'body' => is_string($response) ? $response : (string) json_encode($response),
            ];
        }

        $payload = http_build_query(array_merge([
            'auth_token' => (string) ($this->settings['auth_token'] ?? ''),
            'response_type' => 'json',
        ], $fields));

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 5,
                // The body of an error is what says what went wrong, so it is
                // read even when the status is not a success.
                'ignore_errors' => true,
            ],
        ]);

        try {
            $raw = @file_get_contents(self::ENDPOINT . $method . '/', false, $context);
        } catch (\Throwable $e) {
            error_log(sprintf('[Polaris] Acumbamail %s request failed: %s', $method, $e->getMessage()));

            return ['ok' => false, 'body' => ''];
        }

        if ($raw === false) {
            error_log(sprintf('[Polaris] Acumbamail %s request failed', $method));

            return ['ok' => false, 'body' => ''];
        }

        return [
            'ok' => $this->statusIsSuccessful(),
            'body' => $raw,
        ];
    }

    /**
     * Whether the last request answered with a success status.
     */
    private function statusIsSuccessful(): bool
    {
        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status >= 200 && $status < 300;
    }

    /**
     * Whether the answer mentions one of the phrases.
     *
     * @param array<int,string> $phrases
     */
    private function mentions(string $body, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($body, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A short excerpt of the provider's answer, safe to write to the log.
     *
     * The answer can echo the request, which carries the auth token, so the
     * token is redacted and the excerpt is cut short.
     */
    private function excerpt(string $body): string
    {
        $token = (string) ($this->settings['auth_token'] ?? '');

        if ($token !== '') {
            $body = str_replace($token, '[redacted]', $body);
        }

        return mb_substr((string) preg_replace('/\s+/', ' ', trim($body)), 0, 200);
    }
}
