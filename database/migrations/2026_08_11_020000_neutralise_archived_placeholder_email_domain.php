<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move archived placeholder addresses onto a domain that can never exist
     * 
     * This is mostly a safety precausion against bad actors
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('email_tombstone')
            ->where('email', 'like', '%@unclaimed.account')
            ->update(['email' => DB::raw("REPLACE(email, '@unclaimed.account', '@unclaimed.invalid')")]);
    }

    /**
     * Deliberately irreversible: putting these addresses back on a registrable domain re-opens the takeover above.
     */
    public function down(): void {}
};
