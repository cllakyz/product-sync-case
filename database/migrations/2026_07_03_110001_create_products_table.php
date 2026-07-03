<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            $table->string('provider_type', 32);
            $table->string('external_id', 64);

            $table->string('name');
            // Non-negative enforced at the application layer (portable across MySQL/SQLite).
            $table->decimal('price', 12, 2);
            // unsignedInteger gives a DB-level >= 0 guarantee for stock.
            $table->unsignedInteger('stock')->nullable();
            $table->text('description')->nullable();

            // SHA-256 hex fingerprint of the business fields (SPEC §5).
            $table->char('data_hash', 64);
            // Normalized-source snapshot for audit/debug; never exposed via the API.
            $table->json('raw_payload')->nullable();

            $table->timestamp('last_synced_at');
            $table->softDeletes();
            $table->timestamps();

            // A product is unique per (provider, external id): the basis for
            // idempotent upserts — a duplicate insert is impossible.
            $table->unique(['provider_type', 'external_id']);

            // Active-product listings and stale/deleted detection per provider.
            $table->index(['provider_type', 'deleted_at']);
            // Speeds up hash-comparison lookups.
            $table->index('data_hash');
            // Finds records not seen in the current run (candidates for deletion).
            $table->index('last_synced_at');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
