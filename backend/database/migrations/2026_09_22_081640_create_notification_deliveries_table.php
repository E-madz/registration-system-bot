<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);

            $table->enum('status', ['pending', 'sent', 'failed', 'skipped_banned', 'skipped_blocked'])
                ->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(['notification_id', 'bot_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
