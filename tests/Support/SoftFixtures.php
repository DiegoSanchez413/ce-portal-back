<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Tablas mínimas del Soft en la base SQLite de los tests y datos de ejemplo.
final class SoftFixtures
{
    public static function createTables(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('language', 2)->nullable();
        });
        Schema::create('clients', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->unsignedInteger('lead_id');
        });
        Schema::create('client_accounts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('client_id', 36);
            $table->unsignedInteger('program_id');
            $table->char('account', 15);
            $table->unsignedTinyInteger('status');
            $table->timestamp('created_at')->nullable();
        });
    }

    public static function createFileTables(): void
    {
        Schema::create('files_leads', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('lead_id');
            $table->unsignedTinyInteger('document_type_id');
            $table->string('status');
            $table->date('expiration')->nullable();
            $table->string('route')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedInteger('file_account_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('files_accounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->date('expiration')->nullable();
        });
        Schema::create('wpi_file_requests', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('lead_id');
            $table->unsignedInteger('document_type_id');
            $table->string('status');
        });
        Schema::create('file_leads_tracking', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('file_lead_id');
            $table->string('status');
            $table->string('motive')->nullable();
        });
        Schema::create('client_portal_letters', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('lead_id');
            $table->char('client_account_id', 36);
            $table->string('type');
            $table->string('bureau')->nullable();
            $table->string('status')->default('RECEIVED');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('client_portal_letter_files', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('letter_id');
        });
    }

    public static function lead(string $mobile, string $first = 'Luis', string $last = 'Alvarez', ?string $email = 'luis@gmail.com'): int
    {
        return (int) DB::table('leads')->insertGetId([
            'first_name' => $first, 'last_name' => $last, 'mobile' => $mobile, 'email' => $email, 'language' => 'EN',
        ]);
    }

    public static function account(int $leadId, string $number, int $status = 8, int $program = 3, string $createdAt = '2026-01-01 00:00:00'): string
    {
        $clientId = DB::table('clients')->where('lead_id', $leadId)->value('id');
        if ($clientId === null) {
            $clientId = (string) Str::uuid();
            DB::table('clients')->insert(['id' => $clientId, 'lead_id' => $leadId]);
        }

        $id = (string) Str::uuid();
        DB::table('client_accounts')->insert([
            'id' => $id, 'client_id' => $clientId, 'program_id' => $program, 'account' => $number,
            'status' => $status, 'created_at' => $createdAt,
        ]);

        return $id;
    }
}
