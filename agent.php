#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/src/FileStateStore.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/PriceChartingScraperService.php';
require_once __DIR__ . '/src/JobRunner.php';
require_once __DIR__ . '/src/Agent/HmacSigner.php';
require_once __DIR__ . '/src/Agent/CentralClient.php';
require_once __DIR__ . '/src/Agent/ResultPoster.php';

use DeployableScraper\JobRunner;
use DeployableScraper\Logger;
use DeployableScraper\PriceChartingScraperService;

function readAgentLogTail(string $stateDir, int $limit = 100): array
{
    $logDir = rtrim($stateDir, '/') . '/logs';
    if (!is_dir($logDir)) {
        return [];
    }

    $files = glob($logDir . '/*.log') ?: [];
    if ($files === []) {
        return [];
    }

    usort($files, static function (string $a, string $b): int {
        return (int) @filemtime($b) <=> (int) @filemtime($a);
    });

    $lines = @file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || $lines === []) {
        return [];
    }

    $filtered = [];
    foreach ($lines as $line) {
        if (preg_match('/\bPOST\s+https?:\/\//', $line)) {
            continue;
        }
        $filtered[] = $line;
    }

    return array_values(array_slice($filtered, -max(1, $limit)));
}

function printAgentUsage($stream): void
{
    $msg = <<<USAGE
Usage: php agent.php [options]

Polling mode (default):
  --central=URL       Central API base URL (required)
  --server-id=INT     Server ID (required)
  --secret-file=PATH  Path to secret file (default: <script-dir>/secret.txt)
  --secret=STRING     Secret string (overrides secret-file)
  --config=PATH       Scraper config file returning array
  --poll-interval=INT Poll interval seconds (default: 30, min 5)
  --output=DIR        Output directory (default: <script-dir>/output)
  --state=DIR         State directory (default: <script-dir>/state)
  --max-retries=INT   Scraper max retries (default: 3)
  --interval=FLOAT    Override request interval seconds
  --dlq-dir=DIR       DLQ directory (default: state/dlq)
  --once              Claim/run/post once then exit
  --verify            POST /scraper/verify then exit
  --help              Show this help

Manual mode (when --input, --slug, or --url is given):
  --input=PATH        JSON jobs file
  --slug=SLUG         Build a single job (requires --category unless --url)
  --category=CAT      Category for --slug
  --url=URL           Explicit PriceCharting URL for a single job
  --expected-title=T  Expected game title
  --game-id=ID        Opaque game id passthrough
  --limit=N           Slice job list to first N after offset
  --offset=N          Skip first N jobs
  --skip-existing     Skip jobs whose success doc already exists
  --dry-run           Normalize and print jobs as JSON, no network/writes
  --output=DIR        Output directory (default: <script-dir>/output)
  --state=DIR         State directory (default: <script-dir>/state)
  --config=PATH       Scraper config file returning array
  --interval=FLOAT    Override request interval seconds
  --max-retries=INT   Scraper max retries (default: 3)

Manual mode writes local JSON output only. It does not contact central and
does not use secret.txt.

USAGE;
    fwrite($stream, $msg);
}

$allowed = [
    'central', 'server-id', 'secret-file', 'secret', 'config',
    'poll-interval', 'output', 'state', 'max-retries',
    'interval', 'dlq-dir', 'once', 'verify', 'help',
    'input', 'slug', 'category', 'url', 'expected-title', 'game-id',
    'limit', 'offset', 'skip-existing', 'dry-run',
];

$args = array_slice($argv ?? [], 1);
$options = [];
$i = 0;
$n = count($args);
while ($i < $n) {
    $arg = $args[$i];
    if (strpos($arg, '--') !== 0) {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        printAgentUsage(STDERR);
        exit(2);
    }
    if (strpos($arg, '=') !== false) {
        [$name, $value] = explode('=', substr($arg, 2), 2);
        if (!in_array($name, $allowed, true)) {
            fwrite(STDERR, "Unknown option: --{$name}\n");
            printAgentUsage(STDERR);
            exit(2);
        }
        $options[$name] = $value;
        $i++;
    } else {
        $name = substr($arg, 2);
        if (!in_array($name, $allowed, true)) {
            fwrite(STDERR, "Unknown option: --{$name}\n");
            printAgentUsage(STDERR);
            exit(2);
        }
        if (in_array($name, ['once', 'verify', 'help', 'skip-existing', 'dry-run'], true)) {
            $options[$name] = true;
            $i++;
        } else {
            if ($i + 1 >= $n) {
                fwrite(STDERR, "Missing value for --{$name}\n");
                printAgentUsage(STDERR);
                exit(2);
            }
            $options[$name] = $args[$i + 1];
            $i += 2;
        }
    }
}

if (isset($options['help'])) {
    printAgentUsage(STDOUT);
    exit(0);
}

$manual = isset($options['input']) || isset($options['slug']) || isset($options['url']);

// ---------- Manual mode ----------
if ($manual) {
    if (isset($options['input']) && (isset($options['slug']) || isset($options['url']))) {
        fwrite(STDERR, "FATAL: --input cannot be combined with --slug/--url\n");
        exit(3);
    }
    if (isset($options['slug']) && !isset($options['url']) && !isset($options['category'])) {
        fwrite(STDERR, "FATAL: --category is required when --slug is used without --url\n");
        exit(3);
    }
    if (isset($options['once'])) {
        fwrite(STDERR, "FATAL: --once is not allowed in manual mode\n");
        exit(3);
    }

    $outputDir = $options['output'] ?? (__DIR__ . '/output');
    $stateDir = $options['state'] ?? (__DIR__ . '/state');
    $limit = isset($options['limit']) ? (int) $options['limit'] : null;
    $offset = isset($options['offset']) ? (int) $options['offset'] : 0;
    $skipExisting = isset($options['skip-existing']);
    $dryRun = isset($options['dry-run']);
    $maxRetries = isset($options['max-retries']) ? (int) $options['max-retries'] : 3;
    $interval = isset($options['interval']) ? (float) $options['interval'] : null;

    $singleJob = isset($options['slug']) || isset($options['url']);
    $jobRaw = null;
    if ($singleJob) {
        $raw = [];
        if (isset($options['slug'])) {
            $raw['slug'] = $options['slug'];
        }
        if (isset($options['category'])) {
            $raw['category'] = $options['category'];
        }
        if (isset($options['url'])) {
            $raw['url'] = $options['url'];
        }
        if (isset($options['expected-title'])) {
            $raw['expected_title'] = $options['expected-title'];
        }
        if (isset($options['game-id'])) {
            $raw['game_id'] = $options['game-id'];
        }
        $jobRaw = $raw;
    }

    $inputPath = $options['input'] ?? null;
    if (!$singleJob && $inputPath === null) {
        fwrite(STDERR, "FATAL: --input is required unless --slug or --url is given\n");
        exit(3);
    }

    $config = null;
    if (isset($options['config'])) {
        $configPath = $options['config'];
        if (!is_readable($configPath)) {
            fwrite(STDERR, "FATAL: Cannot read config file: {$configPath}\n");
            exit(3);
        }
        $loaded = require $configPath;
        if (!is_array($loaded)) {
            fwrite(STDERR, "FATAL: Config file must return an array\n");
            exit(3);
        }
        $config = $loaded;
    }

    try {
        if ($singleJob) {
            $jobs = [JobRunner::normalizeJob($jobRaw, 0)];
        } else {
            $jobs = JobRunner::loadJobsFile($inputPath);
        }
        if ($offset > 0) {
            $jobs = array_slice($jobs, $offset);
        }
        if ($limit !== null) {
            $jobs = array_slice($jobs, 0, $limit);
        }
        $jobs = array_values($jobs);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
        exit(3);
    }

    if ($dryRun) {
        echo json_encode($jobs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    @mkdir($outputDir, 0775, true);
    @mkdir($stateDir, 0775, true);

    $logger = new Logger($stateDir . '/logs', true);
    $logFn = function (string $m, string $l = 'INFO') use ($logger): void {
        $logger->log($m, $l);
    };

    $scraper = new PriceChartingScraperService($logFn, $maxRetries, $interval, $stateDir, $config);
    $runner = new JobRunner($scraper, $logger, $outputDir);

    try {
        $summary = $runner->run($jobs, $skipExisting);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
        exit(3);
    }

    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    if (($summary['stop_reason'] ?? null) !== null) {
        exit(2);
    }
    if (($summary['failed'] ?? 0) > 0) {
        exit(1);
    }
    exit(0);
}

// ---------- Polling mode ----------
$central = null;
if (isset($options['central'])) {
    $central = (string) $options['central'];
}
if (!isset($options['central'])) {
    fwrite(STDERR, "Missing required option: --central\n");
    printAgentUsage(STDERR);
    exit(2);
}
if (!isset($options['server-id'])) {
    fwrite(STDERR, "Missing required option: --server-id\n");
    printAgentUsage(STDERR);
    exit(2);
}

$central = (string) $options['central'];
$serverId = (int) $options['server-id'];
$secretFile = $options['secret-file'] ?? (__DIR__ . '/secret.txt');
$secret = null;
if (isset($options['secret'])) {
    $secret = (string) $options['secret'];
} else {
    if (!is_readable($secretFile)) {
        fwrite(STDERR, "Cannot read secret file: {$secretFile}\n");
        exit(2);
    }
    $secret = trim((string) @file_get_contents($secretFile));
}
if ($secret === null || $secret === '') {
    fwrite(STDERR, "Secret must be non-empty\n");
    exit(2);
}

$outputDir = $options['output'] ?? (__DIR__ . '/output');
$stateDir = $options['state'] ?? (__DIR__ . '/state');
$dlqDir = $options['dlq-dir'] ?? ($stateDir . '/dlq');
$pollInterval = isset($options['poll-interval']) ? (int) $options['poll-interval'] : 30;
if ($pollInterval < 5) {
    $pollInterval = 5;
}
$maxRetries = isset($options['max-retries']) ? (int) $options['max-retries'] : 3;
$interval = isset($options['interval']) ? (float) $options['interval'] : null;

@mkdir($outputDir, 0775, true);
@mkdir($stateDir, 0775, true);
@mkdir($dlqDir, 0775, true);

$logger = new Logger($stateDir . '/logs', true);
$logFn = function (string $m, string $l = 'INFO') use ($logger): void {
    $logger->log($m, $l);
};

$config = null;
if (isset($options['config'])) {
    $configPath = $options['config'];
    if (!is_readable($configPath)) {
        fwrite(STDERR, "Cannot read config file: {$configPath}\n");
        exit(2);
    }
    $loaded = require $configPath;
    if (!is_array($loaded)) {
        fwrite(STDERR, "Config file must return an array\n");
        exit(2);
    }
    $config = $loaded;
}

$scraper = new PriceChartingScraperService($logFn, $maxRetries, $interval, $stateDir, $config);
$centralLogger = new Logger($stateDir . '/logs-central', false);
$client = new CentralClient($central, $serverId, $secret, $centralLogger);
$poster = new ResultPoster($client, $logger, $dlqDir);

$applyRemoteSettings = function (array $response) use ($scraper): void {
    if (isset($response['json']['daily_cap']) && is_numeric($response['json']['daily_cap'])) {
        $cap = (int) $response['json']['daily_cap'];
        if ($cap > 0) {
            $scraper->setDailyCap($cap);
        }
    }
};

$running = true;
$shutdown = function () use (&$running): void {
    $running = false;
};
if (function_exists('pcntl_async_signals')) {
    @pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    @pcntl_signal(SIGTERM, $shutdown);
    @pcntl_signal(SIGINT, $shutdown);
}

if (isset($options['verify'])) {
    $res = $client->post('/scraper/verify', []);
    $out = $res['json'] ?? ['status' => $res['status'], 'error' => $res['error']];
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (isset($res['status']) && $res['status'] >= 200 && $res['status'] < 300) {
        exit(0);
    }
    exit(1);
}

$hbResInitial = $client->post('/scraper/heartbeat', ['logs' => readAgentLogTail($stateDir, 100), 'budget' => $scraper->budgetState()]);
$applyRemoteSettings($hbResInitial);
if (isset($hbResInitial['json']['reset_budget']) && $hbResInitial['json']['reset_budget'] === true) {
    $scraper->resetDailyBudget();
    $logger->log('Daily cap reset by central', 'INFO');
}

while ($running) {
    $budget = $scraper->budgetState();
    if (!empty($budget['exhausted'])) {
        $reason = $budget['reason'] ?? 'unknown';
        $resumeAt = $budget['resume_at'] ?? null;
        $resumeLabel = $resumeAt === null ? 'unknown' : gmdate('Y-m-d H:i:s', $resumeAt) . ' UTC';
        $logger->log(sprintf('%s; resets at %s', $reason === 'daily_cap' ? 'Daily cap reached' : 'Request budget paused', $resumeLabel), 'WARN');
        $hbRes = $client->post('/scraper/heartbeat', ['logs' => readAgentLogTail($stateDir, 100), 'budget' => $scraper->budgetState()]);
        if (!isset($hbRes['status']) || $hbRes['status'] < 200 || $hbRes['status'] >= 300) {
            $logger->log('Heartbeat failed with status ' . (int) ($hbRes['status'] ?? 0), 'WARN');
        }
        $applyRemoteSettings($hbRes);
        if (isset($hbRes['json']['reset_budget']) && $hbRes['json']['reset_budget'] === true) {
            $scraper->resetDailyBudget();
            $logger->log('Daily cap reset by central', 'INFO');
        }
        $budget = $scraper->budgetState();
        $resumeAt = $budget['resume_at'] ?? null;
        if (!empty($budget['exhausted'])) {
            if (isset($options['once'])) {
                exit(0);
            }
            $now = time();
            if ($resumeAt === null) {
                sleep($pollInterval);
                continue;
            }
            $wait = (int) $resumeAt - $now;
            if ($wait < 0) {
                $wait = 0;
            }
            if ($wait > 300) {
                $wait = 300;
            }
            sleep($wait);
            continue;
        }
        // budget no longer exhausted, fall through to normal /next call
    }

    $res = $client->post('/scraper/next', ['logs' => readAgentLogTail($stateDir, 100)]);
    $applyRemoteSettings($res);

    if (isset($res['json']['reset_budget']) && $res['json']['reset_budget'] === true) {
        $scraper->resetDailyBudget();
        $logger->log('Daily cap reset by central', 'INFO');
    }

    if ($res['status'] === 401) {
        $logger->log('Authentication failed', 'ERROR');
        exit(1);
    }
    if ($res['status'] === 403) {
        $logger->log('Server disabled; sleeping 300s', 'WARN');
        if (isset($options['once'])) {
            exit(1);
        }
        sleep(300);
        continue;
    }
    if ($res['status'] === 429) {
        $headers = $res['headers'] ?? [];
        $retryAfter = isset($headers['retry-after']) ? (int) $headers['retry-after'] : 60;
        if ($retryAfter < 1) {
            $retryAfter = 60;
        }
        sleep($retryAfter);
        continue;
    }
    if ($res['status'] === 0 || $res['status'] < 200 || $res['status'] >= 300) {
        $logger->log('next failed; backing off', 'WARN');
        sleep(30);
        // exponential backoff small
        for ($b = 1; $b < 4 && $running; $b++) {
            sleep(30 * (2 ** $b));
            if (!$running) {
                break;
            }
        }
        continue;
    }

    $jobs = $res['json']['jobs'] ?? [];
    if (!is_array($jobs) || empty($jobs)) {
        if (isset($options['once'])) {
            exit(0);
        }
        $hint = isset($res['json']['poll_hint']) ? (int) $res['json']['poll_hint'] : $pollInterval;
        if ($hint < 5) {
            $hint = $pollInterval;
        }
        sleep($hint);
        continue;
    }

    $manifestPath = rtrim($outputDir, '/') . '/manifest.jsonl';
    $offset = is_file($manifestPath) ? (int) filesize($manifestPath) : 0;

    $runJobs = [];
    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $j = [];
        if (isset($job['game_id'])) {
            $j['game_id'] = $job['game_id'];
        }
        if (isset($job['slug'])) {
            $j['slug'] = $job['slug'];
        }
        if (isset($job['category'])) {
            $j['category'] = $job['category'];
        }
        if (isset($job['url'])) {
            $j['url'] = $job['url'];
        }
        if (array_key_exists('expected_title', $job)) {
            if ($job['expected_title'] === null) {
                // omit or set null; normalizeJob accepts null
                $j['expected_title'] = null;
            } elseif (is_string($job['expected_title'])) {
                $j['expected_title'] = $job['expected_title'];
            }
        }
        if (isset($job['job_id'])) {
            $j['job_id'] = $job['job_id'];
        }
        $runJobs[] = $j;
    }

    $buildHeartbeatPayload = function (?int $done = null, ?int $total = null, ?array $entry = null) use ($stateDir, $scraper): array {
        $payload = ['logs' => readAgentLogTail($stateDir, 100), 'budget' => $scraper->budgetState()];
        if ($done !== null && $total !== null) {
            $payload['progress'] = [
                'done' => max(0, $done),
                'total' => max(0, $total),
                'current_slug' => (is_array($entry) && isset($entry['slug']) && is_string($entry['slug'])) ? $entry['slug'] : null,
                'status' => (is_array($entry) && isset($entry['status']) && is_string($entry['status'])) ? $entry['status'] : null,
            ];
        }
        return $payload;
    };

    $lastHeartbeat = time();
    $heartbeat = function (int $done, int $total, array $entry) use (&$lastHeartbeat, $client, $stateDir, $logger, $buildHeartbeatPayload, $scraper, $applyRemoteSettings): void {
        if (time() - $lastHeartbeat < 60) {
            return;
        }
        $lastHeartbeat = time();
        $res = $client->post('/scraper/heartbeat', $buildHeartbeatPayload($done, $total, $entry));
        if (!isset($res['status']) || $res['status'] < 200 || $res['status'] >= 300) {
            $logger->log('Heartbeat failed with status ' . (int) ($res['status'] ?? 0), 'WARN');
        }
        $applyRemoteSettings($res);
        if (isset($res['json']['reset_budget']) && $res['json']['reset_budget'] === true) {
            $scraper->resetDailyBudget();
            $logger->log('Daily cap reset by central', 'INFO');
        }
    };

    if (!empty($runJobs)) {
        $hbRes = $client->post('/scraper/heartbeat', $buildHeartbeatPayload(0, count($runJobs)));
        if (!isset($hbRes['status']) || $hbRes['status'] < 200 || $hbRes['status'] >= 300) {
            $logger->log('Heartbeat failed with status ' . (int) ($hbRes['status'] ?? 0), 'WARN');
        }
        $applyRemoteSettings($hbRes);
        if (isset($hbRes['json']['reset_budget']) && $hbRes['json']['reset_budget'] === true) {
            $scraper->resetDailyBudget();
            $logger->log('Daily cap reset by central', 'INFO');
        }
        $lastHeartbeat = time();
    }

    try {
        $runner = new JobRunner($scraper, $logger, $outputDir);
        $summary = $runner->run($runJobs, false, $heartbeat);
    } catch (\Throwable $e) {
        $logger->log('JobRunner failed: ' . $e->getMessage(), 'ERROR');
        if (isset($options['once'])) {
            exit(1);
        }
        sleep(30);
        continue;
    }

    // collect results by tailing manifest from offset
    $results = [];
    if (is_file($manifestPath) && $offset >= 0) {
        $chunkData = @file_get_contents($manifestPath, false, null, $offset);
        if ($chunkData !== false && $chunkData !== '') {
            $lines = preg_split("/\r\n|\n|\r/", $chunkData);
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $entry = json_decode($line, true);
                if (!is_array($entry)) {
                    continue;
                }
                $status = $entry['status'] ?? null;
                if (!in_array($status, ['success', 'failed', 'skipped', 'dead'], true)) {
                    continue;
                }
                if (!array_key_exists('index', $entry) || !is_int($entry['index']) && !ctype_digit((string) $entry['index'])) {
                    continue;
                }
                $idx = (int) $entry['index'];
                if ($idx < 0 || $idx >= count($jobs)) {
                    continue;
                }
                $claimed = $jobs[$idx];
                $jobId = isset($claimed['job_id']) ? (int) $claimed['job_id'] : 0;
                $attempt = isset($claimed['attempt']) ? (int) $claimed['attempt'] : 1;
                if ($attempt < 1) {
                    $attempt = 1;
                }
                $doc = null;
                if ($status === 'skipped') {
                    $doc = [
                        'status' => 'skipped',
                        'error' => $entry['error'] ?? 'skipped',
                    ];
                } else {
                    if (isset($entry['path']) && is_string($entry['path']) && $entry['path'] !== '') {
                        if (is_readable($entry['path'])) {
                            $docRaw = @file_get_contents($entry['path']);
                            if ($docRaw !== false) {
                                $doc = json_decode($docRaw, true);
                            }
                        }
                    }
                    if (!is_array($doc)) {
                        $doc = [
                            'status' => $entry['status'] ?? 'failed',
                            'error' => $entry['error'] ?? 'result file missing',
                            'recent_sales' => [],
                        ];
                    }
                }
                $results[] = [
                    'job_id' => $jobId,
                    'attempt' => $attempt,
                    'doc' => $doc,
                ];
            }
        }
    }

    if (!empty($results)) {
        $post = $poster->post($results, readAgentLogTail($stateDir, 100));
        if (!empty($post['fatal'])) {
            exit(1);
        }
    }

    $logger->log(sprintf('run summary: jobs_total=%d success=%d failed=%d skipped=%d stop_reason=%s', (int) ($summary['jobs_total'] ?? count($jobs)), (int) ($summary['success'] ?? 0), (int) ($summary['failed'] ?? 0), (int) ($summary['skipped'] ?? 0), $summary['stop_reason'] ?? 'null'), 'INFO');

    if (isset($options['once'])) {
        exit(0);
    }
    sleep($pollInterval);
}
