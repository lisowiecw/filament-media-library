<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A stand-in authenticated user, so ingest has an id to stamp uploads with.
 */
class User extends Authenticatable
{
    protected $guarded = [];
}
