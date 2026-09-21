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
     * Falls back to the response's Content-Type header, then finally "bin"
     * if neither gives us anything usable.
     */
    private function guessExtension(?string $path, ?string $contentType): string
    {
        $fromPath = $path ? pathinfo($path, PATHINFO_EXTENSION) : '';

        if ($fromPath !== '') {
            return $fromPath;
        }

        if ($contentType) {
            $mime = trim(explode(';', $contentType)[0]);
            $guessed = MimeTypes::getDefault()->getExtensions($mime)[0] ?? null;

            if ($guessed) {
                return $guessed;
            }
        }

        return 'bin';
    }
}
