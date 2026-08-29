<?php

declare(strict_types=1);

use Filament\Support\Enums\Width;
use Illuminate\Http\UploadedFile;
use Lisowiecw\MediaLibrary\Forms\Components\MediaPicker;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\Article;

/**
 * The configured picker itself, for the settings that are answered by the field
 * rather than by anything the form renders.
 *
 * @param  array<string, mixed>  $picker
 */
function pickerField(array $picker): MediaPicker
{
    /** @var MediaPicker $component */
    $component = pickerForm(article(), $picker)->instance()->form->getComponent('cover_image');

    return $component;
}

it('is a single selection until a field says otherwise', function (): void {
    [$one, $two] = [libraryAsset(), libraryAsset()];

    $single = pickerForm(article());
    $single->instance()->form->getComponent('cover_image')->select([$one->id, $two->id]);

    expect($single->get('data.cover_image'))->toBe([$two->id]);
});

it('holds a gallery once told to be multiple', function (): void {
    [$one, $two] = [libraryAsset(), libraryAsset()];

    $gallery = pickerForm(article(), ['multiple' => true]);
    $gallery->instance()->form->getComponent('cover_image')->select([$one->id, $two->id]);

    expect($gallery->get('data.cover_image'))->toBe([$one->id, $two->id]);
});

it('reads maxItems alone as a gallery, so one line of configuration is enough', function (): void {
    [$one, $two] = [libraryAsset(), libraryAsset()];

    $gallery = pickerForm(article(), ['maxItems' => 12]);
    $gallery->instance()->form->getComponent('cover_image')->select([$one->id, $two->id]);

    expect($gallery->get('data.cover_image'))->toBe([$one->id, $two->id]);
});

it('never attaches the same asset to one field twice', function (): void {
    $asset = libraryAsset();

    $component = pickerForm(article(), ['multiple' => true]);
    $picker = $component->instance()->form->getComponent('cover_image');
    $picker->select([$asset->id]);
    $picker->select([$asset->id, $asset->id]);

    expect($component->get('data.cover_image'))->toBe([$asset->id]);
});

it('keeps what is attached and warns when a selection overflows maxItems', function (): void {
    [$one, $two, $three] = [libraryAsset(), libraryAsset(), libraryAsset()];

    $component = pickerForm(article(), ['multiple' => true, 'maxItems' => 2]);
    $component->instance()->form->getComponent('cover_image')->select([$one->id, $two->id, $three->id]);

    expect($component->get('data.cover_image'))->toBe([$one->id, $two->id]);

    $component->assertNotified();
});

it('reorders by dragging, taking the whole new order at once', function (): void {
    $host = article();
    [$one, $two, $three] = [libraryAsset(), libraryAsset(), libraryAsset()];

    $component = pickerForm($host, ['multiple' => true, 'reorderable' => true])
        ->set('data.cover_image', [$one->id, $two->id, $three->id]);

    pickerCall($component, 'reorderItems', ['ids' => [$three->id, $one->id, $two->id]])
        ->call('save');

    expect($host->media('cover_image')->pluck('id')->all())->toBe([$three->id, $one->id, $two->id]);
});

it('reorders one step at a time from the arrow controls', function (): void {
    [$one, $two, $three] = [libraryAsset(), libraryAsset(), libraryAsset()];

    $component = pickerForm(article(), ['multiple' => true, 'reorderable' => true])
        ->set('data.cover_image', [$one->id, $two->id, $three->id]);

    pickerCall($component, 'moveItem', ['id' => $three->id, 'step' => -1]);
    expect($component->get('data.cover_image'))->toBe([$one->id, $three->id, $two->id]);

    pickerCall($component, 'moveItem', ['id' => $one->id, 'step' => 1]);
    expect($component->get('data.cover_image'))->toBe([$three->id, $one->id, $two->id]);
});

it('leaves the order alone at either end and on a list that is not a rearrangement', function (): void {
    [$one, $two] = [libraryAsset(), libraryAsset()];
    $stranger = libraryAsset();

    $component = pickerForm(article(), ['multiple' => true, 'reorderable' => true])
        ->set('data.cover_image', [$one->id, $two->id]);

    pickerCall($component, 'moveItem', ['id' => $one->id, 'step' => -1]);
    pickerCall($component, 'moveItem', ['id' => $two->id, 'step' => 1]);
    pickerCall($component, 'reorderItems', ['ids' => [$stranger->id, $one->id]]);
    pickerCall($component, 'reorderItems', ['ids' => [$two->id]]);

    expect($component->get('data.cover_image'))->toBe([$one->id, $two->id]);
});

it('refuses to reorder a field that was never made reorderable', function (): void {
    [$one, $two] = [libraryAsset(), libraryAsset()];

    $component = pickerForm(article(), ['multiple' => true])
        ->set('data.cover_image', [$one->id, $two->id]);

    pickerCall($component, 'reorderItems', ['ids' => [$two->id, $one->id]]);

    expect($component->get('data.cover_image'))->toBe([$one->id, $two->id]);
});

it('detaches a removed item on save and leaves the asset alone', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attach($host, $one, $two);

    $component = pickerForm($host, ['multiple' => true]);

    pickerCall($component, 'removeItem', ['id' => $one->id])->call('save');

    expect($host->media('cover_image')->pluck('id')->all())->toBe([$two->id])
        ->and(MediaAsset::query()->whereKey($one->id)->exists())->toBeTrue();
});

it('treats a changed id in a single-selection field as the diff alone, the previous asset surviving', function (): void {
    $host = article();
    [$existing, $replacement] = [libraryAsset(), libraryAsset()];
    attach($host, $existing);

    pickerForm($host)
        ->set('data.cover_image', [$replacement->id])
        ->call('save');

    expect($host->media('cover_image')->pluck('id')->all())->toBe([$replacement->id])
        ->and(MediaAsset::query()->whereKey($existing->id)->exists())->toBeTrue();
});

it('uploads and attaches a file dropped on the inline trigger, without the modal', function (): void {
    $host = article();

    dropOnPicker(pickerForm($host, ['directory' => 'posts/covers']), UploadedFile::fake()->image('dropped.png'))
        ->call('save');

    $asset = MediaAsset::query()->firstOrFail();

    expect($asset->display_name)->toBe('dropped')
        ->and($asset->object_key)->toStartWith('posts/covers/')
        ->and($host->media('cover_image')->pluck('id')->all())->toBe([$asset->id]);
});

it('commits a drop at once, where a click in the Library tab waits for the confirm', function (): void {
    $component = pickerForm(article());

    dropOnPicker($component, UploadedFile::fake()->image('dropped.png'));

    $asset = MediaAsset::query()->firstOrFail();

    expect($component->get('data.cover_image'))->toBe([$asset->id]);
});

it('uses the first of several files dropped on a single-selection field, and says so', function (): void {
    $component = pickerForm(article());

    dropOnPicker(
        $component,
        UploadedFile::fake()->image('first.png'),
        UploadedFile::fake()->image('second.png'),
    );

    expect(MediaAsset::query()->count())->toBe(1)
        ->and(MediaAsset::query()->firstOrFail()->display_name)->toBe('first');

    $component->assertNotified();
});

it('offers the Library tab body as a drop surface too', function (): void {
    libraryModal()->assertSeeHtml('data-droppable="true"');

    libraryModal(['droppable' => false])->assertDontSeeHtml('data-droppable="true"');
});

it('ingests only what the field still has room for, so an over-full drop is never uploaded', function (): void {
    $existing = libraryAsset();
    $host = article();
    attach($host, $existing);

    $component = pickerForm($host, ['multiple' => true, 'maxItems' => 2]);

    dropOnPicker(
        $component,
        UploadedFile::fake()->image('first.png'),
        UploadedFile::fake()->image('second.png'),
    );

    expect(MediaAsset::query()->count())->toBe(2)
        ->and($component->get('data.cover_image'))->toHaveCount(2);

    $component->assertNotified();
});

it('takes every file dropped on a gallery, in the order they arrived', function (): void {
    $component = pickerForm(article(), ['multiple' => true]);

    dropOnPicker(
        $component,
        UploadedFile::fake()->image('first.png'),
        UploadedFile::fake()->image('second.png'),
    );

    expect(MediaAsset::query()->orderBy('id')->pluck('display_name')->all())->toBe(['first', 'second']);
});

it('accepts nothing dropped on a field that opted out of upload', function (): void {
    $component = pickerForm(article(), ['droppable' => false]);

    dropOnPicker($component, UploadedFile::fake()->image('dropped.png'));

    expect(MediaAsset::query()->count())->toBe(0)
        ->and($component->get('data.cover_image'))->toBe([]);
});

it('renders no drop surface and no Upload tab on a field that opted out', function (): void {
    pickerForm(article(), ['droppable' => false])
        ->assertDontSeeHtml('data-droppable="true"');

    libraryModal(['droppable' => false])
        ->assertDontSeeHtml('fi-fo-file-upload')
        ->assertSee('Library');
});

it('offers the drop surface and both tabs by default', function (): void {
    pickerForm(article())->assertSeeHtml('data-droppable="true"');

    libraryModal()->assertSeeHtml('fi-fo-file-upload')->assertSee('Library');
});

it('narrows what the library offers to what the field asked for', function (): void {
    makeAsset(['disk' => 'archive', 'object_key' => 'archive/one.jpg', 'display_name' => 'Archived']);
    makeAsset(['disk' => 'media', 'object_key' => 'media/two.jpg', 'display_name' => 'Everyday']);

    libraryModal(['scopeLibrary' => 'archive'])
        ->assertSee('Archived')
        ->assertDontSee('Everyday');
});

it('cannot be widened past what the package already decided', function (): void {
    makeAsset(['visibility' => 'private', 'object_key' => 'media/private.jpg', 'display_name' => 'Kept back']);

    // A public-placement field only reaches public assets. An `orWhere` in the
    // narrowing is boxed, so it can never reach out for the private one.
    libraryModal(['visibility' => 'public', 'scopeLibrary' => 'widen'])
        ->assertDontSee('Kept back');
});

it('resolves a card thumbnail through the field callback', function (): void {
    $asset = makeAsset(['visibility' => 'public', 'object_key' => 'media/public.jpg']);

    libraryModal(['thumbnailUsing' => 'stamped'])
        ->assertSeeHtml('https://thumbs.test/'.$asset->id);
});

it('opens on the tab the field asked for', function (): void {
    expect(pickerField(['defaultTab' => 'library'])->getDefaultTabIndex())->toBe(1)
        ->and(pickerField(['defaultTab' => 'upload'])->getDefaultTabIndex())->toBe(2);
});

it('opens on the Library tab when a field asked for an Upload tab it does not have', function (): void {
    expect(pickerField(['droppable' => false, 'defaultTab' => 'upload'])->getDefaultTabIndex())->toBe(1);
});

it('carries the field modal width through to the action', function (): void {
    $width = pickerField(['modalWidth' => '7xl'])->getLibraryAction()->getModalWidth();

    expect($width instanceof Width ? $width->value : $width)->toBe('7xl');
});

it('leaves the host record alone when a drop lands on an unsaved form', function (): void {
    dropOnPicker(pickerForm(), UploadedFile::fake()->image('dropped.png'));

    expect(Article::query()->count())->toBe(0)
        ->and(MediaAsset::query()->count())->toBe(1);
});
