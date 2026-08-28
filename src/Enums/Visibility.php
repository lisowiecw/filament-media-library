<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Enums;

/**
 * Whether an asset's content is addressable without a session.
 *
 * Public is the one placement the plugin is not in the request path for, which
 * is why the rules that lean on the Delivery route ask this rather than
 * comparing a string themselves.
 */
enum Visibility: string
{
    case Public = 'public';
    case Private = 'private';

    public function isPublic(): bool
    {
        return $this === self::Public;
    }
}
