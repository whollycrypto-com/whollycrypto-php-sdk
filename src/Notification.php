<?php

declare(strict_types=1);

namespace WhollyCrypto;

final class Notification
{
    use Internal\RejectDynamicProperties;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $eventId, public readonly string $deliveryId, public readonly array $payload)
    {
    }

    public function invoiceId(): string
    {
        return $this->payload['invoice_id'];
    }

    public function status(): string
    {
        return $this->payload['status'];
    }

    public function sequence(): int
    {
        return $this->payload['sequence'];
    }
}
