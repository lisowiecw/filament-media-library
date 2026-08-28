<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Ingest\ReadableName;

it('reduces a client-supplied path to its basename', function (string $given): void {
    expect(ReadableName::from($given)->originalClientFilename)->toBe('report.pdf');
})->with([
    'unix path' => ['../../etc/report.pdf'],
    'windows path' => ['C:\\Users\\me\\report.pdf'],
]);

it('scrubs the original client filename', function (string $given, string $expected): void {
    expect(ReadableName::from($given)->originalClientFilename)->toBe($expected);
})->with([
    'control characters' => ["re\x00po\x1frt.pdf", 'report.pdf'],
    'C1 characters' => ["re\u{0085}port.pdf", 'report.pdf'],
    'bidi overrides' => ["re\u{202E}gnp.pdf", 'regnp.pdf'],
    'surrounding whitespace' => ["  report.pdf \t", 'report.pdf'],
    'already clean' => ['report.pdf', 'report.pdf'],
]);

it('normalizes the original client filename to NFC', function (): void {
    $decomposed = "cafe\u{0301}.pdf";

    expect(ReadableName::from($decomposed)->originalClientFilename)
        ->toBe("caf\u{00e9}.pdf")
        ->not->toBe($decomposed);
});

it('caps the original client filename at 255 bytes and keeps the extension', function (): void {
    $name = ReadableName::from(str_repeat('a', 300).'.pdf');

    expect(strlen($name->originalClientFilename))->toBeLessThanOrEqual(255)
        ->and($name->originalClientFilename)->toEndWith('.pdf')
        ->and($name->extension)->toBe('pdf');
});

it('caps the original client filename without splitting a multibyte character', function (): void {
    $name = ReadableName::from(str_repeat('ä', 200).'.pdf');

    expect(strlen($name->originalClientFilename))->toBeLessThanOrEqual(255)
        ->and(mb_check_encoding($name->originalClientFilename, 'UTF-8'))->toBeTrue();
});

it('derives the display name by removing the final extension', function (string $given, string $expected): void {
    expect(ReadableName::from($given)->displayName)->toBe($expected);
})->with([
    'simple' => ['report.pdf', 'report'],
    'several dots' => ['report.final.pdf', 'report.final'],
    'no extension' => ['report', 'report'],
    'trailing dot' => ['report.', 'report.'],
    'leading dot is part of the name' => ['.gitignore', '.gitignore'],
    'whitespace runs collapse' => ['team    photo  berlin.jpg', 'team photo berlin'],
    'control characters are gone before the collapse' => ["team\tphoto.jpg", 'teamphoto'],
]);

it('never transliterates or prettifies the display name', function (string $given, string $expected): void {
    expect(ReadableName::from($given)->displayName)->toBe($expected);
})->with([
    'japanese' => ['写真.jpg', '写真'],
    'cyrillic' => ['Отчёт.pdf', 'Отчёт'],
    'separators' => ['pH-meter_v2.png', 'pH-meter_v2'],
    'case' => ['iPhone.HEIC', 'iPhone'],
]);

it('falls back to the whole filename when stripping empties the display name', function (): void {
    expect(ReadableName::from('.env')->displayName)->toBe('.env');
});

it('caps the display name in characters rather than bytes', function (): void {
    $name = ReadableName::from(str_repeat('あ', 300).'.jpg');

    // The byte cap on the filename binds first, so the character cap is a
    // ceiling rather than the thing that trims here.
    expect(mb_strlen($name->displayName))->toBeLessThanOrEqual(255)
        ->and($name->displayName)->toBe(str_repeat('あ', mb_strlen($name->originalClientFilename) - 4));
});

it('takes the extension from the client name, lowercased', function (string $given, ?string $expected): void {
    expect(ReadableName::from($given)->extension)->toBe($expected);
})->with([
    'lowercased' => ['photo.JPG', 'jpg'],
    'several dots' => ['archive.tar.gz', 'gz'],
    'none' => ['report', null],
    'leading dot only' => ['.gitignore', null],
    'trailing dot' => ['report.', null],
]);

it('folds a name for collision comparison', function (string $left, string $right, bool $collides): void {
    expect(ReadableName::fold($left) === ReadableName::fold($right))->toBe($collides);
})->with([
    'case differs' => ['Annual Report', 'annual report', true],
    'whitespace runs differ' => ['Annual   Report', 'Annual Report', true],
    'normalization differs' => ["cafe\u{0301}", "caf\u{00e9}", true],
    'separators differ' => ['annual-report', 'annual report', false],
]);
