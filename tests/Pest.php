<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\Article;
use Lisowiecw\MediaLibrary\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
function ingest(UploadedFile $file, ?Placement $placement = null, ?IngestRules $rules = null): MediaAsset
{
    return app(IngestService::class)->ingest($file, $placement ?? Placement::resolve(), $rules);
}

function pngUpload(string $name = 'photo.png'): UploadedFile
{
    return UploadedFile::fake()->image($name);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeAsset(array $overrides = []): MediaAsset
{
    return MediaAsset::create(array_merge([
        'display_name' => 'Holiday photo',
        'original_client_filename' => 'holiday photo.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'mime_source' => MimeSource::Sniffed,
        'size' => 2048,
        'disk' => 'media',
        'object_key' => 'media/holiday-photo.jpg',
        'visibility' => 'private',
        'source' => MediaSource::Upload,
    ], $overrides));
}

/**
 * An asset with an object key of its own, so a test can hold several at once.
 */
function libraryAsset(): MediaAsset
{
    return makeAsset(['object_key' => 'media/'.Str::random(12).'.jpg']);
}

/**
 * A host model to attach media to.
 */
function article(string $title = 'A post'): Article
{
    return Article::create(['title' => $title]);
}
