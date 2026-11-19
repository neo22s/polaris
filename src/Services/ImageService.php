<?php

declare(strict_types=1);

namespace Polaris\Services;

use InvalidArgumentException;
use Polaris\Support\SafeFilesystem;
use Polaris\Support\UploadCheck;
use RuntimeException;

/**
 * Validates and re-encodes uploaded images.
 *
 * Neither the filename nor the declared MIME type is evidence of anything: both
 * come from the client. The type is detected from the bytes, checked against a
 * small allowlist, and the pixels are decoded and encoded again into a new file.
 * Re-encoding drops metadata and any payload hidden in an apparently valid
 * image, so what lands in uploads/ is always a fresh, inert image file.
 */
final class ImageService
{
    /** Detected MIME type => encoder/decoder and the extension we choose. */
    private const FORMATS = [
        'image/jpeg' => ['jpeg', 'jpg'],
        'image/png' => ['png', 'png'],
        'image/webp' => ['webp', 'webp'],
    ];

    public function __construct(
        private string $directory,
        private UploadCheck $uploadCheck,
        private int $maxBytes = 5 * 1024 * 1024,
        private int $maxPixels = 16_000_000,
        private string $publicPath = '/uploads',
    ) {
    }

    /**
     * @return array{file:string,url:string,width:int,height:int,mime:string}
     */
    public function store(string $temporaryPath): array
    {
        if (!$this->uploadCheck->isUploaded($temporaryPath)) {
            throw new InvalidArgumentException('The file was not uploaded in this request.');
        }

        if (!is_file($temporaryPath)) {
            throw new InvalidArgumentException('The uploaded file does not exist.');
        }

        $size = filesize($temporaryPath);

        if ($size === false || $size < 1 || $size > $this->maxBytes) {
            throw new InvalidArgumentException('The uploaded file is empty or too large.');
        }

        $mime = $this->detectMime($temporaryPath);
        $format = self::FORMATS[$mime] ?? null;

        if ($format === null) {
            throw new InvalidArgumentException('Unsupported image type: ' . $mime);
        }

        if (!extension_loaded('gd')) {
            throw new RuntimeException('The gd extension is required to store images.');
        }

        $decoder = 'imagecreatefrom' . $format[0];
        $source = $decoder($temporaryPath);

        if ($source === false) {
            throw new InvalidArgumentException('The image is corrupt or not what its header claims.');
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width < 1 || $height < 1 || $width * $height > $this->maxPixels) {
            imagedestroy($source);
            throw new InvalidArgumentException('The image dimensions are too large.');
        }

        $target = imagecreatetruecolor($width, $height);

        if ($target === false) {
            imagedestroy($source);
            throw new RuntimeException('Could not allocate the output image.');
        }

        // PNG and WebP keep transparency; a freshly created JPEG canvas is
        // black unless it is filled, which would surprise anyone uploading a
        // PNG with an alpha channel and asking for a JPEG.
        if ($mime !== 'image/jpeg') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        }

        if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $width, $height)) {
            imagedestroy($source);
            imagedestroy($target);
            throw new RuntimeException('Could not re-encode the image.');
        }

        imagedestroy($source);

        $file = bin2hex(random_bytes(16)) . '.' . $format[1];
        $destination = rtrim($this->directory, '/') . '/' . $file;
        $temporary = $destination . '.tmp';

        try {
            $this->encode($target, $temporary, $mime);
        } finally {
            imagedestroy($target);
        }

        if (!rename($temporary, $destination)) {
            SafeFilesystem::delete($temporary);
            throw new RuntimeException('Could not store the encoded image.');
        }

        return [
            'file' => $file,
            'url' => rtrim($this->publicPath, '/') . '/' . $file,
            'width' => $width,
            'height' => $height,
            'mime' => $mime,
        ];
    }

    private function detectMime(string $path): string
    {
        // getimagesize() warns on a non-image; that is the case being rejected
        // here, so the warning would only add noise to a validation failure.
        $details = @getimagesize($path);

        if ($details === false || !isset($details['mime']) || !is_string($details['mime'])) {
            throw new InvalidArgumentException('The file is not a readable image.');
        }

        $mime = $details['mime'];

        // finfo is a second, independent opinion. A polyglot that fools one of
        // the two is rejected; a mismatch means somebody renamed a file.
        if (function_exists('finfo_open')) {
            $info = finfo_open(FILEINFO_MIME_TYPE);

            if ($info !== false) {
                $detected = finfo_file($info, $path);
                finfo_close($info);

                if (is_string($detected) && $detected !== '' && $detected !== $mime) {
                    throw new InvalidArgumentException('The image type does not match its content.');
                }
            }
        }

        return $mime;
    }

    private function encode(\GdImage $image, string $path, string $mime): void
    {
        $result = match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, 85),
            'image/png' => imagepng($image, $path, 6),
            'image/webp' => imagewebp($image, $path, 80),
        };

        if ($result !== true) {
            throw new RuntimeException('Could not encode the image.');
        }
    }
}
