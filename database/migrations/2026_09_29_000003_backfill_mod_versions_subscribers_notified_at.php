<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mod_versions')
            ->whereNull('subscribers_notified_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->update(['subscribers_notified_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately empty: nulling the column would make the next sweep announce every existing version.
    }
};
