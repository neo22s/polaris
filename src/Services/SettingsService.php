<?php

declare(strict_types=1);

namespace Polaris\Services;

use InvalidArgumentException;
use Polaris\Repositories\SettingRepository;
use Polaris\Support\Translator;
use Polaris\Support\Validator;

/**
 * Validated access to the runtime `settings` key/value table.
 *
 * The table is a free-form store, but only a fixed set of keys has a defined
 * meaning. Every write goes through this service, which rejects unknown keys
 * and values that would break the feature reading them, so the admin panel and
 * the API cannot drift apart.
 *
 * The rule map is a constructor argument, not a constant: an application
 * declares its own writable settings without forking the service.
 */
final class SettingsService
{
    /**
     * @param array<string,string> $rules Writable setting key => validation rules.
     */
    public function __construct(
        private SettingRepository $settings,
        private array $rules = [],
    ) {
    }

    /**
     * Validate and persist a setting.
     *
     * @throws InvalidArgumentException When the key is unknown or the value invalid.
     */
    public function set(string $key, string $value): void
    {
        $rule = $this->rules[$key] ?? null;

        if ($rule === null) {
            throw new InvalidArgumentException(Translator::t('validation.setting_unknown', ['key' => $key]));
        }

        $validator = new Validator([$key => $value], [$key => $rule]);

        if ($validator->fails()) {
            throw new InvalidArgumentException((string) $validator->firstError());
        }

        $this->settings->set($key, trim($value));
    }

    /** @return array<int,string> */
    public function writableKeys(): array
    {
        return array_keys($this->rules);
    }
}
