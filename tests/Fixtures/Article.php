<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\Concerns\HasMedia;

/**
 * A stand-in host model, so the tests have something with a real table and a
 * real morph type to attach media to.
 */
class Article extends Model
{
    use HasMedia;

    protected $guarded = [];
}
