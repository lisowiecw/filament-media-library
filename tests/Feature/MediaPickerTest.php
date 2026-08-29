<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Lisowiecw\MediaLibrary\Tests\Fixtures\Article;

it('starts a create form with an empty ordered list', function (): void {
    pickerForm()->assertSchemaStateSet(['cover_image' => []]);
});

it('hydrates from the attachment rows for this host and field context, in order', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attach($host, $two, $one);

    pickerForm($host)->assertSchemaStateSet(['cover_image' => [$two->id, $one->id]]);
});

it('hydrates ids alone, whatever the cardinality', function (): void {
    $host = article();
    $asset = libraryAsset();
    attach($host, $asset);

    $state = pickerForm($host)->get('data.cover_image');

    expect($state)->toBe([$asset->id]);
});

it('reads nothing from another host or another field context', function (): void {
    [$host, $other] = [article(), article('Another post')];
    $asset = libraryAsset();
    attach($other, $asset);

    MediaAttachment::query()->create([
        'media_asset_id' => $asset->id,
        'host_type' => $host->getMorphClass(),
        'host_id' => $host->getKey(),
        'field_name' => 'gallery',
        'order' => 0,
    ]);

    pickerForm($host)->assertSchemaStateSet(['cover_image' => []]);
});

it('attaches on save without the host table gaining a column', function (): void {
    $asset = libraryAsset();

    pickerForm()
        ->set('data.cover_image', [$asset->id])
        ->call('save')
        ->assertHasNoErrors();

    $host = Article::query()->firstOrFail();

    expect($host->media('cover_image')->pluck('id')->all())->toBe([$asset->id])
        ->and(array_keys($host->getAttributes()))->not->toContain('cover_image');
});

it('defers attachment writes until the host record exists', function (): void {
    $asset = libraryAsset();

    pickerForm()->set('data.cover_image', [$asset->id]);

    expect(MediaAttachment::query()->count())->toBe(0)
        ->and(Article::query()->count())->toBe(0);
});

it('takes the array index as the attachment order', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];

    pickerForm($host)
        ->set('data.cover_image', [$two->id, $one->id])
        ->call('save');

    expect(MediaAttachment::query()->forField($host, 'cover_image')->orderBy('order')->pluck('media_asset_id')->all())
        ->toBe([$two->id, $one->id]);
});

it('detaches on save when the list no longer names an asset, leaving the asset alone', function (): void {
    $host = article();
    $asset = libraryAsset();
    attach($host, $asset);

    pickerForm($host)
        ->set('data.cover_image', [])
        ->call('save');

    expect(MediaAttachment::query()->count())->toBe(0)
        ->and(MediaAsset::query()->whereKey($asset->id)->exists())->toBeTrue();
});

it('takes ids arriving as strings from the form state', function (): void {
    $host = article();
    $asset = libraryAsset();

    pickerForm($host)
        ->set('data.cover_image', [(string) $asset->id])
        ->call('save');

    expect($host->media('cover_image')->pluck('id')->all())->toBe([$asset->id]);
});

it('requires a selection when the field is required', function (): void {
    pickerForm(picker: ['required' => true])
        ->set('data.cover_image', [])
        ->call('save')
        ->assertHasErrors('data.cover_image');

    expect(Article::query()->count())->toBe(0);
});

it('runs cardinality rules over the id array', function (): void {
    [$one, $two] = [libraryAsset(), libraryAsset()];

    pickerForm(picker: ['maxItems' => 1])
        ->set('data.cover_image', [$one->id, $two->id])
        ->call('save')
        ->assertHasErrors('data.cover_image');

    pickerForm(picker: ['minItems' => 2])
        ->set('data.cover_image', [$one->id])
        ->call('save')
        ->assertHasErrors('data.cover_image');
});

it('rejects the whole save on an id the viewer cannot have, naming the field and never the id', function (): void {
    $host = article();
    $missing = libraryAsset();
    $id = $missing->id;
    $missing->forceDelete();

    $component = pickerForm($host)
        ->set('data.title', 'Renamed')
        ->set('data.cover_image', [$id])
        ->call('save')
        ->assertHasErrors('data.cover_image');

    $errors = $component->errors()->get('data.cover_image');

    expect($errors[0])->toContain('Cover image')
        ->and($errors[0])->not->toContain((string) $id)
        ->and($host->fresh()->title)->toBe('A post')
        ->and(MediaAttachment::query()->count())->toBe(0);
});

it('states where uploads land and with what visibility', function (): void {
    pickerForm(picker: ['disk' => 'media', 'directory' => 'posts/covers', 'visibility' => 'public'])
        ->assertSee('posts/covers')
        ->assertSee('public');
});

it('uploads through the modal with the field placement and attaches on save', function (): void {
    $host = article();

    pickerForm($host, ['directory' => 'posts/covers', 'visibility' => 'public'])
        ->callAction(
            TestAction::make('library')->schemaComponent('cover_image'),
            ['file' => [UploadedFile::fake()->image('holiday photo.png')]],
        )
        ->assertHasNoActionErrors()
        ->call('save');

    $asset = MediaAsset::query()->firstOrFail();

    expect($asset->display_name)->toBe('holiday photo')
        ->and($asset->visibility)->toBe(Visibility::Public)
        ->and($asset->object_key)->toStartWith('posts/covers/')
        ->and($host->media('cover_image')->pluck('id')->all())->toBe([$asset->id]);

    Storage::disk('media')->assertExists($asset->object_key);
});

it('replaces the selection of a single-selection field on upload, leaving the old asset alone', function (): void {
    $host = article();
    $existing = libraryAsset();
    attach($host, $existing);

    pickerForm($host)
        ->callAction(
            TestAction::make('library')->schemaComponent('cover_image'),
            ['file' => [UploadedFile::fake()->image('new.png')]],
        )
        ->call('save');

    $uploaded = MediaAsset::query()->where('id', '!=', $existing->id)->firstOrFail();

    expect($host->media('cover_image')->pluck('id')->all())->toBe([$uploaded->id])
        ->and(MediaAsset::query()->whereKey($existing->id)->exists())->toBeTrue();
});
