<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dead_letter_jobs', function (Blueprint $table): void {
            $table->id();

            $table->string('job_type', 64);
            $table->string('provider_type', 32)->nullable();

            // Minimal input needed to reconstruct the job (provider + options).
            // Never contains credentials/headers (SPEC §4.3, §11).
            $table->json('payload');
            $table->text('exception');

            $table->unsignedInteger('retry_count')->default(0);
            $table->string('status', 16); // pending|retrying|resolved|discarded

            $table->timestamp('failed_at');
            $table->timestamp('last_retried_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['provider_type', 'status']);
            $table->index('failed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letter_jobs');
    }
};
