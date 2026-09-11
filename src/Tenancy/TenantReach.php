<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tenancy;

use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Whether a field context may attach the ids it is asking for.
 *
 * The rule has one home because it is asked twice, once by the picker's
 * validation rule and once by the reconciler that a programmatic attach goes
 * through, and two spellings of it agree only until one of them changes.
 *
 * It answers a bool and never names an id. Reporting which id failed would
 * confirm to a viewer that an asset they cannot reach exists, which is the
 * confidentiality boundary a Tenant is (ADR 7) rather than a filter.
 */
final class TenantReach
{
    /**
     * Every desired id has to exist, and everything arriving has to be inside
     * the tenant boundary.
     *
     * What is already attached is left alone, so an attachment written before
     * tenancy was configured, or before an asset was claimed, degrades to a
     * dimmed tile rather than blocking every save of the host record it sits
     * on: the day a resolver is added is not the day every host form starts
     * failing to save.
     *
     * @param  list<int>  $desired
     * @param  list<int|string>  $attached
     */
    public static function reaches(array $desired, array $attached): bool
    {
        if ($desired === []) {
            return true;
        }

        if (MediaAsset::query()->whereIn('id', $desired)->count() !== count($desired)) {
            return false;
        }

        $arriving = array_values(array_diff($desired, array_map(intval(...), $attached)));

        if ($arriving === [] || ! Tenancy::isEnabled()) {
            return true;
        }

        $reachable = MediaAsset::query()->whereIn('id', $arriving);

        Tenancy::scope($reachable);

        return $reachable->count() === count($arriving);
    }
}
