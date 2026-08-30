<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;

/**
 * A plugin-generated, downscaled rendering of a Media Asset, stored as its own
 * object and recorded as a child of the asset.
 *
 * A derivative is never a Media Asset: it cannot be attached, named or
 * offered, and it inherits its parent's placement and visibility rather than
 * carrying its own. Its key is immutable, so it dies with the asset rather
 * than being edited.
 *
 * @property int $id
 * @property int $media_asset_id
 * @property DerivativeVariant $variant
 * @property string $disk
 * @property string $object_key
 * @property int|null $width
 * @property int|null $height
 * @property int|null $bytes
 * @property DerivativeStatus $status
 * @property string|null $failure_reason
 * @property string|null $config_digest
 * @property-read MediaAsset|null $asset
 */
class MediaDerivative extends Model
{
    /**
     * Every derivative is WEBP, whatever the original was: one encoder, one
     * key suffix, one response type.
     */
    public const string MIME_TYPE = 'image/webp';

    protected $table = 'media_derivatives';

    /** @var list<string> */
    protected $fillable = [
        'media_asset_id',
        'variant',
        'disk',
        'object_key',
        'width',
        'height',
        'bytes',
        'status',
        'failure_reason',
        'config_digest',
    ];

    /**
     * Where a derivative's own bytes live. The layout is by asset and variant,
     * so a whole asset's renderings are removable by prefix and a regeneration
     * overwrites in place rather than leaving the old object behind.
     */
    public static function keyFor(MediaAsset $asset, DerivativeVariant $variant): string
    {
        return self::prefix().'/'.$asset->ulid.'/'.$variant->value.'.webp';
    }

    /**
     * What a saved derivative is called, read from the parent's own names so
     * a person who saves one recognises it, with the original extension
     * dropped because the bytes are no longer that format.
     */
    public static function filenameFor(MediaAsset $asset, DerivativeVariant $variant): string
    {
        $name = pathinfo($asset->original_client_filename ?? $asset->display_name, PATHINFO_FILENAME);

        return $name.'-'.$variant->value.'.webp';
    }

    private static function prefix(): string
    {
        /** @var string $prefix */
        $prefix = config('media-library.derivatives.prefix', 'media-derivatives');

        return trim($prefix, '/');
    }

    /**
     * Where this derivative's content is addressed, or null while there is
     * nothing to address.
     *
     * A public parent resolves to the disk's own URL, exactly as the original
     * does. A private one resolves to the Delivery route's variant parameter,
     * which re-checks View on every hit; the disk URL is never handed out for
     * it, and neither is a presigned one, because a derivative of a private
     * asset is exactly as private as its parent.
     */
    public function url(): ?string
    {
        $asset = $this->asset;

        if (! $this->status->isReady() || $asset === null) {
            return null;
        }

        return $asset->visibility->isPublic()
            ? Storage::disk($this->disk)->url($this->object_key)
            : DeliveryRoute::derivativeUrl($asset, $this->variant);
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variant' => DerivativeVariant::class,
            'status' => DerivativeStatus::class,
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }
}
