<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_users', function (Blueprint $table) {
            $table->string('chat_id', 64)->nullable()->after('external_id');
        });

        // On Telegram/Bale the chat id and the sender id are the same for a
        // private chat, so backfilling existing rows this way is correct
        // there. Rubika is the only platform where they can differ, and its
        // rows will get their real chat_id the next time that user messages
        // the bot (BotConversationService::handle keeps it in sync).
        DB::table('bot_users')->whereNull('chat_id')->update(['chat_id' => DB::raw('external_id')]);
    }

    public function down(): void
    {
        Schema::table('bot_users', function (Blueprint $table) {
            $table->dropColumn('chat_id');
        });
    }
};
