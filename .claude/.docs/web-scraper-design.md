# Web Scraper Discovery + Versioning System
### Laravel / PHP Implementation Design Document

## 1. Overview
This document describes the design for a Laravel-integrated web scraper that:
1. Automatically discovers Terms of Service, Privacy Policies, Cookie Policies, and other legal documents.
2. Scrapes, normalizes, and stores the full content.
3. Version-tracks changes.
4. Runs manually now, with optional scheduled crawls.

## 2. Laravel Architecture
### Components
- Models: Company, Website, Policy, PolicyVersion, CrawlJob
- Services: PolicyDiscoveryService, PolicyScraperService, PolicyVersioningService
- Jobs: RunWebsitePolicyCrawl
- Commands: policies:crawl
- Scheduler: via Kernel.php
- UI pages for management and viewing diffs

## 3. Recommended Libraries
- symfony/dom-crawler
- symfony/css-selector
- spatie/crawler
- league/html-to-markdown
- sebastian/diff
- spatie/browsershot (optional)

## 4. Database Schema
Includes companies, websites, policies, policy_versions, crawl_jobs.

## 5. Discovery Logic
Generate common paths, parse homepage links, parse sitemap, score URLs, choose best per policy type.

## 6. Scraper Logic
Fetch HTML using Laravel Http client, extract main content, convert to Markdown, normalize formatting.

## 7. Versioning Logic
Hash normalized content, compare with latest version, create new version if changed.

## 8. Job Orchestration
RunWebsitePolicyCrawl dispatches discovery → scraping → versioning.

## 9. Manual Command
php artisan policies:crawl {website_id}

## 10. Scheduler
Use Laravel scheduler to automatically queue crawls.

## 11. UI Requirements
Pages for website management, policy dashboard, version viewer, diff viewer.

## 12. Extensions
PDF support, language detection, improved heuristics, git mirroring, per-site frequency.

