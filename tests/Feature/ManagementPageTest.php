<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Panel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Derivatives\DerivativeHealth;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssetResource;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssets\Pages\ViewMediaAsset;
use Lisowiecw\MediaLibrary\Filament\Tables\UnattachedFilter;
use Lisowiecw\MediaLibrary\Jobs\GenerateDerivative;
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\ManagementPolicy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    Gate::policy(MediaAsset::class, ManagementPolicy::class);

    $this->actingAs(user());
});

function listPage(): Testable
{
    return Livewire::test(ListMediaAssets::class);
}

function viewPage(MediaAsset $asset): Testable
{
    return Livewire::test(ViewMediaAsset::class, ['record' => $asset->getKey()]);
}

it('registers the resource only when the panel opts in', function (): void {
    $panel = new Panel;
    MediaLibraryPlugin::make()->register($panel);

    expect($panel->getResources())->not->toContain(MediaAssetResource::class);

    $opted = new Panel;
    MediaLibraryPlugin::make()->withLibraryManagement()->register($opted);

    expect($opted->getResources())->toContain(MediaAssetResource::class);
});

it('gates the page on viewAny', function (): void {
    ManagementPolicy::$allows['viewAny'] = false;

    expect(MediaAssetResource::canViewAny())->toBeFalse();

    ManagementPolicy::$allows['viewAny'] = true;

    expect(MediaAssetResource::canViewAny())->toBeTrue();
});

it('lists every asset including the private ones', function (): void {
    $private = makeAsset(['display_name' => 'Private one', 'visibility' => 'private']);
    $public = libraryAsset();
    $public->update(['display_name' => 'Public one', 'visibility' => 'public']);

    listPage()->assertCanSeeTableRecords([$private, $public]);
});

it('hides trashed assets until the trashed filter asks for them', function (): void {
    $live = makeAsset(['display_name' => 'Live']);
    $trashed = libraryAsset();
    $trashed->delete();

    listPage()
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$trashed])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$trashed]);
});

it('finds an asset by an object key pasted into search', function (): void {
    $wanted = makeAsset(['object_key' => 'media/2026/invoice-cover.pdf']);
    $other = libraryAsset();

    listPage()
        ->searchTable('media/2026/invoice-cover.pdf')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters by source and by the mime source facet', function (): void {
    $uploaded = makeAsset(['source' => MediaSource::Upload, 'mime_source' => MimeSource::Sniffed]);
    $imported = libraryAsset();
    $imported->update(['source' => MediaSource::Import, 'mime_source' => MimeSource::Extension]);

    listPage()
        ->filterTable('source', MediaSource::Import->value)
        ->assertCanSeeTableRecords([$imported])
        ->assertCanNotSeeTableRecords([$uploaded])
        ->resetTableFilters()
        ->filterTable('mime_source', MimeSource::Sniffed->value)
        ->assertCanSeeTableRecords([$uploaded])
        ->assertCanNotSeeTableRecords([$imported]);
});

it('offers an unattached preset that respects the grace period', function (): void {
    config()->set('media-library.unattached_grace_days', 30);

    $recent = makeAsset(['display_name' => 'Detached today']);
    $recent->forceFill(['unattached_since' => now()])->save();

    $old = libraryAsset();
    $old->forceFill(['unattached_since' => now()->subDays(60)])->save();

    listPage()
        ->filterTable('unattached', ['state' => UnattachedFilter::UNATTACHED])
        ->assertCanSeeTableRecords([$recent, $old])
        ->filterTable('unattached', ['state' => UnattachedFilter::PAST_GRACE])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$recent]);
});

it('renames an asset without touching storage', function (): void {
    $asset = storedAsset(['display_name' => 'Old name']);
    $key = $asset->object_key;

    listPage()
        ->callAction(TestAction::make('rename')->table($asset), [
            'display_name' => 'New name',
            'alt' => 'A photo of a rooftop',
        ]);

    $asset->refresh();

    expect($asset->display_name)->toBe('New name')
        ->and($asset->alt)->toBe('A photo of a rooftop')
        ->and($asset->object_key)->toBe($key)
        ->and(Storage::disk($asset->disk)->exists($key))->toBeTrue();
});

it('refuses to delete an asset that is still in use', function (): void {
    $asset = makeAsset();
    attach(article(), $asset);

    listPage()->callAction(TestAction::make('delete')->table($asset));

    expect($asset->fresh()->trashed())->toBeFalse();
});

it('deletes, restores and force deletes a single asset', function (): void {
    $asset = makeAsset();

    listPage()->callAction(TestAction::make('delete')->table($asset));
    expect($asset->fresh()->trashed())->toBeTrue();

    listPage()->filterTable('trashed', true)
        ->callAction(TestAction::make('restore')->table($asset));
    expect($asset->fresh()->trashed())->toBeFalse();

    listPage()->callAction(TestAction::make('forceDelete')->table($asset), ['reviewed' => true]);
    expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull();
});

it('points download at the signed delivery route whatever the visibility', function (string $visibility): void {
    $asset = makeAsset(['visibility' => $visibility]);

    expect($asset->downloadUrl())->toContain('signature');
})->with(['public', 'private']);

it('uploads through the page', function (): void {
    Gate::define(MediaAuthorization::UPLOAD_MEDIA, fn (): bool => true);

    listPage()->callAction(TestAction::make('upload')->table(), ['files' => [UploadedFile::fake()->image('rooftop.png')]]);

    expect(MediaAsset::query()->where('display_name', 'like', '%rooftop%')->exists())->toBeTrue();
});

it('deletes in bulk and reports the rows it skipped', function (): void {
    $free = makeAsset(['display_name' => 'Free']);
    $used = libraryAsset();
    attach(article(), $used);

    listPage()
        ->selectTableRecords([$free->getKey(), $used->getKey()])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect($free->fresh()->trashed())->toBeTrue()
        ->and($used->fresh()->trashed())->toBeFalse();
});

it('restores in bulk and leaves the untrashed alone', function (): void {
    $trashed = makeAsset();
    $trashed->delete();
    $live = libraryAsset();

    listPage()
        ->filterTable('trashed', true)
        ->selectTableRecords([$trashed->getKey(), $live->getKey()])
        ->callAction(TestAction::make('restore')->table()->bulk());

    expect($trashed->fresh()->trashed())->toBeFalse();
});

it('restricts the unattached bulk delete to assets past the grace period', function (): void {
    config()->set('media-library.unattached_grace_days', 30);

    $old = makeAsset(['display_name' => 'Long gone']);
    $old->forceFill(['unattached_since' => now()->subDays(60)])->save();

    $recent = libraryAsset();
    $recent->forceFill(['unattached_since' => now()])->save();

    listPage()
        ->selectTableRecords([$old->getKey(), $recent->getKey()])
        ->callAction(TestAction::make('deleteUnattached')->table()->bulk());

    expect($old->fresh()->trashed())->toBeTrue()
        ->and($recent->fresh()->trashed())->toBeFalse();
});

it('offers no bulk force delete', function (): void {
    $names = array_keys(listPage()->instance()->getTable()->getToolbarActions());

    expect($names)->not->toContain('forceDelete');
});

it('shows the storage identity and the usage panel on the view page', function (): void {
    $asset = makeAsset(['object_key' => 'media/rooftop.jpg', 'import_source' => 'legacy/rooftop.jpg']);
    attach(article('The rooftop post'), $asset);

    viewPage($asset)
        ->assertSee('media/rooftop.jpg')
        ->assertSee('legacy/rooftop.jpg')
        ->assertSee('The rooftop post');
});

it('paints a larger rendering of an image on the view page', function (): void {
    $asset = storedAsset(['size' => 5 * 1024 * 1024]);
    readyDerivative($asset, DerivativeVariant::Preview);

    viewPage($asset)->assertSee($asset->previewUrl());
});

it('paints no rendering on the view page of something that is not an image', function (): void {
    $asset = storedAsset(['mime_type' => 'application/pdf', 'object_key' => 'media/report.pdf']);

    expect($asset->previewUrl())->toBeNull();

    viewPage($asset)->assertOk();
});

it('never exposes the importer as an action', function (): void {
    listPage()->assertActionDoesNotExist('import');
});

it('reads out the derivative health and queues a batch', function (): void {
    Queue::fake();

    storedAsset(['size' => 5 * 1024 * 1024]);

    expect(DerivativeHealth::counts()['missing'])->toBeGreaterThan(0);

    listPage()->callAction(TestAction::make('derivativeHealth')->table());

    Queue::assertPushed(GenerateDerivative::class);

    // Queueing is what clears the count: the readout stops asking for work it
    // has already asked for.
    expect(DerivativeHealth::counts()['missing'])->toBe(0);
});

it('offers no replace, no visibility change and no move', function (string $name): void {
    $asset = makeAsset();

    listPage()->assertActionDoesNotExist(TestAction::make($name)->table($asset));
})->with(['replace', 'visibility', 'move', 'moveDisk', 'moveDirectory']);

it('skips the rows a policy refuses during a bulk delete', function (): void {
    $mine = makeAsset(['display_name' => 'Mine']);
    $theirs = libraryAsset();

    ManagementPolicy::$refuseFor['delete'] = $theirs->getKey();

    listPage()
        ->selectTableRecords([$mine->getKey(), $theirs->getKey()])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect($mine->fresh()->trashed())->toBeTrue()
        ->and($theirs->fresh()->trashed())->toBeFalse();
});

describe('the usage panel', function (): void {
    it('revokes one external reference where it stands', function (): void {
        $asset = makeAsset();
        $reference = $asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412');

        viewPage($asset)
            ->assertSee('Campaign #412')
            ->assertSee(__('media-library::messages.management.actions.revoke'))
            ->callAction(TestAction::make('revoke-'.$reference->id)->schemaComponent('usage-'.$reference->id));

        expect($asset->attachments()->count())->toBe(0);
    });

    it('offers no revoke on a host model row', function (): void {
        $asset = makeAsset();
        $host = article();
        app(AttachmentReconciler::class)->reconcile($host, 'cover', [$asset->getKey()]);

        $row = $asset->attachments()->sole();

        viewPage($asset)->assertActionDoesNotExist(TestAction::make('revoke-'.$row->id)->schemaComponent('usage-'.$row->id));

        expect($asset->attachments()->count())->toBe(1);
    });

    it('offers no revoke the policy does not allow', function (): void {
        ManagementPolicy::$allows['detach'] = false;

        $asset = makeAsset();
        $reference = $asset->attachments()->createExternal('newsletter-2026-08');

        // An unauthorised action is not disabled here: Filament drops it from
        // the panel altogether, so the row lists the reference and offers
        // nothing to do about it.
        viewPage($asset)->assertDontSee(__('media-library::messages.management.actions.revoke'));

        expect($asset->attachments()->count())->toBe(1);
    });
});
