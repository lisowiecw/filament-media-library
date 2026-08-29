<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_derivatives', function (Blueprint $table): void {
            $table->id();

            // A derivative is a child row rather than a Media Asset: it dies
            // with its parent rather than outliving it as something to name.
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();

            $table->enum('variant', array_column(DerivativeVariant::cases(), 'value'));

            // Placement follows the parent, including visibility, so a private
            // thumbnail is never public. It is stored rather than read back
            // through the relation because a derivative is removable by
            // prefix, which needs the disk without the parent in hand.
            $table->string('disk');
            $table->string('object_key');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();

            $table->enum('status', array_column(DerivativeStatus::cases(), 'value'));
            $table->text('failure_reason')->nullable();

            // Null means unknown provenance rather than stale, so upgrading
            // the plugin marks nothing stale.
            $table->string('config_digest')->nullable();

            $table->timestamps();

            // One row per asset and variant: regeneration overwrites in place
            // rather than accumulating renderings of the same size.
            $table->unique(['media_asset_id', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_derivatives');
    }
};
