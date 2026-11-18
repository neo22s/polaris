<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The language directory of the kernel, so a test can build a Translator
// without booting the application.
if (!defined('POLARIS_ROOT')) {
    define('POLARIS_ROOT', dirname(__DIR__));
}
