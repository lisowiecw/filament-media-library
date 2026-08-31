<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

it('renames an asset without touching storage', function (): void {
    $this->signIn();

    $asset = $this->ingest('a-plain-name.jpg');

    visit('/admin/media-assets')
        ->waitForText('a-plain-name')
        ->click($this->rowAction('rename'))
        ->waitForText('Alt text')
        ->fill('[id="mountedActionSchema0.display_name"]', 'A better name')
        ->fill('[id="mountedActionSchema0.alt"]', 'A field of grass')
        ->click($this->confirm())
        ->waitForText('Renamed. Nothing in storage changed.');

    $asset->refresh();

    expect($asset->display_name)->toBe('A better name')
        ->and($asset->alt)->toBe('A field of grass')
        ->and($asset->object_key)->toBe(MediaAsset::query()->sole()->object_key);
});

it('deletes an unused asset and restores it', function (): void {
    $this->signIn();

    $asset = $this->ingest('goes-away.jpg');

    visit('/admin/media-assets')
        ->waitForText('goes-away')
        ->click($this->rowAction('delete'))
        ->waitForText('Are you sure you would like to do this?')
        ->click($this->confirm())
        ->waitForText('Deleted.');

    expect($asset->fresh()->trashed())->toBeTrue();

    // The list hides trashed rows until the filter is asked for them, and the
    // filters are deferred, so the panel has to be opened and applied.
    visit('/admin/media-assets')
        ->click('button[aria-label="Filter"]')
        ->select('[id="tableFiltersForm.trashed.value"]', '0')
        ->click('button[wire\\:click="applyTableFilters"]:visible')
        ->waitForText('goes-away')
        // The panel covers the row it just revealed, so it is put away again.
        ->click('button[aria-label="Filter"]')
        ->click($this->rowAction('restore'))
        ->waitForText('Are you sure you would like to do this?')
        ->click($this->confirm())
        ->waitForText('Restored.');

    expect($asset->fresh()->trashed())->toBeFalse();
});

it('refuses to delete an asset something still uses, and shows what uses it', function (): void {
    $this->signIn();

    $asset = $this->ingest('still-used.jpg');
    $article = $this->article('A post that uses it');
    $this->attach($article, 'cover_image', $asset);

    visit('/admin/media-assets')
        ->waitForText('still-used')
        ->click($this->rowAction('delete'))
        ->waitForText('Are you sure you would like to do this?')
        ->click($this->confirm())
        ->waitForText('This asset is still in use and was not deleted.')
        ->assertSee('A post that uses it');

    expect($asset->fresh()->trashed())->toBeFalse();
});

it('force deletes past the usage list, once the list has been acknowledged', function (): void {
    $this->signIn();

    $asset = $this->ingest('goes-for-good.jpg');
    $article = $this->article('A post that uses it');
    $this->attach($article, 'cover_image', $asset);

    visit('/admin/media-assets')
        ->waitForText('goes-for-good')
        ->click($this->rowAction('forceDelete'))
        // The list is the modal: the acknowledgement is only offered under it.
        ->waitForText('A post that uses it')
        ->click('[id="mountedActionSchema0.reviewed"]')
        ->click($this->confirm())
        ->waitForText('Deleted permanently.');

    expect(MediaAsset::withTrashed()->count())->toBe(0);
});

it('reads out derivative health', function (): void {
    $this->signIn();

    $asset = $this->ingest('has-renderings.jpg');

    MediaDerivative::query()
        ->where('media_asset_id', $asset->id)
        ->limit(1)
        ->update(['status' => DerivativeStatus::Failed]);

    visit('/admin/media-assets')
        ->waitForText('has-renderings')
        ->click('button:has-text("Derivative health")')
        ->waitForText('1 failed');
});
