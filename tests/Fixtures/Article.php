<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in host model. Later tickets attach media to it, so the tests have
 * something with a real table and a real morph type to point placements at.
 */
class Article extends Model
{
    protected $guarded = [];
}
