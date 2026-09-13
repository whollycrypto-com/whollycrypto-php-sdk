<?php

declare(strict_types=1);

namespace WhollyCrypto\Http;

/** @internal A custom transport receives credentials; only use trusted implementations. */
final readonly class Request implements \JsonSerializable
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        #[\SensitiveParameter] private array $headers,
        #[\SensitiveParameter] private ?string $body = null,
    ) {
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'url' => $this->url, 'bodyBytes' => strlen($this->body ?? '')];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Requests containing credentials must not be serialized.');
    }
}
