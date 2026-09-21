<?php

namespace App\Services;

use App\Contracts\MessengerAdapter;
use App\DataTransferObjects\IncomingUpdate;
use App\DataTransferObjects\KeyboardButton;
use App\Models\BotUser;
use App\Models\Program;
use App\Models\Registration;

class BotConversationService
{
    private const MENU_ALL_PROGRAMS = '📋 مشاهده همه رویدادهای فعال';

    private const MENU_MY_PROGRAMS = '🎫 برنامه‌های من';

    private const FLOW_ACTIONS = [
        'field_back', 'field_skip', 'field_choice', 'field_toggle',
        'field_multi_confirm', 'confirm_registration', 'cancel_registration_flow',
    ];

    public function __construct(
        private readonly RegistrationFlowService $registrationFlow,
    ) {}

    public function handle(IncomingUpdate $update, MessengerAdapter $adapter): void
    {
        $botUser = BotUser::updateOrCreate(
            ['platform' => $update->platform, 'external_id' => $update->externalUserId],
            [
                'first_name' => $update->firstName,
                'last_name' => $update->lastName,
                'username' => $update->username,
                'last_interaction_at' => now(),
            ],
        );

        if ($update->isCallback()) {
            $this->handleCallback($botUser, $update, $adapter);

            return;
        }

        $this->handleText($botUser, $update, $adapter);
    }

    private function handleText(BotUser $botUser, IncomingUpdate $update, MessengerAdapter $adapter): void
    {
        $text = trim($update->text ?? '');
        $chatId = $update->chatId;

        $isMenuCommand = in_array($text, ['/start', self::MENU_ALL_PROGRAMS, self::MENU_MY_PROGRAMS], true);

        if ($isMenuCommand) {
            $this->cancelActiveConversationIfAny($botUser, $adapter, notify: true);

            match ($text) {
                '/start' => $this->showMainMenu($chatId, $adapter),
                self::MENU_ALL_PROGRAMS => $this->showProgramList($chatId, $adapter),
                self::MENU_MY_PROGRAMS => $this->showMyPrograms($botUser, $adapter),
            };

            return;
        }

        $state = $botUser->conversationState;

        if ($state) {
            $this->registrationFlow->handleTextAnswer($state, $update, $adapter);

            return;
        }

        $this->search($text, $chatId, $adapter);
    }

    private function handleCallback(BotUser $botUser, IncomingUpdate $update, MessengerAdapter $adapter): void
    {
        $adapter->answerCallback($update->callbackQueryId);

        // '-' (not ':') is used as the action/argument delimiter — Rubika
        // silently refuses to deliver taps on inline buttons whose id
        // contains a colon, discovered via manual testing.
        [$action, $argument] = array_pad(explode('-', $update->callbackData ?? '', 2), 2, null);

        if (in_array($action, self::FLOW_ACTIONS, true)) {
            $state = $botUser->conversationState;

            if ($state) {
                $this->registrationFlow->handleCallback($state, $action, $argument, $adapter);
            }

            return;
        }

        $this->cancelActiveConversationIfAny($botUser, $adapter, notify: true);

        $chatId = $update->chatId;

        match (true) {
            $action === 'program' && $argument => $this->showProgramDetail($botUser, (int) $argument, $adapter),
            $action === 'register' && $argument => $this->startRegistration($botUser, (int) $argument, $adapter),
            $action === 'cancel_registration' && $argument => $this->cancelRegistration($botUser, (int) $argument, $adapter),
            $action === 'back_to_programs' => $this->showProgramList($chatId, $adapter),
            $action === 'browse_all' => $this->showProgramList($chatId, $adapter),
            default => $adapter->sendMessage($chatId, 'دستور نامعتبر است. لطفاً /start را ارسال کنید.'),
        };
    }

    private function cancelActiveConversationIfAny(BotUser $botUser, MessengerAdapter $adapter, bool $notify): void
    {
        $state = $botUser->conversationState;

        if (! $state) {
            return;
        }

        $state->delete();

        if ($notify) {
            $adapter->sendMessage($botUser->external_id, 'ثبت‌نام قبلی شما لغو شد.');
        }
    }

    private function showMainMenu(string $chatId, MessengerAdapter $adapter): void
    {
        $adapter->sendReplyKeyboard(
            $chatId,
            "سلام به ربات خوش آمدید!\nبرای مشاهده برنامه‌ها از دکمه‌های زیر استفاده کنید، یا نام رویداد مورد نظرتان را همین‌جا بنویسید تا جستجو شود.",
            [[self::MENU_ALL_PROGRAMS], [self::MENU_MY_PROGRAMS]],
        );
    }

    private function showProgramList(string $chatId, MessengerAdapter $adapter): void
    {
        $programs = Program::query()->where('status', Program::STATUS_PUBLISHED)->get();

        if ($programs->isEmpty()) {
            $adapter->sendMessage($chatId, 'در حال حاضر هیچ برنامهٔ فعالی برای ثبت‌نام وجود ندارد. به محض انتشار رویداد جدید اطلاع‌رسانی خواهد شد.');

            return;
        }

        $keyboard = $programs
            ->map(fn (Program $program) => [new KeyboardButton($program->title, "program-{$program->id}")])
            ->all();

        $adapter->sendMessage($chatId, 'این لیست برنامه‌های قابل ثبت‌نام است، برای مشاهده اطلاعات بیشتر، برنامه مورد نظر را انتخاب کنید.', $keyboard);
    }

    private function search(string $text, string $chatId, MessengerAdapter $adapter): void
    {
        if ($text === '') {
            $adapter->sendMessage($chatId, 'برای شروع، دستور /start را ارسال کنید.');

            return;
        }

        $matches = Program::query()
            ->where('status', Program::STATUS_PUBLISHED)
            ->where('title', 'like', '%'.$text.'%')
            ->get();

        if ($matches->isEmpty()) {
            $adapter->sendMessage($chatId, 'چیزی با این نام پیدا نشد.');

            return;
        }

        if ($matches->count() === 1) {
            $this->sendProgramDetail($chatId, $matches->first(), null, $adapter);

            return;
        }

        $keyboard = $matches
            ->map(fn (Program $program) => [new KeyboardButton($program->title, "program-{$program->id}")])
            ->all();

        $adapter->sendMessage($chatId, 'نتایج جستجو:', $keyboard);
    }

    private function showMyPrograms(BotUser $botUser, MessengerAdapter $adapter): void
    {
        $chatId = $botUser->external_id;

        $registrations = Registration::query()
            ->where('bot_user_id', $botUser->id)
            ->where('status', Registration::STATUS_CONFIRMED)
            ->whereHas('program', fn ($q) => $q->whereIn('status', [
                Program::STATUS_PUBLISHED, Program::STATUS_CLOSED, Program::STATUS_CANCELED,
            ]))
            ->with('program')
            ->get();

        if ($registrations->isEmpty()) {
            $adapter->sendMessage(
                $chatId,
                "📭 شما هنوز در هیچ برنامه‌ای ثبت‌نام نکرده‌اید!\nبرای مشاهده رویدادهای فعال، دکمه زیر را بزنید:",
                [[new KeyboardButton('🔍 مشاهده رویدادهای فعال', 'browse_all')]],
            );

            return;
        }

        $keyboard = $registrations
            ->map(fn (Registration $registration) => [new KeyboardButton('🎟 '.$registration->program->title, "program-{$registration->program_id}")])
            ->all();

        $adapter->sendMessage(
            $chatId,
            "📋 لیست برنامه‌های شما:\nبرای مشاهده جزییات هر برنامه، روی نام آن بزنید.",
            $keyboard,
        );
    }

    private function showProgramDetail(BotUser $botUser, int $programId, MessengerAdapter $adapter): void
    {
        $program = Program::find($programId);

        if (! $program || in_array($program->status, [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED], true)) {
            $adapter->sendMessage($botUser->external_id, 'این مورد دیگر در دسترس نیست.');

            return;
        }

        $this->sendProgramDetail($botUser->external_id, $program, $botUser, $adapter);
    }

    private function sendProgramDetail(string $chatId, Program $program, ?BotUser $botUser, MessengerAdapter $adapter): void
    {
        $hasConfirmed = $botUser && Registration::query()
            ->where('bot_user_id', $botUser->id)
            ->where('program_id', $program->id)
            ->where('status', Registration::STATUS_CONFIRMED)
            ->exists();

        [$statusLine, $buttons] = $this->resolveProgramState($program, $hasConfirmed, $botUser);

        $parts = array_filter([$program->title, $program->description, $statusLine]);
        $text = implode("\n\n", $parts);

        if ($program->media_path) {
            $adapter->sendPhoto($chatId, storage_path('app/public/'.$program->media_path), trim($text), $buttons);
        } else {
            $adapter->sendMessage($chatId, trim($text), $buttons);
        }
    }

    /**
     * @return array{0: string, 1: array<int, array<int, KeyboardButton>>}
     */
    private function resolveProgramState(Program $program, bool $hasConfirmed, ?BotUser $botUser): array
    {
        $backButton = new KeyboardButton('🔙 بازگشت به لیست برنامه‌ها', 'back_to_programs');

        $registration = $hasConfirmed
            ? Registration::query()
                ->where('bot_user_id', $botUser->id)
                ->where('program_id', $program->id)
                ->where('status', Registration::STATUS_CONFIRMED)
                ->first()
            : null;

        if ($program->status === Program::STATUS_CANCELED) {
            return ['❌ متأسفانه این برنامه توسط برگزارکننده لغو شده است.', [[$backButton]]];
        }

        if ($program->status === Program::STATUS_CLOSED) {
            $status = $hasConfirmed
                ? "🔒 مهلت ثبت‌نام پایان یافته است.\n✅ ثبت‌نام شما نهایی بوده است.\n🎫 کد پیگیری: {$registration->tracking_code}"
                : '🔒 مهلت ثبت‌نام این برنامه پایان یافته است.';

            return [$status, [[$backButton]]];
        }

        // published
        if ($hasConfirmed) {
            return [
                "✅ وضعیت شما: ثبت‌نام قطعی شده است.\n🎫 کد پیگیری: {$registration->tracking_code}",
                [[
                    new KeyboardButton('❌ انصراف از برنامه', "cancel_registration-{$registration->id}"),
                    $backButton,
                ]],
            ];
        }

        $window = $program->registrationWindowState();

        if ($window === 'not_started') {
            $date = $program->registration_starts_at?->format('Y-m-d H:i');

            return ["⏳ ثبت‌نام این برنامه هنوز آغاز نشده است.\nتاریخ شروع ثبت‌نام: {$date}", [[$backButton]]];
        }

        if ($window === 'ended') {
            $date = $program->registration_ends_at?->format('Y-m-d H:i');

            return ["⌛ مهلت ثبت‌نام این برنامه به پایان رسیده است.\nپایان مهلت: {$date}", [[$backButton]]];
        }

        if (! $program->hasCapacity()) {
            return ['🚫 ظرفیت این برنامه تکمیل شده است و امکان ثبت‌نام وجود ندارد.', [[$backButton]]];
        }

        $capacityLine = $program->capacity !== null
            ? "👥 ظرفیت باقی‌مانده: {$program->remainingCapacity()} نفر از {$program->capacity} نفر."
            : '👥 ثبت‌نام برای این برنامه باز است.';

        if ($program->registration_ends_at) {
            $capacityLine .= "\n⏰ مهلت ثبت‌نام تا: {$program->registration_ends_at->format('Y-m-d H:i')}";
        }

        return [$capacityLine, [
            [new KeyboardButton('ثبت‌نام در این برنامه', "register-{$program->id}")],
            [$backButton],
        ]];
    }

    private function startRegistration(BotUser $botUser, int $programId, MessengerAdapter $adapter): void
    {
        $program = Program::find($programId);

        if (! $program) {
            $adapter->sendMessage($botUser->external_id, 'این مورد دیگر در دسترس نیست.');

            return;
        }

        $this->registrationFlow->begin($botUser, $program, $adapter);
    }

    private function cancelRegistration(BotUser $botUser, int $registrationId, MessengerAdapter $adapter): void
    {
        $registration = Registration::query()
            ->where('id', $registrationId)
            ->where('bot_user_id', $botUser->id)
            ->where('status', Registration::STATUS_CONFIRMED)
            ->first();

        if (! $registration) {
            $adapter->sendMessage($botUser->external_id, 'این ثبت‌نام یافت نشد.');

            return;
        }

        $registration->update(['status' => Registration::STATUS_CANCELED, 'canceled_at' => now()]);

        $adapter->sendMessage($botUser->external_id, 'ثبت‌نام شما لغو شد. در صورت تمایل می‌توانید دوباره ثبت‌نام کنید.');
    }
}
