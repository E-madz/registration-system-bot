<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->text('message');
            $table->string('button_label', 40)->nullable();
            $table->foreignId('button_program_id')->nullable()
                ->constrained('programs')->nullOnDelete();

            $table->enum('source', [
                'manual', 'auto_ban', 'auto_unban',
                'auto_program_canceled', 'auto_program_published',
            ])->default('manual')->index();

            $table->enum('audience_type', [
                'program_registrants', 'all_users', 'inactive_users', 'single_user',
            ]);
            $table->foreignId('audience_program_id')->nullable()
                ->constrained('programs')->nullOnDelete();
            $table->foreignId('audience_bot_user_id')->nullable()
                ->constrained('bot_users')->nullOnDelete();
            $table->string('audience_platform', 20)->nullable();
            $table->unsignedInteger('inactive_days')->nullable();

            $table->enum('status', ['pending', 'completed'])->default('pending')->index();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_banned_count')->default(0);
            $table->unsignedInteger('skipped_blocked_count')->default(0);
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
