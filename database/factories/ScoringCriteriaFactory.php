<?php

namespace Database\Factories;

use App\Models\ScoringCriteria;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScoringCriteria>
 */
class ScoringCriteriaFactory extends Factory
{
    protected $model = ScoringCriteria::class;

    public function definition(): array
    {
        $name = fake()->unique()->sentence(3);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'category' => fake()->randomElement(array_keys(ScoringCriteria::getCategories())),
            'description' => fake()->paragraph(),
            'evaluation_prompt' => null,
            'weight' => fake()->randomFloat(2, 0.5, 2.0),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
            'metadata' => null,
        ];
    }

    public function dataCollection(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Data Collection Practices',
            'slug' => 'data-collection-practices',
            'category' => 'data_collection',
            'description' => 'Evaluates what data is collected and whether collection is proportionate to the service provided.',
            'evaluation_prompt' => 'Analyze this privacy policy and evaluate the data collection practices. Consider: What types of data are collected? Is the collection proportionate to the service? Are there any excessive data collection practices?',
            'weight' => 1.5,
        ]);
    }

    public function dataSharing(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Third-Party Data Sharing',
            'slug' => 'third-party-data-sharing',
            'category' => 'data_sharing',
            'description' => 'Evaluates how and with whom user data is shared.',
            'evaluation_prompt' => 'Analyze this privacy policy for data sharing practices. Consider: Who receives user data? Is sharing necessary for the service? Are there any concerning third-party relationships?',
            'weight' => 1.5,
        ]);
    }

    public function userRights(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'User Rights and Control',
            'slug' => 'user-rights-control',
            'category' => 'user_rights',
            'description' => 'Evaluates what control users have over their data.',
            'evaluation_prompt' => 'Analyze this privacy policy for user rights. Consider: Can users access their data? Can they delete it? Can they opt out of data collection? How easy is it to exercise these rights?',
            'weight' => 1.25,
        ]);
    }
}
