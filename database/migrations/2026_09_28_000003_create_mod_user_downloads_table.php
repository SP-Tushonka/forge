<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_user_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mod_id')->constrained('mods')->cascadeOnDelete();

            // Nulled rather than cascaded: the record, and the version string it is compared by, outlive a deleted version.
            $table->foreignId('mod_version_id')->nullable()->constrained('mod_versions')->nullOnDelete();
            $table->string('version');
            $table->timestamp('downloaded_at');
            $table->timestamps();

            $table->unique(['user_id', 'mod_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_user_downloads');
    }
};
