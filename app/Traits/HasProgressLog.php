<?php

namespace App\Traits;

trait HasProgressLog
{
    /**
     * Initialize an empty progress log
     */
    public function initializeProgressLog(): void
    {
        $this->progress_log = [];
        $this->save();
    }

    /**
     * Add a timestamped entry to the progress log
     */
    public function logProgress(string $message, string $type = 'info', array $data = []): void
    {
        $log = $this->progress_log ?? [];

        $log[] = [
            'timestamp' => now()->toISOString(),
            'message' => $message,
            'type' => $type, // info, success, warning, error
            'data' => $data,
        ];

        $this->progress_log = $log;
        $this->save();
    }

    /**
     * Get all progress log entries
     */
    public function getProgressLog(): array
    {
        return $this->progress_log ?? [];
    }

    /**
     * Log an info message
     */
    public function logInfo(string $message, array $data = []): void
    {
        $this->logProgress($message, 'info', $data);
    }

    /**
     * Log a success message
     */
    public function logSuccess(string $message, array $data = []): void
    {
        $this->logProgress($message, 'success', $data);
    }

    /**
     * Log a warning message
     */
    public function logWarning(string $message, array $data = []): void
    {
        $this->logProgress($message, 'warning', $data);
    }

    /**
     * Log an error message
     */
    public function logError(string $message, array $data = []): void
    {
        $this->logProgress($message, 'error', $data);
    }
}
