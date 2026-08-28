<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_attachments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();

            // The host is nullable because an External reference is an
            // attachment with no host: it blocks deletion like any other use,
            // but belongs to no field context.
            $table->string('host_type')->nullable();
            $table->string('host_id')->nullable();
            $table->string('field_name')->nullable();

            // What an External reference names itself and reads as.
            $table->string('reference_identifier')->nullable();
            $table->string('reference_label')->nullable();

            $table->unsignedInteger('order')->default(0);

            $table->timestamps();

            $table->index(['host_type', 'host_id', 'field_name', 'order']);

            // One asset appears at most once in one host and field context.
            // External references are unconstrained by it, since their host
            // columns are null and a null never collides.
            $table->unique(['media_asset_id', 'host_type', 'host_id', 'field_name'], 'media_attachments_context_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
