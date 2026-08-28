<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A plugin-generated, downscaled rendering of a Media Asset. Fleshed out, with
 * its table, in the derivatives ticket; declared here so the asset can name it.
 */
class MediaDerivative extends Model
{
    protected $table = 'media_derivatives';

    protected $guarded = [];
}
