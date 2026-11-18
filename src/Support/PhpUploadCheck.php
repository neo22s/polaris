<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * The upload check of a running web server: only the files PHP received from
 * a request pass.
 */
final class PhpUploadCheck implements UploadCheck
{
    public function isUploaded(string $path): bool
    {
        return is_uploaded_file($path);
    }
}
