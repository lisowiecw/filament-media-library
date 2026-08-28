<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
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
