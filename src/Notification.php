<?php

declare(strict_types=1);

namespace WhollyCrypto;

final readonly class Notification
{
    /** @param array<string, mixed> $payload */
    public function __construct(public string $eventId, public string $deliveryId, public array $payload)
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
