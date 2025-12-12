# AVANT – AI Legal Analysis & Accountability Engine  
## Technical Design Specification (Backend & LLM Integration Only)

> **Scope:**  
> This document covers the **AI / LLM processing layer** for AVANT – how to analyze legal documents, score them, compare versions, and detect suspicious changes.  
> **UI is explicitly out of scope** for this spec. It focuses on backend architecture, data models, pipelines, and LLM integration from a Laravel/PHP codebase.

---

## 1. High-Level Goals

AVANT consumes legal documents (Terms of Service, Privacy Policy, Cookie Policy, EULA, etc.) discovered and versioned by the existing scraper module, and then:

1. **Translates legalese → plain language** for users.
2. **Generates FAQs** explaining key rights, risks, and obligations.
3. **Identifies and flags risky, unfair, or one-sided clauses.**
4. **Assigns a consumer-friendliness grade (A–F)** based on a consistent scoring rubric.
5. **Compares versions (diffs)** to:
   - Highlight what changed between any two versions.
   - Summarize if changes are positive or negative for users.
   - Adjust the company’s score based on changes.
6. **Evaluates timing of changes** for suspicious behavior (e.g. holiday midnights, weekends).
7. **Bases scores on a consistent rule set** inspired by legal standards and consumer protection principles.

The target: **AVANT becomes a “credit score + watchdog” for how companies treat users in their legal terms.**

---

## 2. System Context

### 2.1 Existing Phase 1 Components (Assumed)

Already implemented or in progress:

- **Scraper & Discovery**
  - `PolicyDiscoveryService` finds URLs.
  - `PolicyScraperService` fetches pages and normalizes them (Markdown or cleaned HTML).
- **Versioning**
  - `Policy`, `PolicyVersion` models.
  - Each `PolicyVersion` includes `raw_html`, `normalized_content`, `content_hash`, `fetched_at`, etc.

### 2.2 New AI Layer Components (Phase 2)

New backend components added on top of Phase 1:

- **AI Jobs**
  - `AnalyzePolicyVersionJob`
  - `AnalyzePolicyVersionDiffJob`
- **Services**
  - `LlmClient` + provider-specific implementations (OpenRouter, OpenAI, local, etc.).
  - `PolicyAiAnalysisService`
  - `PolicyDiffAnalysisService`
  - `SuspiciousTimingService`
  - `PolicyScoringService` (rule-based scoring based on AI outputs).
- **Data Models**
  - `PolicyAiAnalysis` (per policy version).
  - `PolicyVersionDiffAnalysis` (per pair of versions).
- **Configuration**
  - LLM provider config, API keys.
  - Scoring config (JSON rulebook).

---

## 3. Data Model Extensions

### 3.1 `policy_ai_analyses` Table

Stores AI-derived information for a **single policy version**.

```php
Schema::create('policy_ai_analyses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('policy_version_id')->constrained()->cascadeOnDelete();

    // Overall
    $table->text('overall_summary');       // high-level summary
    $table->json('faq');                   // array of Q/A entries
    $table->json('risk_flags');            // structured red/yellow/green flags

    // Scoring
    $table->unsignedTinyInteger('score_transparency');      // 0–20
    $table->unsignedTinyInteger('score_user_rights');       // 0–20
    $table->unsignedTinyInteger('score_data_collection');   // 0–20
    $table->unsignedTinyInteger('score_legal_rights');      // 0–20
    $table->unsignedTinyInteger('score_fairness_balance');  // 0–10
    $table->unsignedTinyInteger('score_notifications');     // 0–10

    $table->unsignedTinyInteger('total_score');             // 0–100
    $table->char('grade', 1);                               // 'A'–'F'

    // Raw AI responses for debugging / re-analysis
    $table->json('raw_ai_outputs')->nullable();

    $table->timestamps();
});
```

**FAQ JSON Example:**

```json
[
  {
    "question": "What personal data does this company collect about me?",
    "short_answer": "They collect your name, email, device info, and browsing behavior.",
    "long_answer": "The company collects basic account details (...)",
    "risk_level": 6,
    "what_to_watch_for": "They also track your activity across third-party websites."
  },
  {
    "question": "Can I delete my data?",
    "short_answer": "You can request deletion, but they may retain some data.",
    "long_answer": "The policy allows you to request deletion, however (...)",
    "risk_level": 4,
    "what_to_watch_for": "Deletion might be limited by legal obligations."
  }
]
```

**risk_flags JSON Example:**

```json
{
  "red": [
    {
      "type": "forced_arbitration",
      "description": "You must resolve disputes through binding arbitration...",
      "section_reference": "Section 12",
      "severity": 10
    }
  ],
  "yellow": [
    {
      "type": "vague_data_sharing",
      "description": "They may share data with 'partners' without clear definition...",
      "section_reference": "Section 4",
      "severity": 6
    }
  ],
  "green": [
    {
      "type": "clear_deletion_rights",
      "description": "You can delete your data via account settings...",
      "section_reference": "Section 3",
      "severity": 3
    }
  ]
}
```

---

### 3.2 `policy_version_diff_analyses` Table

Stores AI analysis of **changes between two versions** of the same policy.

```php
Schema::create('policy_version_diff_analyses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('policy_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('old_version_id')->constrained('policy_versions')->cascadeOnDelete();
    $table->foreignUuid('new_version_id')->constrained('policy_versions')->cascadeOnDelete();

    $table->text('human_readable_diff')->nullable();  // optional precomputed diff output
    $table->text('ai_change_summary');                // narrative of what changed
    $table->text('ai_impact_analysis');               // impact on users: rights, data, liability

    $table->integer('impact_score_delta')->nullable();        // e.g. -20, +5 etc.
    $table->json('change_flags')->nullable();                 // structured changes (added arbitration, changed retention, etc.)

    // Suspicious timing
    $table->boolean('is_suspicious_timing')->default(false);
    $table->integer('suspicious_timing_score')->default(0);   // e.g. -10 for shady timing
    $table->json('timing_context')->nullable();               // e.g. "Christmas Eve, 2:30am local time"

    $table->timestamps();
});
```

`impact_score_delta` can later be used to update a **long-term company scorecard**.

---

## 4. LLM Integration Strategy

You’ll be calling LLMs from Laravel. You have two realistic options:

1. **Remote API via a provider** (OpenAI, Anthropic, OpenRouter, etc.).
2. **Local LLM** (e.g. Ollama, LM Studio, vLLM) on your RTX 5070.

You can (and probably should) design AVANT to support **both**, by abstracting the LLM behind a common interface.

### 4.1 Recommendation

- **Short term / MVP:** Use a **hosted LLM provider** (OpenRouter or a direct provider like OpenAI) for:
  - Accuracy
  - Reduced ops complexity
  - Faster iteration
- **Medium term:** Optionally add a **local LLM** for:
  - Cost control on high-volume, lower-risk tasks (e.g. summarizing sections).
  - Keeping sensitive documents on-prem if needed.

### 4.2 Abstraction: LLM Client Interface

Create a simple interface that your Laravel services will use:

```php
namespace App\Services\LLM;

interface LlmClientInterface
{
    /**
     * Sends a prompt and returns the generated text.
     */
    public function complete(array $messages, array $options = []): string;

    /**
     * Optional: streaming support in the future.
     */
    // public function streamComplete(...): \Generator;
}
```

Implement provider-specific classes:

- `OpenRouterLlmClient`
- `OpenAiLlmClient`
- `LocalOllamaLlmClient` (if you decide to add local later)

Bind the interface in a service provider:

```php
public function register()
{
    $this->app->bind(LlmClientInterface::class, function () {
        $provider = config('llm.default');

        return match ($provider) {
            'openrouter' => new OpenRouterLlmClient(),
            'openai'     => new OpenAiLlmClient(),
            'local'      => new LocalOllamaLlmClient(),
            default      => new OpenRouterLlmClient(),
        };
    });
}
```

### 4.3 Example: Calling OpenRouter via Laravel

**Config (`config/llm.php`):**

```php
return [
    'default' => env('LLM_PROVIDER', 'openrouter'),

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => 'https://openrouter.ai/api/v1',
        'model' => env('OPENROUTER_MODEL', 'openrouter/your-preferred-model'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => 'https://api.openai.com/v1',
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    ],

    'local' => [
        'base_url' => env('LOCAL_LLM_URL', 'http://localhost:11434'),
        'model' => env('LOCAL_LLM_MODEL', 'mistral'),
    ],
];
```

**Implementation Sketch (`OpenRouterLlmClient`):**

```php
use Illuminate\Support\Facades\Http;
use App\Services\LLM\LlmClientInterface;

class OpenRouterLlmClient implements LlmClientInterface
{
    public function complete(array $messages, array $options = []): string
    {
        $config = config('llm.openrouter');

        $payload = array_merge([
            'model' => $config['model'],
            'messages' => $messages,
        ], $options);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$config['api_key'],
            'Content-Type'  => 'application/json',
        ])->post($config['base_url'].'/chat/completions', $payload);

        if (!$response->successful()) {
            // Log and throw exception or return fallback
            throw new \RuntimeException('LLM request failed: '.$response->body());
        }

        $data = $response->json();

        // Adjust based on API response format
        return $data['choices'][0]['message']['content'] ?? '';
    }
}
```

**Usage Example:**

```php
$messages = [
    ['role' => 'system', 'content' => 'You are a legal analyst focused on consumer protection.'],
    ['role' => 'user', 'content' => $prompt],
];

$output = $llmClient->complete($messages, [
    'temperature' => 0.2,
    'max_tokens' => 2048,
]);
```

---

### 4.4 Example: Local LLM via HTTP (Optional)

If you later decide to add a local inferencer (Ollama, LM Studio, vLLM, etc.):

```php
class LocalOllamaLlmClient implements LlmClientInterface
{
    public function complete(array $messages, array $options = []): string
    {
        $config = config('llm.local');

        $payload = [
            'model' => $config['model'],
            'messages' => $messages,
            // map $options to local server options as needed
        ];

        $response = Http::post($config['base_url'].'/v1/chat/completions', $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('Local LLM failed: '.$response->body());
        }

        $data = $response->json();

        return $data['choices'][0]['message']['content'] ?? '';
    }
}
```

---

## 5. AI Pipelines (Backend Logic)

Each pipeline is implemented as one or more **Laravel services** + **queued jobs** that call the LLM via `LlmClientInterface`.

### 5.1 Pipeline 1: Policy Version Analysis

**Job:** `AnalyzePolicyVersionJob`  
**Input:** `policy_version_id`  
**Output:** `policy_ai_analyses` row

#### 5.1.1 Flow

1. Load `PolicyVersion` (`normalized_content`, `raw_html`, etc.).
2. If content is large, **chunk by headings** (`#`, `##`, `###`) or by tokens (e.g. ~3–4k tokens per chunk).
3. For each chunk:
   - Call LLM with a structured prompt to:
     - Summarize the chunk in plain English.
     - Extract potential red/yellow/green flags.
4. Aggregate chunk analyses into:
   - `overall_summary` (LLM can re-summarize aggregated chunk summaries).
   - Combined `risk_flags`.
5. Run another LLM call to generate a **global FAQ** about the whole document (or drive this from the aggregated context).
6. Use **`PolicyScoringService`** to:
   - Convert `risk_flags` + structured answers into scores for each dimension.
   - Compute total score and grade.
7. Save `PolicyAiAnalysis`.

#### 5.1.2 Prompt Design – Chunk Analysis

**System message:**

> You are an expert legal analyst focused on consumer protection. You analyze Terms of Service, Privacy Policies, and similar documents. You explain them in clear, simple language and flag anything that might be risky or unfair for ordinary users.

**User message template:**

- Provide:
  - chunk text
  - requested output JSON schema

Ask for a **strict JSON response** (no extra commentary) to make parsing easy.

Example:

```text
Analyze the following section of a legal document.

1. Summarize it in plain English (6th grade level).
2. Identify any clauses that may be:
   - risky or unfair to users (red flags),
   - somewhat concerning (yellow flags),
   - particularly protective or fair (green flags).
3. For each flag, explain why it matters in one or two sentences.

Return your answer in the following JSON format:

{
  "plain_summary": "...",
  "flags": {
    "red": [ { "type": "...", "description": "...", "severity": 1-10 } ],
    "yellow": [ ... ],
    "green": [ ... ]
  }
}

Here is the section:

<<<SECTION_START>>>
{{chunk_text}}
<<<SECTION_END>>>
```

### 5.2 Pipeline 2: FAQ Generation

Later, you may run FAQ generation in the same job after chunk analysis, or as a separate LLM call.

#### 5.2.1 Input

- `normalized_content` (full)
- Optional: aggregated chunk summaries
- Optional: risk flags

#### 5.2.2 Output

JSON array matching the `faq` schema:

```json
[
  {
    "question": "...",
    "short_answer": "...",
    "long_answer": "...",
    "risk_level": 0-10,
    "what_to_watch_for": "..."
  }
]
```

#### 5.2.3 Prompt Snippet

Provide model with:

- “You are creating a FAQ for non-lawyers”
- Example questions to always consider
- Instruction to add/remove questions based on document content

---

### 5.3 Pipeline 3: Scoring & Grading (PolicyScoringService)

This service **does not have to use AI**. It should be mostly rule-based, driven by a configuration file.

#### 5.3.1 Configuration (`config/policy_scoring.php` or JSON in storage)

Example (abbreviated):

```php
return [
    'weights' => [
        'transparency'       => 20,
        'user_rights'        => 20,
        'data_collection'    => 20,
        'legal_rights'       => 20,
        'fairness_balance'   => 10,
        'notifications'      => 10,
    ],

    'flag_penalties' => [
        'forced_arbitration'        => ['legal_rights' => -20],
        'class_action_waiver'       => ['legal_rights' => -15],
        'sell_data'                 => ['data_collection' => -25],
        'share_with_advertisers'    => ['data_collection' => -10],
        'vague_data_sharing'        => ['transparency' => -5],
        'clear_deletion_rights'     => ['user_rights' => +10],
        'plain_language'            => ['transparency' => +10],
    ],
];
```

#### 5.3.2 Scoring Algorithm Sketch

1. Start each dimension at max (or mid) value.
2. For each flag:
   - Look up `type` in `flag_penalties`.
   - Add or subtract from relevant dimensions.
3. Clamp each dimension to [0, weight].
4. Sum dimensions → `total_score` (0–100).
5. Map `total_score` to grade:

```php
if ($score >= 90) $grade = 'A';
elseif ($score >= 80) $grade = 'B';
elseif ($score >= 70) $grade = 'C';
elseif ($score >= 60) $grade = 'D';
else $grade = 'F';
```

---

### 5.4 Pipeline 4: Version Diff Analysis

**Job:** `AnalyzePolicyVersionDiffJob`  
**Input:** `old_version_id`, `new_version_id`  
**Output:** `policy_version_diff_analyses` row

#### 5.4.1 Steps

1. Load both `PolicyVersion` records.
2. Compute a **textual diff** between `normalized_content` fields.
   - Use `sebastian/diff` or similar to produce a **unified diff** or side-by-side diff.
3. Send the diff + key metadata to the LLM:
   - Ask it to:
     - Summarize what changed.
     - Identify changes that increase or decrease user risk.
     - Identify new clauses (e.g., newly added arbitration).
4. Map the AI output to:
   - `ai_change_summary`
   - `ai_impact_analysis`
   - structured `change_flags` JSON and `impact_score_delta`.
5. Run `SuspiciousTimingService` to evaluate timing.

#### 5.4.2 Example `change_flags` Schema

```json
{
  "new_clauses": [
    {
      "type": "forced_arbitration",
      "description": "Added mandatory arbitration for all disputes.",
      "severity": 10
    }
  ],
  "removed_clauses": [
    {
      "type": "data_deletion_right",
      "description": "Removed explicit right to delete data.",
      "severity": 8
    }
  ],
  "neutral_changes": [
    {
      "type": "clarification",
      "description": "Clarified cookie list without changing behavior."
    }
  ]
}
```

---

### 5.5 Pipeline 5: Suspicious Timing Service

**Service:** `SuspiciousTimingService`  
**Input:** `PolicyVersion` timestamps (`fetched_at`, `created_at` or `effective_date` if available), company timezone, global holiday calendar.  
**Output:** `is_suspicious_timing`, `suspicious_timing_score`, `timing_context`.

#### 5.5.1 Rules

- **Suspicious if:**
  - Published between **10pm–6am local time**.
  - Published on:
    - Major public holidays (Christmas, New Year’s, etc.).
    - Weekends, especially late night/early morning.
  - Published just before major regulatory changes or known deadlines (future extension).

#### 5.5.2 Example Scoring

- Base 0 (neutral)
- If nighttime: −5
- If weekend: −5
- If holiday: −10
- If impact_score_delta is significantly negative and timing is suspicious → additional −10

Set:

- `is_suspicious_timing = true` if total timing penalties < 0.
- `suspicious_timing_score = total timing penalties`.

Store explanation in `timing_context`:

```json
{
  "local_time": "2025-12-24T02:34:00",
  "weekday": "Wednesday",
  "flags": [
    "nighttime",
    "christmas_eve"
  ],
  "notes": "High-risk changes deployed when users unlikely to notice."
}
```

---

## 6. Job Wiring & Execution Flow

### 6.1 When a New Policy Version is Created

From Phase 1:

- After `PolicyVersion` is stored (hash changed), dispatch analysis job:

```php
AnalyzePolicyVersionJob::dispatch($policyVersion)->onQueue('ai');
```

### 6.2 When Multiple Versions Exist

- Option 1: Whenever a new version is created and there exists an old version, trigger diff analysis:

```php
$previous = $policy->versions()
    ->where('id', '!=', $policyVersion->id)
    ->orderByDesc('version_number')
    ->first();

if ($previous) {
    AnalyzePolicyVersionDiffJob::dispatch($policy, $previous, $policyVersion)->onQueue('ai');
}
```

- Option 2: Only compute diffs on-demand (e.g. when a user asks), by calling the job or service synchronously.

### 6.3 Queue & Rate Limiting

- Use a dedicated **`ai` queue** with workers tuned for LLM latency.
- Implement **retry with backoff** for transient failures.
- Use **rate limiting** if LLM provider has strict qps:

```php
// Example: use Laravel's rate limiter or a Redis-based lock
```

---

## 7. Running AI Locally vs Remote – Tradeoffs

### 7.1 Remote API (OpenRouter / OpenAI / Others)

**Pros:**
- Best-in-class models, high quality for complex legal reasoning.
- No infra management on your side.
- Scales easily with your queue.
- Good logs and monitoring.

**Cons:**
- Ongoing costs (per-token billing).
- Legal docs may be sensitive; you need to read provider’s data usage policies.
- Latency and rate limiting under heavy load.

**When to favor this:**  
- MVP, early stages, when correctness is more important than cost.  
- Legal nuance and consumer protection require high-quality reasoning.

---

### 7.2 Local LLM on RTX 5070

**Pros:**
- Potentially lower marginal cost per request after initial setup.
- Data stays on your hardware.
- You can fine-tune / control models closely.

**Cons:**
- Setup and maintenance overhead (model downloads, GPU drivers, runtime).
- You must manage concurrency, performance, OOM issues, etc.
- Most local models still lag top-tier APIs on nuanced legal reasoning.
- Your GPU may be busy or overloaded under high load.

**When to favor this:**
- As a **secondary tier**:
  - Use local models for:
    - Basic summarization
    - Preprocessing
    - Non-critical tasks
  - Use cloud models for:
    - Flagging legal risks
    - Grading & scoring
    - Version-diff reasoning

---

### 7.3 Hybrid Strategy (Recommended)

1. **Define task tiers:**
   - **Tier 1 (High-stakes reasoning):** risk flags, scoring, legal-rights interpretation → use **remote LLM**.
   - **Tier 2 (Lower-stakes summarization):** chunk-level summarization, basic FAQ suggestions → can be offloaded to **local LLM** if you want to save API costs later.

2. Implement two LLM clients:
   - `HighQualityLlmClient` (maps to OpenRouter/OpenAI).
   - `CheapLlmClient` (maps to local or cheaper API model).

3. Make pipeline services accept both, e.g.:

```php
public function __construct(
    private HighQualityLlmClientInterface $hqLlm,
    private CheapLlmClientInterface $cheapLlm,
) {}
```

4. Start with **only HighQuality** (simpler). Introduce Cheap later.

---

## 8. Logging, Auditing, and Reproducibility

- Log:
  - Prompts (or anonymized prompts if necessary).
  - Model IDs and provider.
  - Response IDs (if provided).
  - Timestamps and latency.
- Store `raw_ai_outputs` for each analysis to:
  - Debug.
  - Potentially re-score if scoring rules change.
- Make it easy to re-run analysis later (e.g. if you switch models).

---

## 9. Security & Compliance Considerations

- Treat all scraped legal docs as **public** but still:
  - Use HTTPS for all LLM calls.
  - Do not send user PII in prompts.
- If using remote APIs:
  - Verify data retention and training usage policies.
  - Prefer “no training / zero retention” modes where available.
- For local LLM:
  - Limit network access from the inference server.
  - Monitor GPU load to prevent outages.

---

## 10. Summary of Implementation Steps

1. **Add DB tables:**
   - `policy_ai_analyses`
   - `policy_version_diff_analyses`
2. **Create LLM abstraction:**
   - `LlmClientInterface`, `OpenRouterLlmClient`, (optional) `LocalOllamaLlmClient`.
3. **Create services:**
   - `PolicyAiAnalysisService` (wraps chunking + calls to LLM).
   - `PolicyDiffAnalysisService`.
   - `PolicyScoringService` (rule-based).
   - `SuspiciousTimingService`.
4. **Create jobs:**
   - `AnalyzePolicyVersionJob`.
   - `AnalyzePolicyVersionDiffJob`.
5. **Wire jobs into Phase 1:**
   - Dispatch analysis job when a new `PolicyVersion` is created.
   - Dispatch diff job when there is a previous version.
6. **Set up provider config:**
   - `.env` + `config/llm.php` for `OPENROUTER_API_KEY`, `OPENAI_API_KEY`, etc.
7. **Gradually refine:**
   - Add more sophisticated scoring rules.
   - Tune prompts.
   - Optionally add local LLM for certain tasks.

---

This spec should be sufficient to:

- Instruct AI coding agents.
- Onboard devs into the AI-analysis layer.
- Decide how to integrate with OpenRouter/OpenAI/local LLMs.
- Iterate toward AVANT as a **full-fledged consumer-protection engine** without UI details.

