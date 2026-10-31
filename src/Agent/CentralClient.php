<?php

declare(strict_types=1);

class CentralClient
{
    private string $baseUrl;
    private int $serverId;
    private string $secret;
    private ?\DeployableScraper\Logger $logger;

    public function __construct(string $baseUrl, int $serverId, string $secret, ?\DeployableScraper\Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->serverId = $serverId;
        $this->secret = $secret;
        $this->logger = $logger;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function post(string $path, array $payload): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($raw === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null, 'error' => 'json_encode failed'];
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null, 'error' => 'curl_init failed'];
        }

        $headers = HmacSigner::headers($this->serverId, $raw, $this->secret);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            if ($this->logger !== null) {
                $this->logger->log('POST ' . $url . ' status=0 error=' . $err, 'ERROR');
            }
            return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null, 'error' => $err ?: 'curl error'];
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $headersAssoc = [];
        if ($rawHeaders !== false) {
            $lines = preg_split("/\r\n|\n|\r/", $rawHeaders);
            foreach ($lines as $line) {
                if ($line === '' || stripos($line, 'HTTP/') === 0) {
                    continue;
                }
                if (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $headersAssoc[strtolower(trim($name))] = trim($value);
                }
            }
        }

        curl_close($ch);

        if ($this->logger !== null) {
            $this->logger->log('POST ' . $url . ' status=' . $statusCode, 'INFO');
        }

        $json = json_decode($body, true);

        return [
            'status' => $statusCode,
            'headers' => $headersAssoc,
            'body' => $body,
            'json' => $json,
            'error' => $err ?: null,
        ];
    }
}
