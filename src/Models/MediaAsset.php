<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Ingest\ActiveContent;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;

/**
 * A reusable file record with human-readable file metadata and storage
 * metadata. Its readable name is distinct from the storage object identity.
 *
 * @property int $id
 * @property string $ulid
 * @property string $display_name
 * @property string|null $original_client_filename
 * @property string|null $extension
 * @property string|null $alt
 * @property string|null $mime_type
 * @property MimeSource $mime_source
 * @property int|null $size
 * @property string $disk
 * @property string $object_key
 * @property Visibility $visibility
 * @property MediaSource $source
 * @property string|null $import_source
 * @property string|null $uploaded_by
 * @property string|null $tenant_id
 * @property string|null $blurhash
 */
class MediaAsset extends Model
{
    use SoftDeletes;

    protected $table = 'media_assets';

    /**
     * Transient, never persisted: whether ingest saw an existing asset whose
     * readable name folds to this one's. Informational only, so the caller can
     * offer the person a choice; it never blocks and never overwrites.
     */
    public bool $nameCollided = false;

    /** @var list<string> */
    protected $fillable = [
        'ulid',
        'display_name',
        'original_client_filename',
        'extension',
        'alt',
        'mime_type',
        'mime_source',
        'size',
        'disk',
        'object_key',
        'visibility',
        'source',
        'import_source',
        'uploaded_by',
        'tenant_id',
        'blurhash',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            $asset->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Where this asset's content is addressed, and the single supported way to
     * ask: nothing outside the package should build either shape by hand.
     *
     * A public asset resolves to the disk's own URL, so the bytes come from
     * the CDN or bucket the application configured and stay cacheable. The
     * host that answers is whatever the disk's `url` key names; the plugin
     * adds no setting of its own and never checks the hostname, because it
     * assumes public placement is a foreign origin.
     *
     * A private asset resolves to a freshly signed Delivery route, which
     * re-checks View on every hit.
     */
    public function url(): string
    {
        return $this->visibility->isPublic()
            ? Storage::disk($this->disk)->url($this->object_key)
            : DeliveryRoute::signedUrl($this);
    }

    /**
     * Where this asset's content is addressed when the point is to save it
     * rather than show it. Always the Delivery route, public asset included:
     * a link's `download` attribute is ignored cross-origin, and the plugin
     * assumes public placement is a foreign origin, so the route is the only
     * place a saving disposition can be attached. See ADR-0001.
     */
    public function downloadUrl(): string
    {
        return DeliveryRoute::signedUrl($this, download: true);
    }

    /**
     * Whether delivery has to force a download for this asset. Read from the
     * stored type rather than from a column, so a rule tightened today covers
     * everything already in the library.
     */
    public function isActiveContent(): bool
    {
        return ActiveContent::matches($this->mime_type);
    }

    /**
     * Drop the assets whose type the application has declared unwanted. Every
     * picker offer query goes through this: a blocked type is refused at
     * upload, so anything matching here predates the rule, and the grid must
     * not invite attaching it. Nothing is hidden from library management.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeExcludingBlockedTypes(Builder $query, ?IngestRules $rules = null): void
    {
        $rules ??= IngestRules::resolve();

        $query->whereNotIn(DB::raw('lower(extension)'), $rules->blockedExtensions())
            ->whereNotIn(DB::raw('lower(mime_type)'), $rules->blockedMimeTypes());
    }

    /**
     * @return HasMany<MediaAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    /**
     * @return HasMany<MediaDerivative, $this>
     */
    public function derivatives(): HasMany
    {
        return $this->hasMany(MediaDerivative::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mime_source' => MimeSource::class,
            'visibility' => Visibility::class,
            'source' => MediaSource::class,
            'size' => 'integer',
        ];
    }
}
