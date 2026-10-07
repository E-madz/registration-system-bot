<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_states', function (Blueprint $table) {
            // Set when this state represents editing a single answer on an
            // existing registration rather than collecting a brand new one -
            // storeAnswerAndAdvance() branches on this to update the
            // existing RegistrationAnswer in place instead of advancing to
            // the next field / creating a new Registration.
            $table->foreignId('editing_registration_id')->nullable()->after('current_field_id')
                ->constrained('registrations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editing_registration_id');
        });
    }
};
