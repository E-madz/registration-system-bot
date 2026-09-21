<?php

namespace App\Services;

use App\Contracts\MessengerAdapter;
use App\DataTransferObjects\IncomingUpdate;
use App\DataTransferObjects\KeyboardButton;
use App\Models\BotUser;
use App\Models\Program;

class BotConversationService
{
    public function handle(IncomingUpdate $update, MessengerAdapter $adapter): void
    {
        BotUser::updateOrCreate(
            ['platform' => $update->platform, 'external_id' => $update->externalUserId],
            ['first_name' => $update->firstName, 'username' => $update->username],
        );

        if ($update->isCallback()) {
            $this->handleCallback($update, $adapter);

            return;
        }

        if ($update->text === '/start') {
            $this->showProgramList($update->chatId, $adapter);

            return;
        }

        $adapter->sendMessage($update->chatId, 'برای شروع، دستور /start را ارسال کنید.');
    }

    private function handleCallback(IncomingUpdate $update, MessengerAdapter $adapter): void
    {
        $adapter->answerCallback($update->callbackQueryId);

        [$action, $id] = array_pad(explode(':', $update->callbackData ?? '', 2), 2, null);

        if ($action === 'program' && $id) {
            $this->showProgramDetail($update->chatId, (int) $id, $adapter);

            return;
        }

        if ($action === 'register' && $id) {
            $adapter->sendMessage($update->chatId, 'ثبت‌نام برای این مورد به‌زودی فعال می‌شود.');

            return;
        }

        $adapter->sendMessage($update->chatId, 'دستور نامعتبر است. لطفاً /start را ارسال کنید.');
    }

    private function showProgramList(string $chatId, MessengerAdapter $adapter): void
    {
        $programs = Program::query()->where('status', Program::STATUS_PUBLISHED)->get();

        if ($programs->isEmpty()) {
            $adapter->sendMessage($chatId, 'در حال حاضر موردی برای ثبت‌نام فعال نیست.');

            return;
        }

        $keyboard = $programs
            ->map(fn (Program $program) => [new KeyboardButton($program->title, "program:{$program->id}")])
            ->all();

        $adapter->sendMessage($chatId, 'یکی از موارد زیر را انتخاب کنید:', $keyboard);
    }

    private function showProgramDetail(string $chatId, int $programId, MessengerAdapter $adapter): void
    {
        $program = Program::query()->where('status', Program::STATUS_PUBLISHED)->find($programId);

        if (! $program) {
            $adapter->sendMessage($chatId, 'این مورد دیگر در دسترس نیست.');

            return;
        }

        $text = "{$program->title}\n\n".($program->description ?? '');
        $keyboard = [[new KeyboardButton('شروع ثبت‌نام', "register:{$program->id}")]];

        $adapter->sendMessage($chatId, trim($text), $keyboard);
    }
}
