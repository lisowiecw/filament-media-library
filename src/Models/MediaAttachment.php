<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A relationship between a Media Asset and a host model. An attachment belongs
 * to a named field context when a host model uses more than one media field;
 * an attachment with no host at all is an External reference.
 *
 * @property int $id
 * @property int $media_asset_id
 * @property string|null $host_type
 * @property string|null $host_id
 * @property string|null $field_name
 * @property string|null $reference_identifier
 * @property string|null $reference_label
 * @property int $order
 */
class MediaAttachment extends Model
{
    protected $table = 'media_attachments';

    /** @var list<string> */
    protected $fillable = [
        'media_asset_id',
        'host_type',
        'host_id',
        'field_name',
        'reference_identifier',
        'reference_label',
        'order',
    ];

    /**
     * Narrow to the rows one host model holds in one field context. This is
     * the only expression of that predicate, so the rule that an External
     * reference is never in a field context holds by construction: matching a
     * host type excludes the null host an external reference carries.
     *
     * It filters without ordering, so a count or an exists costs no sort; a
     * caller that wants the attachment order asks for it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForField(Builder $query, Model $host, string $field): void
    {
        $query->where('host_type', $host->getMorphClass())
            ->where('host_id', $host->getKey())
            ->where('field_name', $field);
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
            'order' => 'integer',
        ];
    }
}
