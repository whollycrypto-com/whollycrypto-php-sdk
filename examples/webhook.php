<?php

declare(strict_types=1);

// Example HTTP receiver, for ONE configured store/signing secret.
// Requires PDO SQLite in addition to the SDK's requirements.
// Set WHOLLY_SIGNING_SECRET and WHOLLY_CALLBACK_DB=/private/path/callbacks.sqlite.
// Keep that directory outside the web root and writable only by the app user.
// A separate worker MUST process the queue, fetch the authenticated invoice,
// match its expected order/project/store/amount/currency and fulfil once.
// Works directly from the extracted SDK ZIP; no Composer or vendor/ required.
require_once dirname(__DIR__) . '/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit;
}
$raw = file_get_contents('php://input', false, null, 0, 262145);
if ($raw === false || strlen($raw) > 262144) {
    http_response_code(413); exit;
}
try {
    $secret = getenv('WHOLLY_SIGNING_SECRET');
    $database = getenv('WHOLLY_CALLBACK_DB');
    if (!$secret || !$database || !str_starts_with($database, '/') || !is_dir(dirname($database))) {
        throw new RuntimeException('Receiver is not configured.');
    }
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $directory = realpath(dirname($database));
    if ($documentRoot && ($directory === $documentRoot || str_starts_with($directory, $documentRoot . '/'))) {
        throw new RuntimeException('Queue storage must be outside the document root.');
    }
    $notification = \WhollyCrypto\Webhook::parse($raw, getallheaders(), $secret);
    umask(0077);
    $db = new PDO('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=3000');
    $db->exec('CREATE TABLE IF NOT EXISTS wholly_notifications (
        event_id TEXT PRIMARY KEY,
        invoice_id TEXT NOT NULL,
        sequence INTEGER NOT NULL,
        delivery_id TEXT NOT NULL,
        payload TEXT NOT NULL,
        received_at INTEGER NOT NULL,
        processed_at INTEGER,
        UNIQUE(invoice_id, sequence)
    )');
    $db->beginTransaction();
    $insert = $db->prepare('INSERT INTO wholly_notifications
        (event_id, invoice_id, sequence, delivery_id, payload, received_at)
        VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING');
    $insert->execute([$notification->eventId, $notification->invoiceId(), $notification->sequence(), $notification->deliveryId, $raw, time()]);
    $db->commit();
    http_response_code(204); // Acknowledge only after durable storage or deduplication.
} catch (\WhollyCrypto\Exception\InvalidSignatureException) {
    http_response_code(400);
} catch (Throwable) {
    // Let Wholly Crypto retry. Never print secrets, payloads or database errors.
    http_response_code(503);
}
