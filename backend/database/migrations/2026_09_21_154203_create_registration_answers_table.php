<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_field_id')->nullable()
                ->constrained('program_fields')->nullOnDelete();
            $table->string('field_key', 100);
            $table->string('field_label', 40);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['registration_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_answers');
    }
};
