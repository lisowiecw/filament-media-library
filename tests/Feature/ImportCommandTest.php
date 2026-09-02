<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Import\ImportDrift;
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

describe('drift', function (): void {
    it('reports a recorded size that no longer matches the disk', function (): void {
        legacyRow('avatars/drifted.txt');

        runImport()->run();

        MediaAsset::query()->sole()->update(['size' => 3]);

        runImport(['--check-drift' => true])->assertSuccessful()->run();

        $report = importReport();

        expect($report['counts']['drifted'])->toBe(1)
            ->and($report['drifts'])->toHaveCount(1)
            ->and($report['drifts'][0]['path'])->toBe('avatars/drifted.txt')
            ->and($report['drifts'][0]['field'])->toBe(ImportDrift::Size->value)
            ->and($report['drifts'][0]['recorded'])->toBe('3')
            ->and($report['drifts'][0]['reported'])->toBe((string) strlen('the legacy bytes'));
    });

    it('reports a mime type the object no longer carries', function (): void {
        legacyRow('avatars/retyped.txt');

        runImport()->run();

        MediaAsset::query()->sole()->update(['mime_type' => 'image/png']);

        runImport(['--check-drift' => true])->run();

        $drifts = importReport()['drifts'];

        expect($drifts)->toHaveCount(1)
            ->and($drifts[0]['field'])->toBe(ImportDrift::MimeType->value)
            ->and($drifts[0]['recorded'])->toBe('image/png')
            ->and($drifts[0]['reported'])->toBe('text/plain');
    });

    it('reports an object that has gone as its own case rather than as a difference', function (): void {
        legacyRow('avatars/vanished.txt');

        runImport()->run();

        Storage::disk('media')->delete('avatars/vanished.txt');

        runImport(['--check-drift' => true])->run();

        $drifts = importReport()['drifts'];

        expect($drifts)->toHaveCount(1)
            ->and($drifts[0]['field'])->toBe(ImportDrift::MissingObject->value)
            ->and($drifts[0]['recorded'])->toBeNull()
            ->and($drifts[0]['reported'])->toBeNull();
    });

    it('reports nothing on a clean re-run, and repairs nothing on a dirty one', function (): void {
        legacyRow('avatars/clean.txt');

        runImport()->run();

        runImport(['--check-drift' => true])->run();

        expect(importReport()['drifts'])->toBe([]);

        Storage::disk('media')->put('avatars/clean.txt', 'a longer set of legacy bytes');

        runImport(['--check-drift' => true])->run();

        expect(importReport()['drifts'])->toHaveCount(1)
            ->and(MediaAsset::query()->sole()->size)->toBe(strlen('the legacy bytes'));
    });

    it('reads nothing from the disk for an already-present row without the flag', function (): void {
        legacyRow('avatars/unchecked.txt');

        runImport()->run();

        Storage::disk('media')->put('avatars/unchecked.txt', 'a longer set of legacy bytes');

        runImport()->run();

        $report = importReport();

        expect($report['drifts'])->toBe([])
            ->and($report['counts']['already-present'])->toBe(1)
            ->and(MediaAsset::query()->sole()->size)->toBe(strlen('the legacy bytes'));

        // The same disk, the same row, one flag apart: what the default run
        // did not report is exactly what it did not read.
        runImport(['--check-drift' => true])->run();

        expect(importReport()['drifts'])->toHaveCount(1);
    });

    it('reports a type the object itself no longer has, read from the disk', function (): void {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

        legacyRow('avatars/swapped.png', contents: $png);

        // Sniffed on both runs: a type read off one rung says nothing about a
        // type read off another, so the object is measured the same way twice.
        runImport(['--sniff' => true])->run();

        expect(MediaAsset::query()->sole()->mime_type)->toBe('image/png');

        Storage::disk('media')->put('avatars/swapped.png', 'not a picture at all');

        runImport(['--check-drift' => true, '--sniff' => true])->run();

        $drifts = importReport()['drifts'];

        expect($drifts)->toHaveCount(2)
            ->and(array_column($drifts, 'field'))->toBe([ImportDrift::Size->value, ImportDrift::MimeType->value])
            ->and($drifts[1]['recorded'])->toBe('image/png')
            ->and($drifts[1]['reported'])->toBe('text/plain');
    });

    it('says nothing about a type resolved on a rung this run did not reach', function (): void {
        legacyRow('avatars/sniffed.txt');

        runImport(['--sniff' => true])->run();

        expect(MediaAsset::query()->sole()->mime_source)->toBe(MimeSource::Sniffed);

        runImport(['--check-drift' => true])->run();

        expect(importReport()['drifts'])->toBe([]);
    });
});

/**
 * A run that touched every object is exactly the cheap re-run the report
 * exists to protect, so adopting rows fans nothing out. The hash of an
 * imported asset is asked for by the first card that wants one, not here.
 */
it('queues no hash work, however many rows it adopts', function (): void {
    Bus::fake();

    foreach (range(1, 3) as $i) {
        // The fake upload has to outlive the read: its temp file goes with it.
        $file = UploadedFile::fake()->image('x.png', 900, 900);

        Storage::disk('media')->put("photos/{$i}.png", (string) file_get_contents((string) $file->getRealPath()));

        LegacyRecord::create(['cover_path' => "photos/{$i}.png"]);
    }

    runImport()->assertSuccessful()->run();

    expect(MediaAsset::query()->count())->toBe(3);

    Bus::assertNothingDispatched();
});
