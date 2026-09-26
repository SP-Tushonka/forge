<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign-in only needs the provider's profile, and nothing ever called the provider's API with these, so they were
     * live credentials to members' Discord accounts held for no purpose.
     */
    public function up(): void
    {
        Schema::table('oauth_connections', function (Blueprint $table): void {
            $table->dropColumn(['token', 'refresh_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_connections', function (Blueprint $table): void {
            $table->string('token')->default('')->after('provider_id');
            $table->string('refresh_token')->default('')->after('token');
        });
    }
};
