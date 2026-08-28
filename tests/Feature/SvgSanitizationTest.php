<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Exceptions\IngestRefused;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Ingest\SvgSanitization;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

function svgUpload(string $markup, string $name = 'logo.svg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $markup);
}

function storedBytes(MediaAsset $asset): string
{
    return (string) Storage::disk($asset->disk)->get($asset->object_key);
}

$scripted = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect onclick="alert(1)" width="10" height="10"/></svg>';

it('stores only the sanitized bytes of an svg', function () use ($scripted): void {
    $asset = ingest(svgUpload($scripted));

    $bytes = storedBytes($asset);

    expect($bytes)->not->toContain('<script')
        ->and($bytes)->not->toContain('onclick')
        ->and($bytes)->toContain('<rect');
});

it('records the stored size of the sanitized bytes rather than the upload', function () use ($scripted): void {
    $asset = ingest(svgUpload($scripted));

    expect($asset->size)->toBe(strlen(storedBytes($asset)));
});

// The matcher covers `url()` values alone; the references it misses are what
// the Delivery route's content policy and, on public placement, the Strict
// pass are there for (ADR-0005).
it('strips the remote references the sanitizer can see', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(\'https://tracker.example/x\')" width="1" height="1"/></svg>',
    ));

    expect(storedBytes($asset))->not->toContain('tracker.example');
});

it('refuses an svg whose markup cannot be parsed', function (): void {
    ingest(svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><rect'));
})->throws(IngestRefused::class, 'could not be sanitized');

it('refuses markup whose sanitized root is not an svg element', function (): void {
    (new SvgSanitization)->sanitize('<config><value>hi</value></config>', 'logo.svg', strict: false);
})->throws(IngestRefused::class, 'could not be sanitized');

it('stores nothing when an svg cannot be sanitized', function (): void {
    try {
        ingest(svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><rect'));
    } catch (IngestRefused) {
        // The refusal is the point; what matters is what it left behind.
    }

    expect(Storage::disk(Placement::resolve()->disk)->allFiles())->toBeEmpty();
});

it('refuses every svg when the sanitizer is not installed', function (): void {
    $this->app->instance(SvgSanitization::class, new SvgSanitization(sanitizerAvailable: false));

    ingest(svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'));
})->throws(IngestRefused::class, 'could not be sanitized');

it('accepts an svg with a style block on a private placement', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg"><style>rect{fill:red}</style><rect width="1" height="1"/></svg>',
    ));

    expect(storedBytes($asset))->toContain('<style');
});

it('refuses a public svg that carries a style block, naming the element', function (): void {
    ingest(
        svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><style>rect{fill:red}</style><rect width="1" height="1"/></svg>'),
        placement: new Placement(disk: 'media', directory: 'media', visibility: 'public'),
    );
})->throws(IngestRefused::class, 'style');

it('refuses a public svg that embeds an image, naming the element', function (): void {
    ingest(
        svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><image width="1" height="1"/></svg>'),
        placement: new Placement(disk: 'media', directory: 'media', visibility: 'public'),
    );
})->throws(IngestRefused::class, 'image');

it('refuses a public svg that carries a link, naming the element', function (): void {
    ingest(
        svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><a><rect width="1" height="1"/></a></svg>'),
        placement: new Placement(disk: 'media', directory: 'media', visibility: 'public'),
    );
})->throws(IngestRefused::class, 'a');

it('accepts a plain public svg and stores the strictly sanitized bytes', function (): void {
    $asset = ingest(
        svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>'),
        placement: new Placement(disk: 'media', directory: 'media', visibility: 'public'),
    );

    expect(storedBytes($asset))->not->toContain('<script')
        ->and($asset->visibility)->toBe('public');
});

it('is its own thumbnail, so it writes no second object', function (): void {
    ingest(svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'));

    expect(Storage::disk(Placement::resolve()->disk)->allFiles())->toHaveCount(1);
});

// The Strict pass narrows elements alone, so nothing is stripped without a
// refusal: an internal reference survives a public upload untouched.
it('keeps an internal reference on a public svg', function (): void {
    $asset = ingest(
        svgUpload(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<defs><rect id="box" width="1" height="1"/></defs><use xlink:href="#box"/></svg>',
        ),
        placement: new Placement(disk: 'media', directory: 'media', visibility: 'public'),
    );

    expect(storedBytes($asset))->toContain('#box');
});
