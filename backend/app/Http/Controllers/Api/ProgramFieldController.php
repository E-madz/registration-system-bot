<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderProgramFieldsRequest;
use App\Http\Requests\StoreProgramFieldRequest;
use App\Http\Requests\UpdateProgramFieldRequest;
use App\Http\Resources\ProgramFieldResource;
use App\Models\Program;
use App\Models\ProgramField;

class ProgramFieldController extends Controller
{
    public function index(Program $program)
    {
        return ProgramFieldResource::collection($program->fields);
    }

    public function store(StoreProgramFieldRequest $request, Program $program)
    {
        $data = $request->validated();
        $data['order'] = $data['order'] ?? ($program->fields()->max('order') + 1);

        $field = $program->fields()->create($data);

        return (new ProgramFieldResource($field))->response()->setStatusCode(201);
    }

    public function update(UpdateProgramFieldRequest $request, Program $program, ProgramField $field)
    {
        $this->ensureFieldBelongsToProgram($program, $field);

        $field->update($request->validated());

        return new ProgramFieldResource($field);
    }

    public function destroy(Program $program, ProgramField $field)
    {
        $this->ensureFieldBelongsToProgram($program, $field);

        $field->delete();

        return response()->json(null, 204);
    }

    public function reorder(ReorderProgramFieldsRequest $request, Program $program)
    {
        foreach ($request->validated()['order'] as $index => $fieldId) {
            ProgramField::whereKey($fieldId)->update(['order' => $index]);
        }

        return ProgramFieldResource::collection($program->fields()->get());
    }

    private function ensureFieldBelongsToProgram(Program $program, ProgramField $field): void
    {
        abort_unless($field->program_id === $program->id, 404);
    }
}
