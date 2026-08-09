<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the data model for the mod claim feature, fairlysimple structure
     */
    public function up(): void
    {
        Schema::create('mod_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mod_id')->constrained('mods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token');
            $table->string('status')->default('pending');
            $table->string('verified_via')->nullable();
            // Which of the mod source links actually carried the token, so an approval can be audited when a mod
            // lists several repositories
            $table->string('verified_url')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // One claim row per user per mod, reinitiating updates the existing row rather than inserting.
            $table->unique(['mod_id', 'user_id']);
            // Moderation queue: pending + escalated lookups ordered by recency.
            $table->index(['status', 'escalated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mod_claims');
    }
};
