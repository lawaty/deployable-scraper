<?php

declare(strict_types=1);

namespace DeployableScraper;

final class JobRunner
{
    private PriceChartingScraperService $scraper;
    private Logger $logger;
    private string $outputDir;

    public function __construct(PriceChartingScraperService $scraper, Logger $logger, string $outputDir)
    {
        $this->scraper = $scraper;
        $this->logger = $logger;
        $this->outputDir = rtrim($outputDir, '/');
    }

    public function run(array $jobs, bool $skipExisting = false, ?callable $onProgress = null): array
    {
        if (!is_dir($this->outputDir)) {
            @mkdir($this->outputDir, 0775, true);
        }

        $runId = self::generateRunId();

        $startedAt = gmdate('c');
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $stopReason = null;
        $total = count($jobs);

        for ($i = 0; $i < $total; $i++) {
            if ($stopReason === null) {
                if ($this->scraper->isBlockBreakerOpen()) {
                    $stopReason = 'block_breaker';
                } elseif ($this->scraper->budgetExhausted()) {
                    $stopReason = 'budget';
                }
            }

            if ($stopReason !== null) {
                $this->logger->log('Stopped early: ' . $stopReason);
                for ($j = $i; $j < $total; $j++) {
                    $job = $jobs[$j];
                    $docUrl = $this->computeUrl($job);
                    $entry = [
                        'run_id' => $runId,
                        'ts' => gmdate('c'),
                        'index' => $j,
                        'game_id' => $job['game_id'] ?? null,
                        'slug' => $job['slug'],
                        'category' => $job['category'] ?? '',
                        'url' => $docUrl,
                        'status' => 'skipped',
                        'error' => $stopReason,
                        'http_status' => 0,
                        'http_attempts' => 0,
                        'blocked' => false,
                        'path' => null,
                        'recent_sales' => 0,
                        'price_guide_entries' => 0,
                    ];
                    $this->appendManifestLine($this->outputDir, $entry);
                    $skipped++;
                }
                break;
            }

            $job = $jobs[$i];
            $slug = (string) $job['slug'];
            $category = (string) ($job['category'] ?? '');
            $url = $job['url'] ?? null;
            $expectedTitle = $job['expected_title'] ?? null;
            $docUrl = $this->computeUrl($job);

            if ($skipExisting) {
                $successPath = self::targetPathFor($job, $i, $this->outputDir, false);
                if (file_exists($successPath)) {
                    $rawContent = @file_get_contents($successPath);
                    if ($rawContent !== false) {
                        $decoded = json_decode($rawContent, true);
                        if (is_array($decoded) && ($decoded['status'] ?? null) === 'success') {
                            $entry = [
                                'run_id' => $runId,
                                'ts' => gmdate('c'),
                                'index' => $i,
                                'game_id' => $job['game_id'] ?? null,
                                'slug' => $slug,
                                'category' => $category,
                                'url' => $docUrl,
                                'status' => 'skipped',
                                'error' => 'already_exists',
                                'http_status' => null,
                                'http_attempts' => 0,
                                'blocked' => false,
                                'path' => $successPath,
                                'recent_sales' => 0,
                                'price_guide_entries' => 0,
                            ];
                            $this->appendManifestLine($this->outputDir, $entry);
                            $skipped++;
                            $this->logger->log(sprintf('[%d/%d] SKIP %s (already_exists)', $i + 1, $total, $slug));
                            continue;
                        }
                    }
                }
            }

            try {
                if (is_string($url) && $url !== '') {
                    $res = $this->scraper->scrapeByUrl($url, $slug, $expectedTitle);
                } else {
                    $res = $this->scraper->scrapeGame($slug, $category, $expectedTitle);
                }
            } catch (\Throwable $e) {
                $res = [
                    'error' => $e->getMessage(),
                    'slug' => $slug,
                    'http_status' => 0,
                    'http_attempts' => 0,
                    'blocked' => false,
                    'scrapes_total' => 0,
                    'scrapes_successful' => 0,
                ];
            }

            $isSuccess = !isset($res['error']);

            $doc = [
                'schema_version' => 1,
                'source' => 'pricecharting',
                'run_id' => $runId,
                'scraped_at' => gmdate('c'),
                'job' => [
                    'game_id' => $job['game_id'] ?? null,
                    'slug' => $slug,
                    'category' => $category,
                    'expected_title' => $expectedTitle,
                    'url' => $docUrl,
                ],
                'status' => $isSuccess ? 'success' : 'failed',
                'error' => $isSuccess ? null : ($res['error'] ?? 'unknown error'),
                'http_status' => $res['http_status'] ?? 0,
                'http_attempts' => $res['http_attempts'] ?? 0,
                'blocked' => (bool) ($res['blocked'] ?? false),
            ];
            if ($isSuccess) {
                $doc['price_guide'] = $res['price_guide'] ?? [];
                $doc['recent_sales'] = $res['recent_sales'] ?? [];
            }

            $failedFlag = !$isSuccess;
            $path = self::targetPathFor($job, $i, $this->outputDir, $failedFlag);
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $this->atomicWriteJson($path, $doc);

            if ($isSuccess) {
                $success++;
                $this->logger->log(sprintf('[%d/%d] OK %s (recent_sales=%d)', $i + 1, $total, $slug, count($res['recent_sales'] ?? [])));
            } else {
                $failed++;
                $this->logger->log(sprintf('[%d/%d] FAIL %s: %s', $i + 1, $total, $slug, $res['error'] ?? 'unknown'));
            }

            $entry = [
                'run_id' => $runId,
                'ts' => gmdate('c'),
                'index' => $i,
                'game_id' => $job['game_id'] ?? null,
                'slug' => $slug,
                'category' => $category,
                'url' => $docUrl,
                'status' => $isSuccess ? 'success' : 'failed',
                'error' => $isSuccess ? null : ($res['error'] ?? null),
                'http_status' => $res['http_status'] ?? 0,
                'http_attempts' => $res['http_attempts'] ?? 0,
                'blocked' => (bool) ($res['blocked'] ?? false),
                'path' => $path,
                'recent_sales' => $isSuccess ? count($res['recent_sales'] ?? []) : 0,
                'price_guide_entries' => $isSuccess ? count($res['price_guide'] ?? []) : 0,
            ];
            $this->appendManifestLine($this->outputDir, $entry);

            if ($onProgress !== null) {
                $onProgress($i + 1, $total, $entry);
            }

            if ($this->scraper->isBlockBreakerOpen() || $this->scraper->budgetExhausted()) {
                if ($this->scraper->isBlockBreakerOpen()) {
                    $stopReason = 'block_breaker';
                } else {
                    $stopReason = 'budget';
                }
                $this->logger->log('Stopped early: ' . $stopReason);
                for ($j = $i + 1; $j < $total; $j++) {
                    $job2 = $jobs[$j];
                    $docUrl2 = $this->computeUrl($job2);
                    $entry2 = [
                        'run_id' => $runId,
                        'ts' => gmdate('c'),
                        'index' => $j,
                        'game_id' => $job2['game_id'] ?? null,
                        'slug' => $job2['slug'],
                        'category' => $job2['category'] ?? '',
                        'url' => $docUrl2,
                        'status' => 'skipped',
                        'error' => $stopReason,
                        'http_status' => 0,
                        'http_attempts' => 0,
                        'blocked' => false,
                        'path' => null,
                        'recent_sales' => 0,
                        'price_guide_entries' => 0,
                    ];
                    $this->appendManifestLine($this->outputDir, $entry2);
                    $skipped++;
                }
                break;
            }
        }

        $finishedAt = gmdate('c');
        $summary = [
            'run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'output_dir' => $this->outputDir,
            'jobs_total' => $total,
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'stop_reason' => $stopReason,
            'budget' => $this->scraper->budgetState(),
        ];

        $summaryPath = $this->outputDir . '/run-summary.json';
        $this->atomicWriteJson($summaryPath, $summary + ['input_count' => $total]);

        return $summary;
    }

    public static function generateRunId(): string
    {
        return gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public static function loadJobsFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException('Cannot read jobs file: ' . $path);
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Cannot read jobs file: ' . $path);
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException('Invalid JSON in jobs file: ' . $path);
        }
        if (array_key_exists('jobs', $data) && is_array($data['jobs'])) {
            $list = $data['jobs'];
        } elseif (array_is_list($data)) {
            $list = $data;
        } else {
            throw new \RuntimeException('Jobs file must be a JSON list or {"jobs":[...]}: ' . $path);
        }

        $result = [];
        foreach ($list as $i => $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('Invalid job entry at index ' . $i . ' in ' . $path);
            }
            $result[] = self::normalizeJob($entry, (int) $i);
        }
        return $result;
    }

    public static function normalizeJob(array $raw, int $index): array
    {
        $prefix = 'job #' . ($index + 1) . ': ';

        if (!isset($raw['slug']) || !is_string($raw['slug']) || trim($raw['slug']) === '') {
            throw new \InvalidArgumentException($prefix . 'slug is required and must be a non-empty string');
        }
        $slug = $raw['slug'];

        $url = null;
        $hasUrl = false;
        if (array_key_exists('url', $raw) && $raw['url'] !== null) {
            if (!is_string($raw['url'])) {
                throw new \InvalidArgumentException($prefix . 'url must be a string');
            }
            $url = $raw['url'];
            $hasUrl = (trim($url) !== '');
            if (!$hasUrl) {
                $url = null;
            }
        }

        $category = '';
        $hasCategory = false;
        if (array_key_exists('category', $raw) && $raw['category'] !== null) {
            if (!is_string($raw['category'])) {
                throw new \InvalidArgumentException($prefix . 'category must be a string');
            }
            $category = $raw['category'];
            $hasCategory = (trim($category) !== '');
        }

        if (!$hasUrl && !$hasCategory) {
            throw new \InvalidArgumentException($prefix . 'either url or category is required');
        }

        $expectedTitle = null;
        if (array_key_exists('expected_title', $raw)) {
            if ($raw['expected_title'] === null) {
                $expectedTitle = null;
            } elseif (is_string($raw['expected_title'])) {
                $expectedTitle = $raw['expected_title'];
            } else {
                throw new \InvalidArgumentException($prefix . 'expected_title must be string or null');
            }
        }

        $gameId = null;
        if (array_key_exists('game_id', $raw)) {
            $v = $raw['game_id'];
            if ($v === null) {
                $gameId = null;
            } elseif (is_int($v)) {
                $gameId = $v;
            } elseif (is_string($v) && is_numeric($v)) {
                $gameId = (int) $v;
            } else {
                throw new \InvalidArgumentException($prefix . 'game_id must be int, numeric string, or null');
            }
        }

        return [
            'slug' => $slug,
            'category' => $category,
            'url' => $url,
            'expected_title' => $expectedTitle,
            'game_id' => $gameId,
        ];
    }

    public static function targetPathFor(array $job, int $index, string $outputDir, bool $failed): string
    {
        $slug = isset($job['slug']) && is_string($job['slug']) ? $job['slug'] : '';
        $category = isset($job['category']) && is_string($job['category']) ? $job['category'] : '';
        $catSeg = ($category !== '') ? $category : '_url';

        $sanCat = self::sanitizeSegment($catSeg);
        $sanSlug = self::sanitizeSegment($slug);
        if ($sanSlug === '') {
            $sanSlug = 'job-' . $index;
        }

        $base = rtrim($outputDir, '/');
        if ($failed) {
            return $base . '/_failed/' . $sanCat . '/' . $sanSlug . '.json';
        }
        return $base . '/' . $sanCat . '/' . $sanSlug . '.json';
    }

    public function appendManifestLine(string $outputDir, array $entry): void
    {
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0775, true);
        }
        $path = rtrim($outputDir, '/') . '/manifest.jsonl';
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            $this->logger->log('Failed to encode manifest line: ' . json_last_error_msg(), 'ERROR');
            return;
        }
        $result = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($result === false) {
            $this->logger->log('Failed to append manifest line: ' . $path, 'ERROR');
        }
    }

    private function computeUrl(array $job): string
    {
        if (isset($job['url']) && is_string($job['url']) && $job['url'] !== '') {
            return $job['url'];
        }
        $category = $job['category'] ?? '';
        $slug = $job['slug'];
        return 'https://www.pricecharting.com/game/' . $category . '/' . urlencode($slug);
    }

    private function atomicWriteJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $dir . '/.' . basename($path) . '.' . uniqid('', true) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException(json_last_error_msg());
        }
        $bytes = @file_put_contents($tmp, $json);
        if ($bytes === false) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to write temp file: ' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to rename temp file to: ' . $path);
        }
    }

    private static function sanitizeSegment(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9._-]+/', '-', $s);
        if ($s === null) {
            $s = '';
        }
        return trim($s, '-');
    }
}
