<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Derivatives\BlurHash;
use Lisowiecw\MediaLibrary\Derivatives\BlurHashPaint;
use Lisowiecw\MediaLibrary\Derivatives\Raster;

it('paints a hash as a stack of gradients over its average colour', function (): void {
    $css = BlurHashPaint::css('L6PZfSi_.AyE_3t7t7R**0o#DgR4');

    expect($css)->toBeString()
        ->and(substr_count((string) $css, 'radial-gradient'))->toBe(9)
        ->and($css)->toContain('background-color:#');
});

it('paints nothing at all from a string that is not a hash', function (string $hash): void {
    expect(BlurHashPaint::css($hash))->toBeNull();
})->with([
    'empty' => '',
    'too short for its size flag' => 'L6PZ',
    'a character outside base83' => 'L6PZfSi_.AyE_3t7t7R**0o#DgR/',
    'a length the size flag does not agree with' => 'L6PZfSi_.AyE_3t7t7R**0o#Dg',
]);

it('reads the average colour of a hash back out of it', function (): void {
    // A flat mid-grey encodes to a hash whose direct component is that grey,
    // so the painted background colour is recognisably it.
    $grey = imagecreatetruecolor(64, 64);
    imagefilledrectangle($grey, 0, 0, 63, 63, imagecolorallocate($grey, 128, 128, 128));
    ob_start();
    imagepng($grey);
    $bytes = (string) ob_get_clean();

    $raster = Raster::decode($bytes);
    $hash = BlurHash::encode($raster);

    preg_match('/background-color:#([0-9a-f]{6})/', (string) BlurHashPaint::css($hash), $matches);

    $channels = array_map(hexdec(...), str_split($matches[1], 2));

    expect($channels[0])->toBeGreaterThan(120)->toBeLessThan(136)
        ->and($channels[1])->toBe($channels[0])
        ->and($channels[2])->toBe($channels[0]);
});
