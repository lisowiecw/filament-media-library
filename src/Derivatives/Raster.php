<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use GdImage;

/**
 * A decoded raster and the two things the pipeline does to one: downscale it,
 * and encode it as WEBP.
 *
 * The decode is the expensive part of generating a derivative, which is why
 * the blurhash is taken from here rather than from a second pass over the
 * original: the job already holds the pixels.
 *
 * Everything here is PHP's bundled image extension. Nothing shells out, so
 * installing the package stays a Composer install.
 */
final readonly class Raster
{
    private function __construct(private GdImage $image) {}

    /**
     * A raster from stored bytes, or null when nothing here is an image the
     * runtime can decode. A file that cannot be decoded is not an error to
     * throw at this level: the caller records it as a failure with a reason.
     */
    public static function decode(string $bytes): ?self
    {
        if ($bytes === '') {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        return $image === false ? null : new self($image);
    }

    public function width(): int
    {
        return imagesx($this->image);
    }

    public function height(): int
    {
        return imagesy($this->image);
    }

    public function longestEdge(): int
    {
        return max($this->width(), $this->height());
    }

    /**
     * The same picture with its longest edge at most `$edge`. Never an
     * upscale: an original already inside the box is handed back untouched,
     * so a small image is re-encoded rather than blown up.
     */
    public function scaledToEdge(int $edge): self
    {
        if ($edge <= 0 || $this->longestEdge() <= $edge) {
            return $this;
        }

        $ratio = $edge / $this->longestEdge();

        $scaled = imagescale(
            $this->image,
            max(1, (int) round($this->width() * $ratio)),
            max(1, (int) round($this->height() * $ratio)),
        );

        return $scaled === false ? $this : new self($scaled);
    }

    public function webp(int $quality): string
    {
        // Alpha is kept, since a logo on transparency is exactly the kind of
        // image a card is asked to paint.
        imagepalettetotruecolor($this->image);
        imagealphablending($this->image, false);
        imagesavealpha($this->image, true);

        ob_start();
        imagewebp($this->image, null, $quality);

        return (string) ob_get_clean();
    }

    /**
     * The BlurHash of this raster, computed from the pixels already in hand.
     */
    public function blurhash(): string
    {
        return BlurHash::encode($this);
    }

    /**
     * One pixel's sRGB channels, as the blurhash encoder wants them.
     *
     * @return array{int, int, int}
     */
    public function pixel(int $x, int $y): array
    {
        $colour = imagecolorat($this->image, $x, $y);

        return [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF];
    }
}
