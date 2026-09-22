<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_users', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            $table->string('external_id', 64);
            $table->string('first_name', 100)->nullable();
            $table->string('username', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_users');
    }
};
