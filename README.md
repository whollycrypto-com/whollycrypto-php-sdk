# Wholly Crypto PHP SDK

**Merchant 4 upgrade:** read `data.invoice_id` from invoice creation/detail and `invoice_id` from list rows. It matches the callback `invoice_id`. The server no longer returns `public_id`; internal `id` is not a checkout ID. Update custom response readers before upgrading your merchant. For older merchants, keep SDK 1.x or explicitly handle their older response shape.

The official PHP client for your **self-hosted Wholly Crypto merchant API**.
Create invoices, check payments, manage accepted assets and verify IPN/webhooks.

PHP **7.4+**, cURL and JSON. No framework or third-party runtime packages.
SDK **2.5.0** targets API **v1**, tested against merchant **5.6.0**.
The SDK and merchant application have independent version numbers.

PHP 7.4 compatibility is for existing integrations. It [no longer receives PHP security fixes](https://www.php.net/eol.php);
use a supported PHP 8 release for new deployments.

## Install

### With Composer

```bash
composer require whollycrypto/php-sdk
```

If you need to install directly from GitHub before Packagist indexes a release:

```bash
composer config repositories.whollycrypto vcs https://github.com/whollycrypto-com/whollycrypto-php-sdk
composer require whollycrypto/php-sdk
```

Load it in your application with `require_once __DIR__ . '/vendor/autoload.php';`.

### Without Composer (manual download)

1. [Download SDK 2.5.0 as a ZIP](https://github.com/whollycrypto-com/whollycrypto-php-sdk/archive/refs/tags/v2.5.0.zip).
2. Extract it into your application and rename the extracted folder to `whollycrypto-php-sdk`.
   Keep `autoload.php` and the complete `src/` folder together. No `vendor/` folder is needed.
3. Load the SDK and create the client directly:

```php
<?php

require_once __DIR__ . '/whollycrypto-php-sdk/autoload.php';

$client = new \WhollyCrypto\Client(
    'https://api.your-domain.com',
    getenv('WHOLLY_API_TOKEN'),
);
```

This assumes `whollycrypto-php-sdk/` is beside your PHP script; adjust the path if
you keep libraries elsewhere. PHP 7.4+, cURL and JSON are still required.
Set `WHOLLY_API_TOKEN` on your server using a credential from **Settings → API access**.

No `use` statement is needed. The exact class name is `\WhollyCrypto\Client`;
the leading `\` also makes it work inside your application's own namespace.
Both installation methods provide the same client and API methods.

## Create an invoice

Use **your installation's API domain**, not its merchant-console or checkout domain.
Create a credential under **Settings → API access** and grant it the required
project access. Invoice creation needs a read/write credential.

Find both UUIDs in **Project → Stores → select store → Basic → API IDs**.
Copy **Project API ID** and **Store API ID**. The readable project/store identifiers
are not API UUIDs. Projects and stores are created in the console, not through this API.

```php
<?php

require_once __DIR__ . '/whollycrypto-php-sdk/autoload.php';
// With Composer, use require_once __DIR__ . '/vendor/autoload.php'; instead.

$client = new \WhollyCrypto\Client('https://api.your-domain.com', getenv('WHOLLY_API_TOKEN'));

// Persist this key AND the invoice payload with your order before the request.
// Reuse the same key, credential and payload if the response is lost.
$idempotencyKey = 'order-1042-payment-attempt-1';

$result = $client->createInvoice(
    '11111111-1111-4111-8111-111111111111', // Your Project API ID
    '22222222-2222-4222-8222-222222222222', // Your Store API ID
    [
        'amount' => '49.90', // From a variable: 'amount' => (string) $amount
        'currency' => 'EUR',
        'order_id' => 'order-1042',
        'email' => 'customer@example.com',
        'description' => 'Annual plan',
        'ipn_url' => 'https://your-shop.com/wholly/ipn',
        'redirect_url' => 'https://your-shop.com/orders/1042',
        'cancel_url' => 'https://your-shop.com/cart',
    ],
    $idempotencyKey,
);

$publicInvoiceId = $result['data']['invoice_id'];
$checkoutUrl = $result['links']['checkout'];
```

For a runnable example without Composer, see [examples/create-invoice.php](examples/create-invoice.php).
It reads your URL, credential, IDs and persisted idempotency key from environment variables.

The example UUIDs are placeholders. Return the checkout URL to the customer or
redirect from your server. Never expose your API token to browser JavaScript,
HTML, source control or customer checkout URLs. A return/success URL is not proof
of payment; verify the invoice status on your server.

Every method returns the **complete decoded response**, preserving `data`,
`links`, `pagination` and other fields. Reconciliation responses have their own
top-level shape. The SDK does not unwrap, rename or round amounts.

## Check and list payments

```php
$invoice = $client->getInvoice($projectId, $publicInvoiceId)['data'];

if ($invoice['status'] === 'settled') {
    // Match your stored order, expected amount/currency and project/store first.
    // Fulfil once, using a transaction or another durable idempotency mechanism.
}

$page = $client->listInvoices($projectId, [
    'store_id' => $storeId,
    'status' => 'settled',
    'search' => 'order-1042',
    'limit' => 50,
    'offset' => 0,
]);

foreach ($client->iterateInvoices($projectId, ['status' => 'settled']) as $invoice) {
    // Lazy pagination: each page is requested when needed.
}
```

Invoice paths use **`invoice_id`**, not the internal `id` or your `order_id`.
`processing` is not `settled`. Read [the API lifecycle](https://www.whollycrypto.com/api/)
before implementing fulfilment. List pages are separate snapshots: concurrent
inserts can shift offsets, so deduplicate by public ID during exports.

## Choose invoice payment methods

Merchant 5.3.0+ accepts ticker-based `payment_methods` to limit one invoice to methods already
accepted by its store. Omit it (or use `null`) for all store methods. `[]` is invalid.

```php
$payload['payment_methods'] = [
    ['chain_slug' => 'ethereum', 'asset_tickers' => ['USDC', 'USDT']],
    ['chain_slug' => 'bitcoin', 'payment_rail' => 'lightning'],
];
```

Read the chain hint and asset ticker in **Project → Stores → Payment methods**, or read selected
entries from `listStorePaymentAssets($projectId, $storeId)`.
Tickers are trimmed and matched case-insensitively, within that chain and store.
If two accepted contracts share a ticker, the request fails even when one is not ready.
Use `'asset_ids' => [$entry['asset']['id']]` to disambiguate (merchant 5.1.0+).
Never combine non-null `asset_ids` and `asset_tickers` in one selection.
Omit both to include all active accepted assets on that chain. Lightning is separate.
On merchant **5.4.0+**, unknown, inactive, wrong-chain or unaccepted choices are
ignored. If none match, the invoice uses store defaults. Active selected methods
still need ready wallets/scanners and trustworthy rates; this never enables an asset.
Maximum 64 methods; store settings stay unchanged. Older merchants reject unmatched
choices. Keep the exact original payload and key for retries.
SDK 2.4.0+ adds actionable, log-safe explanations to `ApiException::getMessage()`.
For a failed creation, use `$e->getPaymentMethodIssues()` for the chain, ticker,
`reason_code`, provider counts and suggested action. `$e->getDetails()` returns
the full error details; `$e->getApiMessage()` returns the server explanation.
These explicit diagnostics may contain private data.
Do not expose response bodies to customers or log them indiscriminately.
See [the selection schema and examples](https://www.whollycrypto.com/api/#create-invoice).

## Invoice options and appearance

The invoice payload accepts all current API fields, including customer metadata,
expiry, callbacks, language, rate spread, underpayment tolerance and checkout appearance.
Omit optional fields to inherit the store settings. Server-side validation remains authoritative.

```php
$payload = [
    'amount' => '25.00', // Or: 'amount' => (string) $amount
    'currency' => 'USD',
    'exchange_rate_spread_percent' => '0.5',
    'underpayment_tolerance_percent' => '1',
    'expires_in_seconds' => 900,
    'language' => 'de',
    'metadata' => [
        'firstname' => 'Ada', 'lastname' => 'Lovelace',
        'street' => '12 Example Street', 'zip' => '10115',
        'city' => 'Berlin', 'country' => 'Germany', 'countryiso2' => 'DE',
        'company' => 'Example GmbH', 'vatid' => 'DE123456789',
    ],
    'checkout_appearance' => [
        'show_project_name' => true,
        'show_store_name' => false,
        'title' => 'Complete your order',
        'intro' => 'Thanks for choosing us.',
        'outro' => 'Questions? https://your-shop.com/help',
        'intro_font_size' => 18,
        'outro_font_size' => 16,
        'theme' => 'light',
        'accent_color' => '#1768CE',
    ],
];
```

Appearance supports structured settings, not arbitrary HTML, JavaScript or CSS.
`metadata => []` and `checkout_appearance => []` become JSON objects (`{}`).
An empty appearance object freezes the resolved store design for that invoice;
omitting it keeps normal store appearance behavior. JSON object keys are sorted
for deterministic request bytes; list order and decimal strings are preserved.

## All merchant API methods

Merchant 5.6.0 adds these name-visibility controls. They affect the checkout header,
not identity fields in JSON. In **Store → Basic → Store domains**, choose preferred
checkout and API hosts. Links use this store, then its default store, then the
system primary; only active domains qualify. Set your SDK base URL to the preferred
API host. Already-signed callback retries keep their original links.

| Method | Purpose |
| --- | --- |
| `serviceInfo()` / `health()` | Public API service and health; no token sent |
| `createInvoice($projectId, $storeId, $payload, $key)` | Create or replay an invoice |
| `getInvoice($projectId, $publicId)` | Private invoice detail and current checkout link |
| `listInvoices($projectId, $filters)` | Searchable, paginated invoices |
| `iterateInvoices($projectId, $filters)` | Lazy iterator over invoice pages |
| `listProjectPaymentAssets($projectId)` | Native/token asset policies and readiness |
| `updateProjectPaymentAsset($projectId, $assetId, $policy)` | Update one project asset policy |
| `listTokenCandidates($projectId, $chainSlug, $filters)` | Search catalog tokens; filters `q`, `limit` |
| `registerTokenAsset($projectId, $token)` | Verify and register a catalog token |
| `discoverCustomDexPools($projectId, $chainSlug, $contract)` | Discover supported DEX pricing candidates |
| `registerCustomToken($projectId, $token)` | Register a verified custom contract/mint |
| `listStorePaymentAssets($projectId, $storeId)` | Accepted on-chain methods and separate Lightning readiness |
| `updateStorePaymentAssets($projectId, $storeId, $assets)` | **Replace** the store's on-chain selection |
| `updateStoreConfirmationPolicy($projectId, $storeId, $assetId, $policy)` | Inherit or override confirmations |
| `listProjectWallets($projectId)` | Public addresses and current balances; no private keys |
| `listReconciliation($projectId, $filters)` | Needs-attention queue; fixed 25 cases per page |
| `getReconciliation($projectId, $publicId, $page)` | Exception detail and paginated decision history |

For request fields and response schemas, use the [full API reference](https://www.whollycrypto.com/api/)
or the reference installed on your merchant console. More examples are in [docs/payment-methods.md](docs/payment-methods.md).

`updateStorePaymentAssets()` receives the list itself, not an `assets` wrapper.
An empty list removes **all on-chain selections**. It does not configure Lightning.
Refunds, sends, reconciliation decisions, account administration, exchange credentials
and Lightning setup are console-only; this SDK does not invent public endpoints for them.

Keep amounts as decimal strings from the start. `(string) $amount` converts a variable to the required string type, but cannot recover precision already lost through floating-point calculations. Keep the original decimal text; do not calculate payment totals with floats.

## IPN and webhook verification

IPN sends every generated invoice event to the store default URL or invoice's
`ipn_url`. Webhooks send only selected events. Both deliver the same JSON snapshot.
Use **Store → IPN's secret for IPN** and **the individual webhook endpoint's secret
for webhooks**, never an API token. Rotating one does not rotate the others.

**For event-based handling, trigger an order check on `event_type = invoice.settled`
with `status = settled`. Verify the current invoice and fulfil once.**
`status` is a state snapshot; `event_type` explains what happened.
`payment.received` can already carry `settled` when first detected (for example,
on Solana), or `processing` while confirmations are pending. Do not credit both.

The supplied receiver is **state-based**: it groups project + `invoice_id` +
`sequence`. Its worker checks the saved/current state regardless of event type.
Do not add an `invoice.settled`-only filter after grouping: `payment.received`
may have arrived first with the same settled revision. An event-based inbox
instead preserves distinct signed `event_id` values. Both approaches need
separate invoice/order-level fulfil-once protection.

Invoice statuses are `new`, `processing`, `settled`, `expired`, `invalid`, `cancelled`.
`amount_status = paid` includes tolerance, not confirmation finality.
Use `resolution` and `requires_review` for your exception policy.
Version 2 includes signed event identity, `payment_info`, chain/token transfers,
customer data and metadata. `amount`/`currency` are the original invoice total,
not crypto received. Fetch the current invoice before fulfilment.

[IPN/webhook setup and receiver example](examples/ipn-webhooks.md) ·
[Integration guide](https://www.whollycrypto.com/documentation/#delivery-history) ·
[Event table and full payload](https://www.whollycrypto.com/api/#notifications).
Also available in your console at `/settings/api/docs/#notifications`.

```php
$rawBody = file_get_contents('php://input', false, null, 0, 262145);

try {
    $notification = \WhollyCrypto\Webhook::parse(
        $rawBody,
        getallheaders(),
        getenv('WHOLLY_SIGNING_SECRET'),
    );
} catch (\WhollyCrypto\Exception\InvalidSignatureException $error) {
    http_response_code(400);
    exit;
}

// Durably enqueue before returning 2xx. Do not acknowledge a failed DB write.
// Use $notification->eventId and invoiceId()/sequence() for replay protection.
// Re-fetch the invoice through the authenticated client before fulfilment.
```

Use the **exact raw bytes**, before JSON parsing or middleware transformations.
Verification uses HMAC-SHA256 and constant-time comparison, with a default
five-minute past/future clock window. Keep the receiver clock synchronized.

Signatures cover the timestamp and raw body, **not the event/delivery headers**.
Version 2 signs `event_id`, `event_type`, `project_id` and `store_id` in the body.
Legacy events keep their old format. Match receiver scope and keep invoice state
monotonic. Different event types can share a revision: compare the original nine
invoice-state fields, not the entire body, when deduplicating by invoice/sequence.
`payment_info` includes chain/token amounts, remaining funds, confirmations,
locked quote/spread/tolerance, advisory market rates and bounded transfer history.
Use `listInvoicePayments($projectId, $invoiceId, ['limit' => 25, 'offset' => 0])`
for complete current observations. Metadata and customer fields stay private.
For a durable SQLite queue example, see [examples/webhook.php](examples/webhook.php).
It additionally needs PDO SQLite and a private writable directory.

## Errors, timeouts and retries

```php
$client = new \WhollyCrypto\Client(
    'https://api.your-domain.com',
    getenv('WHOLLY_API_TOKEN'),
    new \WhollyCrypto\Options(20, 5, 1), // Timeout seconds, connection timeout, retries
);

try {
    $invoice = $client->getInvoice($projectId, $publicInvoiceId);
} catch (\WhollyCrypto\Exception\ApiException $error) {
    $status = $error->statusCode;        // e.g. 429
    $code = $error->errorCode;           // e.g. rate_limit_exceeded
    $wait = $error->getRetryAfter();     // seconds, or null
    $detail = $error->getApiMessage();   // Remote detail; may contain customer data
    $issues = $error->getPaymentMethodIssues(); // Inspect each reason_code/action privately
    // getMessage() now includes safe scanner/wallet/rate guidance for recognized reasons.
} catch (\WhollyCrypto\Exception\TransportException $error) {
    // A timeout does NOT prove that invoice creation failed.
    // Retry the original invoice payload with the same stored idempotency key.
}

$response = $client->lastResponse();
$quota = $response !== null ? $response->rateLimit() : null; // limit, remaining, reset
```

Examples use positional arguments so they also run on PHP 7.4. Existing PHP 8
named arguments still work. `Options` parameters, in order: `timeoutSeconds`,
`connectTimeoutSeconds`, `maxRetries`, `maxRetryDelaySeconds`,
`allowInsecureLocalhost`, `maxResponseBytes`.

Value properties such as `$options->timeoutSeconds` and `$notification->payload`
remain read-only views. Create a new object to change settings. Internally these
use private properties and getters on every PHP version; do not rely on native
`readonly` reflection or `get_object_vars()` to inspect them. JSON output retains
the same public fields, and request/response bodies stay behind their explicit getters.

Retries are **off by default**. If enabled, only GET requests and invoice creation
with its explicit idempotency key can retry transient connection failures or
HTTP 429/502/503/504. Other writes never retry automatically. Retries reuse the
same encoded body and key. Never change credentials or JSON encoding mid-retry;
the server binds idempotency to the original credential and exact body bytes.

`Retry-After` is respected with jitter. When it exceeds `maxRetryDelaySeconds`
(default 60), the SDK throws immediately so your job queue can reschedule;
it does not retry early. At most three retries can be configured. Timeouts apply
per attempt, so account for attempts and backoff in your worker's time budget.

TLS certificate verification is always on. Redirects and environment proxies
are not followed/used, and `.netrc` credentials are ignored. Responses are bounded
to 8 MiB by default; HTML login pages and malformed JSON are rejected. Configure
the API origin on your server, never from a customer's request. Custom transports
receive your API token and must be trusted.

On PHP 7.4–8.1, set `zend.exception_ignore_args = On` in your application's PHP
configuration so exception traces cannot include API tokens or signing secrets.
PHP 8.2+ additionally supports the SDK's `SensitiveParameter` annotations; older
PHP versions ignore them. Keep argument capture off in error-monitoring tools on every version.

## Optional checkout reader and Lightning

```php
$checkout = new \WhollyCrypto\CheckoutClient('https://pay.your-domain.com');
$public = $checkout->getInvoice($publicInvoiceId);
$url = $checkout->invoiceUrl($publicInvoiceId);
```

This separate client never holds or sends a merchant API token. It can also read
`getPreview($projectId, $storeId)` and build `previewUrl(...)`. Preview state is
illustrative, never proof of payment. QR and image URLs are already returned by
checkout JSON; use those current URLs rather than reconstructing cached assets.

Keep `payment_rail`, `asset_decimals`, destination tags/memos and `payment_uri`
intact. Lightning BTC uses **11 atomic decimals (millisatoshis)**; on-chain BTC
uses 8. A Lightning payment hash is not an on-chain receiving address: use its
`bolt11`/`payment_uri` and respect `payable`. The SDK never signs or sends funds.

## Development

```bash
composer install
composer validate --strict
composer lint
composer test
php tests/run.php --manual-autoload
```

Tests cover all 18 merchant endpoints, mocked responses, exact JSON/amounts,
idempotency, signatures, pagination and a real loopback cURL fixture. They never
create live invoices or move funds. Development tests additionally need OpenSSL
CLI/PHP and PDO SQLite for the TLS and durable callback examples. Plain HTTP is only available with
`new \WhollyCrypto\Options(20, 5, 0, 60, true)` for `localhost`, `127.0.0.1` or `::1`.
This does not disable HTTPS certificate verification.

The minimum supported runtime is PHP 7.4. All 16 suites pass on PHP 7.4.33,
PHP 8.1.34 and PHP 8.3.6. The suite covers both Composer and the
standalone loader, including an isolated copy with no `vendor/` directory,
read-only values, PHP 8 named arguments and legacy cURL resources.
A pinned-action PHP 7.4 / 8.0–8.5
CI template is provided in [ci/github-actions.yml](ci/github-actions.yml).
Repository maintainers can copy it into `.github/workflows/tests.yml` to enable CI.

## License

[MIT](LICENSE), for this SDK only. The merchant application and other Wholly Crypto
software have their own licenses; no server implementation or private service
code is included here.
