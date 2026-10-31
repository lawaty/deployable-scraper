<?php

declare(strict_types=1);

class HmacSigner
{
    public static function canonical(int $serverId, string $timestamp, string $nonce, string $rawBody): string
    {
        return $serverId . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $rawBody);
    }

    public static function signature(int $serverId, string $timestamp, string $nonce, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', self::canonical($serverId, $timestamp, $nonce, $rawBody), $secret);
    }

    public static function headers(int $serverId, string $rawBody, string $secret, ?string $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = $timestamp ?? (string) time();
        $nonce = $nonce ?? bin2hex(random_bytes(16));

        return [
            'Content-Type: application/json',
            'X-Scraper-Server: ' . $serverId,
            'X-Scraper-Timestamp: ' . $timestamp,
            'X-Scraper-Nonce: ' . $nonce,
            'X-Scraper-Signature: ' . self::signature($serverId, $timestamp, $nonce, $rawBody, $secret),
        ];
    }
}
