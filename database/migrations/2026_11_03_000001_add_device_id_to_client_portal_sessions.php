<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Identificador del navegador (cookie cp_device) para agrupar las sesiones por dispositivo en My access.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_portal_sessions', function (Blueprint $table): void {
            $table->uuid('device_id')->nullable()->after('trusted_device');
            $table->index(['lead_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_portal_sessions', function (Blueprint $table): void {
            $table->dropIndex(['lead_id', 'device_id']);
            $table->dropColumn('device_id');
        });
    }
};
