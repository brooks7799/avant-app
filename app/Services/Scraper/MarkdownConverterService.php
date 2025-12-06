<?php

namespace App\Services\Scraper;

use League\HTMLToMarkdown\HtmlConverter;
use League\HTMLToMarkdown\Converter\TableConverter;

class MarkdownConverterService
{
    protected HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'strip_tags' => config('scraper.markdown.strip_tags', true),
            'preserve_comments' => config('scraper.markdown.preserve_comments', false),
            'hard_break' => config('scraper.markdown.hard_break', true),
            'suppress_errors' => config('scraper.markdown.suppress_errors', true),
            'remove_nodes' => 'script style',
        ]);

        // Add table support
        $this->converter->getEnvironment()->addConverter(new TableConverter());
    }

    /**
     * Convert HTML to Markdown.
     */
    public function convert(string $html): string
    {
        try {
            // Pre-process HTML
            $html = $this->preProcess($html);

            // Convert to markdown
            $markdown = $this->converter->convert($html);

            // Post-process markdown
            $markdown = $this->postProcess($markdown);

            return $markdown;
        } catch (\Exception $e) {
            // If conversion fails, return a simplified version
            return $this->fallbackConvert($html);
        }
    }

    /**
     * Pre-process HTML before conversion.
     */
    protected function preProcess(string $html): string
    {
        // Ensure proper encoding
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }

        // Remove excessive whitespace in HTML
        $html = preg_replace('/>\s+</', '> <', $html);

        // Convert common HTML entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $html;
    }

    /**
     * Post-process markdown after conversion.
     */
    protected function postProcess(string $markdown): string
    {
        // Remove excessive blank lines (more than 2 consecutive)
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        // Fix markdown link formatting issues
        $markdown = preg_replace('/\]\s+\(/', '](', $markdown);

        // Remove trailing whitespace from lines
        $markdown = preg_replace('/[ \t]+$/m', '', $markdown);

        // Ensure file ends with single newline
        $markdown = rtrim($markdown) . "\n";

        return $markdown;
    }

    /**
     * Fallback conversion if main converter fails.
     */
    protected function fallbackConvert(string $html): string
    {
        // Strip all HTML tags but preserve some structure
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Convert HTML to markdown with section headers preserved.
     */
    public function convertWithSections(string $html): string
    {
        $markdown = $this->convert($html);

        // Ensure headers have proper spacing
        $markdown = preg_replace('/([^\n])(\n#{1,6}\s)/', "$1\n$2", $markdown);

        return $markdown;
    }
}
