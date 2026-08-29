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
            'reset' => 'The search changed, so the selection was cleared.',
            'selection_empty' => 'Nothing selected yet.',
        ],
        'actions' => [
            'library' => [
                'label' => 'Add media',
                'submit' => 'Attach',
            ],
        ],
    ],
];
