#!/usr/bin/env node
/**
 * Page Renderer Script
 *
 * Renders JavaScript-heavy pages using Playwright (Chromium) or Lightpanda.
 * Called from PHP to handle pages that require JS execution.
 *
 * Usage: node render-page.js <url> [options]
 *
 * Options (passed as JSON via stdin or as second argument):
 *   - browser: 'chromium' | 'lightpanda' (default: 'chromium')
 *   - timeout: number in ms (default: 30000)
 *   - waitUntil: 'load' | 'domcontentloaded' | 'networkidle' (default: 'networkidle')
 *   - waitForSelector: CSS selector to wait for
 *   - userAgent: custom user agent string
 *
 * Output: JSON with { success, html, text, error, metadata }
 */

const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

// Configuration
const DEFAULT_TIMEOUT = 30000;
const DEFAULT_WAIT_UNTIL = 'networkidle';
const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// Lightpanda CDP endpoint (if running)
const LIGHTPANDA_ENDPOINT = process.env.LIGHTPANDA_ENDPOINT || 'http://127.0.0.1:9222';

async function renderWithChromium(url, options) {
    const browser = await chromium.launch({
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--disable-gpu',
            '--single-process',
        ],
    });

    try {
        const context = await browser.newContext({
            userAgent: options.userAgent || DEFAULT_USER_AGENT,
            viewport: { width: 1920, height: 1080 },
            ignoreHTTPSErrors: true,
        });

        const page = await context.newPage();

        // Set timeout
        page.setDefaultTimeout(options.timeout || DEFAULT_TIMEOUT);

        // Navigate to the page
        const response = await page.goto(url, {
            waitUntil: options.waitUntil || DEFAULT_WAIT_UNTIL,
            timeout: options.timeout || DEFAULT_TIMEOUT,
        });

        // Wait for specific selector if provided
        if (options.waitForSelector) {
            await page.waitForSelector(options.waitForSelector, {
                timeout: options.timeout || DEFAULT_TIMEOUT,
            });
        }

        // Additional wait for dynamic content to load
        // Try to wait for main content area or body text
        try {
            await page.waitForFunction(() => {
                const body = document.body;
                if (!body) return false;
                const text = body.innerText || '';
                // Wait until we have substantial text content
                return text.length > 1000;
            }, { timeout: 10000 });
        } catch (e) {
            // Timeout waiting for content, continue anyway
        }

        // Extra wait for any final rendering
        await page.waitForTimeout(2000);

        // Get the rendered HTML
        const html = await page.content();

        // Get text content
        const text = await page.evaluate(() => {
            // Remove script and style elements
            const clone = document.body.cloneNode(true);
            clone.querySelectorAll('script, style, noscript').forEach(el => el.remove());
            return clone.innerText || clone.textContent || '';
        });

        // Get metadata
        const metadata = await page.evaluate(() => ({
            title: document.title,
            description: document.querySelector('meta[name="description"]')?.content || null,
            canonicalUrl: document.querySelector('link[rel="canonical"]')?.href || null,
            language: document.documentElement.lang || null,
        }));

        return {
            success: true,
            html,
            text: text.trim(),
            httpStatus: response?.status() || null,
            finalUrl: page.url(),
            metadata,
            browser: 'chromium',
        };

    } finally {
        await browser.close();
    }
}

async function renderWithLightpanda(url, options) {
    // Lightpanda uses websocket CDP directly
    const wsEndpoint = LIGHTPANDA_ENDPOINT.replace(/^http/, 'ws');

    // Connect to Lightpanda via CDP websocket
    let browser;
    try {
        browser = await chromium.connectOverCDP(wsEndpoint);
    } catch (e) {
        throw new Error(`Lightpanda not available at ${wsEndpoint}: ${e.message}`);
    }

    try {
        // Lightpanda has limited CDP support - use minimal context options
        // Don't set userAgent - Lightpanda doesn't support Emulation.setUserAgentOverride yet
        const context = await browser.newContext({
            ignoreHTTPSErrors: true,
        });

        const page = await context.newPage();
        page.setDefaultTimeout(options.timeout || DEFAULT_TIMEOUT);

        // Use simpler waitUntil for Lightpanda - 'load' is more compatible
        const response = await page.goto(url, {
            waitUntil: 'load',
            timeout: options.timeout || DEFAULT_TIMEOUT,
        });

        if (options.waitForSelector) {
            try {
                await page.waitForSelector(options.waitForSelector, {
                    timeout: options.timeout || DEFAULT_TIMEOUT,
                });
            } catch (e) {
                // Lightpanda may not support waitForSelector
            }
        }

        // Simple wait for content - Lightpanda may not support waitForFunction
        await page.waitForTimeout(3000);

        const html = await page.content();

        // Simple text extraction
        let text = '';
        try {
            text = await page.evaluate(() => {
                const clone = document.body.cloneNode(true);
                clone.querySelectorAll('script, style, noscript').forEach(el => el.remove());
                return clone.innerText || clone.textContent || '';
            });
        } catch (e) {
            // Fallback: extract text from HTML
            text = html.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '')
                       .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
                       .replace(/<[^>]+>/g, ' ')
                       .replace(/\s+/g, ' ')
                       .trim();
        }

        let metadata = { title: '', description: null, canonicalUrl: null, language: null };
        try {
            metadata = await page.evaluate(() => ({
                title: document.title,
                description: document.querySelector('meta[name="description"]')?.content || null,
                canonicalUrl: document.querySelector('link[rel="canonical"]')?.href || null,
                language: document.documentElement.lang || null,
            }));
        } catch (e) {
            // Extract title from HTML as fallback
            const titleMatch = html.match(/<title>([^<]*)<\/title>/i);
            metadata.title = titleMatch ? titleMatch[1] : '';
        }

        return {
            success: true,
            html,
            text: text.trim(),
            httpStatus: response?.status() || null,
            finalUrl: page.url(),
            metadata,
            browser: 'lightpanda',
        };

    } finally {
        await browser.close();
    }
}

async function main() {
    const url = process.argv[2];

    if (!url) {
        console.error(JSON.stringify({
            success: false,
            error: 'URL is required as first argument',
        }));
        process.exit(1);
    }

    // Parse options from argument or stdin
    let options = {};

    if (process.argv[3]) {
        try {
            options = JSON.parse(process.argv[3]);
        } catch (e) {
            // Not JSON, ignore
        }
    }

    // Also check for stdin input (non-blocking)
    if (!process.stdin.isTTY) {
        try {
            const stdinData = fs.readFileSync(0, 'utf8');
            if (stdinData.trim()) {
                options = { ...options, ...JSON.parse(stdinData) };
            }
        } catch (e) {
            // No stdin or invalid JSON, ignore
        }
    }

    try {
        let result;

        if (options.browser === 'lightpanda') {
            try {
                result = await renderWithLightpanda(url, options);
            } catch (e) {
                // Fallback to Chromium if Lightpanda fails
                console.error(`Lightpanda failed, falling back to Chromium: ${e.message}`);
                result = await renderWithChromium(url, options);
                result.fallback = true;
                result.fallbackReason = e.message;
            }
        } else {
            result = await renderWithChromium(url, options);
        }

        console.log(JSON.stringify(result));

    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message,
            stack: error.stack,
        }));
        process.exit(1);
    }
}

main();
