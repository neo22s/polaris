<?php

declare(strict_types=1);

namespace Polaris\Support;

/**
 * Helpers for turning a stored YouTube link into an embeddable URL.
 *
 * Accepts the common YouTube URL shapes (watch, youtu.be, embed, shorts) and
 * returns a privacy-friendly embed URL, or null when no video id can be found.
 */
final class YouTube
{
    /**
     * Extract the 11-character video id from a YouTube URL.
     */
    public static function id(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        // Callers may already hold an id, so accept either form.
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url) === 1) {
            return $url;
        }

        // The patterns are anchored to the start of the URL and stop at the
        // first separator, so a link that merely mentions YouTube is not
        // mistaken for one.
        $patterns = [
            '~^https?://(?:www\.)?youtu\.be/([A-Za-z0-9_-]{11})(?:[/?#].*)?$~i',
            '~^https?://(?:www\.)?youtube\.com/watch\?(?:[^&]*&)*v=([A-Za-z0-9_-]{11})(?:[&].*)?$~i',
            '~^https?://(?:www\.)?youtube\.com/embed/([A-Za-z0-9_-]{11})(?:[/?#].*)?$~i',
            '~^https?://(?:www\.)?youtube\.com/shorts/([A-Za-z0-9_-]{11})(?:[/?#].*)?$~i',
            '~^https?://(?:www\.)?youtube\.com/live/([A-Za-z0-9_-]{11})(?:[/?#].*)?$~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Build a privacy-friendly embed URL (youtube-nocookie.com) for an iframe.
     */
    public static function embedUrl(?string $url): ?string
    {
        $id = self::id($url);

        return $id === null ? null : 'https://www.youtube-nocookie.com/embed/' . $id;
    }
}
