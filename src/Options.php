<?php

declare(strict_types=1);

namespace WhollyCrypto;

final class Options
{
    use Internal\RejectDynamicProperties;

    public function __construct(
        public readonly int $timeoutSeconds = 20,
        public readonly int $connectTimeoutSeconds = 5,
        public readonly int $maxRetries = 0,
        public readonly int $maxRetryDelaySeconds = 60,
        public readonly bool $allowInsecureLocalhost = false,
        public readonly int $maxResponseBytes = 8_388_608,
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
