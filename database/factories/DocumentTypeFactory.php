<?php

namespace Database\Factories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Privacy Policy',
            'Terms of Service',
            'Cookie Policy',
            'Data Processing Agreement',
            'Acceptable Use Policy',
            'DMCA Policy',
            'Refund Policy',
            'Shipping Policy',
            'Community Guidelines',
            'User Agreement',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'icon' => fake()->optional()->randomElement(['shield', 'file-text', 'cookie', 'database', 'check-circle']),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function privacyPolicy(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Privacy Policy',
            'slug' => 'privacy-policy',
            'description' => 'Company privacy policy document',
            'icon' => 'shield',
            'sort_order' => 1,
        ]);
    }

    public function termsOfService(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Terms of Service',
            'slug' => 'terms-of-service',
            'description' => 'Company terms of service document',
            'icon' => 'file-text',
            'sort_order' => 2,
        ]);
    }
}
