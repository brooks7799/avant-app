<?php

namespace App\Services\AI;

use App\Models\ChatMessage;
use App\Models\DocumentChat;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\LLM\LlmClientInterface;
use Illuminate\Support\Facades\Log;

class DocumentChatService
{
    protected const MAX_CONTEXT_CHARS = 8000; // ~2000 tokens - reduced for smaller models

    protected const MAX_HISTORY_MESSAGES = 10;

    protected const SYSTEM_PROMPT = <<<'PROMPT'
You are a legal document expert analyzing a {{DOCUMENT_TYPE}} from {{COMPANY_NAME}}.

FORMAT YOUR RESPONSE EXACTLY LIKE THIS:

**Short answer:** [One sentence answer]

### 📋 Key Points
- ✅ **[Good thing]** — explanation
- ⚠️ **[Caution]** — explanation
- ❌ **[Bad thing]** — explanation

### 💡 Bottom Line
[1-2 sentences of practical advice]

RULES:
- Start every bullet with an emoji (✅ ⚠️ ❌ 💰 🔒)
- Bold key terms
- Keep response under 200 words
- Be direct and helpful

DOCUMENT:
{{DOCUMENT_CONTENT}}
PROMPT;

    protected const SCOPE_CHECK_PROMPT = <<<'PROMPT'
Determine if this user question is related to the legal document being discussed.

Document type: {{DOCUMENT_TYPE}}
Company: {{COMPANY_NAME}}

The question is IN SCOPE if it:
- Asks about specific terms, clauses, or sections in the document
- Asks about data privacy, user rights, or obligations mentioned
- Asks about legal implications of the document
- Asks to explain or clarify document content
- Asks about the company's policies as stated in the document
- Asks comparative questions about this policy vs general practices
- Asks general questions about understanding legal documents

The question is OUT OF SCOPE if it:
- Is completely unrelated to legal documents or this company
- Asks for help with unrelated tasks (coding, math, recipes, etc.)
- Asks about current events, weather, or general knowledge
- Asks you to do something other than discuss the document

User question: "{{QUESTION}}"

Respond with JSON only:
{
  "is_in_scope": true or false,
  "reason": "brief explanation",
  "suggested_topics": ["topic1", "topic2", "topic3"]
}
PROMPT;

    public function __construct(
        protected LlmClientInterface $llmClient,
    ) {}

    /**
     * Get or create a chat session for a document version and user.
     */
    public function getOrCreateChat(DocumentVersion $version, User $user): DocumentChat
    {
        return DocumentChat::firstOrCreate(
            [
                'document_version_id' => $version->id,
                'user_id' => $user->id,
            ],
            [
                'last_message_at' => now(),
            ]
        );
    }

    /**
     * Stream a response to a user question.
     *
     * @return \Generator<string>
     */
    public function streamResponse(DocumentChat $chat, string $question): \Generator
    {
        $version = $chat->documentVersion;
        $document = $version->document;

        // First, check if question is in scope
        $scopeCheck = $this->checkScope($question, $document);

        if (! $scopeCheck['is_in_scope']) {
            // Save user message
            $this->saveMessage($chat, 'user', $question);

            // Generate and save polite refusal
            $refusal = $this->generateRefusalMessage($scopeCheck);
            $this->saveMessage($chat, 'assistant', $refusal, [
                'is_scope_refusal' => true,
                'scope_check' => $scopeCheck,
            ]);

            yield $refusal;

            return;
        }

        // Save user message
        $this->saveMessage($chat, 'user', $question);

        // Build messages array
        $messages = $this->buildMessages($chat, $version, $question);

        // Get response and stream it word by word
        $fullResponse = '';
        try {
            $response = $this->llmClient->complete($messages, [
                'temperature' => 0.3,
                'max_tokens' => 2048,
            ]);

            $fullResponse = $response->content;

            if (empty($fullResponse)) {
                $fullResponse = 'I apologize, but I was unable to generate a response. Please try rephrasing your question.';
            }

            // Stream response in small chunks for smooth display
            $words = preg_split('/(\s+)/', $fullResponse, -1, PREG_SPLIT_DELIM_CAPTURE);
            $buffer = '';

            foreach ($words as $i => $word) {
                $buffer .= $word;
                // Yield every 2-3 words for smooth streaming effect
                if (($i + 1) % 4 === 0 || $i === count($words) - 1) {
                    yield $buffer;
                    $buffer = '';
                }
            }

            if ($buffer !== '') {
                yield $buffer;
            }
        } catch (\Exception $e) {
            Log::error('Chat error', ['error' => $e->getMessage()]);
            $errorMessage = 'Sorry, I encountered an error processing your question. Please try again.';
            $this->saveMessage($chat, 'assistant', $errorMessage, ['error' => $e->getMessage()]);
            yield $errorMessage;

            return;
        }

        // Save assistant response
        $this->saveMessage($chat, 'assistant', $fullResponse);

        // Update chat title if first message
        if ($chat->messages()->count() <= 2) {
            $chat->update([
                'title' => $this->generateTitle($question),
                'last_message_at' => now(),
            ]);
        } else {
            $chat->update(['last_message_at' => now()]);
        }
    }

    /**
     * Check if the question is within scope for this document.
     * Uses fast keyword matching only - no LLM call to avoid delays.
     */
    protected function checkScope(string $question, $document): array
    {
        $questionLower = strtolower($question);

        // Keywords that indicate document-related questions
        $inScopeKeywords = [
            'terms', 'policy', 'cancel', 'delete', 'data', 'privacy', 'account',
            'service', 'agreement', 'rights', 'refund', 'subscription', 'collect',
            'share', 'personal', 'information', 'user', 'content', 'license',
            'terminate', 'liability', 'dispute', 'arbitration', 'copyright',
            'intellectual', 'property', 'warranty', 'indemnif', 'govern', 'law',
            'jurisdiction', 'notice', 'change', 'modify', 'update', 'effective',
            'binding', 'consent', 'opt', 'cookie', 'track', 'advertis', 'third',
            'party', 'minor', 'child', 'age', 'restrict', 'prohibit', 'allow',
            'permit', 'require', 'must', 'shall', 'obligat', 'responsib', 'right',
            'access', 'store', 'retain', 'secure', 'protect', 'breach', 'violat',
            'enforce', 'compli', 'legal', 'court', 'claim', 'damage', 'limit',
            'exclud', 'disclaim', 'waiv', 'assign', 'transfer', 'surviv',
            'what', 'how', 'when', 'where', 'why', 'can', 'does', 'will', 'should',
            'explain', 'mean', 'say', 'tell', 'find', 'section', 'clause', 'part',
        ];

        // Check if question contains any in-scope keywords
        foreach ($inScopeKeywords as $keyword) {
            if (str_contains($questionLower, $keyword)) {
                return ['is_in_scope' => true, 'reason' => 'Document-related question'];
            }
        }

        // Clearly out-of-scope patterns
        $outOfScopePatterns = [
            '/write.*(code|program|script|function)/i',
            '/help.*(homework|math|science)/i',
            '/(weather|temperature|forecast)/i',
            '/(recipe|cook|food|restaurant)/i',
            '/(movie|song|music|game|sport)/i',
            '/translate.*to/i',
        ];

        foreach ($outOfScopePatterns as $pattern) {
            if (preg_match($pattern, $question)) {
                return [
                    'is_in_scope' => false,
                    'reason' => "I can only answer questions about this document.",
                    'suggested_topics' => [
                        'What data does this company collect?',
                        'Can I delete my account?',
                        'What are the cancellation terms?',
                        'Are there any concerning clauses?',
                    ],
                ];
            }
        }

        // Default: allow the question - let the LLM handle edge cases
        return ['is_in_scope' => true, 'reason' => 'Allowed by default'];
    }

    /**
     * Build the messages array for the LLM.
     */
    protected function buildMessages(DocumentChat $chat, DocumentVersion $version, string $question): array
    {
        $document = $version->document;

        // Get document content (truncated if necessary)
        $content = $this->getDocumentContext($version);

        // Get analysis context if available
        $analysisContext = $this->getAnalysisContext($version);

        // Build system prompt
        $systemPrompt = str_replace(
            ['{{DOCUMENT_TYPE}}', '{{COMPANY_NAME}}', '{{ANALYSIS_CONTEXT}}', '{{DOCUMENT_CONTENT}}'],
            [
                $document->documentType?->name ?? 'Legal Document',
                $document->company?->name ?? 'Unknown Company',
                $analysisContext,
                $content,
            ],
            self::SYSTEM_PROMPT
        );

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add conversation history (last N messages)
        $history = $chat->messages()
            ->orderByDesc('created_at')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->reverse();

        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        }

        // Add current question
        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    /**
     * Get document content for context, truncated if necessary.
     */
    protected function getDocumentContext(DocumentVersion $version): string
    {
        $content = $version->content_markdown ?? $version->content_text ?? '';

        if (strlen($content) > self::MAX_CONTEXT_CHARS) {
            // Truncate with indication
            $content = substr($content, 0, self::MAX_CONTEXT_CHARS);
            $content .= "\n\n[Document truncated due to length. Ask about specific sections for more detail.]";
        }

        return $content;
    }

    /**
     * Get analysis context hints if available.
     */
    protected function getAnalysisContext(DocumentVersion $version): string
    {
        $analysis = $version->currentAnalysis;

        if (! $analysis) {
            return '';
        }

        $context = "\nAI ANALYSIS SUMMARY:\n";
        $context .= "- Overall Rating: {$analysis->overall_rating} ({$analysis->overall_score}/100)\n";

        if ($analysis->summary) {
            $context .= '- Summary: '.substr($analysis->summary, 0, 500)."\n";
        }

        // Include FAQ topics as hints
        $faq = $analysis->extracted_data['faq'] ?? [];
        if (! empty($faq)) {
            $questions = array_column(array_slice($faq, 0, 5), 'question');
            $context .= '- Common Questions: '.implode(', ', $questions)."\n";
        }

        return $context;
    }

    /**
     * Generate a polite refusal message for out-of-scope questions.
     */
    protected function generateRefusalMessage(array $scopeCheck): string
    {
        $message = "I'm here to help you understand this specific document. ";
        $message .= $scopeCheck['reason'] ?? "That question doesn't seem related to this document.";
        $message .= "\n\n";

        $suggestions = $scopeCheck['suggested_topics'] ?? [
            'What data does this company collect?',
            'How can I delete my account?',
            'What are my rights under this policy?',
            'Are there any concerning clauses?',
        ];

        $message .= "Here are some things you can ask about this document:\n";
        foreach (array_slice($suggestions, 0, 4) as $topic) {
            $message .= "- {$topic}\n";
        }

        return $message;
    }

    /**
     * Generate a chat title from the first question.
     */
    protected function generateTitle(string $question): string
    {
        $title = substr($question, 0, 50);
        if (strlen($question) > 50) {
            $title .= '...';
        }

        return $title;
    }

    /**
     * Save a message to the database.
     */
    protected function saveMessage(DocumentChat $chat, string $role, string $content, array $metadata = []): ChatMessage
    {
        return $chat->messages()->create([
            'role' => $role,
            'content' => $content,
            'model_used' => $role === 'assistant' ? $this->llmClient->getModel() : null,
            'metadata' => ! empty($metadata) ? $metadata : null,
        ]);
    }
}
