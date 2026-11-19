<?php

declare(strict_types=1);

namespace Polaris\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Polaris\Services\ImageService;
use Polaris\Support\UploadCheck;

/**
 * A filename and a MIME header are client-controlled. Only the bytes should
 * decide whether something is an image, and what reaches uploads/ should be a
 * freshly encoded file, not the payload a client sent.
 */
final class ImageServiceTest extends TestCase
{
    private string $work = '';
    private string $uploads = '';

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/polaris-image-' . bin2hex(random_bytes(6));
        $this->uploads = $this->work . '/uploads';

        mkdir($this->uploads, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploads . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->uploads);

        foreach (glob($this->work . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->work);
    }

    public function testAJpegIsValidatedAndReEncodedUnderANewName(): void
    {
        $source = imagecreatetruecolor(21, 13);
        imagejpeg($source, $this->work . '/photo.jpg');
        imagedestroy($source);

        $uploaded = $this->accept($this->work . '/photo.jpg');
        $result = $this->service()->store($uploaded);

        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertSame(21, $result['width']);
        $this->assertSame(13, $result['height']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $result['file']);
        $this->assertSame('/uploads/' . $result['file'], $result['url']);

        $stored = $this->uploads . '/' . $result['file'];
        $this->assertFileExists($stored);
        $this->assertNotSame((string) file_get_contents($uploaded), (string) file_get_contents($stored));

        $details = getimagesize($stored);
        $this->assertIsArray($details);
        $this->assertSame('image/jpeg', $details['mime']);
    }

    public function testAPhpFileNamedLikeAnImageIsRejected(): void
    {
        file_put_contents($this->work . '/evil.jpg', "<?php echo 'hello'; ?>");
        $uploaded = $this->accept($this->work . '/evil.jpg');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a readable image');

        $this->service()->store($uploaded);
    }

    public function testAnUnsupportedRealImageTypeIsRejected(): void
    {
        if (!function_exists('imagegif')) {
            $this->markTestSkipped('GD has no GIF support here.');
        }

        $source = imagecreatetruecolor(4, 4);
        imagegif($source, $this->work . '/animation.gif');
        imagedestroy($source);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image type');

        $this->service()->store($this->accept($this->work . '/animation.gif'));
    }

    public function testAFileThatWasNotUploadedIsRejected(): void
    {
        $source = imagecreatetruecolor(4, 4);
        imagepng($source, $this->work . '/local.png');
        imagedestroy($source);

        $service = new ImageService($this->uploads, new class implements UploadCheck {
            public function isUploaded(string $path): bool
            {
                return false;
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not uploaded');

        $service->store($this->work . '/local.png');
    }

    public function testAFilesystemCopyCanBeTreatedAsAnUploadForTesting(): void
    {
        $source = imagecreatetruecolor(6, 6);
        imagewebp($source, $this->work . '/photo.webp');
        imagedestroy($source);

        $result = $this->service()->store($this->accept($this->work . '/photo.webp'));

        $this->assertSame('image/webp', $result['mime']);
        $this->assertFileExists($this->uploads . '/' . $result['file']);
    }

    private function service(): ImageService
    {
        return new ImageService($this->uploads, new class implements UploadCheck {
            public function isUploaded(string $path): bool
            {
                return true;
            }
        });
    }

    private function accept(string $path): string
    {
        $copy = $path . '.uploaded';
        copy($path, $copy);

        return $copy;
    }
}
