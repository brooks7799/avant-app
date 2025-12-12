<?php

namespace App\Services\AI;

class PolicyScoringService
{
    protected array $config;

    protected array $weights;

    protected array $flagPenalties;

    protected array $gradeThresholds;

    protected array $severityMultipliers;

    public function __construct()
    {
        $this->config = config('policy_scoring');
        $this->weights = $this->config['weights'] ?? [];
        $this->flagPenalties = $this->config['flag_penalties'] ?? [];
        $this->gradeThresholds = $this->config['grade_thresholds'] ?? [];
        $this->severityMultipliers = $this->config['severity_multipliers'] ?? [];
    }

    /**
     * Calculate dimension scores from risk flags.
     *
     * @param  array  $flags  Risk flags from AI analysis (red, yellow, green)
     * @return array Dimension scores
     */
    public function calculateScores(array $flags): array
    {
        // Initialize scores at starting percentage of max weight
        $startingPercentage = ($this->config['starting_score_percentage'] ?? 70) / 100;

        $scores = [];
        foreach ($this->weights as $dimension => $weight) {
            $scores[$dimension] = $weight * $startingPercentage;
        }

        // Process all flag types (red = bad, yellow = concerning, green = good)
        $allFlags = [];

        // Red flags (high severity)
        foreach ($flags['red'] ?? [] as $flag) {
            $allFlags[] = array_merge($flag, ['flag_color' => 'red']);
        }

        // Yellow flags (medium severity)
        foreach ($flags['yellow'] ?? [] as $flag) {
            $allFlags[] = array_merge($flag, ['flag_color' => 'yellow']);
        }

        // Green flags (positive)
        foreach ($flags['green'] ?? [] as $flag) {
            $allFlags[] = array_merge($flag, ['flag_color' => 'green']);
        }

        // Apply each flag's penalty/bonus
        foreach ($allFlags as $flag) {
            $type = $flag['type'] ?? null;
            $severity = $flag['severity'] ?? 5;

            if (! $type || ! isset($this->flagPenalties[$type])) {
                continue;
            }

            $severityMultiplier = $this->severityMultipliers[$severity] ?? 0.7;
            $dimensionEffects = $this->flagPenalties[$type];

            foreach ($dimensionEffects as $dimension => $baseChange) {
                if (! isset($scores[$dimension])) {
                    continue;
                }

                // Apply severity multiplier to the base change
                $actualChange = $baseChange * $severityMultiplier;
                $scores[$dimension] += $actualChange;
            }
        }

        // Clamp scores to valid ranges [0, weight]
        foreach ($scores as $dimension => $score) {
            $maxScore = $this->weights[$dimension] ?? 0;
            $scores[$dimension] = max(0, min($maxScore, round($score, 1)));
        }

        return $scores;
    }

    /**
     * Calculate the total score from dimension scores.
     */
    public function calculateTotalScore(array $dimensionScores): int
    {
        $total = array_sum($dimensionScores);

        return (int) max(0, min(100, round($total)));
    }

    /**
     * Calculate the letter grade from a total score.
     */
    public function calculateGrade(int $totalScore): string
    {
        foreach ($this->gradeThresholds as $grade => $threshold) {
            if ($totalScore >= $threshold) {
                return $grade;
            }
        }

        return 'F';
    }

    /**
     * Get a human-readable label for a grade.
     */
    public function getGradeLabel(string $grade): string
    {
        return match ($grade) {
            'A' => 'Excellent',
            'B' => 'Good',
            'C' => 'Fair',
            'D' => 'Poor',
            'F' => 'Failing',
            default => 'Unknown',
        };
    }

    /**
     * Get the color class for a grade (for UI display).
     */
    public function getGradeColor(string $grade): string
    {
        return match ($grade) {
            'A' => 'green',
            'B' => 'blue',
            'C' => 'yellow',
            'D' => 'orange',
            'F' => 'red',
            default => 'gray',
        };
    }

    /**
     * Process complete analysis and return all scoring data.
     *
     * @param  array  $flags  Risk flags from AI analysis
     * @return array Complete scoring data
     */
    public function processAnalysis(array $flags): array
    {
        $dimensionScores = $this->calculateScores($flags);
        $totalScore = $this->calculateTotalScore($dimensionScores);
        $grade = $this->calculateGrade($totalScore);

        return [
            'dimension_scores' => $dimensionScores,
            'total_score' => $totalScore,
            'grade' => $grade,
            'grade_label' => $this->getGradeLabel($grade),
            'grade_color' => $this->getGradeColor($grade),
            'flag_summary' => $this->summarizeFlags($flags),
        ];
    }

    /**
     * Summarize flags for quick reference.
     */
    protected function summarizeFlags(array $flags): array
    {
        return [
            'red_count' => count($flags['red'] ?? []),
            'yellow_count' => count($flags['yellow'] ?? []),
            'green_count' => count($flags['green'] ?? []),
            'total_count' => count($flags['red'] ?? [])
                + count($flags['yellow'] ?? [])
                + count($flags['green'] ?? []),
        ];
    }

    /**
     * Get detailed breakdown of how each flag affected scores.
     */
    public function getScoreBreakdown(array $flags): array
    {
        $breakdown = [];

        foreach (['red', 'yellow', 'green'] as $color) {
            foreach ($flags[$color] ?? [] as $flag) {
                $type = $flag['type'] ?? 'unknown';
                $severity = $flag['severity'] ?? 5;

                if (! isset($this->flagPenalties[$type])) {
                    continue;
                }

                $severityMultiplier = $this->severityMultipliers[$severity] ?? 0.7;
                $effects = [];

                foreach ($this->flagPenalties[$type] as $dimension => $baseChange) {
                    $actualChange = $baseChange * $severityMultiplier;
                    $effects[$dimension] = round($actualChange, 1);
                }

                $breakdown[] = [
                    'type' => $type,
                    'color' => $color,
                    'severity' => $severity,
                    'description' => $flag['description'] ?? '',
                    'section_reference' => $flag['section_reference'] ?? null,
                    'effects' => $effects,
                ];
            }
        }

        return $breakdown;
    }

    /**
     * Get available flag types for a category.
     */
    public function getFlagTypesForCategory(string $category): array
    {
        $categories = $this->config['flag_categories'] ?? [];

        return $categories[$category] ?? [];
    }

    /**
     * Check if a flag type exists in configuration.
     */
    public function flagTypeExists(string $type): bool
    {
        return isset($this->flagPenalties[$type]);
    }

    /**
     * Get all available flag types.
     */
    public function getAllFlagTypes(): array
    {
        return array_keys($this->flagPenalties);
    }

    /**
     * Get the dimension weights.
     */
    public function getWeights(): array
    {
        return $this->weights;
    }
}
