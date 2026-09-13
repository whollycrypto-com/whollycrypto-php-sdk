<?php

declare(strict_types=1);

namespace WhollyCrypto\Http;

final readonly class Response
{
    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, string> $headers */
    public function __construct(public int $statusCode, array $headers, private string $body)
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function retryAfterSeconds(?int $now = null): ?int
    {
        $value = $this->header('retry-after');
        if ($value === null) {
            return null;
        }
        if (preg_match('/\A[0-9]{1,9}\z/D', $value)) {
            return (int) $value;
        }
        // HTTP dates are also accepted; reject arbitrary strtotime expressions.
        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('D, d M Y H:i:s \G\M\T') === $value
            ? max(0, $date->getTimestamp() - ($now ?? time())) : null;
    }

    /** @return array{limit: ?int, remaining: ?int, reset: ?int} */
    public function rateLimit(): array
    {
        $result = [];
        foreach (['limit', 'remaining', 'reset'] as $field) {
            $value = $this->header('x-ratelimit-' . $field);
            $result[$field] = $value !== null && preg_match('/\A[0-9]{1,10}\z/D', $value) ? (int) $value : null;
        }
        return $result;
    }

    public function __debugInfo(): array
    {
        return ['statusCode' => $this->statusCode, 'bodyBytes' => strlen($this->body), 'rateLimit' => $this->rateLimit()];
    }
}
