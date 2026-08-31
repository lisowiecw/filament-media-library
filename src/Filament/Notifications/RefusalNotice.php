<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Notifications;

use Lisowiecw\MediaLibrary\Exceptions\IngestRefused;

/**
 * The one place a refusal turns into notification text.
 *
 * A refusal message is prose, and some of it names an element in angle
 * brackets, because that is how a person reads an element. Filament runs both
 * a notification's title and its body through an HTML sanitizer, which parses
 * "<style>" as a tag and swallows it along with the rest of the sentence, so
 * the refusal has to arrive already escaped. That escaping lives here rather
 * than at each surface, so that a new surface cannot forget it.
 */
final readonly class RefusalNotice
{
    /**
     * What the person reads, with every character of the refusal intact.
     */
    public static function text(IngestRefused ...$refusals): string
    {
        return implode(' ', array_map(
            static fn (IngestRefused $refusal): string => e($refusal->getMessage()),
            $refusals,
        ));
    }
}
