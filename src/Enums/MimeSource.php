<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Enums;

/**
 * Which rung of the resolution ladder produced a Media Asset's mime type.
 */
enum MimeSource: string
{
    case Header = 'header';
    case Sniffed = 'sniffed';
    case Extension = 'extension';
    case Unknown = 'unknown';
}
