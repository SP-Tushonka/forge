<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The banned account's device hashes, kept with the ban for the same reason and lifetime as subject_ips.
     */
    public function up(): void
    {
        Schema::table('bans', function (Blueprint $table): void {
            $table->json('subject_devices')->nullable()->after('subject_ips');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bans', function (Blueprint $table): void {
            $table->dropColumn('subject_devices');
        });
    }
};
