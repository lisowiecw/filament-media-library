<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workbench's host table, and the suite's: `TestCase` runs this file rather
 * than restating the columns, so the example and the tests attach media to the
 * same Article.
 *
 * It carries no media column of its own, which is the point. A picker field is
 * virtual, so the only thing an article stores about its images is nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
