<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationAnswer extends Model
{
    protected $fillable = [
        'registration_id',
        'program_field_id',
        'field_key',
        'field_label',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    public function programField()
    {
        return $this->belongsTo(ProgramField::class);
    }
}
