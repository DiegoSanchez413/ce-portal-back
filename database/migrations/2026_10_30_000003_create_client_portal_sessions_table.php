<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('lead_id')->index();
            $table->char('client_account_id', 36)->index();
            $table->json('eligible_lead_ids');
            $table->string('mobile_at_login', 20);
            $table->enum('login_method', ['phone', 'account']);
            $table->enum('otp_channel', ['sms', 'email']);
            $table->boolean('trusted_device');
            $table->string('device', 50)->nullable();
            $table->string('os', 50)->nullable();
            $table->string('browser', 50)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('last_verified_at');
            $table->timestamp('absolute_expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->enum('end_reason', ['logout', 'inactivity', 'expired', 'status_change', 'phone_change', 'portal_disabled', 'signed_out_by_client'])->nullable();
            $table->index(['lead_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_sessions');
    }
};
