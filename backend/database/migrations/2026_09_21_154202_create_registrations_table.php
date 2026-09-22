<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['confirmed', 'canceled'])->default('confirmed')->index();
            $table->string('tracking_code', 8)->unique();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['program_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
