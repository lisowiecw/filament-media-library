<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Schemas;

use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;
use Lisowiecw\MediaLibrary\Lifecycle\UsageEntry;
use Lisowiecw\MediaLibrary\Lifecycle\UsageList;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The Usage list as a person reads it, in the one shape every surface on the
 * management page shows it in: the panel on the asset, the blocked delete, and
 * the force-delete confirmation.
 *
 * The list itself comes from `UsageList` and is not re-queried here. This is
 * only how it is worded, and it is worded in one place so that the list
 * somebody authorises a force delete against is the same list, in the same
 * order, as the one the asset's own page showed them.
 */
final readonly class UsageReadout
{
    /**
     * One line per use: what it is, and the field it sits in where it has one.
     *
     * @param  list<UsageEntry>  $usage
     * @return list<string>
     */
    public static function lines(array $usage): array
    {
        return array_map(
            fn (UsageEntry $entry): string => $entry->field === null
                ? $entry->label
                : $entry->label.' ('.$entry->field.')',
            $usage,
        );
    }

    /**
     * The same lines as a bulleted block, for the modals that have a
     * description rather than a schema to put them in.
     *
     * @param  list<UsageEntry>  $usage
     */
    public static function html(array $usage): HtmlString
    {
        $items = array_map(
            fn (string $line): string => '<li>'.e($line).'</li>',
            self::lines($usage),
        );

        return new HtmlString('<ul class="list-disc ps-4">'.implode('', $items).'</ul>');
    }

    /**
     * The panel both the asset's own page and the force-delete confirmation
     * show: the count, and the list under it where there is one.
     *
     * It is one method rather than two similar schemas because the review a
     * person does before overriding a block has to be the same readout they
     * were shown on the asset, down to the wording.
     *
     * @return list<Text>
     */
    public static function panel(): array
    {
        return [
            Text::make(fn (MediaAsset $record): string => (string) __(
                'media-library::messages.management.usage.count',
                ['count' => self::count($record)],
            )),
            Text::make(fn (MediaAsset $record): HtmlString => self::html(UsageList::for($record)))
                ->visible(fn (MediaAsset $record): bool => self::count($record) > 0),
        ];
    }

    /**
     * The count a table column shows. Read from the attachment rows rather
     * than from the resolved list, since a column paints a page of rows and
     * resolving the labels costs a query each.
     *
     * A count the query already loaded is used as it stands, so a table that
     * asked for it once does not ask again per row.
     */
    public static function count(MediaAsset $asset): int
    {
        $loaded = $asset->getAttribute('attachments_count');

        return $loaded === null ? $asset->attachments()->count() : (int) $loaded;
    }
}
