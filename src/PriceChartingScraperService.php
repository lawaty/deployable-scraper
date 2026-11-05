<?php

declare(strict_types=1);

namespace DeployableScraper;

use DOMDocument;
use DOMXPath;

require_once __DIR__ . '/FileStateStore.php';

/**
 * Standalone extraction of the production PriceCharting scraper: single-process/CLI
 * friendly, no DB/pipeline/persistence, writes nothing except the HTTP cookie jar,
 * the rate-limit lock file, file-backed breaker/budget state under a caller-supplied
 * state directory, and optional anomalous raw HTML dumps.
 */
class PriceChartingScraperService
{
    private const USER_AGENT = 'SuperMyntyBot/1.0 (+https://superminty.com/; game price history data)';

    private const DEFAULT_CONFIG = [
        'request_interval_seconds' => 30.0, 'request_interval_jitter' => 0.25,
        'max_requests_per_run' => 500, 'max_requests_per_day' => 1500,
        'block_breaker_threshold' => 3, 'block_breaker_pause_seconds' => 21600,
    ];
    private const BLOCK_BREAKER_KEY = 'pricecharting_block_breaker';

    /** @var callable|null Signature: (string $message, string $level): void */
    private $logger;
    private int $maxRetries = 3;

    private float $requestInterval = 10.0;
    private string $globalLockFile;

    /**
     * Per-run cookie jar (Netscape cookie file backing CURLOPT_COOKIEFILE /
     * CURLOPT_COOKIEJAR), so cookies set by one page are offered back on the
     * next. Null when no temp file could be created — the run then sends no
     * cookies instead of failing.
     */
    private ?string $cookieJarFile = null;

    /** URL of the previous outgoing request; sent as the next Referer. */
    private ?string $lastUrl = null;

    /** Directory where anomalous raw page HTML is persisted for debugging. */
    private ?string $rawHtmlDir;

    /** Tunables (see api/config/pricecharting.php); defaults merged over a loaded file. */
    private array $config;
    private int $runRequestCount = 0;
    private int $consecutiveBlocks = 0;
    private array $lastResponseHeaders = [];

    private string $stateDir;
    private ?FileStateStore $stateStore = null;

    public function __construct(
        ?callable $logger = null,
        int $maxRetries = 3,
        ?float $requestInterval = null,
        ?string $stateDir = null,
        ?array $config = null
    ) {
        $this->logger = $logger;
        $this->maxRetries = max(1, $maxRetries);
        $this->stateDir = rtrim($stateDir ?? dirname(__DIR__) . '/state', '/');
        $this->globalLockFile = $this->stateDir . '/pricecharting-request.lock';
        $this->rawHtmlDir = $this->stateDir . '/anomalies';
        $this->config = array_merge(self::DEFAULT_CONFIG, $config ?? $this->loadConfigFile());
        try {
            $dailyCapOverride = $this->state()->get('daily_cap_override');
            if (is_numeric($dailyCapOverride) && (int) $dailyCapOverride > 0) {
                $this->config['max_requests_per_day'] = (int) $dailyCapOverride;
            }
        } catch (\Throwable $e) {
            // Fall back to the configured default if the state store is unavailable.
        }
        $this->requestInterval = max(0.5, $requestInterval ?? (float) $this->config['request_interval_seconds']);
        $this->initCookieJar();
    }

    /**
     * Give the run a cookie jar so session state (session id, cf_clearance)
     * survives from one page to the next.
     *
     * Lifecycle: one temp file per process, deleted by a shutdown handler. The
     * only caller is a short-lived CLI worker, so a per-process temp file in
     * sys_get_temp_dir() never outlives the run. Failure to create it is
     * non-fatal — scraping continues without cookie state.
     */
    private function initCookieJar(): void
    {
        $jar = @tempnam(sys_get_temp_dir(), 'pc_cookies_');
        if ($jar === false) {
            $this->log('Cookie jar temp file unavailable — continuing without cookie state', 'WARNING');
            return;
        }

        $this->cookieJarFile = $jar;
        register_shutdown_function(function (): void {
            if ($this->cookieJarFile !== null && is_file($this->cookieJarFile)) {
                @unlink($this->cookieJarFile);
            }
        });
    }

    private function loadConfigFile(): array
    {
        $path = dirname(__DIR__) . '/config/pricecharting.php';
        return is_file($path) ? (array) require $path : [];
    }

    public function scrapeGame(string $slug, string $category, ?string $expectedTitle = null): array
    {
        $url = 'https://www.pricecharting.com/game/' . $category . '/' . urlencode($slug);
        return $this->doScrape($url, $slug, $expectedTitle);
    }

    public function scrapeByUrl(string $url, string $slug, ?string $expectedTitle = null): array
    {
        return $this->doScrape($url, $slug, $expectedTitle);
    }

    public function doScrape(string $url, string $slug, ?string $expectedTitle = null): array
    {
        $this->debug("Fetching {$url}");

        if ($this->isBlockBreakerOpen()) {
            return ['error' => 'block breaker open (recent 403/429)', 'slug' => $slug,
                'http_status' => 0, 'http_attempts' => 0, 'blocked' => true,
                'scrapes_total' => 0, 'scrapes_successful' => 0];
        }

        $attempts = 0; $html = null; $lastStatus = 0; $blocked = false;

        while ($attempts < $this->maxRetries) {
            $attempts++;
            $resp = $this->fetchUrl($url);
            $lastStatus = $resp['status'];

            if (!empty($resp['search_products_redirect'])) {
                $this->log(sprintf('Slug %s is being marked dead because the slug was not found on PriceCharting (search-products redirect)', $slug), 'WARNING');
                return [
                    'error' => 'slug not found on PriceCharting (search-products redirect)',
                    'slug' => $slug,
                    'http_status' => $resp['status'],
                    'http_attempts' => $attempts,
                    'blocked' => false,
                    'dead' => true,
                    'scrapes_total' => 1,
                    'scrapes_successful' => 0,
                ];
            }

            if ($resp['status'] === 200 && $resp['body'] !== null) {
                $html = $resp['body'];
                $this->consecutiveBlocks = 0;
                break;
            }

            if ($resp['status'] === 403) {
                $blocked = true;
                $this->log('HTTP 403 is page-specific and does not increment the global block breaker', 'WARNING');
                break;
            }

            if ($resp['status'] === 429) {
                $blocked = true;
                $this->recordBlockedResponse($resp['status'], $resp['retry_after']);
                break;
            }

            if ($attempts < $this->maxRetries) {
                $this->debug("Retry {$attempts}/{$this->maxRetries} for {$url} (HTTP {$resp['status']}, errno {$resp['errno']})");
                $this->sleepSeconds($this->backoffSeconds($attempts));
            }
        }

        if ($html === null) {
            return ['error' => $blocked ? "blocked (HTTP {$lastStatus})" : 'Request failed',
                'slug' => $slug, 'http_status' => $lastStatus, 'http_attempts' => $attempts,
                'blocked' => $blocked,
                'scrapes_total' => 1, 'scrapes_successful' => 0];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        return [
            'slug' => $slug,
            'price_guide' => $this->parsePriceGuide($xpath),
            'recent_sales' => $this->parseRecentSales($xpath, $expectedTitle, $html, $slug),
            'http_status' => 200,
            'http_attempts' => $attempts,
            'blocked' => false,
            'scrapes_total' => 1,
            'scrapes_successful' => 1,
        ];
    }

    private function backoffSeconds(int $attempt): int
    {
        return (int) min(30, 5 * (2 ** ($attempt - 1)));
    }

    protected function sleepSeconds(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Enforce the request budgets, then perform one request under the global
     * rate-limit lock. Returns a structured result so the caller can apply the
     * retry policy and the block breaker.
     *
     * @return array{status:int, body:?string, errno:int, retry_after:?int}
     */
    private function fetchUrl(string $url): array
    {
        if (!$this->budgetAllows()) {
            return ['status' => 0, 'body' => null, 'errno' => 0, 'retry_after' => null];
        }
        $slot = $this->acquireGlobalRequestSlot();   // returns the lock handle
        try {
            $response = $this->executeRequest($url);
        } finally {
            $this->releaseRequestSlot($slot);        // release AFTER the request
        }
        $this->recordRequest();
        $this->lastUrl = $url;                       // this URL is the next request's Referer
        return $response;
    }

    private function extractLocationHeader(array $headers): ?string
    {
        foreach ($headers as $line) {
            if (preg_match('/^location:\s*(.+)$/i', trim($line), $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    private function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (strpos($location, '//') === 0) {
            $parts = parse_url($currentUrl);
            $scheme = $parts['scheme'] ?? 'https';
            return $scheme . ':' . $location;
        }
        $parts = parse_url($currentUrl);
        if (!$parts) {
            return $location;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $base = $scheme . '://' . $host . $port;
        if (strpos($location, '/') === 0) {
            return $base . $location;
        }
        $path = $parts['path'] ?? '/';
        $dir = strrpos($path, '/') !== false ? substr($path, 0, strrpos($path, '/') + 1) : '/';
        return $base . $dir . $location;
    }

    /** The real HTTP call — protected seam; tests override, zero network. */
    protected function executeRequest(string $url): array
    {
        $currentUrl = $url;
        $maxHops = 3;
        $hops = 0;

        while (true) {
            $this->lastResponseHeaders = [];
            $ch = curl_init();
            $headers = $this->buildRequestHeaders();
            $curlOptions = $this->buildCurlOptions($currentUrl, $headers);
            $curlOptions[CURLOPT_FOLLOWLOCATION] = false;
            curl_setopt_array($ch, $curlOptions);
            $start = microtime(true);
            $html = curl_exec($ch);
            $elapsed = microtime(true) - $start;
            $errno = curl_errno($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                $this->log(sprintf('cURL error %d for %s (%.2fs)', $errno, $currentUrl, $elapsed), 'ERROR');
                return ['status' => 0, 'body' => null, 'errno' => $errno, 'retry_after' => null];
            }

            $retryAfter = $this->parseRetryAfter($this->lastResponseHeaders);

            $this->debug(sprintf('HTTP %d in %.2fs for %s', $httpCode, $elapsed, $currentUrl));

            if ($httpCode >= 300 && $httpCode < 400) {
                $location = $this->extractLocationHeader($this->lastResponseHeaders);
                if ($location !== null && stripos($location, 'search-products') !== false) {
                    $this->log(sprintf('Game URL redirected to search-products and no redirect was followed: %s -> %s', $currentUrl, $location), 'WARNING');
                    return [
                        'status' => $httpCode,
                        'body' => null,
                        'errno' => 0,
                        'retry_after' => $this->parseRetryAfter($this->lastResponseHeaders),
                        'search_products_redirect' => true,
                        'redirect_location' => $location,
                    ];
                }
                if ($location === null || $hops >= $maxHops) {
                    if ($httpCode !== 200) {
                        $this->log("Non-200 response ({$httpCode}) for {$currentUrl}", 'WARNING');
                    }
                    return [
                        'status' => $httpCode,
                        'body' => null,
                        'errno' => 0,
                        'retry_after' => $retryAfter,
                    ];
                }
                $nextUrl = $this->resolveRedirectUrl($location, $currentUrl);
                $currentUrl = $nextUrl;
                $hops++;
                continue;
            }

            if ($httpCode !== 200) {
                $this->log("Non-200 response ({$httpCode}) for {$currentUrl}", 'WARNING');
            }
            return ['status' => $httpCode, 'body' => $httpCode === 200 ? $html : null,
                'errno' => 0, 'retry_after' => $retryAfter];
        }
    }

    /**
     * Header profile. The Referer mirrors the page the run came from, so a
     * sequence of game pages looks like one visitor walking the site. The
     * first request of a run has none — that is a direct hit, which is what a
     * fresh tab actually sends.
     */
    protected function buildRequestHeaders(): array
    {
        $headers = [
            'User-Agent: ' . self::USER_AGENT,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ];
        if ($this->lastUrl !== null) {
            $headers[] = 'Referer: ' . $this->lastUrl;
        }
        return $headers;
    }

    /** Pure option profile — unit-tested without network. */
    protected function buildCurlOptions(string $url, array $headers): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            // Offer HTTP/2 over TLS; ALPN negotiates it and libcurl falls back
            // to 1.1 when the server declines, so this is not protocol-pinned.
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_HEADERFUNCTION => fn($ch, $line) => $this->captureHeader($line),
        ];

        if ($this->cookieJarFile !== null) {
            $options[CURLOPT_COOKIEFILE] = $this->cookieJarFile;   // offer back cookies set earlier in this run
            $options[CURLOPT_COOKIEJAR] = $this->cookieJarFile;    // persist cookies the response sets
        }

        if ($this->lastUrl !== null) {
            $options[CURLOPT_REFERER] = $this->lastUrl;
        }

        return $options;
    }

    private function captureHeader(string $line): int
    {
        $this->lastResponseHeaders[] = $line;
        return strlen($line);
    }

    private function parseRetryAfter(array $headers): ?int
    {
        foreach ($headers as $line) {
            if (preg_match('/^retry-after:\s*(\d+)/i', $line, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Global rate-limiting via flock on a shared file.
     *
     * Opens the lock file, acquires an exclusive lock (blocking until all
     * previous processes have finished), then sleeps a jittered interval while
     * HOLDING the lock. The lock is held until the HTTP request itself has
     * finished (the caller releases it via releaseRequestSlot() after
     * executeRequest() returns), which guarantees that no two HTTP requests
     * across any number of parallel processes can overlap.
     *
     * No fallback to usleep-alone: if the lock file cannot be opened the
     * process throws so the run fails loudly rather than hammering the site.
     *
     * @return resource The open lock handle; the caller must release it via
     *                  releaseRequestSlot() after the request completes.
     */
    private function acquireGlobalRequestSlot()
    {
        $lockFile = $this->globalLockFile;
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }

        $handle = @fopen($lockFile, 'c+');

        if ($handle === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            $this->log("Cannot open rate-limit lock file {$lockFile}: {$error}", 'ERROR');
            throw new \RuntimeException("Rate-limit lock file unavailable: {$lockFile}");
        }

        // Block until every other process has finished their sleep cycle, then
        // hold the lock while WE sleep plus the following HTTP request.
        flock($handle, LOCK_EX);

        $jitterFactor = (float) $this->config['request_interval_jitter'];
        $jitter = 1 + ($jitterFactor * (mt_rand(-10000, 10000) / 10000));   // config default ±25%
        usleep((int) ($this->requestInterval * $jitter * 1_000_000));

        return $handle;
    }

    /** @param resource $handle */
    private function releaseRequestSlot($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    // ==========================================
    // Persistent 429 rate-limit block breaker
    // ==========================================

    private function recordBlockedResponse(int $status, ?int $retryAfter): void
    {
        $this->consecutiveBlocks++;
        if ($this->consecutiveBlocks >= (int) $this->config['block_breaker_threshold']) {
            $pause = max((int) $this->config['block_breaker_pause_seconds'], $retryAfter ?? 0);
            $this->blockBreakerSet([
                'blocked_until' => time() + $pause,
                'status' => $status,
                'tripped_at' => time(),
            ]);
            $this->log("Block breaker tripped after {$this->consecutiveBlocks} consecutive {$status}s — pausing scraping for " . intdiv($pause, 3600) . "h", 'ERROR');
        }
    }

    public function isBlockBreakerOpen(): bool
    {
        $state = $this->blockBreakerGet();
        return is_array($state) && (int) ($state['blocked_until'] ?? 0) > time();
    }

    /**
     * Seams (tests override). File-backed state via FileStateStore.
     */
    private function state(): FileStateStore
    {
        if ($this->stateStore === null) {
            $this->stateStore = new FileStateStore($this->stateDir);
        }
        return $this->stateStore;
    }

    protected function blockBreakerGet(): ?array
    {
        $raw = $this->stateGet(self::BLOCK_BREAKER_KEY);
        return is_array($raw) ? $raw : null;
    }

    protected function blockBreakerSet(array $state): void
    {
        try {
            $this->state()->set(self::BLOCK_BREAKER_KEY, $state);
        } catch (\Throwable $e) {
            $this->log('State store write failed for block breaker: ' . $e->getMessage(), 'WARNING');
        }
    }

    private function stateGet(string $key): mixed
    {
        try {
            return $this->state()->get($key);
        } catch (\Throwable $e) {
            $this->log('State store read failed for ' . $key . ': ' . $e->getMessage(), 'WARNING');
            return null;
        }
    }

    // ==========================================
    // Request budgets (per-run + per-day)
    // ==========================================

    private function budgetAllows(): bool
    {
        if ($this->runRequestCount >= (int) $this->config['max_requests_per_run']) {
            return false;
        }
        $today = (int) ($this->stateGet('requests_' . gmdate('Ymd')) ?? 0);
        return $today < (int) $this->config['max_requests_per_day'];
    }

    public function budgetExhausted(): bool
    {
        return !$this->budgetAllows();
    }

    /**
     * Snapshot of the request budget and the next moment requests are allowed.
     *
     * reason: 'daily_cap' (next UTC midnight), 'block_breaker' (blocked_until),
     * 'run_cap' (a fresh process gets a fresh per-run budget, so now), or null.
     *
     * @return array{run_used:int, run_limit:int, day_used:int, day_limit:int, exhausted:bool, reason:?string, resume_at:?int}
     */
    public function budgetState(): array
    {
        $runLimit = (int) $this->config['max_requests_per_run'];
        $dayLimit = (int) $this->config['max_requests_per_day'];
        $dayUsed = (int) ($this->stateGet('requests_' . gmdate('Ymd')) ?? 0);
        $now = time();

        $reason = null;
        $resumeAt = null;

        if ($dayUsed >= $dayLimit) {
            $reason = 'daily_cap';
            // Next UTC midnight (epoch is UTC-based).
            $resumeAt = (int) (floor($now / 86400) + 1) * 86400;
        }

        $breaker = $this->blockBreakerGet();
        $blockedUntil = is_array($breaker) ? (int) ($breaker['blocked_until'] ?? 0) : 0;
        if ($blockedUntil > $now) {
            if ($reason === null) {
                $reason = 'block_breaker';
            }
            $resumeAt = max((int) ($resumeAt ?? 0), $blockedUntil);
        }

        if ($reason === null && $this->runRequestCount >= $runLimit) {
            $reason = 'run_cap';
            $resumeAt = $now;
        }

        return [
            'run_used'  => $this->runRequestCount,
            'run_limit' => $runLimit,
            'day_used'  => $dayUsed,
            'day_limit' => $dayLimit,
            'exhausted' => $reason !== null,
            'reason'    => $reason,
            'resume_at' => $resumeAt,
        ];
    }

    private function recordRequest(): void
    {
        $this->runRequestCount++;
        $key = 'requests_' . gmdate('Ymd');
        try {
            $this->state()->incr($key, 1, 90000);   // ~25h TTL covers the UTC day
        } catch (\Throwable $e) {
            $this->log('State store increment failed for ' . $key . ': ' . $e->getMessage(), 'WARNING');
        }
    }

    public function resetDailyBudget(): void
    {
        try {
            $key = 'requests_' . gmdate('Ymd');
            $this->state()->delete($key);
            $this->log('Daily cap reset by central', 'INFO');
        } catch (\Throwable $e) {
            $this->log('Daily cap reset failed: ' . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * Apply the central per-server daily cap and persist it for future runs.
     */
    public function setDailyCap(int $dailyCap): void
    {
        if ($dailyCap < 1 || (int) $this->config['max_requests_per_day'] === $dailyCap) {
            return;
        }

        $this->config['max_requests_per_day'] = $dailyCap;
        try {
            $this->state()->set('daily_cap_override', $dailyCap);
            $this->log('Daily cap set to ' . $dailyCap . ' by central', 'INFO');
        } catch (\Throwable $e) {
            $this->log('Daily cap persistence failed: ' . $e->getMessage(), 'ERROR');
        }
    }

    private function debug(string $message): void
    {
        $this->log($message, 'DEBUG');
    }

    private function log(string $message, string $level = 'DEBUG'): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, $level);
        }
    }

    private function parsePriceGuide(DOMXPath $xpath): array
    {
        $prices = [];
        $rows = $xpath->query('//div[@id="full-prices"]//table//tr');

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length >= 2) {
                $prices[trim($cells->item(0)->nodeValue)] = trim($cells->item(1)->nodeValue);
            }
        }

        return $prices;
    }

    /**
     * Parse the "Recent Sales" (Sold Listings) tables from the game page.
     *
     * The page contains several `table.hoverable-rows.sortable` blocks that all
     * carry a "Sale Date" header — one per package condition (Loose, CIB, New,
     * Graded, Box Only, Manual Only, Box+Manual). On 2026-07-29 PriceCharting
     * temporarily served a page whose FIRST "Sale Date" table belonged to a
     * different game, and the old broad XPath `//table[.//th[contains(., "Sale
     * Date")]]` ingested those rows under the wrong game_id.
     *
     * To make this robust:
     *  1. Scope the search to the actual "Sold Listings" section — tables that
     *     live inside a `div.completed-auctions-*` container — instead of any
     *     table on the page with a "Sale Date" header.
     *  2. Gate at the TABLE level (not per row). A listing title may use a
     *     shorthand of the game name (e.g. "MK II", "FF6", "SSB") that shares no
     *     word token with the full title, so per-row filtering would drop valid
     *     entries. Instead: if at least ONE row in a table matches the expected
     *     game, the whole table is trusted and every row is kept. A table where
     *     NO row matches is a foreign/conflicting table and is dropped entirely.
     *
     * @param DOMXPath   $xpath
     * @param string|null $expectedTitle The game title we expect to see on the page
     * @param string      $rawHtml       The raw page HTML (used for anomaly logging)
     * @param string      $slug          The game slug (used for anomaly log filenames)
     * @return array{date: string, title: string, link: string, price: string}[]
     */
    private function parseRecentSales(DOMXPath $xpath, ?string $expectedTitle = null, string $rawHtml = '', string $slug = ''): array
    {
        $sales = [];

        // Only tables inside the completed-auctions section. The section divs are
        // named like `completed-auctions-used`, `completed-auctions-cib`, etc.
        // (the tab-bar divs share the prefix but contain no tables and their
        // class starts with "tab", so starts-with is unambiguous).
        $tables = $xpath->query('//div[starts-with(normalize-space(@class), "completed-auctions")]//table[.//th[contains(., "Sale Date")]]');

        // Per-table audit stats: rows parsed and whether any row matched the game.
        $tableStats = [];
        $tablesRejected = 0;
        $tablesWithForeignRows = 0;

        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            $first = true;

            $tableSales = [];
            $hasExpectedTitle = false;

            foreach ($rows as $row) {
                if ($first) {
                    $first = false;
                    continue;
                }

                $cells = $row->getElementsByTagName('td');
                if ($cells->length < 4) {
                    continue;
                }

                $titleCell = $cells->item(2);
                $title = trim($titleCell->nodeValue);

                if ($title === '') {
                    continue;
                }

                $linkNode = $titleCell->getElementsByTagName('a')->item(0);
                $link = $linkNode ? $linkNode->getAttribute('href') : '';

                if ($link && strpos($link, 'http') !== 0) {
                    $link = 'https://www.pricecharting.com' . $link;
                }

                $tableSales[] = [
                    'date' => trim($cells->item(0)->nodeValue),
                    'title' => $title,
                    'link' => $link,
                    'price' => trim($cells->item(3)->nodeValue),
                ];

                if ($expectedTitle !== null && !$hasExpectedTitle && $this->titleLooksLikeGame($title, $expectedTitle)) {
                    $hasExpectedTitle = true;
                }
            }

            $rowCount = count($tableSales);
            if ($rowCount === 0) {
                continue;
            }

            $tableStats[] = [
                'rows' => $rowCount,
                'matched' => $hasExpectedTitle,
            ];

            // Table-level gate: require at least one row to match the expected game.
            if ($expectedTitle !== null && !$hasExpectedTitle) {
                $this->log(sprintf(
                    'Dropping sold-listings table: no rows look like "%s" (first title: "%s")',
                    $expectedTitle,
                    $this->truncate($tableSales[0]['title'], 80)
                ), 'WARNING');
                $tablesRejected++;
                continue;
            }

            foreach ($tableSales as $sale) {
                $sales[] = $sale;
            }
        }

        $this->logAnomalousPage($rawHtml, $slug, $tableStats, $tablesRejected);

        return $sales;
    }

    /**
     * Persist the raw page HTML when a parse looks wrong or suspicious, so the
     * exact page that caused the problem can be inspected later.
     *
     * Anomalies that trigger a save:
     *  - Any sold-listings table was rejected outright (no rows matched the game).
     *  - Multiple tables held differing content — at least one table matched the
     *    game while another did not (possible wrong/mixed page), which is exactly
     *    the 2026-07-29 failure signature.
     *
     * Files are written to {rawHtmlDir}/{slug}-{timestamp}.html. The directory is
     * created lazily; failures are logged but never fatal.
     */
    private function logAnomalousPage(string $rawHtml, string $slug, array $tableStats, int $tablesRejected): void
    {
        if ($this->rawHtmlDir === null || $rawHtml === '') {
            return;
        }

        $anyMatched = false;
        $anyUnmatched = false;
        foreach ($tableStats as $stat) {
            $anyMatched = $anyMatched || $stat['matched'];
            $anyUnmatched = $anyUnmatched || !$stat['matched'];
        }

        // Rejected tables are always anomalous. Heterogeneous tables (some matched,
        // some did not) are suspicious even when nothing was rejected.
        $isAnomalous = $tablesRejected > 0 || ($anyMatched && $anyUnmatched);

        if (!$isAnomalous) {
            return;
        }

        $dir = $this->rawHtmlDir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $safeSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug)) ?: 'game';
        $file = rtrim($dir, '/') . '/' . $safeSlug . '-' . date('Ymd-His') . '-' . substr(uniqid('', true), -4) . '.html';

        $written = @file_put_contents($file, $rawHtml);
        if ($written === false) {
            $this->log("Failed to save anomalous raw HTML to {$file}", 'ERROR');
            return;
        }

        $this->log(sprintf(
            'Saved anomalous raw HTML (%d tables, %d rejected, %s) to %s',
            count($tableStats),
            $tablesRejected,
            $anyMatched && $anyUnmatched ? 'mixed content' : 'no match',
            $file
        ), 'WARNING');
    }

    /**
     * Determine whether a listing title most likely belongs to the expected game.
     *
     * Compares significant (stopword-filtered) word tokens from both strings using
     * substring-aware matching, so compound/misspelled names still collide:
     * "Spiderman" vs "Spider-Man", "Super Scope 6" vs "Super Scope 6 Cartridge".
     *
     * Used to gate whole tables (see parseRecentSales): we only need ONE matching
     * row to trust a table, so pure-shorthand listings ("MK II", "FF6") don't
     * cause valid data to be dropped.
     *
     * When the expected title yields no significant tokens (too short / all
     * stopwords) we fall back to accepting the row rather than dropping data.
     */
    private function titleLooksLikeGame(string $itemTitle, string $expectedTitle): bool
    {
        $gameTokens = $this->significantTokens($expectedTitle);
        if (empty($gameTokens)) {
            return true;
        }

        $titleTokens = $this->significantTokens($itemTitle);
        if (empty($titleTokens)) {
            return false;
        }

        foreach ($gameTokens as $gameToken) {
            foreach ($titleTokens as $titleToken) {
                if (strpos($gameToken, $titleToken) !== false || strpos($titleToken, $gameToken) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract significant (non-stopword, length >= 3) lowercase tokens from a string.
     *
     * @return list<string>
     */
    private function significantTokens(string $text): array
    {
        $stopwords = [
            'super', 'nintendo', 'snes', 'game', 'cartridge', 'cart', 'only',
            'tested', 'test', 'working', 'works', 'authentic', 'ntsc', 'video',
            'vintage', 'for', 'the', 'and', 'of', 'with', 'new', 'loose', 'sn',
            'us', 'uc', 'condition', 'free', 'ship', 'combine', 'ea', 'sports',
            'nfl', 'players', 'choice', 'box', 'manual', 'case', 'complete',
            'sealed', 'graded', 'ci', 'ntscuc', 'japan', 'japanese',
        ];

        $text = strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
        $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $result = [];
        foreach ($tokens as $token) {
            if (strlen($token) < 3 || in_array($token, $stopwords, true)) {
                continue;
            }
            $result[] = $token;
        }

        return array_values(array_unique($result));
    }

    private function truncate(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3) . '...' : $text;
    }
}
