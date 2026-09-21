<?php

namespace App\Console\Commands;

use App\Contracts\MessengerAdapter;
use App\DataTransferObjects\IncomingUpdate;
use App\Models\BotUser;
use App\Models\Program;
use App\Models\ProgramField;
use App\Models\Registration;
use App\Services\BotConversationService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class SimulateBot extends Command
{
    protected $signature = 'bot:simulate';

    protected $description = 'Simulate a full bot conversation locally and print every message, for manual UX review';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('این دستور فقط در محیط local/testing قابل اجراست (چون داده‌ی دیتابیس رو تغییر می‌ده).');

            return self::FAILURE;
        }

        if (! $this->option('no-interaction') && ! $this->confirm('این دستور چند Program و ثبت‌نام آزمایشی توی دیتابیس فعلی می‌سازه. ادامه می‌دید؟')) {
            return self::SUCCESS;
        }

        $adapter = new class implements MessengerAdapter
        {
            public function platform(): string
            {
                return 'telegram';
            }

            public function parseUpdate(Request $request): IncomingUpdate
            {
                throw new \RuntimeException('not used');
            }

            public function sendMessage(string $chatId, string $text, ?array $keyboard = null): void
            {
                echo "\n--- sendMessage ---\n{$text}\n";
                $this->printKeyboard($keyboard);
            }

            public function sendPhoto(string $chatId, string $photoPath, string $caption, ?array $keyboard = null): void
            {
                echo "\n--- sendPhoto ({$photoPath}) ---\n{$caption}\n";
                $this->printKeyboard($keyboard);
            }

            public function sendReplyKeyboard(string $chatId, string $text, array $buttonRows): void
            {
                echo "\n--- sendReplyKeyboard ---\n{$text}\n";
                foreach ($buttonRows as $row) {
                    echo '  ['.implode('] [', $row)."]\n";
                }
            }

            public function answerCallback(string $callbackQueryId, ?string $text = null): void
            {
                // no-op for simulation
            }

            public function wasBlockedByUser(): bool
            {
                return false;
            }

            private function printKeyboard(?array $keyboard): void
            {
                if (! $keyboard) {
                    return;
                }
                foreach ($keyboard as $row) {
                    $labels = array_map(fn ($b) => "{$b->label} => {$b->value}", $row);
                    echo '  ['.implode('] [', $labels)."]\n";
                }
            }
        };

        $service = app(BotConversationService::class);

        $send = function (array $overrides) use ($service, $adapter) {
            $args = array_merge([
                'platform' => 'telegram',
                'chatId' => '111',
                'externalUserId' => '222',
                'firstName' => 'Emad',
            ], $overrides);
            $update = new IncomingUpdate(...$args);
            $service->handle($update, $adapter);
        };

        $this->info('=== Setup: creating a program with all field types ===');
        $program = Program::create([
            'title' => 'دوره آزمایشی',
            'slug' => 'test-course',
            'description' => 'این یک دوره‌ی آزمایشی برای تست است.',
            'status' => Program::STATUS_PUBLISHED,
            'capacity' => 2,
        ]);
        ProgramField::create(['program_id' => $program->id, 'label' => 'نام کامل', 'key' => 'full_name', 'type' => 'text', 'is_required' => true, 'order' => 0]);
        ProgramField::create(['program_id' => $program->id, 'label' => 'ایمیل', 'key' => 'email', 'type' => 'email', 'is_required' => true, 'order' => 1]);
        ProgramField::create(['program_id' => $program->id, 'label' => 'سطح', 'key' => 'level', 'type' => 'select', 'options' => ['مبتدی', 'پیشرفته'], 'is_required' => true, 'order' => 2]);
        ProgramField::create(['program_id' => $program->id, 'label' => 'علایق', 'key' => 'interests', 'type' => 'multiselect', 'options' => ['موسیقی', 'ورزش', 'هنر'], 'is_required' => false, 'order' => 3]);
        ProgramField::create(['program_id' => $program->id, 'label' => 'توضیحات اضافه', 'key' => 'note', 'type' => 'textarea', 'is_required' => false, 'order' => 4]);

        $this->info("\n=== /start ===");
        $send(['text' => '/start']);

        $this->info("\n=== مشاهده همه رویدادهای فعال ===");
        $send(['text' => '📋 مشاهده همه رویدادهای فعال']);

        $this->info("\n=== انتخاب Program (callback) ===");
        $send(['callbackData' => "program-{$program->id}", 'callbackQueryId' => 'cbq1']);

        $this->info("\n=== شروع ثبت‌نام ===");
        $send(['callbackData' => "register-{$program->id}", 'callbackQueryId' => 'cbq2']);

        $this->info("\n=== پاسخ نام (معتبر) ===");
        $send(['text' => 'علی محمدی']);

        $this->info("\n=== پاسخ ایمیل (نامعتبر) ===");
        $send(['text' => 'not-an-email']);

        $this->info("\n=== پاسخ ایمیل (معتبر) ===");
        $send(['text' => 'ali@example.com']);

        $this->info("\n=== انتخاب سطح ===");
        $send(['callbackData' => 'field_choice-1', 'callbackQueryId' => 'cbq3']);

        $this->info("\n=== تاگل علایق (دو گزینه) ===");
        $send(['callbackData' => 'field_toggle-0', 'callbackQueryId' => 'cbq4']);
        $send(['callbackData' => 'field_toggle-2', 'callbackQueryId' => 'cbq5']);
        $send(['callbackData' => 'field_multi_confirm', 'callbackQueryId' => 'cbq6']);

        $this->info("\n=== رد شدن از فیلد اختیاری (توضیحات) ===");
        $send(['callbackData' => 'field_skip', 'callbackQueryId' => 'cbq7']);

        $this->info("\n=== زدن دکمه‌ی برگشت در صفحه‌ی خلاصه ===");
        $send(['callbackData' => 'field_back', 'callbackQueryId' => 'cbq8']);

        $this->info("\n=== ادامه دوباره تا رسیدن به خلاصه (رد شدن) ===");
        $send(['callbackData' => 'field_skip', 'callbackQueryId' => 'cbq9']);

        $this->info("\n=== تایید نهایی ===");
        $send(['callbackData' => 'confirm_registration', 'callbackQueryId' => 'cbq10']);

        $this->info("\n=== باز کردن دوباره Program (باید بگه قبلا ثبت‌نام کرده) ===");
        $send(['callbackData' => "program-{$program->id}", 'callbackQueryId' => 'cbq11']);

        $this->info("\n=== تلاش برای ثبت‌نام دوباره ===");
        $send(['callbackData' => "register-{$program->id}", 'callbackQueryId' => 'cbq12']);

        $this->info("\n=== برنامه‌های من ===");
        $send(['text' => '🎫 برنامه‌های من']);

        $registration = Registration::first();
        $this->info("\n=== انصراف از ثبت‌نام ===");
        $send(['callbackData' => "cancel_registration-{$registration->id}", 'callbackQueryId' => 'cbq13']);

        $this->info("\n=== باز کردن دوباره بعد از انصراف ===");
        $send(['callbackData' => "program-{$program->id}", 'callbackQueryId' => 'cbq14']);

        $this->info("\n=== جستجو با متن نامرتبط ===");
        $send(['text' => 'چیزی که وجود نداره']);

        $this->info("\n=== برنامه‌های من (وقتی ثبت‌نامی نداره) ===");
        $send(['text' => '🎫 برنامه‌های من']);

        $this->info("\n=== Program با وضعیت closed ===");
        $closed = Program::create(['title' => 'دوره بسته‌شده', 'slug' => 'closed-course', 'status' => Program::STATUS_CLOSED]);
        $send(['callbackData' => "program-{$closed->id}", 'callbackQueryId' => 'cbq15']);

        $this->info("\n=== Program با وضعیت canceled ===");
        $canceled = Program::create(['title' => 'دوره لغوشده', 'slug' => 'canceled-course', 'status' => Program::STATUS_CANCELED]);
        $send(['callbackData' => "program-{$canceled->id}", 'callbackQueryId' => 'cbq16']);

        $this->info("\n=== Program با ظرفیت تکمیل‌شده ===");
        $full = Program::create(['title' => 'دوره پرشده', 'slug' => 'full-course', 'status' => Program::STATUS_PUBLISHED, 'capacity' => 1]);
        $otherUser = BotUser::create(['platform' => 'telegram', 'external_id' => '999']);
        Registration::create(['program_id' => $full->id, 'bot_user_id' => $otherUser->id, 'status' => Registration::STATUS_CONFIRMED, 'tracking_code' => Registration::generateUniqueTrackingCode()]);
        $send(['callbackData' => "program-{$full->id}", 'callbackQueryId' => 'cbq17']);

        $this->info("\n=== Program با ثبت‌نام هنوز شروع‌نشده ===");
        $future = Program::create(['title' => 'دوره آینده', 'slug' => 'future-course', 'status' => Program::STATUS_PUBLISHED, 'registration_starts_at' => now()->addDays(2)]);
        $send(['callbackData' => "program-{$future->id}", 'callbackQueryId' => 'cbq18']);

        $this->info("\n=== لیست خالی از برنامه‌ها ===");
        Program::query()->delete();
        $send(['text' => '📋 مشاهده همه رویدادهای فعال']);

        $this->info("\nDone.");

        return self::SUCCESS;
    }
}
