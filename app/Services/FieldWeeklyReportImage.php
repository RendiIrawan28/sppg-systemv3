<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class FieldWeeklyReportImage
{
    /** Avoid remote URLs, unsafe file types, and oversized PDF payloads. */
    public function dataUri(?string $path, string $storageDisk = 'public'): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $disk = Storage::disk($storageDisk);

        if (! $disk->exists($path) || $disk->size($path) > 5 * 1024 * 1024) {
            return null;
        }

        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }
        try {
            $binary = stream_get_contents($stream, 5 * 1024 * 1024 + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($binary) || strlen($binary) > 5 * 1024 * 1024) {
            return null;
        }

        $details = @getimagesizefromstring($binary);
        if (! $details || ! in_array($details['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        // Compress pictures for small VPS / Dompdf; avoid decoding very large sources.
        if (function_exists('imagecreatefromstring') && ($details[0] * $details[1]) <= 20_000_000) {
            $image = @imagecreatefromstring($binary);
            if ($image !== false) {
                $width = imagesx($image);
                $height = imagesy($image);
                $targetWidth = min(700, $width);
                $targetHeight = max(1, (int) round($height * $targetWidth / max(1, $width)));
                $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                ob_start();
                imagejpeg($canvas, null, 75);
                $compressed = ob_get_clean();
                imagedestroy($canvas);
                imagedestroy($image);

                if (is_string($compressed)) {
                    return 'data:image/jpeg;base64,'.base64_encode($compressed);
                }
            }
        }

        // Dompdf does not reliably support WEBP without conversion.
        return in_array($details['mime'], ['image/jpeg', 'image/png'], true)
            ? 'data:'.$details['mime'].';base64,'.base64_encode($binary)
            : null;
    }
}
