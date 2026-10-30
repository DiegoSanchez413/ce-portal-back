<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedInteger('lead_id')->nullable()->index();
            $table->char('client_account_id', 36)->nullable();
            $table->string('event_type', 40);
            $table->string('section', 30)->nullable();
            $table->string('entity_type', 40)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('created_at');
            $table->timestamp('last_at');
            $table->unique(['session_id', 'event_type', 'section']);
            $table->index('created_at');
        });

        Schema::create('client_portal_requests', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['reactivation', 'services_interest', 'data_change']);
            $table->unsignedInteger('lead_id');
            $table->char('client_account_id', 36);
            $table->unsignedBigInteger('session_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at');
            $table->index(['client_account_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_requests');
        Schema::dropIfExists('client_portal_events');
    }
};
