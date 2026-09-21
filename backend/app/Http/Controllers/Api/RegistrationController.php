<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationResource;
use App\Models\Program;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class RegistrationController extends Controller
{
    public function index(Request $request, Program $program)
    {
        $registrations = $program->registrations()
            ->with('botUser')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('platform'), fn ($q) => $q->whereHas(
                'botUser',
                fn ($q2) => $q2->where('platform', $request->input('platform')),
            ))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return RegistrationResource::collection($registrations);
    }

    public function show(Program $program, Registration $registration)
    {
        $this->ensureRegistrationBelongsToProgram($program, $registration);

        return new RegistrationResource($registration->load('botUser', 'answers'));
    }

    public function cancel(Program $program, Registration $registration)
    {
        $this->ensureRegistrationBelongsToProgram($program, $registration);

        if ($registration->status !== Registration::STATUS_CONFIRMED) {
            return response()->json(['message' => 'این ثبت‌نام از قبل تاییدشده نیست.'], 422);
        }

        $registration->update([
            'status' => Registration::STATUS_CANCELED,
            'canceled_at' => now(),
            'canceled_reason' => Registration::REASON_ADMIN,
        ]);

        return new RegistrationResource($registration->load('botUser'));
    }

    public function export(Request $request, Program $program)
    {
        $registrations = $program->registrations()
            ->with('botUser', 'answers')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('platform'), fn ($q) => $q->whereHas(
                'botUser',
                fn ($q2) => $q2->where('platform', $request->input('platform')),
            ))
            ->latest()
            ->get();

        $currentFields = $program->fields()->get();
        $currentKeys = $currentFields->pluck('key')->all();

        $orphanAnswers = $registrations->flatMap(fn (Registration $r) => $r->answers)
            ->whereNotIn('field_key', $currentKeys)
            ->unique('field_key');

        $headers = ['کد پیگیری', 'وضعیت', 'دلیل لغو', 'پلتفرم', 'نام کاربر', 'تاریخ ثبت‌نام'];
        foreach ($currentFields as $field) {
            $headers[] = $field->label;
        }
        foreach ($orphanAnswers as $answer) {
            $headers[] = $answer->field_label.' (حذف‌شده)';
        }

        $statusLabels = [
            Registration::STATUS_CONFIRMED => 'تاییدشده',
            Registration::STATUS_CANCELED => 'لغوشده',
        ];
        $reasonLabels = [
            Registration::REASON_SELF => 'توسط کاربر',
            Registration::REASON_ADMIN => 'توسط ادمین',
            Registration::REASON_BANNED => 'مسدودشدن کاربر',
            Registration::REASON_BOT_BLOCKED => 'بلاک کردن بات',
        ];

        $filename = "registrations-{$program->slug}.csv";

        return Response::streamDownload(function () use ($registrations, $currentFields, $orphanAnswers, $headers, $statusLabels, $reasonLabels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($registrations as $registration) {
                $answersByKey = $registration->answers->keyBy('field_key');

                $row = [
                    $registration->tracking_code,
                    $statusLabels[$registration->status] ?? $registration->status,
                    $reasonLabels[$registration->canceled_reason] ?? '',
                    $registration->botUser->platform,
                    trim(($registration->botUser->first_name ?? '').' '.($registration->botUser->last_name ?? '')),
                    $registration->created_at?->format('Y-m-d H:i'),
                ];

                foreach ($currentFields as $field) {
                    $row[] = $this->formatAnswerForCsv($answersByKey->get($field->key)?->value);
                }
                foreach ($orphanAnswers as $orphan) {
                    $row[] = $this->formatAnswerForCsv($answersByKey->get($orphan->field_key)?->value);
                }

                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function formatAnswerForCsv(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            if (isset($value['file_id'])) {
                return 'فایل ارسال شد';
            }

            return implode('، ', $value);
        }

        return (string) $value;
    }

    private function ensureRegistrationBelongsToProgram(Program $program, Registration $registration): void
    {
        abort_unless($registration->program_id === $program->id, 404);
    }
}
