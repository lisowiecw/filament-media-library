<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

/**
 * The compact blurred placeholder a card paints while its thumbnail is in
 * flight, encoded to the BlurHash string format.
 *
 * It is computed here rather than fetched, because the alternative to a string
 * on the row is a second tiny object per asset, and reads are billed to the
 * operator. The implementation is the reference algorithm; it lives in the
 * package so no optional binary or extra dependency is needed to get one.
 */
final readonly class BlurHash
{
    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';

    /**
     * Components across and down. Four by three is the usual choice for a
     * landscape-ish card: enough structure to read as the picture, short
     * enough to sit in a string column.
     */
    private const int COMPONENTS_X = 4;

    private const int COMPONENTS_Y = 3;

    /**
     * The grid the hash is computed over. A blurhash is a handful of low
     * frequencies, so sampling beyond this changes nothing anybody can see and
     * costs a pixel read per sample.
     */
    private const int SAMPLES = 32;

    public static function encode(Raster $raster): string
    {
        [$width, $height, $pixels] = self::sample($raster);

        $factors = [];

        for ($y = 0; $y < self::COMPONENTS_Y; $y++) {
            for ($x = 0; $x < self::COMPONENTS_X; $x++) {
                $factors[] = self::basis($pixels, $width, $height, $x, $y);
            }
        }

        $direct = array_shift($factors);
        $hash = self::base83(self::COMPONENTS_X - 1 + (self::COMPONENTS_Y - 1) * 9, 1);

        $maximum = 0.0;

        foreach ($factors as $factor) {
            $maximum = max($maximum, ...array_map(abs(...), $factor));
        }

        $quantisedMax = $factors === [] ? 0 : max(0, min(82, (int) floor($maximum * 166 - 0.5)));
        $actualMax = $factors === [] ? 1.0 : ($quantisedMax + 1) / 166;

        $hash .= self::base83($quantisedMax, 1);
        $hash .= self::base83(self::encodeDirect($direct), 4);

        foreach ($factors as $factor) {
            $hash .= self::base83(self::encodeAlternating($factor, $actualMax), 2);
        }

        return $hash;
    }

    /**
     * The sampled grid, as linear-light channels in row-major order.
     *
     * @return array{int, int, list<array{float, float, float}>}
     */
    private static function sample(Raster $raster): array
    {
        $width = max(1, min(self::SAMPLES, $raster->width()));
        $height = max(1, min(self::SAMPLES, $raster->height()));

        $pixels = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                [$r, $g, $b] = $raster->pixel(
                    (int) floor($x * $raster->width() / $width),
                    (int) floor($y * $raster->height() / $height),
                );

                $pixels[] = [self::linear($r), self::linear($g), self::linear($b)];
            }
        }

        return [$width, $height, $pixels];
    }

    /**
     * One cosine component of the image, averaged over every sample.
     *
     * @param  list<array{float, float, float}>  $pixels
     * @return array{float, float, float}
     */
    private static function basis(array $pixels, int $width, int $height, int $componentX, int $componentY): array
    {
        $normalisation = $componentX === 0 && $componentY === 0 ? 1 : 2;
        $sum = [0.0, 0.0, 0.0];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $basis = cos(M_PI * $componentX * $x / $width) * cos(M_PI * $componentY * $y / $height);
                $pixel = $pixels[$y * $width + $x];

                $sum[0] += $basis * $pixel[0];
                $sum[1] += $basis * $pixel[1];
                $sum[2] += $basis * $pixel[2];
            }
        }

        $scale = $normalisation / ($width * $height);

        return [$sum[0] * $scale, $sum[1] * $scale, $sum[2] * $scale];
    }

    /**
     * @param  array{float, float, float}  $factor
     */
    private static function encodeDirect(array $factor): int
    {
        return (self::srgb($factor[0]) << 16) + (self::srgb($factor[1]) << 8) + self::srgb($factor[2]);
    }

    /**
     * @param  array{float, float, float}  $factor
     */
    private static function encodeAlternating(array $factor, float $maximum): int
    {
        $quantise = static fn (float $value): int => max(0, min(18, (int) floor(
            self::signedPow($value / $maximum, 0.5) * 9 + 9.5,
        )));

        return $quantise($factor[0]) * 19 * 19 + $quantise($factor[1]) * 19 + $quantise($factor[2]);
    }

    private static function signedPow(float $value, float $exponent): float
    {
        return ($value < 0 ? -1 : 1) * abs($value) ** $exponent;
    }

    private static function linear(int $channel): float
    {
        $value = $channel / 255;

        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }

    private static function srgb(float $value): int
    {
        $value = max(0.0, min(1.0, $value));

        return $value <= 0.0031308
            ? (int) round($value * 12.92 * 255 + 0.5)
            : (int) round((1.055 * $value ** (1 / 2.4) - 0.055) * 255 + 0.5);
    }

    private static function base83(int $value, int $length): string
    {
        $encoded = '';

        for ($position = 1; $position <= $length; $position++) {
            $digit = (int) ($value / 83 ** ($length - $position)) % 83;
            $encoded .= self::ALPHABET[$digit];
        }

        return $encoded;
    }
}
