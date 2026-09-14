<?php

declare(strict_types=1);

namespace WhollyCrypto\Exception;

use WhollyCrypto\Http\Response;

/**
 * @property-read int $statusCode
 * @property-read string $errorCode
 */
final class ApiException extends \RuntimeException implements \JsonSerializable
{
    use \WhollyCrypto\Internal\RejectDynamicProperties;

    private const READABLE_PROPERTIES = ['statusCode', 'errorCode'];
    private int $statusCode;
    private string $errorCode;
    private ?string $apiMessage;
    private Response $response;

    public function __construct(
        int $statusCode,
        string $errorCode,
        ?string $apiMessage,
        Response $response
    ) {
        $this->initializeImmutable();
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->apiMessage = $apiMessage;
        $this->response = $response;
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
