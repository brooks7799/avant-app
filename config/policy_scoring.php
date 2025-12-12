<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scoring Dimension Weights
    |--------------------------------------------------------------------------
    |
    | These weights define how much each dimension contributes to the total
    | score (0-100). All weights must sum to 100.
    |
    */

    'weights' => [
        'transparency' => 20,       // How clear and understandable is the policy?
        'user_rights' => 20,        // What rights do users have over their data?
        'data_collection' => 20,    // How much data is collected and how?
        'legal_rights' => 20,       // What legal protections/limitations exist?
        'fairness_balance' => 10,   // Is the agreement fair and balanced?
        'notifications' => 10,      // How are users notified of changes?
    ],

    /*
    |--------------------------------------------------------------------------
    | Flag Penalties and Bonuses
    |--------------------------------------------------------------------------
    |
    | Each flag type from AI analysis maps to penalties (negative) or
    | bonuses (positive) for specific dimensions.
    |
    */

    'flag_penalties' => [
        // Legal Rights Penalties (severe)
        'forced_arbitration' => ['legal_rights' => -20],
        'class_action_waiver' => ['legal_rights' => -15],
        'unilateral_modification' => ['legal_rights' => -10, 'fairness_balance' => -5],
        'jurisdiction_limitation' => ['legal_rights' => -8],
        'liability_limitation' => ['legal_rights' => -8],
        'indemnification_clause' => ['legal_rights' => -5],

        // Data Collection Penalties
        'sell_data' => ['data_collection' => -25, 'transparency' => -5],
        'share_with_advertisers' => ['data_collection' => -15],
        'third_party_sharing' => ['data_collection' => -10],
        'vague_data_sharing' => ['data_collection' => -8, 'transparency' => -5],
        'excessive_data_collection' => ['data_collection' => -12],
        'location_tracking' => ['data_collection' => -8],
        'cross_device_tracking' => ['data_collection' => -10],
        'biometric_data' => ['data_collection' => -10],
        'sensitive_data_collection' => ['data_collection' => -12],

        // User Rights Penalties
        'no_deletion_right' => ['user_rights' => -20],
        'difficult_deletion' => ['user_rights' => -10],
        'no_opt_out' => ['user_rights' => -15],
        'limited_access_rights' => ['user_rights' => -8],
        'no_portability' => ['user_rights' => -5],
        'automatic_consent' => ['user_rights' => -12, 'fairness_balance' => -5],

        // Transparency Penalties
        'vague_language' => ['transparency' => -8],
        'hidden_terms' => ['transparency' => -15],
        'complex_language' => ['transparency' => -5],
        'undefined_terms' => ['transparency' => -5],
        'buried_important_terms' => ['transparency' => -10],

        // Fairness Penalties
        'one_sided_terms' => ['fairness_balance' => -10],
        'surprise_terms' => ['fairness_balance' => -8, 'transparency' => -5],
        'take_it_or_leave_it' => ['fairness_balance' => -5],

        // Notification Penalties
        'no_change_notification' => ['notifications' => -10],
        'vague_notification_policy' => ['notifications' => -5],
        'continued_use_consent' => ['notifications' => -8, 'user_rights' => -5],

        // Bonuses (positive flags)
        'clear_deletion_rights' => ['user_rights' => +10],
        'easy_opt_out' => ['user_rights' => +8],
        'data_portability' => ['user_rights' => +5],
        'plain_language' => ['transparency' => +10],
        'no_data_selling' => ['data_collection' => +10],
        'minimal_data_collection' => ['data_collection' => +8],
        'clear_data_usage' => ['transparency' => +8, 'data_collection' => +5],
        'proactive_notifications' => ['notifications' => +10],
        'gdpr_compliant' => ['user_rights' => +5, 'data_collection' => +5],
        'ccpa_compliant' => ['user_rights' => +5, 'data_collection' => +5],
        'encryption_mentioned' => ['data_collection' => +3],
        'limited_retention' => ['data_collection' => +5],
        'user_control' => ['user_rights' => +8],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grade Thresholds
    |--------------------------------------------------------------------------
    |
    | Total score ranges that map to letter grades.
    |
    */

    'grade_thresholds' => [
        'A' => 90,  // 90-100 = A
        'B' => 80,  // 80-89 = B
        'C' => 70,  // 70-79 = C
        'D' => 60,  // 60-69 = D
        // Below 60 = F
    ],

    /*
    |--------------------------------------------------------------------------
    | Starting Scores
    |--------------------------------------------------------------------------
    |
    | Default starting score for each dimension before penalties/bonuses.
    | This represents a "neutral" baseline.
    |
    */

    'starting_score_percentage' => 70, // Start at 70% of max weight

    /*
    |--------------------------------------------------------------------------
    | Flag Categories
    |--------------------------------------------------------------------------
    |
    | Map flag types to categories for better organization and reporting.
    |
    */

    'flag_categories' => [
        'legal_rights' => [
            'forced_arbitration',
            'class_action_waiver',
            'unilateral_modification',
            'jurisdiction_limitation',
            'liability_limitation',
            'indemnification_clause',
        ],
        'data_collection' => [
            'sell_data',
            'share_with_advertisers',
            'third_party_sharing',
            'vague_data_sharing',
            'excessive_data_collection',
            'location_tracking',
            'cross_device_tracking',
            'biometric_data',
            'sensitive_data_collection',
            'no_data_selling',
            'minimal_data_collection',
            'encryption_mentioned',
            'limited_retention',
        ],
        'user_rights' => [
            'no_deletion_right',
            'difficult_deletion',
            'no_opt_out',
            'limited_access_rights',
            'no_portability',
            'automatic_consent',
            'clear_deletion_rights',
            'easy_opt_out',
            'data_portability',
            'gdpr_compliant',
            'ccpa_compliant',
            'user_control',
        ],
        'transparency' => [
            'vague_language',
            'hidden_terms',
            'complex_language',
            'undefined_terms',
            'buried_important_terms',
            'plain_language',
            'clear_data_usage',
        ],
        'fairness' => [
            'one_sided_terms',
            'surprise_terms',
            'take_it_or_leave_it',
        ],
        'notifications' => [
            'no_change_notification',
            'vague_notification_policy',
            'continued_use_consent',
            'proactive_notifications',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity Multipliers
    |--------------------------------------------------------------------------
    |
    | Multipliers based on AI-assigned severity (1-10 scale).
    | Severity affects how much of the base penalty/bonus is applied.
    |
    */

    'severity_multipliers' => [
        1 => 0.3,
        2 => 0.4,
        3 => 0.5,
        4 => 0.6,
        5 => 0.7,
        6 => 0.8,
        7 => 0.9,
        8 => 1.0,
        9 => 1.1,
        10 => 1.2,
    ],

];
