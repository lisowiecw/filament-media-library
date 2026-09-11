<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\Visibility;
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

// The matcher reaches `url()` values, bare `href` and `src`, and `url()` or
// `@import` inside a style; the references it misses are what the Delivery
// route's content policy and, on public placement, the Strict pass are there
// for (ADR-0005).
it('strips the remote references the sanitizer can see', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(\'https://tracker.example/x\')" width="1" height="1"/></svg>',
    ));

    expect(storedBytes($asset))->not->toContain('tracker.example');
});

it('strips a bare remote href rather than only a url() one', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg"><a href="https://tracker.example/x"><rect width="1" height="1"/></a></svg>',
    ));

    expect(storedBytes($asset))->not->toContain('tracker.example');
});

it('strips a remote url() carried in a style attribute', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:red;background:url(https://tracker.example/x.png)" width="1" height="1"/></svg>',
    ));

    expect(storedBytes($asset))->not->toContain('tracker.example');
});

it('strips a remote @import from the text of a style element', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url("https://tracker.example/x.css");</style><rect width="1" height="1"/></svg>',
    ));

    expect(storedBytes($asset))->not->toContain('tracker.example');
});

// GHSA-m9xh-6747-9r6f: a mixed-case spelling used to slip past the check.
// `href` matching is case-insensitive now, so this is stripped like any other.
it('strips a remote reference spelled in mixed case', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
        .'<use xlink:HrEf="https://tracker.example/x.svg"/></svg>',
    ));

    expect(storedBytes($asset))->not->toContain('tracker.example');
});

// A local or fragment reference is not remote, so the stricter matcher must
// still leave it alone.
it('keeps a local reference while stripping the remote ones', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg"><a href="/local/page"><rect width="1" height="1"/></a></svg>',
    ));

    expect(storedBytes($asset))->toContain('/local/page');
});

// The doctype is stripped before parsing, so a reference to an entity declared
// there is left undefined and the parse fails: the refusal is on the use, not
// on the declaration.
it('refuses an svg that references a custom entity', function (): void {
    ingest(svgUpload(
        '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "hello">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
    ));
})->throws(IngestRefused::class, 'could not be sanitized');

// Declaring an entity and never using it leaves nothing undefined to trip the
// parse, so the declaration goes with the doctype and the document survives.
it('accepts an svg that declares a custom entity it never references', function (): void {
    $asset = ingest(svgUpload(
        '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "hello">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
    ));

    expect(storedBytes($asset))->toContain('<rect')
        ->and(storedBytes($asset))->not->toContain('ENTITY');
});

// A remote `url()` costs the whole style attribute rather than the one
// declaration, so a co-located `fill` goes with it. That is upstream's choice,
// pinned here because it is the one narrowing that changes how a file renders.
it('drops the whole style attribute a remote url() sits in', function (): void {
    $asset = ingest(svgUpload(
        '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:red;background:url(https://tracker.example/x.png)" width="1" height="1"/></svg>',
    ));

    expect(storedBytes($asset))->not->toContain('fill:red')
        ->and(storedBytes($asset))->not->toContain('style=');
});

// The third leg of the three-way failure check: markup that parses and
// sanitizes but whose root is not an `svg` is still caught by the root test
// rather than reaching the caller as bytes.
it('still refuses a sanitizable document whose root is not an svg', function (): void {
    (new SvgSanitization)->sanitize(
        '<html xmlns="http://www.w3.org/1999/xhtml"><body><p>hi</p></body></html>',
        'logo.svg',
        strict: false,
    );
})->throws(IngestRefused::class, 'could not be sanitized');

// A plain doctype carries no entity declarations, so it is stripped and the
// document survives.
it('accepts an svg carrying a plain doctype', function (): void {
    $asset = ingest(svgUpload(
        '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" '
        .'"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
        .'<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
    ));

    expect(storedBytes($asset))->toContain('<rect')
        ->and(storedBytes($asset))->not->toContain('DOCTYPE');
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
        placement: new Placement(disk: 'media', directory: 'media', visibility: Visibility::Public),
    );
})->throws(IngestRefused::class, 'style');

it('refuses a public svg that embeds an image, naming the element', function (): void {
    ingest(
        svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><image width="1" height="1"/></svg>'),
        placement: new Placement(disk: 'media', directory: 'media', visibility: Visibility::Public),
    );
})->throws(IngestRefused::class, 'image');

it('refuses a public svg that carries a link, naming the element', function (): void {
    ingest(
        svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><a><rect width="1" height="1"/></a></svg>'),
        placement: new Placement(disk: 'media', directory: 'media', visibility: Visibility::Public),
    );
})->throws(IngestRefused::class, 'a');

it('accepts a plain public svg and stores the strictly sanitized bytes', function (): void {
    $asset = ingest(
        svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>'),
        placement: new Placement(disk: 'media', directory: 'media', visibility: Visibility::Public),
    );

    expect(storedBytes($asset))->not->toContain('<script')
        ->and($asset->visibility)->toBe(Visibility::Public);
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
        placement: new Placement(disk: 'media', directory: 'media', visibility: Visibility::Public),
    );

    expect(storedBytes($asset))->toContain('#box');
});
