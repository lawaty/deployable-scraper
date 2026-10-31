<?php
/**
 * PriceCharting scraper configuration. Copied with the scraper and may be
 * overridden by --config=PATH.
 */
return [
    'request_interval_seconds'    => 30.0,
    'request_interval_jitter'     => 0.25,
    'max_requests_per_run'        => 500,
    'max_requests_per_day'        => 1500,
    'block_breaker_threshold'     => 3,
    'block_breaker_pause_seconds' => 21600,
];
