<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Una fila de section_view por sesión, sección y cuenta: al cambiar de cuenta las visitas van al cliente nuevo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_portal_events', function (Blueprint $table): void {
            $table->unique(['session_id', 'event_type', 'section', 'client_account_id'], 'client_portal_events_section_view_unique');
            $table->dropUnique(['session_id', 'event_type', 'section']);
        });
    }
};
