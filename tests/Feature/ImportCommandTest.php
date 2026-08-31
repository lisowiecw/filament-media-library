<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Import\ImportOmission;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\LegacyRecord;

/**
 * Seed one legacy row, and put its object where the row says it is unless the
 * caller is testing what happens when it is not.
 */
function legacyRow(string $path, ?string $author = null, bool $stored = true, string $contents = 'the legacy bytes'): LegacyRecord
{
    if ($stored) {
        Storage::disk('media')->put($path, $contents);
    }

    return LegacyRecord::create(['cover_path' => $path, 'author_id' => $author]);
}

/**
 * @param  array<string, mixed>  $options
 */
function runImport(array $options = []): PendingCommand
{
    /** @var PendingCommand $command */
    $command = test()->artisan('media:import', array_merge([
        '--model' => LegacyRecord::class,
        '--column' => 'cover_path',
        '--disk' => 'media',
        '--tenant' => 'none',
        '--field' => 'cover_image',
        '--report' => storage_path('logs/import-test.json'),
    ], $options));

    return $command;
}

/**
 * @return array<string, mixed>
 */
function importReport(): array
{
    $contents = (string) file_get_contents(storage_path('logs/import-test.json'));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($contents, true);

    return $decoded;
}

/**
 * Where copy mode puts a given source key: derived from the pair rather than
 * freshly generated, which is what makes a second run a no-op.
 */
function copyKey(string $sourceKey, string $extension = 'txt'): string
{
    return 'media/'.substr(hash('sha256', 'media:'.$sourceKey), 0, 32).'.'.$extension;
}

it('registers a legacy object in place, taking its key verbatim', function (): void {
    legacyRow('avatars/9f2c1b7a4d8e.txt');

    runImport()->assertSuccessful()->run();

    $asset = MediaAsset::query()->sole();

    expect($asset->disk)->toBe('media')
        ->and($asset->object_key)->toBe('avatars/9f2c1b7a4d8e.txt')
        ->and($asset->original_client_filename)->toBe('9f2c1b7a4d8e.txt')
        ->and($asset->display_name)->toBe('9f2c1b7a4d8e')
        ->and($asset->extension)->toBe('txt')
        ->and($asset->size)->toBe(strlen('the legacy bytes'))
        ->and($asset->source)->toBe(MediaSource::Import)
        ->and($asset->import_source)->toBe(LegacyRecord::class.'.cover_path')
        ->and($asset->uploaded_by)->toBeNull()
        ->and($asset->mime_type)->toBe('text/plain')
        ->and($asset->mime_source)->toBe(MimeSource::Header);
});

it('never writes to the source disk', function (): void {
    legacyRow('avatars/kept.txt');

    runImport()->run();

    expect(Storage::disk('media')->get('avatars/kept.txt'))->toBe('the legacy bytes')
        ->and(Storage::disk('media')->allFiles())->toBe(['avatars/kept.txt']);
});

it('records the host row owner as the uploader when one is declared', function (): void {
    legacyRow('avatars/owned.txt', author: '42');

    runImport(['--uploader' => 'author_id'])->run();

    expect(MediaAsset::query()->sole()->uploaded_by)->toBe('42');
});

it('leaves an unknown uploader null rather than fabricating one', function (): void {
    legacyRow('avatars/unowned.txt');

    runImport(['--uploader' => 'author_id'])->run();

    expect(MediaAsset::query()->sole()->uploaded_by)->toBeNull();
});

it('is idempotent on re-run and leaves a later edit alone', function (): void {
    legacyRow('avatars/twice.txt');

    runImport()->run();

    $asset = MediaAsset::query()->sole();
    $asset->update(['display_name' => 'A name a person chose']);

    runImport()->run();

    expect(MediaAsset::query()->count())->toBe(1)
        ->and(MediaAsset::query()->sole()->display_name)->toBe('A name a person chose')
        ->and(importReport()['counts']['already-present'])->toBe(1);
});

it('writes no row for a path whose object is missing, and reports it by path', function (): void {
    legacyRow('avatars/gone.txt', stored: false);

    runImport()->run();

    expect(MediaAsset::query()->count())->toBe(0);

    $omissions = importReport()['omissions'];

    expect($omissions)->toHaveCount(1)
        ->and($omissions[0]['path'])->toBe('avatars/gone.txt')
        ->and($omissions[0]['reason'])->toBe(ImportOmission::MissingObject->value);
});

it('reports a blocked type by path and adopts nothing', function (): void {
    legacyRow('uploads/payload.phar');

    runImport()->run();

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(importReport()['omissions'][0]['reason'])->toBe(ImportOmission::BlockedType->value)
        ->and(importReport()['omissions'][0]['path'])->toBe('uploads/payload.phar');
});

it('skips an empty column value and reports it', function (): void {
    LegacyRecord::create(['cover_path' => '   ']);

    runImport()->run();

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(importReport()['omissions'][0]['reason'])->toBe(ImportOmission::EmptyValue->value);
});

it('adopts an object the ingest ceiling would have refused', function (): void {
    config()->set('media-library.max_upload_size', 1);

    legacyRow('avatars/big.txt', contents: str_repeat('x', 4096));

    runImport()->run();

    expect(MediaAsset::query()->sole()->size)->toBe(4096);
});

it('fails the whole run when the disk is not configured', function (): void {
    legacyRow('avatars/one.txt');

    runImport(['--disk' => 'nowhere'])->assertFailed()->run();

    expect(MediaAsset::query()->count())->toBe(0);
});

it('fails the whole run when the model is not an Eloquent model', function (): void {
    runImport(['--model' => 'App\\Models\\NotHere'])->assertFailed()->run();
});

it('takes the declared visibility for every adopted object', function (): void {
    legacyRow('avatars/pub.txt');

    runImport(['--visibility' => 'public'])->run();

    expect(MediaAsset::query()->sole()->visibility)->toBe(Visibility::Public);
});

it('fails the run when the uploader column does not exist', function (): void {
    legacyRow('avatars/typo.txt');

    runImport(['--uploader' => 'auther_id'])->assertFailed()->run();

    expect(MediaAsset::query()->count())->toBe(0);
});

it('refuses a visibility the disk cannot deliver, before writing a row', function (): void {
    config()->set('filesystems.disks.media.url', null);

    legacyRow('avatars/undeliverable.txt');

    runImport(['--visibility' => 'public'])->assertFailed()->run();

    expect(MediaAsset::query()->count())->toBe(0);
});

it('fails the run when the visibility is not one', function (): void {
    runImport(['--visibility' => 'semi-public'])->assertFailed()->run();
});

it('writes nothing on a dry run while still reporting', function (): void {
    legacyRow('avatars/dry.txt');

    runImport(['--dry-run' => true])->assertSuccessful()->run();

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(importReport()['counts']['registered'])->toBe(1)
        ->and(importReport()['dry_run'])->toBeTrue();
});

it('names the run in the report without listing what it adopted', function (): void {
    legacyRow('avatars/reported.txt');

    runImport()->run();

    $report = importReport();

    expect($report['source'])->toBe(LegacyRecord::class.'.cover_path')
        ->and($report['disk'])->toBe('media')
        ->and($report['field'])->toBe('cover_image')
        ->and($report['counts']['registered'])->toBe(1)
        ->and($report['omissions'])->toBe([]);
});

it('defaults the report to a run-timestamped path under the log directory', function (): void {
    legacyRow('avatars/defaulted.txt');

    runImport(['--report' => null])->run();

    $written = glob(storage_path('logs/media-import-*.json'));

    expect($written)->toHaveCount(1);

    foreach ((array) $written as $path) {
        unlink((string) $path);
    }
});

describe('copy mode', function (): void {
    it('copies to a fresh key, keeps the source and points the row at the copy', function (): void {
        legacyRow('avatars/copied.txt');

        runImport(['--copy' => true])->run();

        $asset = MediaAsset::query()->sole();

        expect($asset->object_key)->not->toBe('avatars/copied.txt')
            ->and($asset->object_key)->toStartWith('media/')
            ->and(Storage::disk('media')->exists('avatars/copied.txt'))->toBeTrue()
            ->and(Storage::disk('media')->get($asset->object_key))->toBe('the legacy bytes');
    });

    it('refuses to write over an occupied destination', function (): void {
        Storage::disk('media')->put(copyKey('avatars/clash.txt'), 'somebody else');

        legacyRow('avatars/clash.txt');

        runImport(['--copy' => true])->run();

        expect(MediaAsset::query()->count())->toBe(0)
            ->and(Storage::disk('media')->get(copyKey('avatars/clash.txt')))->toBe('somebody else')
            ->and(importReport()['omissions'][0]['reason'])->toBe(ImportOmission::DestinationOccupied->value);
    });

    it('counts the copy it would make on a dry run', function (): void {
        legacyRow('avatars/dry-copy.txt');

        runImport(['--copy' => true, '--dry-run' => true])->run();

        expect(importReport()['counts']['copied'])->toBe(1)
            ->and(Storage::disk('media')->allFiles())->toBe(['avatars/dry-copy.txt']);
    });

    it('resolves the same destination on a re-run, so it copies nothing twice', function (): void {
        legacyRow('avatars/twice.txt');

        runImport(['--copy' => true])->run();
        runImport(['--copy' => true])->run();

        expect(MediaAsset::query()->count())->toBe(1)
            ->and(importReport()['counts']['already-present'])->toBe(1)
            ->and(importReport()['counts']['copied'])->toBe(0);
    });
});
