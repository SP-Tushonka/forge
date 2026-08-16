<?php

declare(strict_types=1);

use App\Support\SemiJoinHint;
use Illuminate\Support\Facades\DB;

it('selects the constant 1 so it stays a valid EXISTS subquery', function (): void {
    $sql = (string) SemiJoinHint::firstMatch()->getValue(DB::connection()->getQueryGrammar());

    expect($sql)->toEndWith('1');
});

it('only emits the MySQL hint when the connection is MySQL', function (): void {
    $sql = (string) SemiJoinHint::firstMatch()->getValue(DB::connection()->getQueryGrammar());

    expect($sql)->when(
        DB::connection()->getDriverName() === 'mysql',
        fn ($sql) => $sql->toBe('/*+ SEMIJOIN(FIRSTMATCH) */ 1'),
        fn ($sql) => $sql->toBe('1'),
    );
});
