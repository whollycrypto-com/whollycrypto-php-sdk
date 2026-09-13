<?php

declare(strict_types=1);

namespace WhollyCrypto;

final readonly class Options
{
    public function __construct(
        public int $timeoutSeconds = 20,
        public int $connectTimeoutSeconds = 5,
        public int $maxRetries = 0,
        public int $maxRetryDelaySeconds = 60,
        public bool $allowInsecureLocalhost = false,
        public int $maxResponseBytes = 8_388_608,
    ) {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120
            || $connectTimeoutSeconds < 1 || $connectTimeoutSeconds > $timeoutSeconds
            || $maxRetries < 0 || $maxRetries > 3
            || $maxRetryDelaySeconds < 0 || $maxRetryDelaySeconds > 60
            || $maxResponseBytes < 1024 || $maxResponseBytes > 67_108_864) {
            throw new \InvalidArgumentException('Invalid timeout, retry or response-size options.');
        }
    }
}
