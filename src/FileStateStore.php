<?php
declare(strict_types=1);

namespace DeployableScraper;

/**
 * File-backed state store replacing WordPress/Redis cache for block-breaker,
 * daily counters and any scraper state. Shared safely across concurrent PHP
 * processes via an exclusive flock on a lock file.
 *
 * Note: the store is shared across processes via flock, with no lock timeout
 * (a stuck holder blocks other callers by design).
 */
final class FileStateStore
{
    private string $dir;
    private string $stateFile;
    private string $lockFile;
    private int $lockDepth = 0;
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        if (!is_dir($this->dir)) {
            throw new \RuntimeException('State directory is not a directory: ' . $this->dir);
        }
        $this->stateFile = $this->dir . '/state.json';
        $this->lockFile = $this->dir . '/state.lock';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->withLock(function () use ($key, $default): mixed {
            $state = $this->readState();
            $now = time();
            $purged = false;
            foreach (array_keys($state) as $k) {
                if (!is_array($state[$k] ?? null)) {
                    unset($state[$k]);
                    $purged = true;
                    continue;
                }
                $e = $state[$k]['e'] ?? null;
                if (is_int($e) && $e <= $now) {
                    unset($state[$k]);
                    $purged = true;
                }
            }
            if ($purged) {
                $this->writeState($state);
            }

            if (!isset($state[$key]) || !is_array($state[$key])) {
                return $default;
            }
            $rec = $state[$key];
            $e = $rec['e'] ?? null;
            if (is_int($e) && $e <= $now) {
                unset($state[$key]);
                $this->writeState($state);
                return $default;
            }
            return array_key_exists('v', $rec) ? $rec['v'] : $default;
        });
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): void
    {
        $this->withLock(function () use ($key, $value, $ttlSeconds): void {
            $state = $this->readState();
            $state[$key] = [
                'v' => $value,
                'e' => $ttlSeconds > 0 ? time() + $ttlSeconds : null,
            ];
            $this->writeState($state);
        });
    }

    public function add(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        return $this->withLock(function () use ($key, $value, $ttlSeconds): bool {
            $state = $this->readState();
            $now = time();
            if (isset($state[$key]) && is_array($state[$key])) {
                $e = $state[$key]['e'] ?? null;
                $unexpired = ($e === null) || (is_int($e) && $e > $now);
                if ($unexpired) {
                    return false;
                }
            }
            $state[$key] = [
                'v' => $value,
                'e' => $ttlSeconds > 0 ? $now + $ttlSeconds : null,
            ];
            $this->writeState($state);
            return true;
        });
    }

    public function incr(string $key, int $offset = 1, int $ttlSecondsIfNew = 0): int
    {
        $offset = (int) $offset;
        return $this->withLock(function () use ($key, $offset, $ttlSecondsIfNew): int {
            $state = $this->readState();
            $now = time();
            if (isset($state[$key]) && is_array($state[$key])) {
                $e = $state[$key]['e'] ?? null;
                $expired = is_int($e) && $e <= $now;
                if (!$expired) {
                    $currentRaw = $state[$key]['v'] ?? 0;
                    $current = is_numeric($currentRaw) ? (int) $currentRaw : 0;
                    $new = $current + $offset;
                    $state[$key]['v'] = $new;
                    $this->writeState($state);
                    return $new;
                }
            }
            $state[$key] = [
                'v' => $offset,
                'e' => $ttlSecondsIfNew > 0 ? $now + $ttlSecondsIfNew : null,
            ];
            $this->writeState($state);
            return $offset;
        });
    }

    public function delete(string $key): void
    {
        $this->withLock(function () use ($key): void {
            $state = $this->readState();
            if (array_key_exists($key, $state)) {
                unset($state[$key]);
                $this->writeState($state);
            }
        });
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $raw = @file_get_contents($this->stateFile);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $timestamp = gmdate('Ymd-His') . '-' . substr(uniqid('', true), -4);
            $corrupt = $this->stateFile . '.corrupt-' . $timestamp;
            if (!@rename($this->stateFile, $corrupt)) {
                @unlink($this->stateFile);
            }
            return [];
        }
        return $data;
    }

    private function writeState(array $state): void
    {
        $tmp = $this->dir . '/state.json.' . uniqid('', true) . '.tmp';
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode state: ' . $this->stateFile);
        }
        $bytes = @file_put_contents($tmp, $json);
        if ($bytes === false) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to write temp state file: ' . $tmp);
        }
        if (!@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to rename state file: ' . $this->stateFile);
        }
    }

    private function withLock(callable $fn): mixed
    {
        if ($this->lockDepth === 0) {
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);
            }
            if (!is_dir($this->dir)) {
                throw new \RuntimeException('State directory is not a directory: ' . $this->dir);
            }
            $handle = @fopen($this->lockFile, 'c+');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open lock file: ' . $this->lockFile);
            }
            if (!flock($handle, LOCK_EX)) {
                @fclose($handle);
                throw new \RuntimeException('Unable to acquire lock: ' . $this->lockFile);
            }
            $this->lockHandle = $handle;
        }

        $this->lockDepth++;
        try {
            return $fn();
        } finally {
            $this->lockDepth--;
            if ($this->lockDepth === 0 && $this->lockHandle !== null) {
                @flock($this->lockHandle, LOCK_UN);
                @fclose($this->lockHandle);
                $this->lockHandle = null;
            }
        }
    }
}
