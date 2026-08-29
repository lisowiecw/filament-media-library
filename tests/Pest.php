<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Forms\Components\MediaPicker;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Lisowiecw\MediaLibrary\Tests\Fixtures\Article;
use Lisowiecw\MediaLibrary\Tests\Fixtures\ArticleForm;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;
use Lisowiecw\MediaLibrary\Tests\Fixtures\User;
use Lisowiecw\MediaLibrary\Tests\TestCase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(TestCase::class)->in(__DIR__);

// The stand-in host policy answers through statics, so one test's answer would
// otherwise be the next test's starting point.
uses()->beforeEach(function (): void {
    HostPolicy::$allows = true;
    HostPolicy::$evaluations = 0;
})->in(__DIR__);
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
 * Re-resolve the faked disk without local URL serving, which is how a disk
 * with no temporary URL of its own behaves.
 */
function withoutTemporaryUrls(string $disk = 'media'): void
{
    config()->set('filesystems.disks.'.$disk.'.serve', false);

    Storage::forgetDisk($disk);
}

/**
 * An authenticated user to ask the policy and the gates about.
 */
function user(): User
{
    return User::create(['name' => 'Ada']);
}

/**
 * A host model to attach media to.
 */
function article(string $title = 'A post'): Article
{
    return Article::create(['title' => $title]);
}

/**
 * @param  array<string, mixed>  $picker
 */
function pickerForm(?Article $record = null, array $picker = []): Testable
{
    return Livewire::test(ArticleForm::class, [
        'articleId' => $record?->getKey(),
        'picker' => $picker,
    ]);
}

function attach(Article $host, MediaAsset ...$assets): void
{
    foreach ($assets as $order => $asset) {
        MediaAttachment::query()->create([
            'media_asset_id' => $asset->id,
            'host_type' => $host->getMorphClass(),
            'host_id' => $host->getKey(),
            'field_name' => 'cover_image',
            'order' => $order,
        ]);
    }
}

/**
 * A library at a size a person would actually browse, seeded in one insert so
 * the test spends its time on the grid rather than on the fixtures.
 *
 * @return list<int>
 */
function seedLibrary(int $count, string $prefix = 'Asset'): array
{
    $rows = [];

    foreach (range(1, $count) as $index) {
        $rows[] = [
            'ulid' => (string) Str::ulid(),
            'display_name' => $prefix.' '.$index,
            'original_client_filename' => Str::slug($prefix).'-'.$index.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'mime_source' => MimeSource::Sniffed->value,
            'size' => 2048,
            'disk' => 'media',
            'object_key' => 'media/'.Str::slug($prefix).'-'.$index.'.jpg',
            'visibility' => 'private',
            'source' => MediaSource::Upload->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    MediaAsset::query()->insert($rows);

    return MediaAsset::query()->orderBy('id')->pluck('id')->all();
}

/**
 * @param  array<string, mixed>  $picker
 */
function libraryModal(array $picker = []): Testable
{
    return pickerForm(article(), $picker)
        ->mountAction(TestAction::make('library')->schemaComponent('cover_image'))
        ->call('renderEverything');
}

function gridState(Testable $component, string $key): mixed
{
    return $component->get('mountedActions.0.data.library.'.$key);
}

function search(Testable $component, string $query): Testable
{
    return $component->set('mountedActions.0.data.library.search', $query)
        ->call('renderEverything');
}

function clickCard(Testable $component, int $id): Testable
{
    /** @var array<int> $selection */
    $selection = gridState($component, 'selection') ?? [];

    $selection = in_array($id, $selection, false)
        ? array_values(array_filter($selection, fn (int $selected): bool => $selected !== $id))
        : [...$selection, $id];

    return $component->set('mountedActions.0.data.library.selection', $selection)
        ->call('renderEverything');
}

/**
 * Call one of the picker's exposed methods the way the browser calls it,
 * through Filament's schema-component dispatcher rather than around it.
 *
 * @param  array<string, mixed>  $arguments
 */
function pickerCall(Testable $component, string $method, array $arguments = []): Testable
{
    return $component->call('callSchemaComponentMethod', 'form.cover_image', $method, $arguments);
}

/**
 * Stage files where the browser stages a drop, then commit it.
 */
function dropOnPicker(Testable $component, UploadedFile ...$files): Testable
{
    /** @var MediaPicker $picker */
    $picker = $component->instance()->form->getComponent('cover_image');

    return pickerCall($component->set($picker->getDropStatePath(), $files), 'dropped');
}
