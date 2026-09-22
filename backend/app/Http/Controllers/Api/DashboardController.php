<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Http\Resources\RegistrationResource;
use App\Models\BotUser;
use App\Models\Program;
use App\Models\Registration;

class DashboardController extends Controller
{
    public function index()
    {
        $programs = Program::all();

        $programsByStatus = collect(Program::STATUSES)
            ->mapWithKeys(fn ($status) => [$status => $programs->where('status', $status)->count()]);

        $registrationsByPlatform = Registration::query()
            ->join('bot_users', 'bot_users.id', '=', 'registrations.bot_user_id')
            ->where('registrations.status', Registration::STATUS_CONFIRMED)
            ->selectRaw('bot_users.platform, count(*) as count')
            ->groupBy('bot_users.platform')
            ->pluck('count', 'platform');

        $programsNearCapacity = $programs
            ->filter(fn (Program $p) => $p->status === Program::STATUS_PUBLISHED && $p->capacity !== null)
            ->map(fn (Program $p) => ['program' => $p, 'remaining' => $p->remainingCapacity()])
            ->filter(fn ($row) => $row['remaining'] !== null && $row['remaining'] <= 5)
            ->sortBy('remaining')
            ->take(5)
            ->map(fn ($row) => [
                'program' => new ProgramResource($row['program']),
                'remaining' => $row['remaining'],
            ])
            ->values();

        $recentRegistrations = Registration::query()
            ->with('botUser', 'program')
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'data' => [
                'programs_total' => $programs->count(),
                'programs_by_status' => $programsByStatus,
                'confirmed_registrations_total' => Registration::where('status', Registration::STATUS_CONFIRMED)->count(),
                'canceled_registrations_total' => Registration::where('status', Registration::STATUS_CANCELED)->count(),
                'banned_users_total' => BotUser::where('is_banned', true)->count(),
                'blocked_users_total' => BotUser::where('is_blocked', true)->count(),
                'registrations_by_platform' => $registrationsByPlatform,
                'programs_near_capacity' => $programsNearCapacity,
                'recent_registrations' => RegistrationResource::collection($recentRegistrations),
            ],
        ]);
    }
}
