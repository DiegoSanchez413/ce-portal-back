<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('lead_id');
            $table->char('client_account_id', 36)->index();
            $table->unsignedBigInteger('card_id');
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['processing', 'approved', 'declined', 'error']);
            $table->string('transaction_id', 64)->nullable();
            $table->string('message', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_payment_attempts');
    }
};
