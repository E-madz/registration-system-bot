<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\UpdateProgramRequest;
use App\Http\Requests\UpdateProgramStatusRequest;
use App\Http\Requests\UploadProgramMediaRequest;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProgramController extends Controller
{
    public function index(Request $request)
    {
        $programs = Program::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ProgramResource::collection($programs);
    }

    public function store(StoreProgramRequest $request)
    {
        $data = $request->validated();
        $data['slug'] = Program::generateUniqueSlug($data['title']);
        $data['status'] = Program::STATUS_DRAFT;

        $program = Program::create($data);

        return (new ProgramResource($program))->response()->setStatusCode(201);
    }

    public function show(Program $program)
    {
        return new ProgramResource($program->load('fields'));
    }

    public function update(UpdateProgramRequest $request, Program $program)
    {
        $data = $request->validated();

        if (isset($data['title']) && $data['title'] !== $program->title) {
            $data['slug'] = Program::generateUniqueSlug($data['title'], $program->id);
        }

        $program->update($data);

        return new ProgramResource($program);
    }

    public function updateStatus(UpdateProgramStatusRequest $request, Program $program)
    {
        $status = $request->validated()['status'];

        if (! $program->canTransitionTo($status)) {
            return response()->json([
                'message' => "امکان تغییر وضعیت از «{$program->status}» به «{$status}» وجود ندارد.",
            ], 422);
        }

        $program->update(['status' => $status]);

        return new ProgramResource($program);
    }

    public function uploadMedia(UploadProgramMediaRequest $request, Program $program)
    {
        if ($program->media_path) {
            Storage::disk('public')->delete($program->media_path);
        }

        $path = $request->file('media')->store('programs/media', 'public');
        $program->update(['media_path' => $path]);

        return new ProgramResource($program);
    }

    public function deleteMedia(Program $program)
    {
        if ($program->media_path) {
            Storage::disk('public')->delete($program->media_path);
            $program->update(['media_path' => null]);
        }

        return new ProgramResource($program);
    }

    public function destroy(Program $program)
    {
        if (! $program->isDraft()) {
            return response()->json([
                'message' => 'فقط موارد در وضعیت «draft» قابل حذف کامل هستند. برای بقیه از آرشیو/لغو استفاده کنید.',
            ], 422);
        }

        $program->delete();

        return response()->json(null, 204);
    }
}
