<?php

declare(strict_types=1);

/*
 * Test bootstrap.
 *
 * The site's own vendor is used for PHPUnit; the kernel is autoloaded through
 * the same composer.json the application uses, so a test sees exactly the code
 * the site runs.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
