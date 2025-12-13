<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a test company "ACME Corp" with multi-version policies
 * to test diff viewing and behavioral signal detection.
 *
 * Features:
 * - Two versions of Terms of Service (with meaningful changes)
 * - Version 1: August 10, 2024 (business hours, normal day)
 * - Version 2: November 28, 2024 (Thanksgiving Day - major holiday flag!)
 */
class AcmeTestDataSeeder extends Seeder
{
    public function run(): void
    {
        // Create ACME company
        $company = Company::updateOrCreate(
            ['slug' => 'acme-corp'],
            [
                'name' => 'ACME Corporation',
                'website' => 'https://acme-example.com',
                'description' => 'A test company for demonstrating policy analysis features.',
                'industry' => 'Technology',
                'headquarters_country' => 'United States',
                'headquarters_state' => 'California',
                'is_active' => true,
            ]
        );

        // Create website
        $website = Website::updateOrCreate(
            ['company_id' => $company->id, 'url' => 'https://acme-example.com'],
            [
                'base_url' => 'acme-example.com',
                'name' => 'Main Website',
                'is_primary' => true,
                'is_active' => true,
                'discovery_status' => 'completed',
                'last_discovered_at' => now(),
            ]
        );

        // Get Terms of Service document type
        $tosType = DocumentType::firstOrCreate(
            ['slug' => 'terms-of-service'],
            ['name' => 'Terms of Service', 'description' => 'Terms of Service agreement']
        );

        // Create the document
        $document = Document::updateOrCreate(
            ['source_url' => 'https://acme-example.com/legal/terms-of-service'],
            [
                'company_id' => $company->id,
                'website_id' => $website->id,
                'document_type_id' => $tosType->id,
                'scrape_status' => 'completed',
                'last_scraped_at' => Carbon::create(2024, 11, 28, 14, 30, 0), // Thanksgiving
                'last_changed_at' => Carbon::create(2024, 11, 28, 14, 30, 0),
                'is_active' => true,
            ]
        );

        // Delete existing versions for clean seed
        $document->versions()->delete();

        // VERSION 1: August 10, 2024 - Original, more user-friendly version
        $version1Content = $this->getVersion1Content();
        $version1Text = strip_tags($version1Content);
        $version1 = DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => '1',
            'content_raw' => $version1Content,
            'content_text' => $version1Text,
            'content_markdown' => $version1Content,
            'content_hash' => hash('sha256', $version1Content),
            'word_count' => str_word_count($version1Text),
            'character_count' => strlen($version1Text),
            'scraped_at' => Carbon::create(2024, 8, 10, 10, 30, 0), // Saturday morning
            'is_current' => false,
        ]);

        // VERSION 2: November 28, 2024 (Thanksgiving!) - Less user-friendly changes
        $version2Content = $this->getVersion2Content();
        $version2Text = strip_tags($version2Content);
        $version2 = DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => '2',
            'content_raw' => $version2Content,
            'content_text' => $version2Text,
            'content_markdown' => $version2Content,
            'content_hash' => hash('sha256', $version2Content),
            'word_count' => str_word_count($version2Text),
            'character_count' => strlen($version2Text),
            'scraped_at' => Carbon::create(2024, 11, 28, 14, 30, 0), // Thanksgiving Day!
            'is_current' => true,
        ]);

        $this->command->info("Created ACME Corp with 2 policy versions:");
        $this->command->info("  - Version 1: August 10, 2024 (original, user-friendly)");
        $this->command->info("  - Version 2: November 28, 2024 (THANKSGIVING - less user-friendly changes!)");
        $this->command->info("");
        $this->command->info("Key changes between versions:");
        $this->command->info("  - Added forced arbitration clause");
        $this->command->info("  - Added class action waiver");
        $this->command->info("  - Changed data deletion from 30 days to 90 days");
        $this->command->info("  - Added broad data sharing with 'partners'");
        $this->command->info("  - Removed easy opt-out options");
    }

    protected function getVersion1Content(): string
    {
        return <<<'MARKDOWN'
# ACME Corporation Terms of Service

**Effective Date: August 10, 2024**

Welcome to ACME Corporation ("ACME", "we", "us", or "our"). These Terms of Service ("Terms") govern your use of our services.

## 1. Acceptance of Terms

By accessing or using our services, you agree to be bound by these Terms. If you do not agree to these Terms, please do not use our services.

## 2. User Accounts

### 2.1 Account Creation
You must provide accurate and complete information when creating an account. You are responsible for maintaining the security of your account credentials.

### 2.2 Account Termination
You may terminate your account at any time by contacting our support team. We will process your request within 7 business days.

## 3. Privacy and Data Collection

### 3.1 Data We Collect
We collect information you provide directly to us, including:
- Name and email address
- Payment information (processed securely through third-party providers)
- Usage data to improve our services

### 3.2 How We Use Your Data
We use your data solely to:
- Provide and maintain our services
- Communicate with you about your account
- Improve our products based on aggregated, anonymized usage patterns

### 3.3 Data Sharing
We do not sell your personal data. We only share data with:
- Service providers who help us operate our business (under strict confidentiality agreements)
- Law enforcement when required by law

### 3.4 Your Data Rights
You have the right to:
- Access your personal data at any time
- Request correction of inaccurate data
- Request deletion of your data
- Export your data in a portable format

**Data Deletion:** Upon request, we will delete your personal data within **30 days**. Some data may be retained for legal compliance purposes.

## 4. User Rights and Protections

### 4.1 Opt-Out Options
You can opt out of:
- Marketing communications (via email preferences)
- Non-essential data collection (via account settings)
- Targeted advertising (via our privacy dashboard)

### 4.2 Notification of Changes
We will notify you of any material changes to these Terms at least **30 days** before they take effect. You will receive notification via:
- Email to your registered address
- Prominent notice on our website

## 5. Dispute Resolution

### 5.1 Informal Resolution First
Before initiating any legal proceedings, you agree to contact us first and attempt to resolve the dispute informally. We will make good faith efforts to resolve your concern.

### 5.2 Legal Proceedings
If informal resolution is unsuccessful, either party may pursue legal remedies in a court of competent jurisdiction.

### 5.3 Class Actions
You retain your right to participate in class action lawsuits and class-wide arbitration.

## 6. Limitation of Liability

ACME's liability for any claims arising from these Terms or our services shall not exceed the amount you paid us in the 12 months preceding the claim, or $100, whichever is greater.

## 7. Governing Law

These Terms are governed by the laws of the State of California, without regard to conflict of law principles.

## 8. Contact Us

If you have questions about these Terms, please contact us at:
- Email: legal@acme-example.com
- Mail: ACME Corporation, 123 Innovation Drive, San Francisco, CA 94102

---

*Last updated: August 10, 2024*
MARKDOWN;
    }

    protected function getVersion2Content(): string
    {
        return <<<'MARKDOWN'
# ACME Corporation Terms of Service

**Effective Date: November 28, 2024**

Welcome to ACME Corporation ("ACME", "we", "us", or "our"). These Terms of Service ("Terms") govern your use of our services.

## 1. Acceptance of Terms

By accessing or using our services, you agree to be bound by these Terms. **Your continued use of our services after any changes to these Terms constitutes acceptance of those changes.**

## 2. User Accounts

### 2.1 Account Creation
You must provide accurate and complete information when creating an account. You are responsible for maintaining the security of your account credentials.

### 2.2 Account Termination
You may terminate your account at any time by contacting our support team. We will process your request within 30 business days. **We reserve the right to retain certain data as described in Section 3.**

## 3. Privacy and Data Collection

### 3.1 Data We Collect
We collect information you provide directly to us, including:
- Name, email address, phone number, and physical address
- Payment information and transaction history
- Device information, IP addresses, and browser data
- Usage data, browsing patterns, and interaction history
- **Location data when you use our mobile applications**
- **Data from third-party sources to enhance our records**

### 3.2 How We Use Your Data
We use your data to:
- Provide and maintain our services
- Communicate with you about your account
- Improve our products based on usage patterns
- **Personalize advertising and promotional content**
- **Conduct research and analytics**
- **Share insights with our business partners**

### 3.3 Data Sharing
We may share your data with:
- Service providers who help us operate our business
- **Our affiliated companies and business partners**
- **Advertisers and marketing partners (in aggregated or identifiable form)**
- **Third parties for their own marketing purposes**
- Law enforcement when required by law or when we believe disclosure is necessary

### 3.4 Your Data Rights
You have the right to:
- Access your personal data (subject to verification)
- Request correction of inaccurate data
- Request deletion of your data (subject to our retention policies)

**Data Deletion:** Upon verified request, we will delete your personal data within **90 days**. **Certain data will be retained for up to 7 years for legal, tax, and business purposes.**

## 4. User Rights and Protections

### 4.1 Communication Preferences
You can manage communication preferences through your account settings. **Note: You cannot opt out of transactional and service-related communications.**

### 4.2 Notification of Changes
We may update these Terms at any time. **Changes become effective immediately upon posting.** We will make reasonable efforts to notify you of significant changes, but you are responsible for reviewing these Terms periodically.

## 5. Dispute Resolution

### 5.1 MANDATORY BINDING ARBITRATION
**ANY DISPUTE ARISING FROM THESE TERMS OR YOUR USE OF OUR SERVICES SHALL BE RESOLVED EXCLUSIVELY THROUGH BINDING ARBITRATION** administered by the American Arbitration Association under its Commercial Arbitration Rules. The arbitration shall take place in San Francisco, California.

### 5.2 CLASS ACTION WAIVER
**YOU AGREE TO RESOLVE DISPUTES WITH US ON AN INDIVIDUAL BASIS ONLY. YOU WAIVE YOUR RIGHT TO PARTICIPATE IN CLASS ACTIONS, CLASS ARBITRATIONS, OR ANY OTHER REPRESENTATIVE PROCEEDINGS.**

### 5.3 Limitation on Claims
Any claim arising from these Terms must be brought within **one (1) year** after the cause of action arises, or such claim shall be permanently barred.

## 6. Limitation of Liability

**TO THE MAXIMUM EXTENT PERMITTED BY LAW, ACME'S TOTAL LIABILITY FOR ANY CLAIMS ARISING FROM THESE TERMS OR OUR SERVICES SHALL NOT EXCEED THE GREATER OF (A) THE AMOUNT YOU PAID US IN THE 3 MONTHS PRECEDING THE CLAIM, OR (B) $50.**

**IN NO EVENT SHALL ACME BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES.**

## 7. Indemnification

**You agree to indemnify, defend, and hold harmless ACME and its officers, directors, employees, and agents from any claims, damages, losses, and expenses arising from your use of our services or violation of these Terms.**

## 8. Governing Law

These Terms are governed by the laws of the State of Delaware, without regard to conflict of law principles. **You consent to exclusive jurisdiction in the state and federal courts located in Delaware.**

## 9. Severability and Entire Agreement

If any provision of these Terms is found unenforceable, the remaining provisions will continue in effect. These Terms constitute the entire agreement between you and ACME.

## 10. Contact Us

If you have questions about these Terms, please contact us at:
- Email: terms@acme-example.com

---

*Last updated: November 28, 2024*
MARKDOWN;
    }
}
