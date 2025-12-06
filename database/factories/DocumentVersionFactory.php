<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    public function definition(): array
    {
        $content = fake()->paragraphs(10, true);
        $contentText = strip_tags($content);

        return [
            'document_id' => Document::factory(),
            'version_number' => fake()->date('Y-m-d'),
            'content_raw' => '<html><body>' . $content . '</body></html>',
            'content_text' => $contentText,
            'content_markdown' => $content,
            'content_hash' => DocumentVersion::generateContentHash($contentText),
            'word_count' => str_word_count($contentText),
            'character_count' => strlen($contentText),
            'language' => 'en',
            'effective_date' => fake()->optional()->dateTimeBetween('-1 year', 'now'),
            'scraped_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'extraction_metadata' => [
                'extractor' => 'default',
                'selectors_used' => ['body'],
            ],
            'metadata' => null,
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_current' => true,
            'scraped_at' => now(),
        ]);
    }

    public function withRealContent(): static
    {
        $content = <<<'EOT'
We collect information you provide directly to us, such as when you create an account,
make a purchase, or contact us for support. This information may include your name,
email address, postal address, phone number, and payment information.

We automatically collect certain information when you use our Services, including your
IP address, device and browser type, operating system, referral URLs, and information
about how you interact with our Services.

We may share information about you as follows: with vendors, consultants, and other
service providers who need access to such information to carry out work on our behalf;
in response to a request for information if we believe disclosure is in accordance with
applicable law; and with your consent or at your direction.
EOT;

        return $this->state(fn (array $attributes) => [
            'content_raw' => '<html><body><div class="privacy-policy">' . nl2br($content) . '</div></body></html>',
            'content_text' => $content,
            'content_markdown' => $content,
            'content_hash' => DocumentVersion::generateContentHash($content),
            'word_count' => str_word_count($content),
            'character_count' => strlen($content),
        ]);
    }
}
