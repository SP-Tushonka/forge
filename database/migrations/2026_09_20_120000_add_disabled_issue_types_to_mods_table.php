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
            // The types the owner switched OFF. Null or empty means every type is accepted, so a type added later
            // is available on every mod until its owner opts out.
            $table->jsonb('disabled_issue_types')->nullable()->after('issues_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('mods', function (Blueprint $table): void {
            $table->dropColumn('disabled_issue_types');
        });
    }
};
