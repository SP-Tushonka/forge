<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mods', function (Blueprint $table): void {
            $table->boolean('issues_enabled')->default(false)->after('lists_disabled');

            // Per-mod issue counter. Only ever incremented, so a deleted issue's number is never handed out again.
            $table->unsignedInteger('last_issue_number')->default(0)->after('issues_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('mods', function (Blueprint $table): void {
            $table->dropColumn(['issues_enabled', 'last_issue_number']);
        });
    }
};
