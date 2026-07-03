<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table): void {
            $table->id();

            $table->string('provider_type', 32);
            $table->string('trigger_type', 16);   // scheduled | manual
            $table->string('status', 16);         // pending|running|completed|failed|partial|skipped

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedInteger('products_added')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_deleted')->default(0);
            $table->unsignedInteger('products_unchanged')->default(0);
            $table->unsignedInteger('products_failed')->default(0);
            $table->unsignedInteger('api_requests')->default(0);

            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['provider_type', 'status']);
            $table->index('created_at');
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
