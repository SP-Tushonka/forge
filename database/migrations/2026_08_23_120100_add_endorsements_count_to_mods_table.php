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
            $table->unsignedBigInteger('endorsements_count')->default(0)->after('favourites_count');
            $table->index(['disabled', 'endorsements_count'], 'mods_filter_endorsements_index');
        });
    }

    public function down(): void
    {
        Schema::table('mods', function (Blueprint $table): void {
            $table->dropIndex('mods_filter_endorsements_index');
            $table->dropColumn('endorsements_count');
        });
    }
};
