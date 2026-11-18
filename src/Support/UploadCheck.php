<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Tells whether a file is one PHP received from a request.
 *
 * The answer depends on the environment, so it is an interface: the web
 * implementation asks PHP, and a test can ask for something else.
 */
interface UploadCheck
{
    public function isUploaded(string $path): bool;
}
