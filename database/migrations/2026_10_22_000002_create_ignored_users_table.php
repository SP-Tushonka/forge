<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ignored_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ignored_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('include_coauthored')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'ignored_user_id']);
            $table->index('ignored_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ignored_users');
    }
};
