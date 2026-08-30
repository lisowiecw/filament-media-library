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
        'full' => 'This field holds :count at most, so the rest were left out.',
        'single_drop' => 'This field holds one file, so the first of the :count dropped was used.',
        'drop_hint' => 'Drop a file here to upload and attach it.',
        'move_up' => 'Move :name earlier',
        'move_down' => 'Move :name later',
        'detach' => 'Detach :name',
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
            'play' => 'Video',
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

    'management' => [
        'model' => 'media asset',
        'model_plural' => 'media assets',
        'navigation' => 'Media library',

        'sections' => [
            'asset' => 'Asset',
            'storage' => 'Storage',
            'storage_hint' => 'Where the bytes live. Copyable, and not editable: a published URL is built from these.',
            'usage' => 'Usage',
        ],

        'fields' => [
            'display_name' => 'Name',
            'alt' => 'Alt text',
            'mime_type' => 'Type',
            'mime_source' => 'Type resolved by',
            'size' => 'Size',
            'visibility' => 'Visibility',
            'source' => 'Source',
            'import_source' => 'Imported from',
            'disk' => 'Disk',
            'object_key' => 'Object key',
            'usage' => 'Uses',
            'created_at' => 'Added',
            'reviewed' => 'I have reviewed the list above and still want to delete this asset.',
            'files' => 'Files',
        ],

        'enums' => [
            'upload' => 'Upload',
            'import' => 'Import',
            'header' => 'Declared header',
            'sniffed' => 'Sniffed content',
            'extension' => 'File extension',
            'unknown' => 'Unknown',
        ],

        'filters' => [
            'unattached' => 'Attachment',
            'unattached_any' => 'Any',
            'unattached_now' => 'Not attached anywhere',
            'unattached_past_grace' => 'Unattached for more than :days day(s)',
        ],

        'actions' => [
            'rename' => 'Rename',
            'download' => 'Download',
            'delete' => 'Delete',
            'force_delete' => 'Force delete',
            'restore' => 'Restore',
            'upload' => 'Upload',
            'delete_unattached' => 'Delete unattached (:days+ days)',
            'health' => 'Derivative health',
            'regenerate' => 'Regenerate',
        ],

        'modals' => [
            'force_delete' => 'Force delete',
            'delete_unattached' => 'Only the selected assets that nothing has referenced for more than :days day(s) will be deleted. Everything else is left alone.',
            'health' => 'Derivative health',
        ],

        'health' => [
            'summary' => ':failed failed, :missing missing and :stale stale rendering(s). Regenerating queues a batch of them.',
        ],

        'usage' => [
            'count' => 'Used in :count place(s).',
        ],

        'notifications' => [
            'renamed' => 'Renamed. Nothing in storage changed.',
            'deleted' => 'Deleted.',
            'delete_blocked' => 'This asset is still in use and was not deleted.',
            'force_deleted' => 'Deleted permanently.',
            'restored' => 'Restored.',
            'uploaded' => 'Uploaded :count file(s).',
            'bulk_deleted' => 'Deleted :count asset(s).',
            'bulk_restored' => 'Restored :count asset(s).',
            'regenerating' => 'Queued :count rendering(s).',
            'regenerate_remaining' => ':count more are waiting. Run media:regenerate-derivatives to finish them.',
            'skipped_in_use' => 'Skipped :count still in use: :names.',
            'skipped_forbidden' => 'Skipped :count you may not act on: :names.',
            'skipped_attached' => 'Skipped :count not unattached for :days day(s) or more: :names.',
            'and_more' => 'and :count more',
        ],
    ],
];
