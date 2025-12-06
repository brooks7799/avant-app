<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'Privacy Policy',
                'slug' => 'privacy-policy',
                'description' => 'Document outlining how the company collects, uses, and protects user data.',
                'icon' => 'shield',
                'sort_order' => 1,
            ],
            [
                'name' => 'Terms of Service',
                'slug' => 'terms-of-service',
                'description' => 'Legal agreement between the service provider and users.',
                'icon' => 'file-text',
                'sort_order' => 2,
            ],
            [
                'name' => 'Cookie Policy',
                'slug' => 'cookie-policy',
                'description' => 'Document explaining the use of cookies and tracking technologies.',
                'icon' => 'cookie',
                'sort_order' => 3,
            ],
            [
                'name' => 'Data Processing Agreement',
                'slug' => 'data-processing-agreement',
                'description' => 'Agreement for GDPR compliance regarding data processing.',
                'icon' => 'database',
                'sort_order' => 4,
            ],
            [
                'name' => 'Acceptable Use Policy',
                'slug' => 'acceptable-use-policy',
                'description' => 'Rules governing the acceptable use of the service.',
                'icon' => 'check-circle',
                'sort_order' => 5,
            ],
            [
                'name' => 'CCPA Notice',
                'slug' => 'ccpa-notice',
                'description' => 'California Consumer Privacy Act disclosure.',
                'icon' => 'map-pin',
                'sort_order' => 6,
            ],
            [
                'name' => 'Community Guidelines',
                'slug' => 'community-guidelines',
                'description' => 'Rules for user behavior within the community or platform.',
                'icon' => 'users',
                'sort_order' => 7,
            ],
        ];

        foreach ($types as $type) {
            DocumentType::updateOrCreate(
                ['slug' => $type['slug']],
                $type
            );
        }
    }
}
