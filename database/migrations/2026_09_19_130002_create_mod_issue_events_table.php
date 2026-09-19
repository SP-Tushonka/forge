<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_issue_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mod_issue_id')->constrained('mod_issues')->cascadeOnDelete();

            // Null for automatic changes, such as Needs info moving back to New when the reporter replies.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 30);
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->timestamps();

            $table->index(['mod_issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_issue_events');
    }
};
