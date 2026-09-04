<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;

/**
 * Where the asset's BlurHash is in its lifecycle, alongside the hash it
 * already had.
 *
 * The column is added wherever the engine puts it, never placed against
 * `blurhash`, because a migration that names another migration's column has an
 * ordering dependency these filenames cannot express (ADR 20).
 *
 * The column is nullable and stays nullable, because null is a state rather
 * than a gap: it means nobody has ever asked for this asset's hash, which a
 * recorded failure is not. That is also what makes this migration free of any
 * data decision for the operator.
 *
 * Existing rows that already carry a hash are backfilled as ready, since a
 * hash on the row is exactly what ready says. Rows without one are left null,
 * so they are picked up as never asked rather than reported as failures of a
 * computation that never ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('media_assets', 'blurhash_status')) {
            Schema::table('media_assets', function (Blueprint $table): void {
                $table->string('blurhash_status')->nullable();
            });
        }

        DB::table('media_assets')
            ->whereNull('blurhash_status')
            ->whereNotNull('blurhash')
            ->update(['blurhash_status' => BlurHashStatus::Ready->value]);
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn('blurhash_status');
        });
    }
};
