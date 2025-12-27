<?php

namespace App\Services\AI;

use App\Models\Document;
use App\Models\DocumentVersion;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Analyzes behavioral patterns in policy updates that may indicate
 * manipulative or deceptive practices.
 *
 * Red flags include:
 * - Updates timed around holidays when users are distracted
 * - Rapid succession of changes (confusion tactic)
 * - Weekend/late night updates (avoiding attention)
 * - Large changes with no notice period
 */
class BehavioralSignalsService
{
    /**
     * Major holidays - highest penalty (users most distracted)
     * Format: [month, day, name, days_before, days_after]
     */
    protected const MAJOR_HOLIDAYS = [
        // US Thanksgiving (4th Thursday of November) - handled dynamically
        'thanksgiving' => ['name' => 'Thanksgiving', 'days_before' => 2, 'days_after' => 4],
        'black_friday' => ['name' => 'Black Friday', 'days_before' => 0, 'days_after' => 3],
        'christmas_eve' => ['month' => 12, 'day' => 24, 'name' => 'Christmas Eve', 'days_before' => 3, 'days_after' => 0],
        'christmas' => ['month' => 12, 'day' => 25, 'name' => 'Christmas Day', 'days_before' => 0, 'days_after' => 2],
        'new_years_eve' => ['month' => 12, 'day' => 31, 'name' => "New Year's Eve", 'days_before' => 2, 'days_after' => 0],
        'new_years_day' => ['month' => 1, 'day' => 1, 'name' => "New Year's Day", 'days_before' => 0, 'days_after' => 2],
    ];

    /**
     * Minor holidays - moderate penalty
     */
    protected const MINOR_HOLIDAYS = [
        'mlk_day' => ['name' => 'MLK Day', 'days_before' => 1, 'days_after' => 1], // 3rd Monday of January
        'presidents_day' => ['name' => "Presidents' Day", 'days_before' => 1, 'days_after' => 1], // 3rd Monday of February
        'easter' => ['name' => 'Easter', 'days_before' => 2, 'days_after' => 1], // Calculated
        'memorial_day' => ['name' => 'Memorial Day', 'days_before' => 2, 'days_after' => 1], // Last Monday of May
        'independence_day' => ['month' => 7, 'day' => 4, 'name' => 'Independence Day', 'days_before' => 2, 'days_after' => 1],
        'labor_day' => ['name' => 'Labor Day', 'days_before' => 2, 'days_after' => 1], // 1st Monday of September
        'veterans_day' => ['month' => 11, 'day' => 11, 'name' => 'Veterans Day', 'days_before' => 1, 'days_after' => 1],
        'super_bowl' => ['name' => 'Super Bowl Sunday', 'days_before' => 1, 'days_after' => 1], // Calculated
    ];

    /**
     * Signal severity levels and their score penalties.
     */
    protected const SIGNAL_PENALTIES = [
        'major_holiday_update' => -15,      // Published during major holiday
        'minor_holiday_update' => -8,       // Published during minor holiday
        'holiday_weekend_update' => -10,    // Published during holiday weekend
        'weekend_update' => -3,             // Published on weekend
        'late_night_update' => -5,          // Published outside business hours
        'rapid_changes' => -12,             // 3+ changes within 90 days
        'frequent_changes' => -6,           // 2+ changes within 30 days
        'stealth_update' => -10,            // Large change with minimal notice
        'friday_afternoon_drop' => -7,      // Classic news dump timing
        'suspicious_pattern' => -8,         // Multiple timing red flags
    ];

    /**
     * Analyze a document version for behavioral signals.
     */
    public function analyzeVersion(DocumentVersion $version): array
    {
        $signals = [];
        $updateDate = $this->getEffectiveDate($version);

        if (!$updateDate) {
            return ['signals' => [], 'penalty' => 0, 'risk_score' => 0];
        }

        $carbon = $updateDate;

        // Check holiday timing
        $holidaySignal = $this->checkHolidayTiming($carbon);
        if ($holidaySignal) {
            $signals[] = $holidaySignal;
        }

        // Check weekend timing
        $weekendSignal = $this->checkWeekendTiming($carbon);
        if ($weekendSignal) {
            $signals[] = $weekendSignal;
        }

        // Check late night timing
        $lateNightSignal = $this->checkLateNightTiming($carbon);
        if ($lateNightSignal) {
            $signals[] = $lateNightSignal;
        }

        // Check Friday afternoon drop
        $fridaySignal = $this->checkFridayAfternoonDrop($carbon);
        if ($fridaySignal) {
            $signals[] = $fridaySignal;
        }

        // Load document for change frequency analysis
        $document = $version->document;
        if ($document) {
            // Check update frequency
            $frequencySignals = $this->analyzeUpdateFrequency($document, $version);
            $signals = array_merge($signals, $frequencySignals);
        }

        // Calculate total penalty and risk score
        $totalPenalty = array_sum(array_column($signals, 'penalty'));
        $riskScore = $this->calculateRiskScore($signals);

        // Check for suspicious patterns (multiple red flags)
        if (count($signals) >= 3) {
            $signals[] = [
                'type' => 'suspicious_pattern',
                'severity' => 'high',
                'penalty' => self::SIGNAL_PENALTIES['suspicious_pattern'],
                'description' => 'Multiple timing red flags detected - this update shows signs of deliberate timing to avoid user attention.',
                'details' => 'Found ' . count($signals) . ' timing signals.',
            ];
            $totalPenalty += self::SIGNAL_PENALTIES['suspicious_pattern'];
        }

        return [
            'signals' => $signals,
            'penalty' => $totalPenalty,
            'risk_score' => $riskScore,
            'update_date' => $carbon->toISOString(),
            'summary' => $this->generateSignalSummary($signals),
        ];
    }

    /**
     * Analyze a document's entire update history for patterns.
     */
    public function analyzeDocumentHistory(Document $document): array
    {
        $versions = $document->versions()->orderBy('scraped_at', 'desc')->get();

        if ($versions->count() < 2) {
            return [
                'signals' => [],
                'version_analyses' => [],
                'overall_risk' => 'low',
                'pattern_summary' => 'Insufficient version history for pattern analysis.',
            ];
        }

        $versionAnalyses = [];
        $allSignals = [];

        foreach ($versions as $version) {
            $analysis = $this->analyzeVersion($version);
            $versionAnalyses[$version->id] = $analysis;
            $allSignals = array_merge($allSignals, $analysis['signals']);
        }

        // Look for patterns across versions
        $patternSignals = $this->detectHistoricalPatterns($versions, $versionAnalyses);

        return [
            'signals' => $patternSignals,
            'version_analyses' => $versionAnalyses,
            'overall_risk' => $this->calculateOverallRisk($allSignals, $patternSignals),
            'pattern_summary' => $this->generatePatternSummary($patternSignals, $versions->count()),
        ];
    }

    /**
     * Check if update date falls near a holiday.
     */
    protected function checkHolidayTiming(Carbon $date): ?array
    {
        $year = $date->year;
        // Normalize to start of day for date-only comparison
        $dateOnly = $date->copy()->startOfDay();

        // Check major holidays
        foreach (self::MAJOR_HOLIDAYS as $key => $holiday) {
            $holidayDate = $this->getHolidayDate($key, $year);
            if (!$holidayDate) continue;

            $holidayDateOnly = $holidayDate->copy()->startOfDay();
            $daysBefore = $holiday['days_before'] ?? 2;
            $daysAfter = $holiday['days_after'] ?? 2;

            $rangeStart = $holidayDateOnly->copy()->subDays($daysBefore);
            $rangeEnd = $holidayDateOnly->copy()->addDays($daysAfter);

            if ($dateOnly->between($rangeStart, $rangeEnd)) {
                $daysFromHoliday = (int) $dateOnly->diffInDays($holidayDateOnly, false);
                $timing = $this->formatDaysTiming($daysFromHoliday);

                return [
                    'type' => 'major_holiday_update',
                    'severity' => 'critical',
                    'penalty' => self::SIGNAL_PENALTIES['major_holiday_update'],
                    'holiday' => $holiday['name'],
                    'timing' => $timing,
                    'description' => "Policy updated {$timing} {$holiday['name']} - a time when users are most distracted.",
                    'details' => "Updates during major holidays like {$holiday['name']} are concerning because users are less likely to notice and review changes.",
                ];
            }
        }

        // Check minor holidays
        foreach (self::MINOR_HOLIDAYS as $key => $holiday) {
            $holidayDate = $this->getHolidayDate($key, $year);
            if (!$holidayDate) continue;

            $holidayDateOnly = $holidayDate->copy()->startOfDay();
            $daysBefore = $holiday['days_before'] ?? 1;
            $daysAfter = $holiday['days_after'] ?? 1;

            $rangeStart = $holidayDateOnly->copy()->subDays($daysBefore);
            $rangeEnd = $holidayDateOnly->copy()->addDays($daysAfter);

            if ($dateOnly->between($rangeStart, $rangeEnd)) {
                $daysFromHoliday = (int) $dateOnly->diffInDays($holidayDateOnly, false);
                $timing = $this->formatDaysTiming($daysFromHoliday);

                return [
                    'type' => 'minor_holiday_update',
                    'severity' => 'high',
                    'penalty' => self::SIGNAL_PENALTIES['minor_holiday_update'],
                    'holiday' => $holiday['name'],
                    'timing' => $timing,
                    'description' => "Policy updated {$timing} {$holiday['name']}.",
                    'details' => "Holiday-adjacent updates may be timed to reduce user attention.",
                ];
            }
        }

        return null;
    }

    /**
     * Format days difference into human-readable timing string.
     */
    protected function formatDaysTiming(int $days): string
    {
        if ($days === 0) {
            return 'on';
        }

        if ($days < 0) {
            $absDays = abs($days);
            return $absDays === 1 ? '1 day before' : "{$absDays} days before";
        }

        return $days === 1 ? '1 day after' : "{$days} days after";
    }

    /**
     * Get the actual date for a holiday in a given year.
     */
    protected function getHolidayDate(string $key, int $year): ?Carbon
    {
        // Fixed-date holidays
        $fixedHolidays = [
            'christmas_eve' => Carbon::create($year, 12, 24),
            'christmas' => Carbon::create($year, 12, 25),
            'new_years_eve' => Carbon::create($year, 12, 31),
            'new_years_day' => Carbon::create($year, 1, 1),
            'independence_day' => Carbon::create($year, 7, 4),
            'veterans_day' => Carbon::create($year, 11, 11),
        ];

        if (isset($fixedHolidays[$key])) {
            return $fixedHolidays[$key];
        }

        // Calculated holidays
        return match ($key) {
            'thanksgiving' => $this->getNthWeekdayOfMonth($year, 11, Carbon::THURSDAY, 4),
            'black_friday' => $this->getNthWeekdayOfMonth($year, 11, Carbon::THURSDAY, 4)?->addDay(),
            'mlk_day' => $this->getNthWeekdayOfMonth($year, 1, Carbon::MONDAY, 3),
            'presidents_day' => $this->getNthWeekdayOfMonth($year, 2, Carbon::MONDAY, 3),
            'memorial_day' => $this->getLastWeekdayOfMonth($year, 5, Carbon::MONDAY),
            'labor_day' => $this->getNthWeekdayOfMonth($year, 9, Carbon::MONDAY, 1),
            'easter' => $this->calculateEaster($year),
            'super_bowl' => $this->calculateSuperBowlSunday($year),
            default => null,
        };
    }

    /**
     * Get the Nth weekday of a month (e.g., 4th Thursday of November).
     */
    protected function getNthWeekdayOfMonth(int $year, int $month, int $dayOfWeek, int $n): ?Carbon
    {
        $date = Carbon::create($year, $month, 1);
        $count = 0;

        while ($date->month === $month) {
            if ($date->dayOfWeek === $dayOfWeek) {
                $count++;
                if ($count === $n) {
                    return $date->copy();
                }
            }
            $date->addDay();
        }

        return null;
    }

    /**
     * Get the last weekday of a month (e.g., last Monday of May).
     */
    protected function getLastWeekdayOfMonth(int $year, int $month, int $dayOfWeek): Carbon
    {
        $date = Carbon::create($year, $month, 1)->endOfMonth();

        while ($date->dayOfWeek !== $dayOfWeek) {
            $date->subDay();
        }

        return $date;
    }

    /**
     * Calculate Easter Sunday using the Anonymous Gregorian algorithm.
     */
    protected function calculateEaster(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day);
    }

    /**
     * Calculate Super Bowl Sunday (typically first Sunday of February).
     */
    protected function calculateSuperBowlSunday(int $year): Carbon
    {
        // Super Bowl is typically the second Sunday of February
        return $this->getNthWeekdayOfMonth($year, 2, Carbon::SUNDAY, 2) ?? Carbon::create($year, 2, 12);
    }

    /**
     * Check if update was on a weekend.
     */
    protected function checkWeekendTiming(Carbon $date): ?array
    {
        if ($date->isWeekend()) {
            return [
                'type' => 'weekend_update',
                'severity' => 'low',
                'penalty' => self::SIGNAL_PENALTIES['weekend_update'],
                'day' => $date->format('l'),
                'description' => "Policy updated on {$date->format('l')} - weekend updates may be timed for lower visibility.",
                'details' => 'Fewer users monitor policy changes on weekends.',
            ];
        }

        return null;
    }

    /**
     * Check if update was during late night hours.
     */
    protected function checkLateNightTiming(Carbon $date): ?array
    {
        $hour = $date->hour;

        // Late night: before 6am or after 10pm
        if ($hour < 6 || $hour >= 22) {
            $timeDesc = $hour < 6 ? 'early morning' : 'late night';

            return [
                'type' => 'late_night_update',
                'severity' => 'medium',
                'penalty' => self::SIGNAL_PENALTIES['late_night_update'],
                'hour' => $hour,
                'description' => "Policy updated at {$date->format('g:i A')} ({$timeDesc}) - off-hours updates attract less attention.",
                'details' => 'Updates outside normal business hours may be intentionally timed for reduced visibility.',
            ];
        }

        return null;
    }

    /**
     * Check for "Friday afternoon news dump" pattern.
     */
    protected function checkFridayAfternoonDrop(Carbon $date): ?array
    {
        if ($date->isFriday() && $date->hour >= 15) {
            return [
                'type' => 'friday_afternoon_drop',
                'severity' => 'medium',
                'penalty' => self::SIGNAL_PENALTIES['friday_afternoon_drop'],
                'description' => 'Policy updated Friday afternoon - the classic "news dump" timing for unfavorable announcements.',
                'details' => 'Friday afternoon is traditionally when organizations release news they want to minimize attention to.',
            ];
        }

        return null;
    }

    /**
     * Analyze update frequency for the document.
     */
    protected function analyzeUpdateFrequency(Document $document, DocumentVersion $currentVersion): array
    {
        $signals = [];
        $currentDate = $currentVersion->scraped_at ?? $currentVersion->created_at;

        if (!$currentDate) {
            return $signals;
        }

        $currentCarbon = Carbon::parse($currentDate);

        // Get versions from last 90 days
        $recentVersions = $document->versions()
            ->where('id', '!=', $currentVersion->id)
            ->where('scraped_at', '>=', $currentCarbon->copy()->subDays(90))
            ->count();

        // Rapid changes: 3+ in 90 days
        if ($recentVersions >= 2) { // 2 others + current = 3 total
            $signals[] = [
                'type' => 'rapid_changes',
                'severity' => 'high',
                'penalty' => self::SIGNAL_PENALTIES['rapid_changes'],
                'count' => $recentVersions + 1,
                'period' => '90 days',
                'description' => ($recentVersions + 1) . ' policy changes in the last 90 days - rapid changes can confuse users and obscure important modifications.',
                'details' => 'Frequent updates make it difficult for users to track what has changed.',
            ];
        }

        // Frequent changes: 2+ in 30 days
        $thirtyDayVersions = $document->versions()
            ->where('id', '!=', $currentVersion->id)
            ->where('scraped_at', '>=', $currentCarbon->copy()->subDays(30))
            ->count();

        if ($thirtyDayVersions >= 1 && $recentVersions < 2) { // Only if not already flagged for rapid
            $signals[] = [
                'type' => 'frequent_changes',
                'severity' => 'medium',
                'penalty' => self::SIGNAL_PENALTIES['frequent_changes'],
                'count' => $thirtyDayVersions + 1,
                'period' => '30 days',
                'description' => ($thirtyDayVersions + 1) . ' policy changes in the last 30 days.',
                'details' => 'Multiple updates in a short period warrants close attention.',
            ];
        }

        return $signals;
    }

    /**
     * Detect patterns across document history.
     */
    protected function detectHistoricalPatterns(Collection $versions, array $versionAnalyses): array
    {
        $patterns = [];

        // Count how many versions had holiday timing
        $holidayUpdates = 0;
        $weekendUpdates = 0;
        $lateNightUpdates = 0;

        foreach ($versionAnalyses as $analysis) {
            foreach ($analysis['signals'] as $signal) {
                match ($signal['type']) {
                    'major_holiday_update', 'minor_holiday_update' => $holidayUpdates++,
                    'weekend_update' => $weekendUpdates++,
                    'late_night_update' => $lateNightUpdates++,
                    default => null,
                };
            }
        }

        $totalVersions = $versions->count();

        // Pattern: Habitual holiday updates
        if ($holidayUpdates >= 2 || ($holidayUpdates > 0 && $holidayUpdates / $totalVersions > 0.3)) {
            $patterns[] = [
                'type' => 'habitual_holiday_timing',
                'severity' => 'critical',
                'description' => "Pattern detected: {$holidayUpdates} of {$totalVersions} updates were near holidays.",
                'implication' => 'This company appears to habitually time policy changes around holidays.',
            ];
        }

        // Pattern: Habitual weekend updates
        if ($weekendUpdates >= 3 || ($weekendUpdates > 0 && $weekendUpdates / $totalVersions > 0.5)) {
            $patterns[] = [
                'type' => 'habitual_weekend_timing',
                'severity' => 'high',
                'description' => "Pattern detected: {$weekendUpdates} of {$totalVersions} updates were on weekends.",
                'implication' => 'Weekend updates may be a deliberate strategy to reduce visibility.',
            ];
        }

        return $patterns;
    }

    /**
     * Calculate risk score (0-100) from signals.
     */
    protected function calculateRiskScore(array $signals): int
    {
        if (empty($signals)) {
            return 0;
        }

        $severityScores = [
            'critical' => 30,
            'high' => 20,
            'medium' => 10,
            'low' => 5,
        ];

        $totalScore = 0;
        foreach ($signals as $signal) {
            $totalScore += $severityScores[$signal['severity']] ?? 5;
        }

        return min(100, $totalScore);
    }

    /**
     * Calculate overall risk level from all signals.
     */
    protected function calculateOverallRisk(array $allSignals, array $patternSignals): string
    {
        $hasCritical = false;
        $highCount = 0;

        foreach (array_merge($allSignals, $patternSignals) as $signal) {
            if (($signal['severity'] ?? '') === 'critical') {
                $hasCritical = true;
            }
            if (($signal['severity'] ?? '') === 'high') {
                $highCount++;
            }
        }

        if ($hasCritical || $highCount >= 3) {
            return 'critical';
        }
        if ($highCount >= 2 || count($allSignals) >= 5) {
            return 'high';
        }
        if (count($allSignals) >= 2) {
            return 'medium';
        }
        if (count($allSignals) >= 1) {
            return 'low';
        }

        return 'none';
    }

    /**
     * Generate a human-readable summary of signals.
     */
    protected function generateSignalSummary(array $signals): string
    {
        if (empty($signals)) {
            return 'No concerning timing patterns detected.';
        }

        $summaries = array_column($signals, 'description');

        return implode(' ', $summaries);
    }

    /**
     * Generate pattern summary for document history.
     */
    protected function generatePatternSummary(array $patterns, int $versionCount): string
    {
        if (empty($patterns)) {
            return "Analyzed {$versionCount} versions. No systematic timing manipulation detected.";
        }

        $descriptions = array_column($patterns, 'description');

        return "Analyzed {$versionCount} versions. " . implode(' ', $descriptions);
    }

    /**
     * Get all signals with their penalties for configuration/display.
     */
    public static function getSignalDefinitions(): array
    {
        return self::SIGNAL_PENALTIES;
    }

    /**
     * Get holiday definitions.
     */
    public static function getHolidayDefinitions(): array
    {
        return [
            'major' => self::MAJOR_HOLIDAYS,
            'minor' => self::MINOR_HOLIDAYS,
        ];
    }

    /**
     * Get the effective date from a document version.
     * Prefers the document's stated effective date, falls back to scraped_at.
     */
    protected function getEffectiveDate(DocumentVersion $version): ?Carbon
    {
        // Check if there's an effective date in metadata
        $metadata = $version->metadata ?? [];
        if (isset($metadata['effective_date']['value'])) {
            try {
                return Carbon::parse($metadata['effective_date']['value']);
            } catch (\Exception $e) {
                // Invalid date format, fall through
            }
        }

        // Fall back to scraped_at or created_at
        $fallbackDate = $version->scraped_at ?? $version->created_at;
        return $fallbackDate ? Carbon::parse($fallbackDate) : null;
    }
}
