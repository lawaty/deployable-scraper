<?php

declare(strict_types=1);

class ResultPoster
{
    private CentralClient $client;
    private DeployableScraper\Logger $logger;
    private string $dlqDir;

    public function __construct(CentralClient $client, DeployableScraper\Logger $logger, string $dlqDir)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->dlqDir = rtrim($dlqDir, '/');
    }

    public function post(array $results, array $logs = []): array
    {
        return $this->postTo('/scraper/results', $results, $logs);
    }

    private function postTo(string $path, array $results, array $logs = []): array
    {
        $accepted = 0;
        $duplicates = 0;
        $rejected = 0;
        $dead = 0;
        $fatal = false;

        if (empty($results)) {
            return [
                'accepted' => 0,
                'duplicates' => 0,
                'rejected' => 0,
                'dead' => 0,
                'fatal' => false,
            ];
        }

        // Chunk size must stay synchronized with central's handleResults limit (currently 100) and current server capacity.
        $chunks = array_chunk($results, 100);
        foreach ($chunks as $chunkIndex => $chunk) {
            if ($fatal) {
                break;
            }

            $body = [
                'version' => 1,
                'results' => $chunk,
                'logs' => $chunkIndex === 0 ? $logs : [],
            ];

            $attempt = 0;
            $maxAttempts = 8;
            $posted = false;
            $lastError = 'unknown error';

            while ($attempt < $maxAttempts && !$posted && !$fatal) {
                $attempt++;
                $res = $this->client->post($path, $body);

                if ($res['status'] === 0) {
                    $lastError = $res['error'] ?? 'network error';
                    if ($attempt >= $maxAttempts) {
                        break;
                    }
                    $this->sleepBackoff($attempt);
                    continue;
                }

                if ($res['status'] === 401) {
                    $this->logger->log('ResultPoster: HTTP 401 unauthorized; stopping', 'ERROR');
                    $fatal = true;
                    break;
                }

                if ($res['status'] === 429) {
                    $headers = $res['headers'] ?? [];
                    $retryAfter = isset($headers['retry-after']) ? (int) $headers['retry-after'] : 60;
                    if ($retryAfter < 1) {
                        $retryAfter = 60;
                    }
                    $this->logger->log('ResultPoster: HTTP 429 rate limited; sleeping ' . $retryAfter . 's', 'WARN');
                    sleep($retryAfter);
                    continue;
                }

                if ($res['status'] >= 200 && $res['status'] < 300) {
                    if ($res['status'] === 202) {
                        $json = is_array($res['json']) ? $res['json'] : [];
                        $accepted += (int) ($json['accepted'] ?? 0);
                        $duplicates += (int) ($json['duplicates'] ?? 0);
                        $rejected += (int) ($json['rejected'] ?? 0);
                        if (isset($json['rejected']) && is_array($json['rejected'])) {
                            foreach ($json['rejected'] as $rej) {
                                if (is_array($rej)) {
                                    $reason = $rej['reason'] ?? 'rejected';
                                    $jobId = $rej['job_id'] ?? null;
                                    $idx = $rej['index'] ?? null;
                                    if ($jobId !== null) {
                                        $this->logger->log('ResultPoster: rejected job_id=' . $jobId . ' reason=' . $reason, 'WARN');
                                    } elseif ($idx !== null) {
                                        $this->logger->log('ResultPoster: rejected index=' . $idx . ' reason=' . $reason, 'WARN');
                                    } else {
                                        $this->logger->log('ResultPoster: rejected reason=' . $reason, 'WARN');
                                    }
                                } elseif (is_string($rej)) {
                                    $this->logger->log('ResultPoster: rejected ' . $rej, 'WARN');
                                }
                            }
                        }
                    } else {
                        $this->logger->log('ResultPoster: POST ' . $path . ' status=' . $res['status'], 'INFO');
                    }
                    $posted = true;
                    continue;
                }

                // non-2xx (except 401/429)
                $lastError = 'http_' . $res['status'];
                if (isset($res['body']) && is_string($res['body']) && $res['body'] !== '') {
                    $lastError = $res['body'];
                }
                if ($attempt >= $maxAttempts) {
                    break;
                }
                $this->sleepBackoff($attempt);
                continue;
            }

            if (!$posted && !$fatal) {
                // write all results in chunk to DLQ
                if (!is_dir($this->dlqDir)) {
                    @mkdir($this->dlqDir, 0775, true);
                }
                $dlqPath = $this->dlqDir . '/' . gmdate('Y-m-d') . '.jsonl';
                $failedAt = gmdate('c');
                $fp = @fopen($dlqPath, 'a');
                if ($fp !== false) {
                    @flock($fp, LOCK_EX);
                    foreach ($chunk as $r) {
                        $line = json_encode([
                            'result' => $r,
                            'error' => $lastError,
                            'failed_at' => $failedAt,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                        if ($line !== false) {
                            @fwrite($fp, $line . "\n");
                        }
                        $dead++;
                    }
                    @flock($fp, LOCK_UN);
                    @fclose($fp);
                } else {
                    foreach ($chunk as $r) {
                        $dead++;
                    }
                }
                $this->logger->log('ResultPoster: chunk failed after ' . $maxAttempts . ' attempts; wrote ' . count($chunk) . ' to DLQ', 'ERROR');
            }
        }

        $this->logger->log(sprintf('ResultPoster: accepted=%d duplicates=%d rejected=%d dead=%d fatal=%s', $accepted, $duplicates, $rejected, $dead, $fatal ? 'true' : 'false'), 'INFO');

        return [
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'dead' => $dead,
            'fatal' => $fatal,
        ];
    }

    private function sleepBackoff(int $attempt): void
    {
        $delay = 30 * (2 ** ($attempt - 1));
        if ($delay > 600) {
            $delay = 600;
        }
        $this->logger->log('ResultPoster: retrying in ' . $delay . 's (attempt ' . $attempt . ')', 'WARN');
        sleep($delay);
    }
}
