<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'type' => fake()->randomElement(array_keys(Tag::getTypes())),
            'color' => fake()->optional()->hexColor(),
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function industry(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'industry',
        ]);
    }

    public function concern(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'concern',
            'color' => '#ef4444', // Red
        ]);
    }

    public function compliance(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'compliance',
            'color' => '#3b82f6', // Blue
        ]);
    }
}
