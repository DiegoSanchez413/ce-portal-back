<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('lead_id')->unique();
            $table->boolean('portal_enabled')->default(true);
            $table->unsignedInteger('disabled_by')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 500)->nullable();
            $table->unsignedInteger('enabled_by')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->char('portal_language', 2)->nullable();
            $table->unsignedInteger('invitation_sent_by')->nullable();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_settings');
    }
};
