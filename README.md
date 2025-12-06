# Avant - Legal Document Tracking System

Avant is a comprehensive legal document tracking and analysis platform designed to monitor, scrape, and analyze privacy policies, terms of service, and other legal documents from companies and their products.

## Overview

The platform enables organizations to:
- Track companies and their various products/services
- Monitor multiple websites per company
- Automatically discover and scrape legal documents (privacy policies, ToS, etc.)
- Store document versions with change tracking
- Link documents to specific products within a company
- (Planned) AI-powered analysis and scoring of legal documents

## Tech Stack

### Backend
- **PHP 8.2+**
- **Laravel 12** - PHP framework
- **MySQL 8.0+** - Primary database
- **Laravel Queue** - Async job processing for scraping

### Frontend
- **Vue 3** - JavaScript framework
- **Inertia.js** - SPA bridge between Laravel and Vue
- **TypeScript** - Type-safe JavaScript
- **Tailwind CSS** - Utility-first CSS framework
- **shadcn-vue** - UI component library (Reka UI primitives)

### Key Packages
- `symfony/dom-crawler` - HTML parsing and content extraction
- `league/html-to-markdown` - Convert HTML to Markdown
- `sebastian/diff` - Generate diffs between document versions
- `laravel/fortify` - Authentication scaffolding

## Features

### Completed

#### Company Management
- Create, read, update, delete companies
- Company metadata (name, website, industry, description, location)
- Tag support for categorization

#### Product/Service Tracking
- Add products and services to companies (e.g., Disney+, Disney Games)
- Product types: Product, Service, Mobile App, Game, Platform, Website, Hardware
- Store product URLs, App Store links, Play Store links
- Link documents to specific products
- Mark primary documents for each product

#### Website Management
- Track multiple websites per company
- Mark primary website
- Auto-discovery of policy URLs via:
  - robots.txt parsing
  - Sitemap crawling
  - Common path checking (/privacy, /terms, etc.)
  - Link crawling and keyword detection

#### Document Scraping
- Manual document URL entry
- Automatic policy discovery
- Queue-based scraping with rate limiting
- Content extraction and Markdown conversion
- Version tracking with content hashing (SHA256)
- Diff generation between versions
- Scrape status tracking (pending, success, failed, blocked)
- Configurable scrape frequency (hourly, daily, weekly, monthly)

#### Database Schema
- `companies` - Company information
- `products` - Products/services per company
- `websites` - Websites per company
- `documents` - Legal document URLs
- `document_types` - Categories (Privacy Policy, ToS, etc.)
- `document_versions` - Scraped content with versioning
- `version_comparisons` - Diffs between versions
- `document_product` - Many-to-many linking
- `scrape_jobs` - Job execution tracking
- `discovery_jobs` - Discovery execution tracking
- `tags` / `taggables` - Polymorphic tagging
- `scoring_criteria` - AI scoring criteria definitions
- `document_scores` - Scores per criteria
- `analysis_results` - AI analysis storage

### Planned

#### AI Analysis
- Automated document analysis using LLMs
- Scoring based on configurable criteria
- Overall ratings (A-F) for document quality
- Trend analysis across versions

#### Alerts & Notifications
- Email alerts when documents change
- Significant change detection
- Scheduled digest reports

#### Public API
- REST API for external integrations
- Webhook support for change notifications

#### Enhanced UI
- Document diff viewer
- Version timeline
- Bulk operations
- Export capabilities

## Getting Started

### Prerequisites

- PHP 8.2 or higher
- Composer
- Node.js 18+ and npm
- MySQL 8.0+
- Redis (optional, for queue driver)

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd avant-app
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install Node.js dependencies**
   ```bash
   npm install
   ```

4. **Environment configuration**
   ```bash
   cp .env.example .env
   ```

5. **Configure your `.env` file**
   ```env
   APP_NAME=Avant
   APP_URL=http://localhost:8000

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=avant
   DB_USERNAME=your_username
   DB_PASSWORD=your_password

   QUEUE_CONNECTION=database  # or redis for production
   ```

6. **Generate application key**
   ```bash
   php artisan key:generate
   ```

7. **Run database migrations**
   ```bash
   php artisan migrate
   ```

8. **Seed the database** (creates document types and scoring criteria)
   ```bash
   php artisan db:seed
   ```

9. **Build frontend assets**
   ```bash
   npm run build
   ```

### Running the Application

#### Development

1. **Start the Laravel development server**
   ```bash
   php artisan serve
   ```

2. **Start the Vite dev server** (in a separate terminal)
   ```bash
   npm run dev
   ```

3. **Start the queue worker** (in a separate terminal)
   ```bash
   php artisan queue:work
   ```

4. Visit `http://localhost:8000` in your browser

#### Production

1. Build optimized assets:
   ```bash
   npm run build
   ```

2. Optimize Laravel:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. Set up a process manager (Supervisor) for queue workers

### Queue Configuration

For production, configure Supervisor to run queue workers:

```ini
[program:avant-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/avant-app/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/avant-app/storage/logs/worker.log
```

### Scheduled Tasks

Add to your server's crontab:

```bash
* * * * * cd /path/to/avant-app && php artisan schedule:run >> /dev/null 2>&1
```

Register the crawl command in `app/Console/Kernel.php` to run periodically:

```php
$schedule->command('policies:crawl-all')->hourly();
```

## Artisan Commands

### Policy Discovery
```bash
# Discover policies on a website
php artisan policies:discover {website_id}

# Run synchronously (useful for debugging)
php artisan policies:discover {website_id} --sync
```

### Document Scraping
```bash
# Scrape a specific document
php artisan policies:scrape {document_id}

# Run synchronously
php artisan policies:scrape {document_id} --sync
```

### Batch Processing
```bash
# Queue all documents due for scraping
php artisan policies:crawl-all

# Force scrape all documents regardless of schedule
php artisan policies:crawl-all --force
```

## Project Structure

```
avant-app/
├── app/
│   ├── Console/Commands/     # Artisan commands
│   ├── Http/Controllers/     # HTTP controllers
│   ├── Jobs/                 # Queue jobs
│   ├── Models/               # Eloquent models
│   └── Services/Scraper/     # Scraping services
│       ├── DTO/              # Data transfer objects
│       ├── ContentExtractorService.php
│       ├── DiffService.php
│       ├── DocumentScraperService.php
│       ├── HttpClientService.php
│       ├── MarkdownConverterService.php
│       ├── PolicyDiscoveryService.php
│       └── VersioningService.php
├── config/
│   └── scraper.php           # Scraper configuration
├── database/
│   ├── migrations/           # Database migrations
│   └── seeders/              # Database seeders
├── resources/
│   └── js/
│       ├── components/       # Vue components
│       ├── layouts/          # Page layouts
│       └── pages/            # Vue pages
│           └── companies/    # Company management pages
└── routes/
    └── web.php               # Web routes
```

## Configuration

### Scraper Settings (`config/scraper.php`)

```php
return [
    'user_agent' => 'AvantBot/1.0 (+https://example.com/bot)',
    'timeout' => 30,
    'connect_timeout' => 10,
    'max_redirects' => 5,

    'rate_limiting' => [
        'requests_per_minute' => 30,
        'delay_between_requests' => 2000, // milliseconds
    ],

    'retry' => [
        'times' => 3,
        'sleep' => 1000,
    ],

    // Common paths to check for policies
    'discovery' => [
        'common_paths' => [
            '/privacy', '/privacy-policy', '/privacypolicy',
            '/terms', '/terms-of-service', '/tos',
            // ... more paths
        ],
    ],
];
```

## API Routes

### Companies
- `GET /companies` - List all companies
- `GET /companies/{id}` - Show company details
- `POST /companies` - Create company
- `PUT /companies/{id}` - Update company
- `DELETE /companies/{id}` - Delete company

### Websites
- `POST /companies/{company}/websites` - Add website
- `PUT /websites/{id}` - Update website
- `DELETE /websites/{id}` - Delete website
- `POST /websites/{id}/discover` - Trigger policy discovery

### Documents
- `POST /websites/{website}/documents` - Add document
- `PUT /documents/{id}` - Update document
- `DELETE /documents/{id}` - Delete document
- `POST /documents/{id}/scrape` - Trigger scrape

### Products
- `POST /companies/{company}/products` - Add product
- `PUT /products/{id}` - Update product
- `DELETE /products/{id}` - Delete product
- `POST /products/{id}/documents` - Link documents
- `DELETE /products/{id}/documents/{doc}` - Unlink document

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is proprietary software. All rights reserved.
