<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_type_id' => DocumentType::factory(),
            'source_url' => fake()->url() . '/privacy',
            'canonical_url' => null,
            'is_active' => true,
            'is_monitored' => true,
            'scrape_frequency' => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'last_scraped_at' => null,
            'last_changed_at' => null,
            'scrape_status' => 'pending',
            'scrape_notes' => null,
            'metadata' => null,
        ];
    }

    public function scraped(): static
    {
        return $this->state(fn (array $attributes) => [
            'scrape_status' => 'success',
            'last_scraped_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'scrape_status' => 'failed',
            'scrape_notes' => 'Failed to fetch document: ' . fake()->sentence(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function unmonitored(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_monitored' => false,
        ]);
    }
}
