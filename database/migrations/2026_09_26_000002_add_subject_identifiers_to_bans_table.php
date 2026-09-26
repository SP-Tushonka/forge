<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The banned account's emails and IPs, copied onto the ban so it stays enforceable after the 12-month prune or the
     * account's deletion removes them elsewhere. Cleared once the ban is lifted or expires.
     */
    public function up(): void
    {
        Schema::table('bans', function (Blueprint $table): void {
            $table->json('subject_emails')->nullable()->after('ip');
            $table->json('subject_ips')->nullable()->after('subject_emails');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bans', function (Blueprint $table): void {
            $table->dropColumn(['subject_emails', 'subject_ips']);
        });
    }
};
