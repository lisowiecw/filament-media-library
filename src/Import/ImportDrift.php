<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

/**
 * How an already-present asset's recorded metadata disagrees with what the
 * disk now says about its object.
 *
 * A drift is not an omission: the row was adopted by an earlier run and is
 * still there afterwards, because nothing here repairs anything. It is a thing
 * for a person to look at, named precisely enough that they can.
 */
enum ImportDrift: string
{
    /** The disk reports a different number of bytes than the row records. */
    case Size = 'size';

    /** The mime ladder now lands on a different type than the row records. */
    case MimeType = 'mime-type';

    /** There is no object under the key the row claims, at all: the same fact an omission names, on a row a run already holds. */
    case MissingObject = 'missing-object';

    /** The object is there, but the disk would not say how large it is. */
    case UnreadableMetadata = 'unreadable-metadata';

    public function label(): string
    {
        return match ($this) {
            self::Size => 'the recorded size is not what the disk reports',
            self::MimeType => 'the recorded mime type is not what the disk reports',
            self::MissingObject => 'the object under this key has gone',
            self::UnreadableMetadata => 'the disk would not report its size',
        };
    }
}
