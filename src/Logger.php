<?php

declare(strict_types=1);

namespace DeployableScraper;

final class Logger
{
    private string $logDir;
    private bool $toStderr;

    public function __construct(string $logDir, bool $toStderr = true)
    {
        $this->logDir = rtrim($logDir, '/');
        $this->toStderr = $toStderr;
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
    }

    public function log(string $message, string $level = 'INFO'): void
    {
        try {
            $date = gmdate('Y-m-d');
            $utcDateTime = gmdate('Y-m-d H:i:s');
            $line = '[' . $utcDateTime . '] [' . $level . '] ' . $message . "\n";

            if (!is_dir($this->logDir)) {
                @mkdir($this->logDir, 0775, true);
            }

            $path = $this->logDir . '/scrape-' . $date . '.log';
            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

            if ($this->toStderr && defined('STDERR')) {
                @fwrite(STDERR, $line);
            }
        } catch (\Throwable $e) {
            // Never throw.
        }
    }

    public function path(): string
    {
        $date = gmdate('Y-m-d');
        return $this->logDir . '/scrape-' . $date . '.log';
    }
}
