<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_versions', function (Blueprint $table): void {
            $table->timestamp('subscribers_notified_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mod_versions', function (Blueprint $table): void {
            $table->dropIndex(['subscribers_notified_at']);
            $table->dropColumn('subscribers_notified_at');
        });
    }
};
