<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nothing ever read the city coordinates, so they were personal data held without a purpose.
     */
    public function up(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 8)->nullable()->after('city_name');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
        });
    }
};
