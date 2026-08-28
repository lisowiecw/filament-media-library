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
            'upload' => 'Upload',
        ],
        'actions' => [
            'upload' => [
                'label' => 'Add media',
                'submit' => 'Attach',
            ],
        ],
    ],
];
