<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * One-shot session messages (success/error/info) shown after a redirect.
 */
final class Flash
{
    public function add(string $type, string $message): void
    {
        $messages = Session::get('_flash', []);

        if (!is_array($messages)) {
            $messages = [];
        }

        $messages[] = ['type' => $type, 'message' => $message];
        Session::set('_flash', $messages);
    }

    public function success(string $message): void
    {
        $this->add('success', $message);
    }

    public function error(string $message): void
    {
        $this->add('error', $message);
    }

    public function info(string $message): void
    {
        $this->add('info', $message);
    }

    /** @return array<int,array{type:string,message:string}> */
    public function pull(): array
    {
        $messages = Session::pull('_flash', []);

        return is_array($messages) ? $messages : [];
    }
}
