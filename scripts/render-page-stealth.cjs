#!/usr/bin/env node
/**
 * Stealth Page Renderer Script
 *
 * Uses puppeteer-extra with stealth plugin to bypass bot detection.
 */

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

// Add stealth plugin
puppeteer.use(StealthPlugin());

const DEFAULT_TIMEOUT = 30000;
const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

async function renderPage(url, options = {}) {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--disable-gpu',
            '--window-size=1920,1080',
        ],
    });

    try {
        const page = await browser.newPage();

        // Set viewport
        await page.setViewport({ width: 1920, height: 1080 });

        // Set user agent
        await page.setUserAgent(options.userAgent || DEFAULT_USER_AGENT);

        // Set extra headers to look more like a real browser
        await page.setExtraHTTPHeaders({
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.9',
            'Accept-Encoding': 'gzip, deflate, br',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
            'Sec-Fetch-Dest': 'document',
            'Sec-Fetch-Mode': 'navigate',
            'Sec-Fetch-Site': 'none',
            'Sec-Fetch-User': '?1',
            'Cache-Control': 'max-age=0',
        });

        // Set timeout
        page.setDefaultTimeout(options.timeout || DEFAULT_TIMEOUT);

        // Navigate to the page
        const response = await page.goto(url, {
            waitUntil: options.waitUntil || 'networkidle2',
            timeout: options.timeout || DEFAULT_TIMEOUT,
        });

        // Wait for selector if provided
        if (options.waitForSelector) {
            await page.waitForSelector(options.waitForSelector, {
                timeout: options.timeout || DEFAULT_TIMEOUT,
            });
        }

        // Wait for content to load
        try {
            await page.waitForFunction(() => {
                const body = document.body;
                if (!body) return false;
                const text = body.innerText || '';
                return text.length > 500;
            }, { timeout: 10000 });
        } catch (e) {
            // Continue anyway
        }

        // Extra wait for dynamic content
        await new Promise(r => setTimeout(r, 2000));

        // Get the rendered HTML
        const html = await page.content();

        // Get text content
        const text = await page.evaluate(() => {
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
            browser: 'chromium-stealth',
        };

    } finally {
        await browser.close();
    }
}

async function main() {
    const url = process.argv[2];

    if (!url) {
        console.log(JSON.stringify({
            success: false,
            error: 'URL is required as first argument',
        }));
        process.exit(1);
    }

    let options = {};
    if (process.argv[3]) {
        try {
            options = JSON.parse(process.argv[3]);
        } catch (e) {
            // Not JSON, ignore
        }
    }

    try {
        const result = await renderPage(url, options);
        console.log(JSON.stringify(result));
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message,
        }));
        process.exit(1);
    }
}

main();
