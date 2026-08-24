<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_endorsements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mod_id')->constrained('mods')->cascadeOnDelete();

            // Drives the interval leaderboards. Set once, on the first endorsement, and never moved again, so
            // withdrawing and re-adding an endorsement cannot push a mod back into a shorter window.
            $table->timestamp('endorsed_at');

            // Null while the endorsement stands. Set when it is withdrawn; the row itself is kept forever so that
            // endorsed_at survives and a later re-endorsement reuses the original anchor.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // One row per user per mod. Re-endorsing revives the existing row rather than inserting a second one,
            // which is what makes the anchor permanent.
            $table->unique(['user_id', 'mod_id']);

            // Per-mod active counts, for the denormalised mods.endorsements_count recalculation.
            $table->index(['mod_id', 'revoked_at']);

            // Covering index for the windowed leaderboards: range scan the active rows inside the window and read
            // mod_id straight off the index rather than sorting afterwards.
            $table->index(['revoked_at', 'endorsed_at', 'mod_id'], 'mod_endorsements_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_endorsements');
    }
};
