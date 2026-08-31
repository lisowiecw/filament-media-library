<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in for the host table a migrating application already has: a string
 * column holding a legacy upload path, and a column naming who owned the row.
 */
class LegacyRecord extends Model
{
    protected $table = 'legacy_records';

    protected $guarded = [];

    public $timestamps = false;
}
