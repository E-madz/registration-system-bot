<?php

namespace Database\Factories;

use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    protected $model = Program::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->words(3, true);

        return [
            'title' => Str::limit($title, 40, ''),
            'slug' => Program::generateUniqueSlug($title),
            'description' => $this->faker->optional()->paragraph(),
            'status' => Program::STATUS_DRAFT,
            'requires_approval' => false,
        ];
    }
}
