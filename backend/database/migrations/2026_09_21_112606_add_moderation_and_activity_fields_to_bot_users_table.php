<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_users', function (Blueprint $table) {
            $table->timestamp('last_interaction_at')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('blocked_at')->nullable();
            $table->boolean('is_banned')->default(false);
            $table->timestamp('banned_at')->nullable();
            $table->string('ban_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_users', function (Blueprint $table) {
            $table->dropColumn([
                'last_interaction_at',
                'is_blocked',
                'blocked_at',
                'is_banned',
                'banned_at',
                'ban_reason',
            ]);
        });
    }
};
