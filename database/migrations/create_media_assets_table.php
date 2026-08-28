<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();

            // Naming. The display name is presentation metadata and never an
            // identifier; the client filename is provenance and never changes.
            $table->string('display_name');
            $table->string('original_client_filename')->nullable();
            $table->string('extension')->nullable();
            $table->text('alt')->nullable();

            // Type. The mime source states how far the mime type can be trusted.
            $table->string('mime_type')->nullable();
            $table->enum('mime_source', array_column(MimeSource::cases(), 'value'));
            $table->unsignedBigInteger('size')->nullable();

            // Storage. Every storage operation resolves from the row rather
            // than from convention.
            $table->string('disk');
            $table->string('object_key');
            $table->string('visibility');

            // Provenance.
            $table->enum('source', array_column(MediaSource::cases(), 'value'));
            $table->string('import_source')->nullable();
            $table->string('uploaded_by')->nullable()->index();

            $table->string('tenant_id')->nullable();

            $table->string('blurhash')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['disk', 'object_key']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
