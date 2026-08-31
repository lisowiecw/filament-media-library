<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\Concerns\HasMedia;
use Workbench\Database\Factories\ArticleFactory;

/**
 * The host model the example attaches media to, and the one the test suite
 * attaches media to as well. There is one Article, here, rather than a
 * workbench copy of a fixture that would drift from it.
 *
 * @property string $title
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use HasMedia;

    protected $guarded = [];

    /**
     * What a usage list calls this record, which is the optional hook a host
     * model offers so the list reads in the application's own terms.
     */
    public function mediaUsageLabel(): string
    {
        return $this->title;
    }
}
