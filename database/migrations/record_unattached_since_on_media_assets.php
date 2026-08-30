<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the asset last stopped being referenced, which is what the unattached
 * grace period counts from.
 *
 * The column is a cache of "when did the last attachment row go", never a
 * second source of truth for whether the asset is in use: the attachment rows
 * stay canonical, and the unattached set is still the assets that have none.
 *
 * Existing rows are backfilled with their own `created_at`, which is the clock
 * the report used until now, so the reported set is unchanged on the day this
 * runs and only diverges going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('media_assets', 'unattached_since')) {
            Schema::table('media_assets', function (Blueprint $table): void {
                $table->timestamp('unattached_since')->nullable()->index();
            });
        }

        DB::table('media_assets')
            ->whereNull('unattached_since')
            ->update(['unattached_since' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn('unattached_since');
        });
    }
};
