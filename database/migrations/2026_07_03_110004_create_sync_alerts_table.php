<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_alerts', function (Blueprint $table): void {
            $table->id();

            $table->string('alert_type', 48);
            $table->string('severity', 16);
            $table->string('provider_type', 32)->nullable();
            $table->json('details')->nullable();

            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['alert_type', 'provider_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_alerts');
    }
};
