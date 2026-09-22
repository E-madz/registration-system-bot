<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 40);
            $table->string('slug', 60)->unique();
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'published', 'closed', 'canceled', 'archived'])
                ->default('draft')
                ->index();
            $table->dateTime('registration_starts_at')->nullable();
            $table->dateTime('registration_ends_at')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->text('success_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
