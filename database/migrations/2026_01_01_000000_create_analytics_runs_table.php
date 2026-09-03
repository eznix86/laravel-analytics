<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analytics_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('run_id')->index();
            $table->string('model')->index();
            $table->string('materialization');
            $table->string('status');
            $table->unsignedBigInteger('rows')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->text('error')->nullable();
            $table->timestamp('synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_runs');
    }
};
