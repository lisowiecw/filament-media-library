<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;
use Workbench\App\Models\Article;

it('uploads through the Upload tab and attaches what it made', function (): void {
    $this->signIn();

    $article = $this->article('Uploading');
    $path = $this->file('through-the-tab.jpg');

    $page = visit("/admin/articles/{$article->id}/edit")
        ->click('[data-field="cover_image"] .fi-ml-picker-trigger button')
        ->waitForText('Upload')
        ->click('Upload');

    $this->staged($this->pour($page, $path))
        ->click('Attach')
        ->waitForText('through-the-tab')
        ->click('Save changes')
        ->waitForText('Saved');

    $asset = MediaAsset::query()->sole();

    expect($asset->display_name)->toBe('through-the-tab')
        ->and(Article::query()->sole()->media('cover_image')->pluck('id')->all())->toBe([$asset->id]);
});

/**
 * The picker's Upload tab sits in the same Livewire component as the library
 * grid behind it, and the grid asks the component to refresh itself for as
 * long as anything on the page is unresolved. A refresh landing in the middle
 * of a send is the obvious way for an upload to lose its place, so it is
 * arranged here deliberately rather than left to a suspicion: an unresolved
 * card in the library, and an interval short enough that a refresh is all but
 * certain to land inside the upload.
 */
it('finishes an upload with the grid behind it polling', function (): void {
    $this->signIn();

    config()->set('media-library.poll_interval', '150ms');

    // Ingested the ordinary way, then put back into the state a card is in
    // while its work sits in a queue, which is what makes the grid poll. Both
    // halves are left claimed rather than absent: an absent claim is one the
    // render would take itself, and the workbench queue runs inline, so the
    // card would be resolved again by the time the page it made was drawn.
    $waiting = $this->ingest('still-working.jpg', 'public');
    $waiting->derivatives()->delete();

    $waiting->derivatives()->create([
        'variant' => DerivativeVariant::Thumb->value,
        'disk' => $waiting->disk,
        'object_key' => MediaDerivative::keyFor($waiting, DerivativeVariant::Thumb),
        'status' => DerivativeStatus::Pending->value,
    ]);

    $waiting->forceFill([
        'blurhash' => null,
        'blurhash_status' => BlurHashStatus::Pending->value,
    ])->save();

    $article = $this->article('Polling');
    $path = $this->file('through-the-poll.jpg');

    $page = visit("/admin/articles/{$article->id}/edit")
        ->click('[data-field="cover_image"] .fi-ml-picker-trigger button')
        ->waitForText('Upload');

    // The premise of the test: the grid really is asking again behind the
    // tab. Waited on rather than read once, because the grid arrives with the
    // modal's own content and the tab's label is on screen before it does.
    // The interval is in the attribute's name, so the selector carries the one
    // set above.
    $this->present($page, '[wire\\:poll\\.visible\\.150ms]');

    $page->click('Upload');

    $this->staged($this->pour($page, $path))
        ->click('Attach')
        ->waitForText('through-the-poll')
        ->click('Save changes')
        ->waitForText('Saved');

    expect(Article::query()->sole()->media('cover_image')->pluck('display_name')->all())->toBe(['through-the-poll']);
});
