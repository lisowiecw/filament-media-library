<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Schemas;

use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;
use Lisowiecw\MediaLibrary\Filament\Actions\RevokeExternalReference;
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
        return array_map(self::line(...), $usage);
    }

    /**
     * One use as a person reads it: what it is, and the field it sits in where
     * it has one. An External reference has no field context, so it reads as
     * its label alone.
     */
    private static function line(UsageEntry $entry): string
    {
        return $entry->field === null
            ? $entry->label
            : $entry->label.' ('.$entry->field.')';
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
     * This is the readout for a modal, where the list is something a person is
     * in the middle of reviewing rather than acting on. The asset's own page
     * shows `revocablePanel()` instead, which is the same count, the same
     * lines from `line()` and the same order, with a revoke offered beside
     * each External reference. The wording lives in one place precisely so
     * that the list somebody authorises a force delete against reads as the
     * one the asset's page showed them.
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
     * The same panel, with each External reference revocable where it stands.
     *
     * This is the asset's own page rather than a modal, which is why the rows
     * carry actions at all: it is the one surface where withdrawing a
     * reference is an act somebody can take rather than a review they are in
     * the middle of. Host rows are listed and nothing more, since detaching
     * belongs on the host record.
     *
     * @return list<Text|Group>
     */
    public static function revocablePanel(): array
    {
        return [
            Text::make(fn (MediaAsset $record): string => (string) __(
                'media-library::messages.management.usage.count',
                ['count' => self::count($record)],
            )),
            Group::make()->schema(fn (MediaAsset $record): array => self::rows($record)),
        ];
    }

    /**
     * @return list<Flex>
     */
    private static function rows(MediaAsset $record): array
    {
        return array_map(
            fn (UsageEntry $entry): Flex => Flex::make([
                Text::make(self::line($entry))->grow(),
                Actions::make([RevokeExternalReference::make($entry)])
                    ->key('usage-'.$entry->attachmentId)
                    ->grow(false),
            ]),
            UsageList::for($record),
        );
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
