<?php

namespace App\Services\Messengers;

use Symfony\Component\Mime\MimeTypes;

trait GuessesFileExtension
{
    /**
     * The URL/file path alone isn't reliable for guessing an extension -
     * e.g. Rubika's download URL has no file extension in it at all, which
     * was silently falling back to ".bin" for every downloaded file even
     * though the content itself (and viewers opening it) were always fine.
     * Falling back to the response's Content-Type header isn't enough
     * either - Bale's file download response sends a generic
     * "application/octet-stream" regardless of the actual file, so as a
     * last resort before giving up, this sniffs the real MIME type from
     * the downloaded bytes themselves (the same magic-number detection
     * `file` uses), which doesn't depend on any platform reporting
     * anything correctly.
     */
    private function guessExtension(?string $path, ?string $contentType, ?string $contents = null): string
    {
        $fromPath = $path ? pathinfo($path, PATHINFO_EXTENSION) : '';

        if ($fromPath !== '') {
            return $fromPath;
        }

        if ($extension = $this->extensionForMime($contentType)) {
            return $extension;
        }

        if ($contents !== null) {
            $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: null;

            if ($extension = $this->extensionForMime($sniffed)) {
                return $extension;
            }
        }

        return 'bin';
    }

    private function extensionForMime(?string $mimeType): ?string
    {
        if (! $mimeType) {
            return null;
        }

        $mimeType = trim(explode(';', $mimeType)[0]);

        // Generic/unhelpful - not worth trusting even if a platform sends it.
        if (in_array($mimeType, ['application/octet-stream', 'binary/octet-stream'], true)) {
            return null;
        }

        return MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null;
    }
}
