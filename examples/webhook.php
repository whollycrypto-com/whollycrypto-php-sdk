<?php

declare(strict_types=1);

// Example HTTP receiver, for ONE configured store/signing secret.
// Works for IPN or webhooks; see ipn-webhooks.md and notification.json.
// IPN uses Store -> IPN's secret; webhooks use their own endpoint's secret.
// Requires PDO SQLite in addition to the SDK's requirements.
// Set WHOLLY_SIGNING_SECRET and WHOLLY_CALLBACK_DB=/private/path/callbacks.sqlite.
// Set WHOLLY_CALLBACK_PROJECT_ID to the project's API UUID, not a request value.
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
    $project = getenv('WHOLLY_CALLBACK_PROJECT_ID');
    if (!is_string($project) || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $project)) {
        throw new RuntimeException('Configure a project UUID for this receiver.');
    }
    $project = strtolower($project);
    if (!$secret || !$database || strpos($database, '/') !== 0 || !is_dir(dirname($database))) {
        throw new RuntimeException('Receiver is not configured.');
    }
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $directory = realpath(dirname($database));
    if ($documentRoot && ($directory === $documentRoot || strpos($directory, $documentRoot . '/') === 0)) {
        throw new RuntimeException('Queue storage must be outside the document root.');
    }
    $notification = \WhollyCrypto\Webhook::parse($raw, getallheaders(), $secret);
    umask(0077);
    $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=3000');
    $db->exec('PRAGMA synchronous=FULL');
    $db->exec('CREATE TABLE IF NOT EXISTS wholly_callback_inbox (
        project_id TEXT NOT NULL,
        event_id TEXT NOT NULL,
        invoice_id TEXT NOT NULL,
        sequence INTEGER NOT NULL,
        delivery_id TEXT NOT NULL,
        payload TEXT NOT NULL,
        received_at INTEGER NOT NULL,
        processed_at INTEGER,
        PRIMARY KEY(project_id, invoice_id, sequence)
    )');
    $db->beginTransaction();
    $insert = $db->prepare('INSERT INTO wholly_callback_inbox
        (project_id, event_id, invoice_id, sequence, delivery_id, payload, received_at)
        VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING');
    $insert->execute([$project, $notification->eventId, $notification->invoiceId(), $notification->sequence(), $notification->deliveryId, $raw, time()]);
    $existing = $db->prepare('SELECT payload FROM wholly_callback_inbox WHERE project_id=? AND invoice_id=? AND sequence=?');
    $existing->execute([$project, $notification->invoiceId(), $notification->sequence()]);
    if ($existing->fetchColumn() !== $raw) {
        $db->rollBack();
        http_response_code(409); exit; // Conflicting signed snapshot needs review.
    }
    $db->commit();
    http_response_code(204); // Acknowledge only after durable storage or deduplication.
} catch (\WhollyCrypto\Exception\InvalidSignatureException $error) {
    http_response_code(400);
} catch (Throwable $error) {
    // Let Wholly Crypto retry. Never print secrets, payloads or database errors.
    http_response_code(503);
}
