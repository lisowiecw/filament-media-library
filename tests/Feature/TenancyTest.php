<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\AttachRefused;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Library\OfferScope;
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\Article;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;

beforeEach(function (): void {
    Gate::policy(MediaAsset::class, HostPolicy::class);

    $this->actingAs(user());
});

/**
 * What the library offers a field, by name, under whatever tenant is current.
 *
 * @return list<string>
 */
function offeredNames(): array
{
    /** @var list<string> $names */
    $names = (new OfferScope(IngestRules::resolve(), Visibility::Private))
        ->query()
        ->pluck('display_name')
        ->all();

    return $names;
}

describe('the stamp', function (): void {
    it('stamps an upload with the tenant that was current', function (): void {
        tenantIs('acme');

        expect(ingest(pngUpload())->tenant_id)->toBe('acme');
    });

    it('leaves an upload untenanted where no resolver was configured', function (): void {
        expect(ingest(pngUpload())->tenant_id)->toBeNull();
    });

    it('refuses to move an asset from one tenant to another', function (): void {
        $asset = makeAsset(['tenant_id' => 'acme']);

        $asset->tenant_id = 'other';

        expect(fn () => $asset->save())->toThrow(AttachRefused::class);
    });

    it('lets an untenanted asset be claimed once', function (): void {
        $asset = makeAsset();

        $asset->tenant_id = 'acme';
        $asset->save();

        expect($asset->refresh()->tenant_id)->toBe('acme');
    });
});

describe('the offer', function (): void {
    it('offers only what belongs to the current tenant', function (): void {
        makeAsset(['display_name' => 'Ours', 'tenant_id' => 'acme']);
        makeAsset(['display_name' => 'Theirs', 'tenant_id' => 'other', 'object_key' => 'media/two.jpg']);
        makeAsset(['display_name' => 'Nobody', 'object_key' => 'media/three.jpg']);

        tenantIs('acme');

        expect(offeredNames())->toBe(['Ours']);
    });

    it('offers everything where the plugin is not tenanted at all', function (): void {
        makeAsset(['display_name' => 'Ours', 'tenant_id' => 'acme']);
        makeAsset(['display_name' => 'Nobody', 'object_key' => 'media/two.jpg']);

        expect(offeredNames())->toEqualCanonicalizing(['Ours', 'Nobody']);
    });

    it('offers nothing where the resolver answers with no tenant', function (): void {
        makeAsset(['display_name' => 'Ours', 'tenant_id' => 'acme']);

        tenantIs('acme');

        /** @var MediaLibraryPlugin $plugin */
        $plugin = Filament\Facades\Filament::getCurrentOrDefaultPanel()->getPlugin('media-library');
        $plugin->tenantUsing(fn () => null);

        expect(offeredNames())->toBe([]);
    });
});

describe('delivery', function (): void {
    it('answers a cross-tenant request with a 404 rather than a 403', function (): void {
        $asset = storedAsset(['tenant_id' => 'other']);
        $url = DeliveryRoute::signedUrl($asset);

        tenantIs('acme');

        $this->get($url)->assertNotFound();
    });

    it('still answers a refusal within the tenant with a 403', function (): void {
        $asset = storedAsset(['tenant_id' => 'acme']);
        $url = DeliveryRoute::signedUrl($asset);

        tenantIs('acme');
        HostPolicy::$allows = false;

        $this->get($url)->assertForbidden();
    });

    it('hides an untenanted asset from a tenant, public or not', function (): void {
        $asset = storedAsset(['visibility' => 'public']);
        $url = DeliveryRoute::signedUrl($asset);

        tenantIs('acme');

        $this->get($url)->assertNotFound();
    });

    it('delivers within the tenant', function (): void {
        $asset = storedAsset(['tenant_id' => 'acme']);
        $url = DeliveryRoute::signedUrl($asset);

        tenantIs('acme');

        $this->get($url)->assertOk();
    });
});

describe('attaching', function (): void {
    it('refuses to attach an asset from another tenant', function (): void {
        $asset = makeAsset(['tenant_id' => 'other']);
        $article = Article::create(['title' => 'Post']);

        tenantIs('acme');

        expect(fn () => app(AttachmentReconciler::class)->reconcile($article, 'cover_image', [$asset->id]))
            ->toThrow(AttachRefused::class);
    });

    it('leaves an attachment made before the tenant existed alone', function (): void {
        $asset = makeAsset(['tenant_id' => 'other']);
        $article = Article::create(['title' => 'Post']);
        app(AttachmentReconciler::class)->reconcile($article, 'cover_image', [$asset->id]);

        tenantIs('acme');

        app(AttachmentReconciler::class)->reconcile($article, 'cover_image', [$asset->id]);

        expect($article->mediaAttachments()->count())->toBe(1);
    });
});

describe('the claim command', function (): void {
    it('claims every untenanted asset for the named tenant', function (): void {
        $ours = makeAsset();
        $theirs = makeAsset(['tenant_id' => 'other', 'object_key' => 'media/two.jpg']);

        $this->artisan('media:assign-tenant', ['tenant' => 'acme'])->assertSuccessful();

        expect($ours->refresh()->tenant_id)->toBe('acme')
            ->and($theirs->refresh()->tenant_id)->toBe('other');
    });

    it('reports an asset that already belongs to a tenant rather than moving it', function (): void {
        $theirs = makeAsset(['tenant_id' => 'other']);

        $this->artisan('media:assign-tenant', ['tenant' => 'acme', '--asset' => [$theirs->ulid]])
            ->expectsOutputToContain('already claimed by other')
            ->assertSuccessful();

        expect($theirs->refresh()->tenant_id)->toBe('other');
    });

    it('writes nothing on a dry run', function (): void {
        $asset = makeAsset();

        $this->artisan('media:assign-tenant', ['tenant' => 'acme', '--dry-run' => true])->assertSuccessful();

        expect($asset->refresh()->tenant_id)->toBeNull();
    });
});

describe('the import option', function (): void {
    beforeEach(function (): void {
        Storage::disk('media')->put('legacy/one.txt', 'bytes');
    });

    it('stamps adopted assets with the tenant it was given', function (): void {
        $this->artisan('media:import', [
            '--source' => 'disk',
            '--disk' => 'media',
            '--prefix' => 'legacy',
            '--tenant' => 'acme',
            '--report' => storage_path('logs/tenancy-import.json'),
        ])->assertSuccessful();

        expect(MediaAsset::query()->first()?->tenant_id)->toBe('acme');
    });

    it('takes the literal none as belonging to no one', function (): void {
        $this->artisan('media:import', [
            '--source' => 'disk',
            '--disk' => 'media',
            '--prefix' => 'legacy',
            '--tenant' => 'none',
            '--report' => storage_path('logs/tenancy-import.json'),
        ])->assertSuccessful();

        expect(MediaAsset::query()->first()?->tenant_id)->toBeNull();
    });

    it('refuses a run whose tenant is empty rather than stamping an empty one', function (): void {
        $this->artisan('media:import', [
            '--source' => 'disk',
            '--disk' => 'media',
            '--prefix' => 'legacy',
            '--tenant' => ' ',
            '--report' => storage_path('logs/tenancy-import.json'),
        ])->assertFailed();

        expect(MediaAsset::query()->count())->toBe(0);
    });

    it('refuses a run that never says who the assets belong to', function (): void {
        $this->artisan('media:import', [
            '--source' => 'disk',
            '--disk' => 'media',
            '--prefix' => 'legacy',
            '--report' => storage_path('logs/tenancy-import.json'),
        ])->assertFailed();

        expect(MediaAsset::query()->count())->toBe(0);
    });
});
