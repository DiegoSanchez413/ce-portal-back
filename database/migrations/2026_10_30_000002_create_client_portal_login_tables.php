<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('login_method', ['phone', 'account']);
            $table->string('mobile', 20);
            $table->string('email', 100)->nullable();
            $table->json('lead_ids');
            $table->char('client_account_id', 36)->nullable();
            $table->char('language', 2)->default('en');
            $table->string('ip', 45)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->enum('verified_channel', ['sms', 'email'])->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('client_portal_otps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('challenge_id')->index();
            $table->enum('channel', ['sms', 'email']);
            $table->char('destination_hash', 64)->index();
            $table->string('code_hash');
            $table->enum('purpose', ['login', 'step_up'])->default('login');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('client_portal_locks', function (Blueprint $table): void {
            $table->id();
            $table->char('destination_hash', 64)->unique();
            $table->timestamp('locked_until');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_locks');
        Schema::dropIfExists('client_portal_otps');
        Schema::dropIfExists('client_portal_challenges');
    }
};
