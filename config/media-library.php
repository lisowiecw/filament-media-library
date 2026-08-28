<?php

declare(strict_types=1);

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
    */

    'disk' => env('MEDIA_LIBRARY_DISK'),

    'directory' => env('MEDIA_LIBRARY_DIRECTORY', 'media'),

    'visibility' => env('MEDIA_LIBRARY_VISIBILITY', 'private'),

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

    'facet_count_threshold' => (int) env('MEDIA_LIBRARY_FACET_COUNT_THRESHOLD', 50_000),

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
