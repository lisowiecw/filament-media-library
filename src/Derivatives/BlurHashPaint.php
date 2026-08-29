<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

/**
 * A BlurHash as a CSS declaration a card can wear: an average colour with a
 * few soft gradients over it.
 *
 * The package ships no JavaScript and no stylesheet, so painting the hash is
 * done with the two things a card already has, which are PHP and a style
 * attribute. That costs a few hundred bytes per pending card and needs no
 * decoder in the browser. It is deliberately coarser than a real decode: the
 * hash rides along as `data-blurhash` for a consumer who wants one.
 */
final readonly class BlurHashPaint
{
    /**
     * Cells across and down. Three by three is what reads as the picture at
     * this size: fewer loses the composition, more only lengthens the
     * attribute on every card in the grid.
     */
    private const int CELLS = 3;

    /**
     * How far each gradient reaches. Past half the tile the cells overlap
     * enough to blend into one another rather than stack as visible blobs.
     */
    private const string REACH = '66%';

    /**
     * The `style` attribute for a pending tile, or null when there is nothing
     * trustworthy to paint from.
     */
    public static function css(string $hash): ?string
    {
        $grid = BlurHash::decode($hash, self::CELLS, self::CELLS);

        if ($grid === null) {
            return null;
        }

        $gradients = [];

        foreach ($grid as $index => $colour) {
            $x = round(($index % self::CELLS + 0.5) / self::CELLS * 100, 1);
            $y = round((intdiv($index, self::CELLS) + 0.5) / self::CELLS * 100, 1);

            $gradients[] = sprintf(
                'radial-gradient(at %s%% %s%%,%s,transparent %s)',
                $x,
                $y,
                self::hex($colour),
                self::REACH,
            );
        }

        return sprintf(
            'background-color:%s;background-image:%s',
            self::hex(self::average($grid)),
            implode(',', $gradients),
        );
    }

    /**
     * @param  list<array{int, int, int}>  $grid
     * @return array{int, int, int}
     */
    private static function average(array $grid): array
    {
        $count = count($grid);

        return [
            (int) round(array_sum(array_column($grid, 0)) / $count),
            (int) round(array_sum(array_column($grid, 1)) / $count),
            (int) round(array_sum(array_column($grid, 2)) / $count),
        ];
    }

    /**
     * @param  array{int, int, int}  $colour
     */
    private static function hex(array $colour): string
    {
        return sprintf('#%02x%02x%02x', ...$colour);
    }
}
