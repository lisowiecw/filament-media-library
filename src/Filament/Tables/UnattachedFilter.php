<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Tables;

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Lifecycle\GracePeriod;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Finding the cleanup candidates, at the two strengths the question actually
 * has.
 *
 * "Nothing references it" is a browsing question and answers immediately.
 * "Nothing has referenced it for a while" is the one cleanup is allowed to act
 * on, and it is the same predicate the report-only command selects on, read
 * from the same configured grace period. That is what lets the restricted bulk
 * delete be trusted: it removes exactly what the report would have listed.
 *
 * Neither reading is proof that nothing uses the asset. A URL can live in a
 * sent email or an export the plugin cannot see, which is why the grace period
 * exists at all and why nothing here deletes on its own.
 */
final readonly class UnattachedFilter
{
    public const string ANY = 'any';

    public const string UNATTACHED = 'unattached';

    public const string PAST_GRACE = 'past_grace';

    public static function make(): Filter
    {
        return Filter::make('unattached')
            ->schema([
                Select::make('state')
                    ->label(__('media-library::messages.management.filters.unattached'))
                    ->options(self::options())
                    ->default(self::ANY),
            ])
            ->query(function (Builder $query, array $data): void {
                /** @var Builder<MediaAsset> $query */
                /** @var string $state */
                $state = $data['state'] ?? self::ANY;

                match ($state) {
                    self::UNATTACHED => $query->whereDoesntHave('attachments'),
                    self::PAST_GRACE => $query->unattachedFor(GracePeriod::days()),
                    default => null,
                };
            })
            ->indicateUsing(function (array $data): ?string {
                /** @var string $state */
                $state = $data['state'] ?? self::ANY;

                return $state === self::ANY ? null : static::options()[$state];
            });
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::ANY => __('media-library::messages.management.filters.unattached_any'),
            self::UNATTACHED => __('media-library::messages.management.filters.unattached_now'),
            self::PAST_GRACE => __('media-library::messages.management.filters.unattached_past_grace', [
                'days' => GracePeriod::days(),
            ]),
        ];
    }

    /**
     * The assets a restricted sweep is allowed to touch, out of a selection.
     *
     * Spelled as a query against the same scope rather than as a filter over
     * the loaded rows, so the eligibility a bulk delete enforces cannot drift
     * from the eligibility the filter showed.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function eligible(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<int> $eligible */
        $eligible = MediaAsset::query()
            ->unattachedFor(GracePeriod::days())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        return $eligible;
    }
}
