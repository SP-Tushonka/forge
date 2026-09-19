<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mod_id')->constrained('mods')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->string('title');
            $table->text('body');
            $table->foreignId('affected_mod_version_id')->nullable()->constrained('mod_versions')->nullOnDelete();
            $table->string('fixed_version', 50)->nullable();
            $table->timestamp('fix_notified_at')->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('mod_issues')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('last_activity_at')->useCurrent();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mod_id', 'number']);
            $table->index(['mod_id', 'status', 'last_activity_at']);

            // The fix-release sweep: completed issues that have not had their release notice yet.
            $table->index(['status', 'fix_notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_issues');
    }
};
