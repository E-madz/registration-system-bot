<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramField extends Model
{
    use HasFactory;

    public const TYPES = [
        'text', 'textarea', 'number', 'email', 'phone', 'date', 'select', 'multiselect', 'file',
    ];

    public const CHOICE_TYPES = ['select', 'multiselect'];

    protected $fillable = [
        'program_id',
        'label',
        'key',
        'type',
        'help_text',
        'options',
        'is_required',
        'order',
        'validation_rules',
        'is_editable_after_submit',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'validation_rules' => 'array',
            'is_required' => 'boolean',
            'is_editable_after_submit' => 'boolean',
        ];
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }
}
