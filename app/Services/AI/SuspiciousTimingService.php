<?php

namespace App\Services\AI;

use App\Models\DocumentVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SuspiciousTimingService
{
    /**
     * Evaluate timing of a document version update for suspicious patterns.
     * Uses the document's effective date if available, otherwise falls back to scraped_at.
     */
    public function evaluate(DocumentVersion $version, ?int $impactDelta = null): array
    {
        // Prefer the document's stated effective date over our scrape date
        $timestamp = $this->getEffectiveDate($version);

        if (! $timestamp) {
            return [
                'is_suspicious' => false,
                'score' => 0,
                'context' => ['error' => 'No timestamp available'],
            ];
        }

        $flags = [];
        $score = 0;

        // Check nighttime (10pm - 6am)
        if ($this->isNighttime($timestamp)) {
            $flags[] = 'nighttime';
            $score -= 5;
        }

        // Check weekend
        if ($this->isWeekend($timestamp)) {
            $flags[] = 'weekend';
            $score -= 5;
        }

        // Check holiday
        $holiday = $this->getHoliday($timestamp);
        if ($holiday) {
            $flags[] = $holiday;
            $score -= 10;
        }

        // Additional penalty if negative changes during suspicious timing
        if ($impactDelta !== null && $impactDelta < -10 && ! empty($flags)) {
            $flags[] = 'negative_changes_during_suspicious_timing';
            $score -= 10;
        }

        $isSuspicious = $score < 0;

        $context = [
            'local_time' => $timestamp->toIso8601String(),
            'weekday' => $timestamp->format('l'),
            'hour' => $timestamp->hour,
            'flags' => $flags,
            'impact_delta' => $impactDelta,
        ];

        if ($isSuspicious) {
            $context['notes'] = $this->generateNotes($flags, $impactDelta);
        }

        Log::debug('Timing analysis completed', [
            'version_id' => $version->id,
            'is_suspicious' => $isSuspicious,
            'score' => $score,
            'flags' => $flags,
        ]);

        return [
            'is_suspicious' => $isSuspicious,
            'score' => $score,
            'context' => $context,
        ];
    }

    /**
     * Check if timestamp is during nighttime hours (10pm - 6am).
     */
    public function isNighttime(Carbon $time): bool
    {
        $hour = $time->hour;

        return $hour >= 22 || $hour < 6;
    }

    /**
     * Check if timestamp is on a weekend.
     */
    public function isWeekend(Carbon $time): bool
    {
        return $time->isWeekend();
    }

    /**
     * Check if timestamp is on a holiday and return the holiday name.
     */
    public function isHoliday(Carbon $time): bool
    {
        return $this->getHoliday($time) !== null;
    }

    /**
     * Get the holiday name if the timestamp falls on a holiday.
     */
    public function getHoliday(Carbon $time): ?string
    {
        $holidays = $this->getHolidays($time->year);

        $dateKey = $time->format('m-d');

        return $holidays[$dateKey] ?? null;
    }

    /**
     * Get holidays for a given year.
     * Returns array keyed by 'MM-DD' format.
     */
    protected function getHolidays(int $year): array
    {
        // Fixed holidays
        $holidays = [
            '01-01' => 'new_years_day',
            '07-04' => 'independence_day',
            '12-24' => 'christmas_eve',
            '12-25' => 'christmas_day',
            '12-31' => 'new_years_eve',
        ];

        // Calculated holidays (US)

        // MLK Day - 3rd Monday of January
        $mlk = Carbon::create($year, 1, 1)->nthOfMonth(3, Carbon::MONDAY);
        $holidays[$mlk->format('m-d')] = 'mlk_day';

        // Presidents Day - 3rd Monday of February
        $presidents = Carbon::create($year, 2, 1)->nthOfMonth(3, Carbon::MONDAY);
        $holidays[$presidents->format('m-d')] = 'presidents_day';

        // Memorial Day - Last Monday of May
        $memorial = Carbon::create($year, 5, 31)->previous(Carbon::MONDAY);
        if ($memorial->month !== 5) {
            $memorial = Carbon::create($year, 5, 1)->lastOfMonth(Carbon::MONDAY);
        }
        $holidays[$memorial->format('m-d')] = 'memorial_day';

        // Labor Day - 1st Monday of September
        $labor = Carbon::create($year, 9, 1)->nthOfMonth(1, Carbon::MONDAY);
        $holidays[$labor->format('m-d')] = 'labor_day';

        // Columbus Day - 2nd Monday of October
        $columbus = Carbon::create($year, 10, 1)->nthOfMonth(2, Carbon::MONDAY);
        $holidays[$columbus->format('m-d')] = 'columbus_day';

        // Thanksgiving - 4th Thursday of November
        $thanksgiving = Carbon::create($year, 11, 1)->nthOfMonth(4, Carbon::THURSDAY);
        $holidays[$thanksgiving->format('m-d')] = 'thanksgiving';

        // Day after Thanksgiving (Black Friday)
        $blackFriday = $thanksgiving->copy()->addDay();
        $holidays[$blackFriday->format('m-d')] = 'black_friday';

        // Veterans Day
        $holidays['11-11'] = 'veterans_day';

        return $holidays;
    }

    /**
     * Generate human-readable notes about suspicious timing.
     */
    protected function generateNotes(array $flags, ?int $impactDelta): string
    {
        $notes = [];

        if (in_array('nighttime', $flags)) {
            $notes[] = 'Published during late night hours when users are unlikely to notice';
        }

        if (in_array('weekend', $flags)) {
            $notes[] = 'Published on weekend when fewer people are paying attention';
        }

        $holidayFlags = array_filter($flags, fn ($f) => ! in_array($f, ['nighttime', 'weekend', 'negative_changes_during_suspicious_timing']));
        if (! empty($holidayFlags)) {
            $holidayName = $this->formatHolidayName(reset($holidayFlags));
            $notes[] = "Published on {$holidayName} when users are distracted";
        }

        if (in_array('negative_changes_during_suspicious_timing', $flags)) {
            $notes[] = 'Significant negative changes made during suspicious timing window';
        }

        if ($impactDelta !== null && $impactDelta < -15) {
            $notes[] = 'Changes substantially reduce user rights or protections';
        }

        return implode('. ', $notes).'.';
    }

    /**
     * Format holiday key to human-readable name.
     */
    protected function formatHolidayName(string $key): string
    {
        return match ($key) {
            'new_years_day' => "New Year's Day",
            'new_years_eve' => "New Year's Eve",
            'mlk_day' => 'Martin Luther King Jr. Day',
            'presidents_day' => "Presidents' Day",
            'memorial_day' => 'Memorial Day',
            'independence_day' => 'Independence Day',
            'labor_day' => 'Labor Day',
            'columbus_day' => 'Columbus Day',
            'veterans_day' => 'Veterans Day',
            'thanksgiving' => 'Thanksgiving',
            'black_friday' => 'Black Friday',
            'christmas_eve' => 'Christmas Eve',
            'christmas_day' => 'Christmas Day',
            default => str_replace('_', ' ', ucfirst($key)),
        };
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
        return $version->scraped_at ?? $version->created_at;
    }

    /**
     * Calculate suspiciousness score for a specific time.
     * Used for testing/preview without a full version record.
     */
    public function calculateScore(Carbon $time, ?int $impactDelta = null): int
    {
        $score = 0;

        if ($this->isNighttime($time)) {
            $score -= 5;
        }

        if ($this->isWeekend($time)) {
            $score -= 5;
        }

        if ($this->isHoliday($time)) {
            $score -= 10;
        }

        if ($impactDelta !== null && $impactDelta < -10 && $score < 0) {
            $score -= 10;
        }

        return $score;
    }
}
