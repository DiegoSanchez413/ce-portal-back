<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('tiene cargado el esquema del Soft con sus procedimientos', function (): void {
    $database = DB::getDatabaseName();

    expect(DB::selectOne('select count(*) as total from information_schema.tables where table_schema = ? and table_name = ?', [$database, 'leads'])->total)->toBe(1)
        ->and(DB::selectOne('select count(*) as total from information_schema.routines where routine_schema = ? and routine_name = ?', [$database, 'get_account_payments'])->total)->toBe(1);
});
