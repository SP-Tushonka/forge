<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mod_id')->constrained('mods')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'mod_id']);
            $table->index('mod_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_subscriptions');
    }
};
