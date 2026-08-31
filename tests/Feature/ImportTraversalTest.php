<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Lisowiecw\MediaLibrary\Import\DiskTraversal;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Lisowiecw\MediaLibrary\Tests\Fixtures\LegacyRecord;

/**
 * @param  array<string, mixed>  $options
 */
function runTraversal(array $options = []): PendingCommand
{
    /** @var PendingCommand $command */
    $command = test()->artisan('media:import', array_merge([
        '--source' => 'disk',
        '--disk' => 'media',
        '--tenant' => 'none',
        '--prefix' => 'legacy',
        '--report' => storage_path('logs/import-traversal-test.json'),
    ], $options));

    return $command;
}

/**
 * @return array<string, mixed>
 */
function traversalReport(): array
{
    $contents = (string) file_get_contents(storage_path('logs/import-traversal-test.json'));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($contents, true);

    return $decoded;
}

it('adopts every object under the prefix, including nested ones', function (): void {
    Storage::disk('media')->put('legacy/one.txt', 'the legacy bytes');
    Storage::disk('media')->put('legacy/nested/two.txt', 'the legacy bytes');

    runTraversal()->assertSuccessful()->run();

    expect(MediaAsset::query()->pluck('object_key')->sort()->values()->all())
        ->toBe(['legacy/nested/two.txt', 'legacy/one.txt']);
});

it('leaves everything outside the prefix alone', function (): void {
    Storage::disk('media')->put('legacy/inside.txt', 'the legacy bytes');
    Storage::disk('media')->put('elsewhere/outside.txt', 'the legacy bytes');

    runTraversal()->run();

    expect(MediaAsset::query()->sole()->object_key)->toBe('legacy/inside.txt');
});

it('requires a prefix, since a whole bucket is not something to adopt', function (): void {
    Storage::disk('media')->put('legacy/one.txt', 'the legacy bytes');

    runTraversal(['--prefix' => null])->assertFailed()->run();

    expect(MediaAsset::query()->count())->toBe(0);
});

it('refuses a field context, since traversal has no host row to attach to', function (): void {
    Storage::disk('media')->put('legacy/one.txt', 'the legacy bytes');

    runTraversal(['--field' => 'gallery'])->assertFailed()->run();

    expect(MediaAttachment::query()->count())->toBe(0);
});

it('refuses an uploader column, since traversal reads no row', function (): void {
    runTraversal(['--model' => LegacyRecord::class, '--uploader' => 'author_id'])->assertFailed()->run();
});

it('names the prefix it walked in the report rather than a host model', function (): void {
    Storage::disk('media')->put('legacy/named.txt', 'the legacy bytes');

    runTraversal()->run();

    expect(traversalReport()['source'])->toBe('disk:legacy')
        ->and(MediaAsset::query()->sole()->import_source)->toBe('disk:legacy');
});

it('is idempotent, since identity is still the disk and object key', function (): void {
    Storage::disk('media')->put('legacy/twice.txt', 'the legacy bytes');

    runTraversal()->run();
    runTraversal()->run();

    expect(MediaAsset::query()->count())->toBe(1)
        ->and(traversalReport()['counts']['already-present'])->toBe(1);
});

it('iterates lazily rather than materialising the listing', function (): void {
    Storage::disk('media')->put('legacy/first.txt', 'the legacy bytes');

    $keys = DiskTraversal::keys(Storage::disk('media'), 'legacy');

    expect($keys)->toBeInstanceOf(Generator::class)
        ->and(iterator_to_array($keys, false))->toBe(['legacy/first.txt']);
});

it('walks a bucket larger than a page without exhausting memory', function (): void {
    foreach (range(1, 200) as $index) {
        Storage::disk('media')->put('legacy/bulk/'.$index.'.txt', 'the legacy bytes');
    }

    $before = memory_get_usage();

    $count = 0;

    foreach (DiskTraversal::keys(Storage::disk('media'), 'legacy') as $ignored) {
        $count++;
    }

    expect($count)->toBe(200)
        ->and(memory_get_usage() - $before)->toBeLessThan(1024 * 512);
});

it('reaches for no call that builds the whole listing first', function (): void {
    $code = implode(' ', array_map(
        fn (array|string $token): string => is_string($token) ? $token : (string) $token[1],
        array_filter(
            token_get_all((string) file_get_contents(__DIR__.'/../../src/Import/DiskTraversal.php')),
            fn (array|string $token): bool => is_string($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true),
        ),
    ));

    expect($code)->not->toContain('allFiles');
});
