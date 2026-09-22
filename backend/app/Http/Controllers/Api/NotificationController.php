<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request)
    {
        $notifications = Notification::query()
            ->with('audienceProgram')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return NotificationResource::collection($notifications);
    }

    public function store(StoreNotificationRequest $request)
    {
        $data = $request->validated();
        $data['source'] = Notification::SOURCE_MANUAL;
        $data['created_by'] = $request->user()?->id;

        $notification = $this->notifications->create($data);

        return (new NotificationResource($notification->load('audienceProgram')))->response()->setStatusCode(201);
    }

    public function show(Notification $notification)
    {
        return new NotificationResource($notification->load(['audienceProgram', 'deliveries.botUser']));
    }
}
