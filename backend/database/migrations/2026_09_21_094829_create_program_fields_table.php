<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->string('key', 100);
            $table->enum('type', [
                'text', 'textarea', 'number', 'email', 'phone', 'date', 'select', 'multiselect', 'file',
            ]);
            $table->string('help_text', 50)->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->json('validation_rules')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_fields');
    }
};
