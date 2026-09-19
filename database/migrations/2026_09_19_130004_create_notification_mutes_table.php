<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('mutable');
            $table->timestamps();

            $table->unique(['user_id', 'mutable_type', 'mutable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_mutes');
    }
};
