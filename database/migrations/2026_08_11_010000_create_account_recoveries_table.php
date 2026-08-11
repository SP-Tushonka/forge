<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outstanding recovery links for archived accounts. The email column holds the address the
     * claimant proved control of, which becomes the accounts address once the link is followed.
     */
    public function up(): void
    {
        Schema::create('account_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            // SHA-256 of the emailed token; the plaintext exists only in the email itself.
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'consumed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_recoveries');
    }
};
