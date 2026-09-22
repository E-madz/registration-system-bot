<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('source', [
                'manual', 'auto_ban', 'auto_unban',
                'auto_program_canceled', 'auto_program_published',
                'auto_registration_canceled',
            ])->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('source', [
                'manual', 'auto_ban', 'auto_unban',
                'auto_program_canceled', 'auto_program_published',
            ])->default('manual')->change();
        });
    }
};
