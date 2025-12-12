# AVANT AI Analysis Implementation Plan

## Overview
Implement AI-powered legal document analysis for AVANT: translate legalese, generate FAQs, flag risky clauses, assign grades (A-F), compare versions, and detect suspicious timing.

**Progress Tracking**: `.claude/.docs/ai-analysis-implementation-progress.md`
**Spec Reference**: `.claude/.docs/avant-ai-analysis-spec.md`

---

## Stage 1: LLM Infrastructure (Foundation)

### 1.1 Create LLM Configuration
**File**: `config/llm.php`
```php
return [
    'default' => env('LLM_PROVIDER', 'openrouter'),
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => 'https://openrouter.ai/api/v1',
        'model' => env('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet'),
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => 'https://api.openai.com/v1',
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],
    'settings' => [
        'timeout' => env('LLM_TIMEOUT', 120),
        'max_tokens' => env('LLM_MAX_TOKENS', 4096),
        'temperature' => env('LLM_TEMPERATURE', 0.2),
    ],
];
```

### 1.2 Create LLM Client Interface
**File**: `app/Services/LLM/LlmClientInterface.php`
- `complete(array $messages, array $options = []): string`
- `completeJson(array $messages, array $options = []): array` (auto-parse JSON)

### 1.3 Implement Provider Clients
**Files**:
- `app/Services/LLM/OpenRouterLlmClient.php`
- `app/Services/LLM/OpenAiLlmClient.php`
- `app/Services/LLM/AbstractLlmClient.php` (shared logic: logging, retries)

### 1.4 Create Service Provider
**File**: `app/Providers/LlmServiceProvider.php`
- Bind interface to configured provider
- Register in `config/app.php`

### 1.5 Add Environment Variables
**File**: `.env.example`
```env
LLM_PROVIDER=openrouter
OPENROUTER_API_KEY=
OPENROUTER_MODEL=anthropic/claude-3.5-sonnet
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o
LLM_TIMEOUT=120
LLM_MAX_TOKENS=4096
LLM_TEMPERATURE=0.2
```

---

## Stage 2: Scoring System

### 2.1 Create Scoring Configuration
**File**: `config/policy_scoring.php`
```php
return [
    'weights' => [
        'transparency' => 20,
        'user_rights' => 20,
        'data_collection' => 20,
        'legal_rights' => 20,
        'fairness_balance' => 10,
        'notifications' => 10,
    ],
    'flag_penalties' => [
        'forced_arbitration' => ['legal_rights' => -20],
        'class_action_waiver' => ['legal_rights' => -15],
        'sell_data' => ['data_collection' => -25],
        // ... more flags
    ],
    'grade_thresholds' => [
        'A' => 90, 'B' => 80, 'C' => 70, 'D' => 60,
    ],
];
```

### 2.2 Create PolicyScoringService
**File**: `app/Services/AI/PolicyScoringService.php`
- `calculateScores(array $flags): array` - dimension scores
- `calculateTotalScore(array $dimensionScores): int`
- `calculateGrade(int $totalScore): string`

### 2.3 Seed Default Scoring Criteria
**File**: `database/seeders/ScoringCriteriaSeeder.php`
- Populate `scoring_criteria` table with evaluation prompts

---

## Stage 3: Document Analysis Pipeline

### 3.1 Create PolicyAiAnalysisService
**File**: `app/Services/AI/PolicyAiAnalysisService.php`

**Methods**:
- `analyze(DocumentVersion $version): AnalysisResult`
- `chunkContent(string $content): array` - split by headings (~3-4k tokens)
- `analyzeChunk(string $chunk): array` - LLM call for flags/summary
- `aggregateResults(array $chunkResults): array`
- `generateFaq(DocumentVersion $version, array $context): array`

**Chunk Analysis Prompt** (per spec section 5.1.2):
```
Analyze the following section of a legal document.
1. Summarize it in plain English (6th grade level).
2. Identify any clauses that may be:
   - risky or unfair to users (red flags),
   - somewhat concerning (yellow flags),
   - particularly protective or fair (green flags).
Return JSON format: { "plain_summary": "...", "flags": { "red": [...], "yellow": [...], "green": [...] } }
```

### 3.2 Create AnalyzeDocumentVersionJob
**File**: `app/Jobs/AnalyzeDocumentVersionJob.php`
- Queue: 'ai'
- Timeout: 300s (5 min for large docs)
- Tries: 2
- Calls PolicyAiAnalysisService
- Saves to AnalysisResult model

### 3.3 Wire to Version Creation
**File**: `app/Services/Scraper/VersioningService.php`
- In `createVersion()`: dispatch `AnalyzeDocumentVersionJob` after version saved

---

## Stage 4: FAQ Generation

### 4.1 Add FAQ to Analysis Service
**File**: `app/Services/AI/PolicyAiAnalysisService.php`

**FAQ Prompt**:
```
Generate 5-8 FAQs for non-lawyers about this legal document.
For each FAQ include: question, short_answer, long_answer, risk_level (0-10), what_to_watch_for.
Always include questions about: data collection, data sharing, deletion rights, dispute resolution.
```

### 4.2 Update AnalysisResult Model (if needed)
**Migration**: Add `faq` JSON column if not present

---

## Stage 5: Version Diff Analysis

### 5.1 Create PolicyDiffAnalysisService
**File**: `app/Services/AI/PolicyDiffAnalysisService.php`

**Methods**:
- `analyzeDiff(DocumentVersion $old, DocumentVersion $new): array`
- `calculateImpactDelta(array $changeFlags): int`

**Diff Analysis Prompt**:
```
Compare these two versions of a legal document.
Identify: new_clauses, removed_clauses, modified_clauses, neutral_changes.
For each change: type, description, severity (1-10), impact on users.
Summarize if changes are positive or negative for users overall.
```

### 5.2 Create AnalyzeVersionDiffJob
**File**: `app/Jobs/AnalyzeVersionDiffJob.php`
- Dispatched after CompareVersionsJob
- Updates VersionComparison with AI analysis

### 5.3 Update VersionComparison Model
**Migration** (if needed): Add columns:
- `ai_change_summary` text
- `ai_impact_analysis` text
- `impact_score_delta` integer
- `change_flags` JSON
- `is_suspicious_timing` boolean
- `suspicious_timing_score` integer
- `timing_context` JSON

---

## Stage 6: Suspicious Timing Detection

### 6.1 Create SuspiciousTimingService
**File**: `app/Services/AI/SuspiciousTimingService.php`

**Methods**:
- `evaluate(DocumentVersion $version): array`
- `isNighttime(Carbon $time): bool` (10pm-6am)
- `isWeekend(Carbon $time): bool`
- `isHoliday(Carbon $time): bool`
- `calculateScore(array $flags, ?int $impactDelta): int`

**Scoring Rules**:
- Nighttime: -5
- Weekend: -5
- Holiday: -10
- Negative impact + suspicious timing: additional -10

### 6.2 Create Holiday Configuration
**File**: `config/holidays.php` or `storage/app/holidays.json`
- Major US holidays (Christmas, New Year, Thanksgiving, etc.)

### 6.3 Integrate with Diff Analysis
- Call SuspiciousTimingService from PolicyDiffAnalysisService
- Store results in VersionComparison

---

## Stage 7: Queue Configuration

### 7.1 Add AI Queue
**File**: `config/queue.php`
- Add 'ai' queue for LLM jobs

**File**: `routes/console.php`
- Schedule AI queue worker if needed

### 7.2 Update QueueController
**File**: `app/Http/Controllers/QueueController.php`
- Start AI queue alongside scraping queue
- Add AI job stats to dashboard

---

## Files Summary

### New Files
| File | Stage |
|------|-------|
| `config/llm.php` | 1 |
| `config/policy_scoring.php` | 2 |
| `config/holidays.php` | 6 |
| `app/Services/LLM/LlmClientInterface.php` | 1 |
| `app/Services/LLM/AbstractLlmClient.php` | 1 |
| `app/Services/LLM/OpenRouterLlmClient.php` | 1 |
| `app/Services/LLM/OpenAiLlmClient.php` | 1 |
| `app/Providers/LlmServiceProvider.php` | 1 |
| `app/Services/AI/PolicyScoringService.php` | 2 |
| `app/Services/AI/PolicyAiAnalysisService.php` | 3 |
| `app/Services/AI/PolicyDiffAnalysisService.php` | 5 |
| `app/Services/AI/SuspiciousTimingService.php` | 6 |
| `app/Jobs/AnalyzeDocumentVersionJob.php` | 3 |
| `app/Jobs/AnalyzeVersionDiffJob.php` | 5 |
| `database/seeders/ScoringCriteriaSeeder.php` | 2 |

### Modified Files
| File | Stage |
|------|-------|
| `.env.example` | 1 |
| `config/app.php` | 1 |
| `app/Services/Scraper/VersioningService.php` | 3 |
| `app/Jobs/CompareVersionsJob.php` | 5 |
| `app/Models/VersionComparison.php` | 5 |
| `app/Http/Controllers/QueueController.php` | 7 |

---

## Implementation Order

1. **Stage 1** - LLM clients (required for everything else)
2. **Stage 2** - Scoring system (needed for analysis)
3. **Stage 3** - Document analysis (core feature)
4. **Stage 4** - FAQ generation (extends Stage 3)
5. **Stage 5** - Diff analysis (builds on Stage 3)
6. **Stage 6** - Suspicious timing (extends Stage 5)
7. **Stage 7** - Queue management (polish)

Each stage is independently testable. Start with Stage 1.
