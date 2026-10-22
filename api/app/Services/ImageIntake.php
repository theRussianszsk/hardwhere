<?php

namespace App\Services;

use App\Support\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Image boundary: verifies the bytes really are an image, caps dimensions, and re-encodes to a clean JPEG
 * (drops EXIF/GPS and any appended payload) before anything else sees the file.
 */
class ImageIntake
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{bytes: string, mime: string, sha256: string} */
    public function sanitize(UploadedFile $file): array
    {
        $raw = file_get_contents($file->getRealPath());
        $info = $raw === false ? false : @getimagesizefromstring($raw);

        if (! $info || ! in_array($info['mime'], self::ALLOWED, true)) {
            throw ApiException::invalid('invalid_image', 'Upload a JPEG, PNG or WebP photo.');
        }
        if (strlen($raw) > config('hardwhere.ai.max_image_kb') * 1024) {
            throw ApiException::invalid('image_too_large', 'The photo is too large. Maximum is '.(config('hardwhere.ai.max_image_kb') / 1024).' MB.');
        }
        [$w, $h] = $info;
        if ($w < 64 || $h < 64 || $w * $h > 16_000_000) {
            throw ApiException::invalid('invalid_image_size', 'The photo dimensions are not supported.');
        }

        $img = @imagecreatefromstring($raw) ?: throw ApiException::invalid('invalid_image', 'The photo could not be read.');
        $edge = config('hardwhere.ai.max_image_edge');
        if (max($w, $h) > $edge) {
            $img = imagescale($img, $w >= $h ? $edge : (int) round($w * $edge / $h), $w >= $h ? (int) round($h * $edge / $w) : $edge);
        }

        ob_start();
        imagejpeg($img, null, 85);
        $bytes = (string) ob_get_clean();

        return ['bytes' => $bytes, 'mime' => 'image/jpeg', 'sha256' => hash('sha256', $bytes)];
    }

    /** Store on the private disk; returns the relative path. */
    public function store(string $bytes, string $sha256, int $tenantId): string
    {
        $path = "ai-uploads/$tenantId/".substr($sha256, 0, 2)."/$sha256.jpg";
        Storage::disk(config('hardwhere.ai.uploads_disk'))->put($path, $bytes);

        return $path;
    }
}
