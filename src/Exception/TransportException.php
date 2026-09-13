<?php

declare(strict_types=1);

namespace WhollyCrypto\Exception;

final class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
