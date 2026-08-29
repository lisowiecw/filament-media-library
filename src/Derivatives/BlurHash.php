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
     * The hash read back as a small grid of sRGB colours, sampled at the
     * centre of each cell, or null when the string is not a hash this can
     * trust: a stored value is data, and a card is not the place to discover
     * that a column holds something else.
     *
     * @return list<array{int, int, int}>|null
     */
    public static function decode(string $hash, int $across, int $down): ?array
    {
        $components = self::components($hash);

        if ($components === null) {
            return null;
        }

        [$componentsX, $componentsY, $factors] = $components;

        $grid = [];

        for ($y = 0; $y < $down; $y++) {
            for ($x = 0; $x < $across; $x++) {
                $positionX = ($x + 0.5) / $across;
                $positionY = ($y + 0.5) / $down;

                $colour = [0.0, 0.0, 0.0];

                for ($componentY = 0; $componentY < $componentsY; $componentY++) {
                    for ($componentX = 0; $componentX < $componentsX; $componentX++) {
                        $basis = cos(M_PI * $componentX * $positionX) * cos(M_PI * $componentY * $positionY);
                        $factor = $factors[$componentY * $componentsX + $componentX];

                        $colour[0] += $factor[0] * $basis;
                        $colour[1] += $factor[1] * $basis;
                        $colour[2] += $factor[2] * $basis;
                    }
                }

                $grid[] = [self::srgb($colour[0]), self::srgb($colour[1]), self::srgb($colour[2])];
            }
        }

        return $grid;
    }

    /**
     * The hash split into its component count and its linear-light factors.
     *
     * @return array{int, int, list<array{float, float, float}>}|null
     */
    private static function components(string $hash): ?array
    {
        if (mb_strlen($hash) < 6) {
            return null;
        }

        $sizeFlag = self::base83Decode(mb_substr($hash, 0, 1));
        $quantisedMax = self::base83Decode(mb_substr($hash, 1, 1));

        if ($sizeFlag === null || $quantisedMax === null) {
            return null;
        }

        $componentsX = $sizeFlag % 9 + 1;
        $componentsY = intdiv($sizeFlag, 9) + 1;

        if (mb_strlen($hash) !== 6 + 2 * ($componentsX * $componentsY - 1)) {
            return null;
        }

        $direct = self::base83Decode(mb_substr($hash, 2, 4));

        if ($direct === null) {
            return null;
        }

        $maximum = ($quantisedMax + 1) / 166;
        $factors = [[
            self::linear($direct >> 16 & 255),
            self::linear($direct >> 8 & 255),
            self::linear($direct & 255),
        ]];

        for ($index = 1; $index < $componentsX * $componentsY; $index++) {
            $alternating = self::base83Decode(mb_substr($hash, 4 + $index * 2, 2));

            if ($alternating === null) {
                return null;
            }

            $factors[] = self::decodeAlternating($alternating, $maximum);
        }

        return [$componentsX, $componentsY, $factors];
    }

    /**
     * @return array{float, float, float}
     */
    private static function decodeAlternating(int $value, float $maximum): array
    {
        $dequantise = static fn (int $quantised): float => self::signedPow(($quantised - 9) / 9, 2.0) * $maximum;

        return [
            $dequantise(intdiv($value, 19 * 19)),
            $dequantise(intdiv($value, 19) % 19),
            $dequantise($value % 19),
        ];
    }

    private static function base83Decode(string $encoded): ?int
    {
        if ($encoded === '') {
            return null;
        }

        $value = 0;

        foreach (mb_str_split($encoded) as $character) {
            $digit = mb_strpos(self::ALPHABET, $character);

            if ($digit === false) {
                return null;
            }

            $value = $value * 83 + $digit;
        }

        return $value;
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

        // Truncated rather than rounded, because the `+ 0.5` is the rounding:
        // rounding on top of it biases every channel a step bright, which only
        // stays invisible while the same code both writes and reads the hash.
        return $value <= 0.0031308
            ? (int) ($value * 12.92 * 255 + 0.5)
            : (int) ((1.055 * $value ** (1 / 2.4) - 0.055) * 255 + 0.5);
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
