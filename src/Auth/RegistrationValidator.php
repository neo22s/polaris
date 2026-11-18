<?php

declare(strict_types=1);

namespace Polaris\Auth;

use InvalidArgumentException;
use Polaris\Support\Handle;
use Polaris\Support\HandleValidator;
use Polaris\Support\Translator;

/**
 * Account creation rules shared by the starter kit: a valid, unique email and
 * a free, well-formed handle.
 *
 * Registration policy lives here rather than in AuthService so an application
 * that needs different rules (an invitation, a domain allow-list, a different
 * handle format) replaces one small class instead of forking the session code.
 */
final class RegistrationValidator
{
    public function __construct(private UserStore $users)
    {
    }

    /**
     * @return array{error:?string,field:string}
     */
    public function validate(string $email, string $handle): array
    {
        $email = strtolower(trim($email));
        $handle = strtolower(trim($handle));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'error' => Translator::t('validation.email', ['field' => Translator::t('field.email')]),
                'field' => 'email',
            ];
        }

        if ($this->users->findByEmail($email) !== null) {
            return ['error' => Translator::t('validation.email_exists'), 'field' => 'email'];
        }

        if (($error = HandleValidator::check($handle, fn (string $candidate): bool => $this->handleExists($candidate))) !== null) {
            return ['error' => $error, 'field' => 'handle'];
        }

        return ['error' => null, 'field' => ''];
    }

    /**
     * A free handle derived from a display name, or null when the name cannot
     * produce one.
     */
    public function uniqueHandle(string $name): ?string
    {
        return Handle::unique($name, fn (string $candidate): bool => $this->handleExists($candidate));
    }

    /**
     * Whether the handle is taken. Throws when the bound store cannot answer:
     * silently returning false would create duplicate handles, and MySQL would
     * reject the insert with a confusing error instead of a form message.
     */
    public function handleExists(string $handle, ?int $exceptUserId = null): bool
    {
        if (!$this->users instanceof HandleStore) {
            throw new InvalidArgumentException(
                'RegistrationValidator needs a HandleStore to check handle uniqueness.'
            );
        }

        return $this->users->handleExists($handle, $exceptUserId);
    }
}
