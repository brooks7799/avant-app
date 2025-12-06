<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . fake()->unique()->randomNumber(4),
            'website' => fake()->url(),
            'logo_url' => fake()->optional()->imageUrl(200, 200, 'business'),
            'description' => fake()->optional()->paragraph(),
            'industry' => fake()->randomElement([
                'Technology',
                'Finance',
                'Healthcare',
                'Retail',
                'Social Media',
                'Entertainment',
                'Education',
                'Transportation',
            ]),
            'headquarters_country' => fake()->country(),
            'headquarters_state' => fake()->optional()->state(),
            'is_active' => true,
            'metadata' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withMetadata(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => [
                'founded_year' => fake()->year(),
                'employee_count' => fake()->randomElement(['1-50', '51-200', '201-1000', '1000+']),
                'public_company' => fake()->boolean(),
            ],
        ]);
    }
}
