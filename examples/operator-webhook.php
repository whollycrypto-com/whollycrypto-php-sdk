<?php
declare(strict_types=1);
// Receiver building block. Load the SDK and connect your durable queue separately.
function verifiedOperatorEvent(string $rawBody, string $signatureHeader, string $secret, array $expectedMerchantIds): array
{
    if (!WhollyCrypto\Webhook::verify($rawBody, $signatureHeader, $secret)) {
        throw new RuntimeException('Invalid or expired signature');
    }
    $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
    $events = ['merchant.created','merchant.updated','user.created','user.updated','invitation.accepted','password_reset.completed','topup.settled','credit.balance_changed'];
    if (!is_array($event) || !is_string($event['event_id'] ?? null) || !preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $event['event_id'])
        || !in_array($event['merchant_id'] ?? null,$expectedMerchantIds,true) || !in_array($event['event_type'] ?? null,$events,true)) {
        throw new RuntimeException('Unexpected Operator event');
    }
    return $event;
}
// Insert unique event_id and enqueue reconciliation in one database transaction.
// Return 2xx only after durable acceptance; duplicates must not repeat actions.
// Do not use Webhook::parse(): Operator events are not invoice notifications.
