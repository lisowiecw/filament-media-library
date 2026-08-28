<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A relationship between a Media Asset and a host model. Fleshed out, with its
 * table, in the attachments ticket; declared here so the asset can name it.
 */
class MediaAttachment extends Model
{
    protected $table = 'media_attachments';

    protected $guarded = [];
}
