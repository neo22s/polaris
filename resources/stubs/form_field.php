<?php

declare(strict_types=1);

/*
 * One field of the form scaffold.
 *
 * Supplied by CrudGenerator: $name, $label, $error, $required, $input.
 * The Twig snippets built for $input and $error are deliberate strings: every
 * field type renders different markup, and the expressions stay in one place.
 */

return <<<TWIG

        <div>
            <label for="{$name}" class="block text-sm font-medium">{$label}</label>{$input}
            {$error}
        </div>
TWIG;
