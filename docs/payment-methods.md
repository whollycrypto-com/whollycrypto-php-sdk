# Payment methods and reconciliation

Configure the client as shown in the [README](../README.md). These examples use
your Project/Store API UUIDs, not their readable identifiers.

Token registration performs live chain checks. For these longer operations use
`new \WhollyCrypto\Options(60)` when constructing your client. Your installation's
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

## Invoice-specific selection

On merchant 5.3.0+, select already accepted tokens with `chain_slug` and
`asset_tickers`, for example `{"chain_slug":"ethereum","asset_tickers":["USDC","USDT"]}`.
Find the chain hint and asset ticker in **Project → Stores → Payment methods**.
Put your selection in `payment_methods`. Tickers are case-insensitive and chain-scoped.
They never enable new store assets. Duplicate accepted symbols are rejected;
use `asset_ids` with the selected entry's `asset.id` for an exact contract instead.
Do not send both selectors. Omit both to include all active accepted assets on that chain.
Merchant 5.4.0+ ignores unknown, inactive or unaccepted choices and uses store
defaults if none match. Active selected assets still need ready wallets/scanners
and trustworthy rates. Older merchants reject unmatched choices. Check the API
error message and details.payment_methods for chain-specific diagnostics.
See the [complete invoice example](../README.md#choose-invoice-payment-methods).

## Receiving diagnostics

Merchant 5.5.0+ includes `receive_readiness` in scoped asset and wallet listings.
It describes cached receiving requirements, not a balance or sending/gas check.
Invoice creation rechecks requirements and rates for its currency. A healthy
TRON full node alone is not a history indexer for payment discovery.

SDK 2.4.0+ gives `ApiException::getMessage()` a safe, actionable summary. Use
`$error->getPaymentMethodIssues()` for structured chain, ticker, reason code and
provider requirements, or `$error->getDetails()` for the full details object.
Treat those explicit server details as untrusted data: escape before rendering
and do not log full responses or customer data. Fix the named configuration,
then retry with the **same idempotency key**. Never disable scanner checks.

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

$detail = $client->getReconciliation($projectId, $publicInvoiceId, 2); // Decision-history page
```

`page` in detail selects decision history only. The latest observations,
deliveries and refund records have separate bounded lists. Refunds, cancel,
accept/reject and other financial decisions are controlled console actions,
not SDK mutations.
