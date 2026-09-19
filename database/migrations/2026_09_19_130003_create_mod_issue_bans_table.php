<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_issue_bans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mod_id')->constrained('mods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();

            // Visible to the mod's managers and staff only.
            $table->text('reason')->nullable();

            // Null means permanent. Expiry is checked on read, so nothing has to sweep old rows.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['mod_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_issue_bans');
    }
};
