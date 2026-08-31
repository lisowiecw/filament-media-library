<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Lisowiecw\MediaLibrary\Import\ImportOmission;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Lisowiecw\MediaLibrary\Tests\Fixtures\LegacyRecord;

/**
 * Seed one legacy row whose multi-value column holds the given paths, putting
 * an object behind each of them unless the caller says otherwise.
 *
 * @param  list<string>  $paths
 * @param  list<string>  $stored  the paths to actually put on the disk, defaulting to all of them
 */
function galleryRow(array $paths, ?array $stored = null): LegacyRecord
{
    foreach ($stored ?? $paths as $path) {
        Storage::disk('media')->put($path, 'the legacy bytes');
    }

    return LegacyRecord::create(['gallery_paths' => json_encode($paths)]);
}

/**
 * A row whose multi-value column holds something that is not a list of paths
 * at all, written verbatim so the shape rules are tested on real column text.
 */
function rawGalleryRow(string $value): LegacyRecord
{
    return LegacyRecord::create(['gallery_paths' => $value]);
}

/**
 * @param  array<string, mixed>  $options
 */
function runGalleryImport(array $options = []): PendingCommand
{
    /** @var PendingCommand $command */
    $command = test()->artisan('media:import', array_merge([
        '--model' => LegacyRecord::class,
        '--column' => 'gallery_paths',
        '--disk' => 'media',
        '--field' => 'gallery',
        '--cardinality' => 'many',
        '--report' => storage_path('logs/import-attachments-test.json'),
    ], $options));

    return $command;
}

/**
 * @return array<string, mixed>
 */
function galleryReport(): array
{
    $contents = (string) file_get_contents(storage_path('logs/import-attachments-test.json'));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($contents, true);

    return $decoded;
}

/**
 * The object keys a host row holds in a field context, in attachment order.
 *
 * @return list<string>
 */
function attachedKeys(LegacyRecord $row, string $field = 'gallery'): array
{
    return $row->media($field)->map(fn (MediaAsset $asset): string => $asset->object_key)->all();
}

describe('attachment writing', function (): void {
    it('attaches an adopted asset to the row that held its path, in the declared field', function (): void {
        $row = galleryRow(['gallery/one.txt']);

        runGalleryImport()->assertSuccessful()->run();

        $attachment = MediaAttachment::query()->sole();

        expect($attachment->host_type)->toBe($row->getMorphClass())
            ->and((string) $attachment->host_id)->toBe((string) $row->getKey())
            ->and($attachment->field_name)->toBe('gallery')
            ->and($attachment->media_asset_id)->toBe(MediaAsset::query()->sole()->getKey())
            ->and(galleryReport()['counts']['attached'])->toBe(1);
    });

    it('takes array index order as attachment order verbatim', function (): void {
        $row = galleryRow(['gallery/c.txt', 'gallery/a.txt', 'gallery/b.txt']);

        runGalleryImport()->run();

        expect(attachedKeys($row))->toBe(['gallery/c.txt', 'gallery/a.txt', 'gallery/b.txt']);
    });

    it('writes one attachment per host, field and asset however often the run repeats', function (): void {
        $row = galleryRow(['gallery/twice.txt']);

        runGalleryImport()->run();
        runGalleryImport()->run();

        expect(MediaAttachment::query()->count())->toBe(1)
            ->and(galleryReport()['counts']['attached'])->toBe(0)
            ->and(attachedKeys($row))->toBe(['gallery/twice.txt']);
    });

    it('never rewrites an order a person has since edited', function (): void {
        $row = galleryRow(['gallery/first.txt', 'gallery/second.txt']);

        runGalleryImport()->run();

        // What a reorder in the picker leaves behind.
        MediaAttachment::query()->orderBy('order')->get()
            ->each(fn (MediaAttachment $attachment, int $index) => $attachment->update(['order' => 1 - $index]));

        runGalleryImport()->run();

        expect(attachedKeys($row))->toBe(['gallery/second.txt', 'gallery/first.txt']);
    });

    it('attaches an asset a previous run already adopted, rather than skipping the row', function (): void {
        $row = galleryRow(['gallery/known.txt']);

        runGalleryImport()->run();
        MediaAttachment::query()->delete();
        runGalleryImport()->run();

        expect(galleryReport()['counts']['already-present'])->toBe(1)
            ->and(attachedKeys($row))->toBe(['gallery/known.txt']);
    });

    it('attaches nothing on a dry run', function (): void {
        galleryRow(['gallery/dry.txt']);

        runGalleryImport(['--dry-run' => true])->assertSuccessful()->run();

        expect(MediaAttachment::query()->count())->toBe(0);
    });

    it('adopts without attaching when no field context is declared', function (): void {
        galleryRow(['gallery/fieldless.txt']);

        runGalleryImport(['--field' => null])->assertSuccessful()->run();

        expect(MediaAsset::query()->count())->toBe(1)
            ->and(MediaAttachment::query()->count())->toBe(0);
    });

    it('attaches a single-value column to the field it declares', function (): void {
        Storage::disk('media')->put('covers/one.txt', 'the legacy bytes');
        $row = LegacyRecord::create(['cover_path' => 'covers/one.txt']);

        runGalleryImport(['--column' => 'cover_path', '--cardinality' => 'single', '--field' => 'cover'])->run();

        expect(attachedKeys($row, 'cover'))->toBe(['covers/one.txt']);
    });
});

describe('declared cardinality', function (): void {
    it('fails the run when a single column holds an array', function (): void {
        galleryRow(['gallery/a.txt']);

        runGalleryImport(['--cardinality' => 'single'])->assertFailed()->run();
    });

    it('fails the run when a multi-value column holds one bare path', function (): void {
        rawGalleryRow('gallery/bare.txt');

        runGalleryImport()->assertFailed()->run();

        expect(MediaAsset::query()->count())->toBe(0);
    });

    it('fails the run on a nested object in the column', function (): void {
        rawGalleryRow('[{"path": "gallery/nested.txt"}]');

        runGalleryImport()->assertFailed()->run();

        expect(MediaAsset::query()->count())->toBe(0);
    });

    it('fails the run on a URL in a multi-value column', function (): void {
        rawGalleryRow('["https://example.test/gallery/remote.txt"]');

        runGalleryImport()->assertFailed()->run();
    });

    it('fails the run on a URL in a single-value column', function (): void {
        LegacyRecord::create(['cover_path' => 'https://example.test/covers/remote.txt']);

        runGalleryImport(['--column' => 'cover_path', '--cardinality' => 'single'])->assertFailed()->run();
    });

    it('fails the run when the declared cardinality is not one', function (): void {
        runGalleryImport(['--cardinality' => 'several'])->assertFailed()->run();
    });
});

describe('messy elements', function (): void {
    it('skips a duplicate element and reports it against its index', function (): void {
        $row = galleryRow(['gallery/same.txt', 'gallery/same.txt']);

        runGalleryImport()->assertSuccessful()->run();

        $skipped = galleryReport()['omissions'];

        expect(attachedKeys($row))->toBe(['gallery/same.txt'])
            ->and($skipped)->toHaveCount(1)
            ->and($skipped[0]['reason'])->toBe(ImportOmission::DuplicateElement->value)
            ->and($skipped[0]['element'])->toBe(1);
    });

    it('skips an empty element and carries on with the rest', function (): void {
        $row = galleryRow(['gallery/kept.txt', '   ']);

        runGalleryImport()->assertSuccessful()->run();

        expect(attachedKeys($row))->toBe(['gallery/kept.txt'])
            ->and(galleryReport()['omissions'][0]['reason'])->toBe(ImportOmission::EmptyElement->value);
    });

    it('skips an element whose object is missing and attaches the rest in order', function (): void {
        $row = galleryRow(['gallery/gone.txt', 'gallery/here.txt'], stored: ['gallery/here.txt']);

        runGalleryImport()->assertSuccessful()->run();

        expect(attachedKeys($row))->toBe(['gallery/here.txt'])
            ->and(galleryReport()['omissions'][0]['reason'])->toBe(ImportOmission::MissingObject->value)
            ->and(galleryReport()['omissions'][0]['element'])->toBe(0);
    });

    it('counts skipped elements apart from omitted rows', function (): void {
        galleryRow(['gallery/kept.txt', 'gallery/kept.txt']);
        rawGalleryRow('   ');

        runGalleryImport()->assertSuccessful()->run();

        expect(galleryReport()['counts']['skipped-elements'])->toBe(1)
            ->and(galleryReport()['counts']['omitted-rows'])->toBe(1);
    });
});

describe('a refused run', function (): void {
    it('still reports what it adopted before the row that ended it', function (): void {
        galleryRow(['gallery/adopted.txt']);
        rawGalleryRow('gallery/bare.txt');

        runGalleryImport()->assertFailed()->run();

        expect(galleryReport()['counts']['registered'])->toBe(1);
    });

    it('names an element that is not text as such, rather than as an empty one', function (): void {
        rawGalleryRow('["gallery/kept.txt", 7]');
        Storage::disk('media')->put('gallery/kept.txt', 'the legacy bytes');

        runGalleryImport()->assertSuccessful()->run();

        expect(galleryReport()['omissions'][0]['reason'])->toBe(ImportOmission::NonTextElement->value);
    });

    it('says a list is malformed rather than blaming the declared cardinality', function (): void {
        rawGalleryRow('["gallery/unclosed.txt"');

        runGalleryImport()->assertFailed()
            ->expectsOutputToContain('does not parse as one')
            ->run();
    });
});
