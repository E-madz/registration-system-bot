<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_fields', function (Blueprint $table) {
            $table->boolean('is_editable_after_submit')->default(false)->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('program_fields', function (Blueprint $table) {
            $table->dropColumn('is_editable_after_submit');
        });
    }
};
