<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the asset's BlurHash went pending, which is the only clock that says
 * whether a computation in flight is still anybody's.
 *
 * `updated_at` moves for reasons that have nothing to do with hashing, so it
 * cannot stand in for this. The column belongs to the pending status alone: it
 * is written where the status is taken and cleared wherever the status
 * settles, so it never describes a hash that is ready or failed.
 *
 * Nothing is backfilled, and that is the data decision the operator is spared.
 * A null time on a pending row reads as abandoned rather than fresh, because
 * the rows that carry one are precisely the ones a killed worker stranded, and
 * a null that read as fresh would strand them for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('media_assets', 'blurhash_pending_since')) {
            return;
        }

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->timestamp('blurhash_pending_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn('blurhash_pending_since');
        });
    }
};
