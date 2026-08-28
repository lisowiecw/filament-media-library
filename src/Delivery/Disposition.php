<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Delivery;

use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Whether the Delivery route serves an asset's content for rendering in place
 * or for saving to disk.
 *
 * Rendering in place is earned rather than assumed: the route serves from the
 * panel's own origin, so an asset only renders there when it is not Active
 * content and its type came from a stored header or a content sniff. An
 * extension-derived type asserts that a filename ended in `.jpg`, which is no
 * basis for running its bytes in the panel's origin. See ADR-0004.
 */
enum Disposition: string
{
    case Inline = 'inline';
    case Attachment = 'attachment';

    /**
     * Asking for a download always wins, since saving a file is never less
     * safe than rendering it. The opposite is not a lever the request holds at
     * all: rendering in place is earned from the asset, so `?download=0` says
     * nothing anywhere, for active content or for anything else.
     */
    public static function for(MediaAsset $asset, bool $download): self
    {
        if ($download || $asset->isActiveContent()) {
            return self::Attachment;
        }

        return in_array($asset->mime_source, [MimeSource::Header, MimeSource::Sniffed], strict: true)
            ? self::Inline
            : self::Attachment;
    }
}
