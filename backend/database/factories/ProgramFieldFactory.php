<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\ProgramField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgramField>
 */
class ProgramFieldFactory extends Factory
{
    protected $model = ProgramField::class;

    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'label' => $this->faker->words(2, true),
            'key' => $this->faker->unique()->word(),
            'type' => 'text',
            'is_required' => true,
            'order' => 0,
        ];
    }
}
