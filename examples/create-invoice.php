<?php

declare(strict_types=1);

// Running this example creates a REAL invoice on the installation you configure.
// Persist WHOLLY_IDEMPOTENCY_KEY and this payload before calling it. Do not
// generate another key merely because a request timed out.
// Works directly from the extracted SDK ZIP; no Composer or vendor/ required.
require_once dirname(__DIR__) . '/autoload.php';

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException('Set ' . $name . ' before running this example.');
    }
    return $value;
}

$client = new \WhollyCrypto\Client(
    requiredEnvironment('WHOLLY_API_URL'),
    requiredEnvironment('WHOLLY_API_TOKEN'),
);
$result = $client->createInvoice(
    requiredEnvironment('WHOLLY_PROJECT_ID'),
    requiredEnvironment('WHOLLY_STORE_ID'),
    ['amount' => '10.00', 'currency' => 'EUR', 'order_id' => 'sdk-example-order'],
    requiredEnvironment('WHOLLY_IDEMPOTENCY_KEY'),
);
echo json_encode(['public_id' => $result['data']['public_id'], 'checkout' => $result['links']['checkout']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
