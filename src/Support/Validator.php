<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Declarative form validation with translated error messages.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $data;

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules field => "required|email|max:255"
     * @param array<string,string> $labels field => "Human label"
     */
    public function __construct(array $data, private array $rules, private array $labels = [])
    {
        $this->data = $data;
        $this->validate();
    }

    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rules = array_filter(explode('|', $ruleString));

            $isRequired = in_array('required', $rules, true);
            $isEmpty = $value === null || (is_string($value) && trim($value) === '');

            if ($isRequired && $isEmpty) {
                $this->errors[$field] = Translator::t('validation.required', ['field' => $this->label($field)]);
                continue;
            }

            if ($isEmpty) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'required') {
                    continue;
                }

                $message = $this->applyRule($field, $value, $rule);
                if ($message !== null) {
                    $this->errors[$field] = $message;
                    break;
                }
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule): ?string
    {
        [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
        $label = $this->label($field);

        return match ($name) {
            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false
                ? Translator::t('validation.email', ['field' => $label]) : null,
            'url' => filter_var((string) $value, FILTER_VALIDATE_URL) === false
                ? Translator::t('validation.url', ['field' => $label]) : null,
            'numeric' => !is_numeric($value) ? Translator::t('validation.numeric', ['field' => $label]) : null,
            'integer' => filter_var($value, FILTER_VALIDATE_INT) === false ? Translator::t('validation.integer', ['field' => $label]) : null,
            'min' => mb_strlen(trim((string) $value)) < (int) $param
                ? Translator::t('validation.min', ['field' => $label, 'min' => (int) $param]) : null,
            'max' => mb_strlen(trim((string) $value)) > (int) $param
                ? Translator::t('validation.max', ['field' => $label, 'max' => (int) $param]) : null,
            'between' => $this->between($value, (string) $param, $label),
            'in' => !in_array((string) $value, explode(',', (string) $param), true)
                ? Translator::t('validation.in', ['field' => $label]) : null,
            'date' => strtotime((string) $value) === false ? Translator::t('validation.date', ['field' => $label]) : null,
            'handle' => preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/', (string) $value) !== 1
                ? Translator::t('validation.handle') : null,
            'youtube' => $this->youtube((string) $value, $label),
            // An unknown rule would silently turn the field into an unvalidated
            // one, so it is a configuration mistake and has to be loud.
            default => throw new \InvalidArgumentException('Unknown validation rule: ' . $name),
        };
    }

    private function between(mixed $value, string $param, string $label): ?string
    {
        [$min, $max] = array_pad(explode(',', $param), 2, null);

        if (!is_numeric($value)) {
            return Translator::t('validation.numeric', ['field' => $label]);
        }

        $number = (float) $value;
        if ($min !== null && $number < (float) $min) {
            return Translator::t('validation.between_min', ['field' => $label, 'min' => $min]);
        }
        if ($max !== null && $number > (float) $max) {
            return Translator::t('validation.between_max', ['field' => $label, 'max' => $max]);
        }

        return null;
    }

    private function youtube(string $value, string $label): ?string
    {
        $pattern = '~^(https?://)?(www\.)?(youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)[A-Za-z0-9_-]{6,}~i';

        return preg_match($pattern, $value) === 1 ? null : Translator::t('validation.youtube', ['field' => $label]);
    }

    private function label(string $field): string
    {
        if (isset($this->labels[$field])) {
            return $this->labels[$field];
        }

        $key = 'field.' . $field;
        if (Translator::instance()->has($key)) {
            return Translator::t($key);
        }

        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : array_values($this->errors)[0];
    }
}
