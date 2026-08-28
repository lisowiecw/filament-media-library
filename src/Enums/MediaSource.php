<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Enums;

/**
 * The origin of a Media Asset. Source records origin alone; it never encodes
 * whether the plugin wrote the bytes or adopted existing ones.
 */
enum MediaSource: string
{
    case Upload = 'upload';
    case Import = 'import';
}
