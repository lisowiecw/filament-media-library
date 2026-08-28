<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;

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
 * @property string $visibility
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
            'source' => MediaSource::class,
            'size' => 'integer',
        ];
    }
}
