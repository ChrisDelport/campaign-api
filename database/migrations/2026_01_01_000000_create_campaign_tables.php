<?php

/**
 * Domain schema for the Campaign Manager, in one atomic migration so the
 * four tables can never drift apart (e.g. a partial deploy creating
 * campaign_data without its unique constraint).
 *
 * Key decisions (Chris Delport):
 *
 * - campaign_data.unique(campaign_id, user_id) is the backbone of duplicate
 *   detection. Enforcing it at the DB level means no amount of concurrent
 *   imports can create a true duplicate - the application layer (job) only
 *   decides how to *handle* collisions, it doesn't police them alone.
 * - custom_fields is JSON, deliberately. Arbitrary per-user keys with no
 *   migrations; still queryable via MySQL JSON functions. If a specific key
 *   becomes hot, add a generated column + index without touching this app.
 * - duplicate_logs is a separate append-only table rather than a flag on
 *   campaign_data: re-imports overwrite the data row, and we don't want the
 *   overwrite to erase the evidence that a duplicate happened.
 * - All FKs cascade on delete: dropping a campaign removes its data and
 *   duplicate history together - they're meaningless without it.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_data', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('user_id');
            $table->string('video_url');
            $table->json('custom_fields')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
        });

        Schema::create('duplicate_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('user_id');
            $table->json('custom_fields')->nullable();
            // Idempotency key so retried jobs never log the same duplicate twice.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'user_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_logs');
        Schema::dropIfExists('campaign_data');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('clients');
    }
};
