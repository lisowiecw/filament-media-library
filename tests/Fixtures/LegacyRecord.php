<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\Concerns\HasMedia;

/**
 * A stand-in for the host table a migrating application already has: a string
 * column holding a legacy upload path, a column holding several of them, and a
 * column naming who owned the row.
 *
 * It reads its own media back, because what an import is for is the host row
 * finding its assets afterwards without the legacy column.
 */
class LegacyRecord extends Model
{
    use HasMedia;

    protected $table = 'legacy_records';

    protected $guarded = [];

    public $timestamps = false;
}
