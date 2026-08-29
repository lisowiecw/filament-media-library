<?php

declare(strict_types=1);

return [
    'plugin' => [
        'name' => 'Media Library',
    ],

    'visibility' => [
        'public' => 'public',
        'private' => 'private',
    ],

    'picker' => [
        'empty' => 'Nothing attached yet.',
        'placement' => 'Uploads land on the :disk disk under :directory, and are :visibility.',
        'unavailable' => 'The selection for :field is no longer available.',
        'tabs' => [
            'library' => 'Library',
            'upload' => 'Upload',
        ],

        'grid' => [
            'search' => 'Search the library',
            'search_placeholder' => 'Name, filename, alt text, uploader or key',
            'empty' => 'Nothing in the library matches.',
            'load_more' => 'Load more',
            'end' => '{0} Nothing in the library.|{1} One asset in the library.|[2,*] :count assets in the library.',
            'reset' => 'The filter changed, so the selection was cleared.',
            'facets' => 'Narrow the library',
            'sort' => 'Sort by',
            'selection_empty' => 'Nothing selected yet.',
        ],

        'facets' => [
            'type' => 'Type',
            'visibility' => 'Visibility',
            'usage' => 'Usage',
            'uploader' => 'Uploaded by',
            'uploaded' => 'Uploaded',
        ],

        'facet_options' => [
            'type' => [
                'image/*' => 'Images',
                'video/*' => 'Video',
                'audio/*' => 'Audio',
                'text/*' => 'Text',
                'application/*' => 'Documents',
            ],
            'visibility' => [
                'public' => 'Public',
                'private' => 'Private',
            ],
            'usage' => [
                'attached' => 'Attached somewhere',
                'unattached' => 'Not attached anywhere',
            ],
            'uploaded' => [
                'today' => 'Today',
                'week' => 'Past week',
                'month' => 'Past month',
                'year' => 'Past year',
            ],
            'uploader' => [
                'none' => 'Nobody signed in',
            ],
        ],

        'sort' => [
            'newest' => 'Newest',
            'oldest' => 'Oldest',
            'name' => 'Name',
            'most_used' => 'Most used',
        ],
        'actions' => [
            'library' => [
                'label' => 'Add media',
                'submit' => 'Attach',
            ],
        ],
    ],
];
