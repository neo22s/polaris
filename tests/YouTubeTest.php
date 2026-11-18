<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\YouTube;
use PHPUnit\Framework\TestCase;

final class YouTubeTest extends TestCase
{
    public function testExtractsIdFromCommonUrls(): void
    {
        $this->assertSame('dQw4w9WgXcQ', YouTube::id('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', YouTube::id('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', YouTube::id('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s'));
        $this->assertSame('dQw4w9WgXcQ', YouTube::id('https://www.youtube.com/embed/dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', YouTube::id('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
    }

    public function testReturnsNullForInvalid(): void
    {
        $this->assertNull(YouTube::id('https://vimeo.com/123456'));
        $this->assertNull(YouTube::id(''));
        $this->assertNull(YouTube::id(null));
    }

    public function testALinkThatOnlyMentionsYoutubeIsNotAVideo(): void
    {
        // A page that links to a video is not itself a video.
        $this->assertNull(YouTube::id('https://evil.example/youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(YouTube::id('https://evil.example/youtu.be/dQw4w9WgXcQ'));
    }

    public function testTheVideoIdHasToBeTheWholeParameter(): void
    {
        $this->assertNull(YouTube::id('https://www.youtube.com/watch?v=dQw4w9WgXcQextra'));
    }

    public function testBuildsNoCookieEmbedUrl(): void
    {
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            YouTube::embedUrl('https://youtu.be/dQw4w9WgXcQ'),
        );
        $this->assertNull(YouTube::embedUrl('nope'));
    }
}
