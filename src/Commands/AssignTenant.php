<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tenancy\Claim;

/**
 * Claiming the library a site inherits the day it configures a tenant
 * resolver: everything uploaded before there were tenants belongs to no one,
 * and so is visible to no one.
 *
 * Claiming is one way and allowed once. An asset that already has a tenant is
 * reported and left exactly as it was, because an unowned asset gaining an
 * owner is not the same act as an asset changing owner, and the second one is
 * not something the package will do at all.
 *
 * The run is neither scoped nor policy-checked, like every other command here:
 * an operator on the server is not a request inside a panel, and a claim that
 * could only be made from within the tenant it was claiming for would be
 * impossible to make at all.
 */
class AssignTenant extends Command implements Isolatable
{
    /**
     * How many rows are read at once, so a library that has never been
     * tenanted is walked rather than loaded.
     */
    private const int CHUNK = 200;

    protected $signature = 'media:assign-tenant
        {tenant : The tenant to claim for, as the key the resolver answers with}
        {--asset=* : ULIDs to claim. Every untenanted asset by default}
        {--dry-run : Report what would be claimed and write nothing}';

    protected $description = 'Claim untenanted media assets for one tenant. One way, and never a move between tenants.';

    public function handle(): int
    {
        /** @var string $tenant */
        $tenant = $this->argument('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $claimed = 0;
        $refused = 0;

        foreach ($this->selection() as $asset) {
            if ($asset->tenant_id !== null) {
                $refused++;

                // Named rather than counted: a run that was handed a list of
                // ULIDs was handed them for a reason, and which one already
                // has an owner is the answer the operator came for.
                $this->components->twoColumnDetail(
                    $asset->ulid.' '.$asset->display_name,
                    'already claimed by '.$asset->tenant_id,
                );

                continue;
            }

            $claimed++;

            if ($dryRun) {
                continue;
            }

            Claim::assign($asset, $tenant);
        }

        $this->components->info(sprintf(
            '%d asset(s) %s for %s, %d already claimed and left alone.',
            $claimed,
            $dryRun ? 'would be claimed' : 'claimed',
            $tenant,
            $refused,
        ));

        return self::SUCCESS;
    }

    /**
     * What this run looks at. Named ULIDs are looked at whatever their tenant,
     * so a mistyped claim is answered rather than silently doing nothing;
     * without them the run is the untenanted pile, which is the only set a
     * claim could ever touch.
     *
     * @return iterable<MediaAsset>
     */
    private function selection(): iterable
    {
        /** @var list<string> $ulids */
        $ulids = $this->option('asset');

        $query = MediaAsset::query()->orderBy('id');

        if ($ulids === []) {
            $query->whereNull('tenant_id');
        } else {
            $query->whereIn('ulid', $ulids);
        }

        return $query->lazyById(self::CHUNK);
    }

    /**
     * Two claims for different tenants must not interleave over the same
     * untenanted pile, since both would read it as unclaimed.
     */
    public function isolatableId(): string
    {
        return 'media-library-claim';
    }
}
