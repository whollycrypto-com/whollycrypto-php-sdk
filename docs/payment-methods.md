# Payment methods and reconciliation

Configure the client as shown in the [README](../README.md). These examples use
your Project/Store API UUIDs, not their readable identifiers.

Token registration performs live chain checks. For these longer operations use
`new \WhollyCrypto\Options(timeoutSeconds: 60)` when constructing your client. Your installation's
reverse-proxy timeout must also permit the operation. A timeout can leave the
registration completed on the server: check the asset list before retrying.

## Discover and register a catalog token

```php
$candidates = $client->listTokenCandidates($projectId, 'ethereum', [
    'q' => 'USDC', 'limit' => 20,
]);

$registered = $client->registerTokenAsset($projectId, [
    'chain_slug' => 'ethereum',
    'coingecko_id' => 'usd-coin',
    'enabled' => true,
]);
$assetId = $registered['data']['asset_id'];
```

Catalog matching is discovery, not proof that an asset can be paid. The merchant
server verifies on-chain metadata and scanner support. It also applies project
limits, provider readiness and wallet backup policy.

## Custom token and pricing

```php
$pools = $client->discoverCustomDexPools($projectId, 'ethereum', $contractAddress);

$registered = $client->registerCustomToken($projectId, [
    'chain_slug' => 'ethereum',
    'contract_address' => $contractAddress,
    'name' => 'Example token',
    'symbol' => 'EXAMPLE',
    'price_usd' => '0.25', // Fixed USD price; always a string
]);
```

For DEX-linked pricing, choose a verified pool from the discovery response:

```php
$registered = $client->registerCustomToken($projectId, [
    'chain_slug' => 'ethereum',
    'contract_address' => $contractAddress,
    'name' => 'Example token',
    'symbol' => 'EXAMPLE',
    'price_mode' => 'dex',
    'dex_pair_address' => $chosenPoolAddress,
    // Omit price_usd in DEX mode.
]);
```

Never assume that a ticker identifies a contract, or that a discovered pool has
safe liquidity. The server rechecks the pool and applies its current pricing rules.

## Accept assets in a store

```php
$current = $client->listStorePaymentAssets($projectId, $storeId);

// This is the ENTIRE desired list, not an append operation.
$client->updateStorePaymentAssets($projectId, $storeId, [
    ['asset_id' => $bitcoinAssetId, 'display_order' => 0],
    ['asset_id' => $usdcAssetId, 'display_order' => 1],
]);

$client->updateStoreConfirmationPolicy($projectId, $storeId, $bitcoinAssetId, [
    'strategy' => 'custom', 'required_confirmations' => 2,
]);

// Restore the inherited project policy:
$client->updateStoreConfirmationPolicy($projectId, $storeId, $bitcoinAssetId, [
    'strategy' => 'inherit',
]);
```

Selecting zero confirmations allows settlement on detection and carries greater
reversal/double-spend risk. Finality-only chains may reject editable confirmation
settings. Lightning is selected separately in the console and is not changed by
the on-chain `assets` list. Its readiness is returned in the `lightning` member.

## Wallet balances

```php
$wallets = $client->listProjectWallets($projectId)['data'];
foreach ($wallets as $wallet) {
    foreach ($wallet['balances'] as $asset) {
        // Preserve balance / balance_atomic as strings.
        // Check status and checked_at: a null balance is NOT zero.
    }
}
```

These are public wallet summaries. Private keys, seed phrases, sweeps and sends
are not exposed through the merchant bearer API.

## Needs attention

```php
$queue = $client->listReconciliation($projectId, [
    'status' => 'open', 'reason' => 'underpaid', 'page' => 1,
]);

$detail = $client->getReconciliation($projectId, $publicInvoiceId, page: 2);
```

`page` in detail selects decision history only. The latest observations,
deliveries and refund records have separate bounded lists. Refunds, cancel,
accept/reject and other financial decisions are controlled console actions,
not SDK mutations.
