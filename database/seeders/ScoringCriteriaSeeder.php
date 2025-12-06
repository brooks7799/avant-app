<?php

namespace Database\Seeders;

use App\Models\ScoringCriteria;
use Illuminate\Database\Seeder;

class ScoringCriteriaSeeder extends Seeder
{
    public function run(): void
    {
        $criteria = [
            // Data Collection Category
            [
                'name' => 'Data Minimization',
                'slug' => 'data-minimization',
                'category' => 'data_collection',
                'description' => 'Evaluates whether the company collects only the data necessary for providing the service.',
                'evaluation_prompt' => 'Analyze the data collection practices. Does the company practice data minimization? Are there excessive data collection requirements? Score higher if only essential data is collected.',
                'weight' => 1.50,
                'sort_order' => 1,
            ],
            [
                'name' => 'Sensitive Data Handling',
                'slug' => 'sensitive-data-handling',
                'category' => 'data_collection',
                'description' => 'Evaluates how sensitive personal data (health, biometric, financial) is collected and handled.',
                'evaluation_prompt' => 'Does the policy mention collection of sensitive data categories? If so, are there enhanced protections? Score lower if sensitive data is collected without clear justification.',
                'weight' => 1.75,
                'sort_order' => 2,
            ],

            // Data Sharing Category
            [
                'name' => 'Third-Party Sharing Transparency',
                'slug' => 'third-party-sharing-transparency',
                'category' => 'data_sharing',
                'description' => 'Evaluates how clearly the company discloses third-party data sharing.',
                'evaluation_prompt' => 'How transparent is the policy about third-party data sharing? Are specific partners named? Are purposes clearly stated? Score higher for specific, clear disclosures.',
                'weight' => 1.50,
                'sort_order' => 3,
            ],
            [
                'name' => 'Data Selling Practices',
                'slug' => 'data-selling-practices',
                'category' => 'data_sharing',
                'description' => 'Evaluates whether user data is sold to third parties.',
                'evaluation_prompt' => 'Does the company sell user data? Is there a clear statement about data sales? Score very low if data is sold, high if there is an explicit commitment to never sell data.',
                'weight' => 2.00,
                'sort_order' => 4,
            ],
            [
                'name' => 'Advertising Data Use',
                'slug' => 'advertising-data-use',
                'category' => 'advertising',
                'description' => 'Evaluates how user data is used for advertising and targeting.',
                'evaluation_prompt' => 'How is user data used for advertising? Is there targeted advertising? Can users opt out? Score higher if advertising use is limited and opt-out is available.',
                'weight' => 1.25,
                'sort_order' => 5,
            ],

            // Data Retention Category
            [
                'name' => 'Retention Period Clarity',
                'slug' => 'retention-period-clarity',
                'category' => 'data_retention',
                'description' => 'Evaluates whether data retention periods are clearly stated.',
                'evaluation_prompt' => 'Are data retention periods clearly specified? Are they reasonable? Score higher for specific, limited retention periods.',
                'weight' => 1.00,
                'sort_order' => 6,
            ],
            [
                'name' => 'Post-Deletion Practices',
                'slug' => 'post-deletion-practices',
                'category' => 'data_retention',
                'description' => 'Evaluates what happens to data after account deletion.',
                'evaluation_prompt' => 'What happens to user data when they delete their account? Is data truly deleted or retained? Score higher for clear, complete deletion practices.',
                'weight' => 1.25,
                'sort_order' => 7,
            ],

            // User Rights Category
            [
                'name' => 'Access Rights',
                'slug' => 'access-rights',
                'category' => 'user_rights',
                'description' => 'Evaluates users\' ability to access their personal data.',
                'evaluation_prompt' => 'Can users access their data? Is there a clear process? Is it free? Score higher for easy, free access to personal data.',
                'weight' => 1.25,
                'sort_order' => 8,
            ],
            [
                'name' => 'Deletion Rights',
                'slug' => 'deletion-rights',
                'category' => 'user_rights',
                'description' => 'Evaluates users\' ability to delete their personal data.',
                'evaluation_prompt' => 'Can users delete their data? Is there a clear process? Are there unreasonable limitations? Score higher for clear, comprehensive deletion rights.',
                'weight' => 1.50,
                'sort_order' => 9,
            ],
            [
                'name' => 'Opt-Out Options',
                'slug' => 'opt-out-options',
                'category' => 'user_rights',
                'description' => 'Evaluates available opt-out mechanisms for data collection and use.',
                'evaluation_prompt' => 'What opt-out options are available? Can users opt out of data collection, sharing, or marketing? Score higher for comprehensive opt-out options.',
                'weight' => 1.25,
                'sort_order' => 10,
            ],
            [
                'name' => 'Data Portability',
                'slug' => 'data-portability',
                'category' => 'user_rights',
                'description' => 'Evaluates users\' ability to export their data in usable formats.',
                'evaluation_prompt' => 'Can users export their data? Is it in a usable format? Score higher if data portability is supported with standard formats.',
                'weight' => 1.00,
                'sort_order' => 11,
            ],

            // Transparency Category
            [
                'name' => 'Policy Readability',
                'slug' => 'policy-readability',
                'category' => 'transparency',
                'description' => 'Evaluates how readable and understandable the privacy policy is.',
                'evaluation_prompt' => 'How readable is the policy? Is it written in plain language? Are complex terms explained? Score higher for clear, accessible language.',
                'weight' => 1.00,
                'sort_order' => 12,
            ],
            [
                'name' => 'Change Notification',
                'slug' => 'change-notification',
                'category' => 'transparency',
                'description' => 'Evaluates how users are notified of policy changes.',
                'evaluation_prompt' => 'How are users notified of policy changes? Is there advance notice? Can users review changes before they take effect? Score higher for proactive notification.',
                'weight' => 1.25,
                'sort_order' => 13,
            ],

            // Security Category
            [
                'name' => 'Security Measures',
                'slug' => 'security-measures',
                'category' => 'security',
                'description' => 'Evaluates disclosed security measures for protecting user data.',
                'evaluation_prompt' => 'What security measures are disclosed? Is encryption mentioned? Are there industry-standard protections? Score higher for comprehensive security disclosures.',
                'weight' => 1.25,
                'sort_order' => 14,
            ],
            [
                'name' => 'Breach Notification',
                'slug' => 'breach-notification',
                'category' => 'security',
                'description' => 'Evaluates the company\'s commitment to notifying users of data breaches.',
                'evaluation_prompt' => 'Is there a breach notification commitment? How quickly will users be notified? Score higher for clear, timely breach notification commitments.',
                'weight' => 1.50,
                'sort_order' => 15,
            ],

            // Consent Category
            [
                'name' => 'Consent Clarity',
                'slug' => 'consent-clarity',
                'category' => 'consent',
                'description' => 'Evaluates how clearly consent is obtained for data practices.',
                'evaluation_prompt' => 'How is consent obtained? Is it clear what users are consenting to? Is consent freely given? Score higher for clear, granular consent.',
                'weight' => 1.25,
                'sort_order' => 16,
            ],
            [
                'name' => 'Consent Withdrawal',
                'slug' => 'consent-withdrawal',
                'category' => 'consent',
                'description' => 'Evaluates how easily users can withdraw consent.',
                'evaluation_prompt' => 'Can users withdraw consent? Is it as easy to withdraw as to give? Score higher if consent withdrawal is straightforward.',
                'weight' => 1.25,
                'sort_order' => 17,
            ],

            // Children's Privacy Category
            [
                'name' => 'Children\'s Privacy Protection',
                'slug' => 'childrens-privacy-protection',
                'category' => 'children_privacy',
                'description' => 'Evaluates protections for children\'s personal data.',
                'evaluation_prompt' => 'Are there specific protections for children? Is parental consent required? Is the service age-gated appropriately? Score higher for strong children\'s privacy protections.',
                'weight' => 1.50,
                'sort_order' => 18,
            ],

            // International Category
            [
                'name' => 'International Transfer Safeguards',
                'slug' => 'international-transfer-safeguards',
                'category' => 'international',
                'description' => 'Evaluates safeguards for international data transfers.',
                'evaluation_prompt' => 'How is data transferred internationally? Are there adequate safeguards? Is the legal basis disclosed? Score higher for clear transfer mechanisms and safeguards.',
                'weight' => 1.00,
                'sort_order' => 19,
            ],
        ];

        foreach ($criteria as $criterion) {
            ScoringCriteria::updateOrCreate(
                ['slug' => $criterion['slug']],
                array_merge($criterion, ['is_active' => true])
            );
        }
    }
}
