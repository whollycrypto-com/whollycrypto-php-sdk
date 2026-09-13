<?php

declare(strict_types=1);

namespace WhollyCrypto\Exception;

use WhollyCrypto\Http\Response;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        private readonly ?string $apiMessage,
        private readonly Response $response,
    ) {
        // Do not put remote bodies, credentials or customer data into log messages.
        parent::__construct(sprintf('Wholly Crypto API request failed (HTTP %d, %s).', $statusCode, $errorCode));
    }

    public function getApiMessage(): ?string
    {
        return $this->apiMessage;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function getRetryAfter(): ?int
    {
        return $this->response->retryAfterSeconds();
    }

    public function __debugInfo(): array
    {
        return ['statusCode' => $this->statusCode, 'errorCode' => $this->errorCode];
    }
}
