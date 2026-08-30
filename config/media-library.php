<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Library\FacetSidebar;

return [

    /*
    |--------------------------------------------------------------------------
    | Default placement
    |--------------------------------------------------------------------------
    |
    | The disk, directory prefix and visibility a Media Picker applies to new
    | uploads unless the field overrides them. A null disk means the
    | application's own default filesystem disk, resolved through the Storage
    | facade at use time; the package never names a driver of its own.
    |
    | A bucket is a disk. An application that keeps public and private media in
    | two buckets names both disks below and says nothing more: a field that
    | declares only its visibility lands in the matching one. That pair is the
    | supported shape for a two-bucket deployment. Set neither key and disk
    | resolution is exactly what it was before the pair existed; set one and the
    | other visibility still falls through to the disk below, which is what a
    | half-migrated deployment gets. A field that names a disk of its own always
    | wins.
    |
    */

    'disk' => env('MEDIA_LIBRARY_DISK'),

    'public_disk' => env('MEDIA_LIBRARY_PUBLIC_DISK'),

    'private_disk' => env('MEDIA_LIBRARY_PRIVATE_DISK'),

    'directory' => env('MEDIA_LIBRARY_DIRECTORY', 'media'),

    'visibility' => env('MEDIA_LIBRARY_VISIBILITY', 'private'),

    /*
    |--------------------------------------------------------------------------
    | Disk and visibility invariant
    |--------------------------------------------------------------------------
    |
    | A placement whose disk cannot deliver its visibility is refused when the
    | placement resolves: a public placement on a disk configured with no `url`
    | has no address to hand a browser, and a private placement on a disk
    | declared public, as the `public_disk` above or on the disk itself, is
    | authorized on the way in while its bytes stay fetchable by anyone. The check reads this configuration only; nothing asks
    | the provider, which on R2 would answer no ACL call anyway.
    |
    | Turn it off if the application deliberately serves a public disk through
    | its own origin, which is a deployment the package cannot see.
    |
    */

    'enforce_disk_visibility' => env('MEDIA_LIBRARY_ENFORCE_DISK_VISIBILITY', true),

    /*
    |--------------------------------------------------------------------------
    | Ingest floor
    |--------------------------------------------------------------------------
    |
    | The maximum upload size in kilobytes, which a field may override in either
    | direction, and the type denylist, which a field may only narrow. Blocked
    | types are matched on both extension and resolved mime.
    |
    */

    'max_upload_size' => (int) env('MEDIA_LIBRARY_MAX_UPLOAD_SIZE', 12 * 1024),

    'blocked_types' => [
        'php',
        'phar',
        'phtml',
        'htaccess',
        'application/x-httpd-php',
        'application/x-msdownload',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | How long a signed URL for an original stays valid, and the bucket that
    | derivative URL expiry is quantized to so a thumbnail's URL stays
    | byte-stable long enough for a browser to cache it.
    |
    */

    'signed_url_ttl' => (int) env('MEDIA_LIBRARY_SIGNED_URL_TTL', 5 * 60),

    'derivative_url_bucket' => (int) env('MEDIA_LIBRARY_DERIVATIVE_URL_BUCKET', 6 * 60 * 60),

    /*
    |--------------------------------------------------------------------------
    | Derivatives
    |--------------------------------------------------------------------------
    |
    | The variant set is fixed by the package. Only the dimensions and quality
    | are configurable. An original small enough on both counts below gets no
    | derivatives at all.
    |
    */

    'derivatives' => [

        'prefix' => env('MEDIA_LIBRARY_DERIVATIVES_PREFIX', 'media-derivatives'),

        'quality' => (int) env('MEDIA_LIBRARY_DERIVATIVE_QUALITY', 82),

        'variants' => [
            'thumb' => ['edge' => 400],
            'preview' => ['edge' => 1600],
        ],

        'small_original' => [
            'bytes' => 32 * 1024,
            'edge' => 800,
        ],

        'lazy_dispatch' => [
            'per_minute' => 60,
            'per_request' => 5,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Library grid
    |--------------------------------------------------------------------------
    |
    | Above the facet threshold, measured on the field-scoped set before search
    | and facets are applied, counts are dropped entirely and the facets stay
    | listed and clickable without numbers.
    |
    */

    'search_debounce' => (int) env('MEDIA_LIBRARY_SEARCH_DEBOUNCE', 400),

    'facet_count_threshold' => (int) env('MEDIA_LIBRARY_FACET_COUNT_THRESHOLD', FacetSidebar::DEFAULT_THRESHOLD),

    /*
    |--------------------------------------------------------------------------
    | Unattached assets
    |--------------------------------------------------------------------------
    |
    | How long an asset must have been unattached before the report-only sweep
    | surfaces it. Nothing is ever deleted automatically.
    |
    */

    'unattached_grace_days' => (int) env('MEDIA_LIBRARY_UNATTACHED_GRACE_DAYS', 30),

];
