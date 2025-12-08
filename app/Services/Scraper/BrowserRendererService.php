<?php

namespace App\Services\Scraper;

use App\Services\Scraper\DTO\BrowserRenderResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class BrowserRendererService
{
    protected string $scriptPath;
    protected string $defaultBrowser;
    protected int $defaultTimeout;

    public function __construct()
    {
        $this->scriptPath = base_path('scripts/render-page.cjs');
        $this->defaultBrowser = config('scraper.browser.default', 'chromium');
        $this->defaultTimeout = config('scraper.browser.timeout', 30000);
    }

    /**
     * Render a page using a headless browser.
     */
    public function render(string $url, array $options = []): BrowserRenderResult
    {
        if (!file_exists($this->scriptPath)) {
            return BrowserRenderResult::failure("Render script not found: {$this->scriptPath}");
        }

        $options = array_merge([
            'browser' => $this->defaultBrowser,
            'timeout' => $this->defaultTimeout,
            'waitUntil' => 'networkidle',
            'userAgent' => config('scraper.browser.user_agent', null),
        ], $options);

        // Filter null values
        $options = array_filter($options, fn ($v) => $v !== null);

        $optionsJson = json_encode($options);

        try {
            $result = Process::timeout(($options['timeout'] / 1000) + 30)
                ->run([
                    'node',
                    $this->scriptPath,
                    $url,
                    $optionsJson,
                ]);

            if (!$result->successful()) {
                $errorOutput = $result->errorOutput();
                Log::error('Browser render failed', [
                    'url' => $url,
                    'exitCode' => $result->exitCode(),
                    'error' => $errorOutput,
                ]);

                // Try to parse error from output
                $output = $result->output();
                if ($output && str_starts_with(trim($output), '{')) {
                    $data = json_decode($output, true);
                    if ($data && isset($data['error'])) {
                        return BrowserRenderResult::failure($data['error']);
                    }
                }

                return BrowserRenderResult::failure("Browser process failed: {$errorOutput}");
            }

            $output = $result->output();
            $data = json_decode($output, true);

            if (!$data) {
                return BrowserRenderResult::failure('Failed to parse browser output');
            }

            return BrowserRenderResult::fromJson($data);

        } catch (\Exception $e) {
            Log::error('Browser render exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return BrowserRenderResult::failure($e->getMessage());
        }
    }

    /**
     * Check if browser rendering is available.
     */
    public function isAvailable(): bool
    {
        if (!file_exists($this->scriptPath)) {
            return false;
        }

        // Check if node is available
        $result = Process::run('node --version');

        return $result->successful();
    }

    /**
     * Check if Lightpanda is running.
     * Lightpanda uses websocket CDP, so we try a socket connection.
     */
    public function isLightpandaAvailable(): bool
    {
        $endpoint = config('scraper.browser.lightpanda_endpoint', 'http://127.0.0.1:9222');

        // Parse host and port from endpoint
        $parsed = parse_url($endpoint);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 9222;

        // Try to connect to the socket
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);

        if ($socket) {
            fclose($socket);
            return true;
        }

        return false;
    }

    /**
     * Get the best available browser.
     */
    public function getBestAvailableBrowser(): string
    {
        // Prefer Lightpanda if available (lighter weight)
        if ($this->isLightpandaAvailable()) {
            return 'lightpanda';
        }

        return 'chromium';
    }
}
