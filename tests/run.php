<?php

declare(strict_types=1);

require dirname(__DIR__) . (in_array('--manual-autoload', $argv, true) ? '/autoload.php' : '/vendor/autoload.php');

use WhollyCrypto\Client;
use WhollyCrypto\CheckoutClient;
use WhollyCrypto\Options;
use WhollyCrypto\Webhook;
use WhollyCrypto\Exception\ApiException;
use WhollyCrypto\Exception\InvalidResponseException;
use WhollyCrypto\Exception\InvalidSignatureException;
use WhollyCrypto\Exception\TransportException;
use WhollyCrypto\Http\Request;
use WhollyCrypto\Http\Response;
use WhollyCrypto\Http\TransportInterface;
use WhollyCrypto\Internal\Compat;

error_reporting(E_ALL);
set_error_handler(static function (int $number, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $number)) { return false; }
    throw new ErrorException($message, 0, $number, $file, $line);
});

const PROJECT = '11111111-1111-4111-8111-111111111111';
const STORE = '22222222-2222-4222-8222-222222222222';
const INVOICE = '33333333-3333-4333-8333-333333333333';
const ASSET = '44444444-4444-4444-8444-444444444444';
const TOKEN = 'wc_fixture_not_a_real_credential';

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function same($expected, $actual, string $message = 'Values differ'): void
{
    check($expected === $actual, $message);
}

function throws(callable $fn, string $class): Throwable
{
    try { $fn(); } catch (Throwable $e) {
        check($e instanceof $class, 'Expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        return $e;
    }
    throw new RuntimeException('Expected ' . $class);
}

final class FakeTransport implements TransportInterface
{
    public array $requests = [];
    public array $queue;
    public function __construct(array $queue = []) { $this->queue = $queue; }
    public function send(
        #[SensitiveParameter]
        Request $request,
        Options $options
    ): Response {
        $this->requests[] = $request;
        if ($this->queue === []) { throw new RuntimeException('Unexpected extra HTTP request.'); }
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}

function reply(array $body = ['data' => []], int $status = 200, array $headers = []): Response
{
    return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
}

function invokeEndpoint(Client $client, string $id, ?array $body): array
{
    switch ($id) {
        case 'api-service-root': return $client->serviceInfo();
        case 'api-health': return $client->health();
        case 'reconciliation-list': return $client->listReconciliation(PROJECT, ['status' => 'open', 'page' => 1]);
        case 'reconciliation-detail': return $client->getReconciliation(PROJECT, INVOICE);
        case 'list-project-payment-assets': return $client->listProjectPaymentAssets(PROJECT);
        case 'update-project-payment-asset': return $client->updateProjectPaymentAsset(PROJECT, ASSET, $body);
        case 'list-token-candidates': return $client->listTokenCandidates(PROJECT, 'ethereum', ['q' => 'usd', 'limit' => 10]);
        case 'register-token-asset': return $client->registerTokenAsset(PROJECT, $body);
        case 'discover-custom-dex-pools': return $client->discoverCustomDexPools(PROJECT, 'ethereum', '0x' . str_repeat('1', 40));
        case 'register-custom-token': return $client->registerCustomToken(PROJECT, $body);
        case 'list-store-payment-assets': return $client->listStorePaymentAssets(PROJECT, STORE);
        case 'update-store-payment-assets': return $client->updateStorePaymentAssets(PROJECT, STORE, $body['assets']);
        case 'update-store-confirmation-policy': return $client->updateStoreConfirmationPolicy(PROJECT, STORE, ASSET, $body);
        case 'list-project-wallets': return $client->listProjectWallets(PROJECT);
        case 'create-invoice': return $client->createInvoice(PROJECT, STORE, $body, 'persistent-order-1042');
        case 'list-invoices': return $client->listInvoices(PROJECT, ['search' => 'order-1042', 'limit' => 50, 'offset' => 0]);
        case 'get-invoice': return $client->getInvoice(PROJECT, INVOICE);
        default: throw new RuntimeException('Public merchant endpoint lacks SDK coverage: ' . $id);
    }
}

$tests = [];
$tests['standalone loader works without vendor and coexists with Composer in either order'] = static function (): void {
    $root = dirname(__DIR__);
    $directory = sys_get_temp_dir() . '/wholly-php-autoload-' . bin2hex(random_bytes(8));
    check(mkdir($directory, 0700));
    try {
        check(copy($root . '/autoload.php', $directory . '/autoload.php'));
        check(mkdir($directory . '/src', 0700));
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $target = $directory . substr($file->getPathname(), strlen($root));
            check($file->isDir() ? mkdir($target, 0700) : copy($file->getPathname(), $target));
        }
        foreach (['isolated', 'composer-first', 'manual-first'] as $mode) {
            $sdk = $mode === 'isolated' ? $directory : $root;
            $process = proc_open([PHP_BINARY, __DIR__ . '/autoload-probe.php', $sdk, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            check(is_resource($process));
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            same(0, proc_close($process), 'Autoload probe failed: ' . $output);
        }
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
};

$tests['PHP 7.4-compatible values retain read-only views, private data and JSON shapes'] = static function (): void {
    $options = new Options();
    $request = new Request('GET', 'https://api.example.test/healthz', ['Authorization' => 'Bearer ' . TOKEN]);
    $response = new Response(200, ['Content-Type' => 'application/json'], '{}');
    $notification = new WhollyCrypto\Notification(PROJECT, STORE, ['invoice_id' => INVOICE, 'sequence' => 1, 'status' => 'settled']);
    $apiError = new ApiException(429, 'rate_limit_exceeded', TOKEN, $response);
    $transportError = new TransportException('fixture', true);

    foreach ([$options, $request, $response, $notification, $apiError, $transportError] as $value) {
        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            check(!$property->isPublic(), 'SDK values must not expose mutable public storage.');
        }
        throws(static function () use ($value): void { $value->extra = 'not-allowed'; }, Error::class);
        check(!property_exists($value, 'extra'));
        check(!isset($value->extra));
        foreach ($value->jsonSerialize() as $name => $expected) {
            // Request's JSON deliberately includes a computed bodyBytes field.
            if ($name === 'bodyBytes') { continue; }
            check(isset($value->$name));
            same($expected, $value->$name);
            throws(static function () use ($value, $name, $expected): void { $value->$name = $expected; }, Error::class);
            throws(static function () use ($value, $name): void { unset($value->$name); }, Error::class);
        }
    }
    throws(static function () use ($options): void { $options->timeoutSeconds = 99; }, Error::class);
    throws(static function () use ($options): void { unset($options->maxRetries); }, Error::class);
    throws(static function () use ($request): void { $request->url = 'https://untrusted.example.test'; }, Error::class);
    throws(static function () use ($response): void { $response->statusCode = 201; }, Error::class);
    throws(static function () use ($notification): void { $notification->payload['status'] = 'invalid'; }, ErrorException::class);
    throws(static function () use ($request): void { $request->headers = []; }, Error::class);
    throws(static fn () => $request->headers, Error::class);
    throws(static fn () => $request->body, Error::class);
    throws(static fn () => $apiError->apiMessage, Error::class);
    check(!isset($request->headers));
    throws(static fn () => $options->__construct(), Error::class);
    throws(static fn () => $request->__construct('POST', 'https://different.example.test', []), Error::class);
    throws(static fn () => $response->__construct(500, [], 'changed'), Error::class);
    throws(static fn () => $notification->__construct(ASSET, ASSET, []), Error::class);
    throws(static fn () => $apiError->__construct(500, 'changed', null, $response), Error::class);
    throws(static fn () => $transportError->__construct('changed', false), Error::class);
    // With ordinary PHP notices suppressed, indirect writes still cannot mutate the original array.
    set_error_handler(static fn (int $number): bool => in_array($number, [E_NOTICE, E_WARNING], true));
    try {
        $notification->payload['status'] = 'invalid';
        $copy =& $notification->payload;
        $copy['status'] = 'invalid';
        unset($copy);
    } finally { restore_error_handler(); }
    $headers = $request->headers();
    $headers['Authorization'] = 'changed-copy';
    same('Bearer ' . TOKEN, $request->headers()['Authorization']);
    same('settled', $notification->status());
    same('GET', $request->method);
    same(200, $response->statusCode);
    same(20, $options->timeoutSeconds);
    same(429, $apiError->statusCode);
    same(true, $transportError->retryable);
    same(['statusCode' => 200], json_decode(json_encode($response), true));
    same(['statusCode' => 429, 'errorCode' => 'rate_limit_exceeded'], json_decode(json_encode($apiError), true));
    same(['retryable' => true], json_decode(json_encode($transportError), true));
    same(['eventId' => PROJECT, 'deliveryId' => STORE, 'payload' => $notification->payload], json_decode(json_encode($notification), true));
    same(['timeoutSeconds' => 20, 'connectTimeoutSeconds' => 5, 'maxRetries' => 0, 'maxRetryDelaySeconds' => 60, 'allowInsecureLocalhost' => false, 'maxResponseBytes' => 8_388_608], json_decode(json_encode($options), true));
};

$tests['local compatibility helpers preserve empty strings and exact list/object detection'] = static function (): void {
    foreach ([[[], true], [[0 => 'a', 1 => 'b'], true], [[1 => 'a'], false], [[-1 => 'a'], false], [['a' => 1], false], [[1 => 'b', 0 => 'a'], false]] as [$value, $expected]) {
        same($expected, Compat::isList($value));
        if (function_exists('array_is_list')) { same(array_is_list($value), Compat::isList($value)); }
    }
    foreach ([['', '', true], ['value', '', true], ['', 'x', false], ['é/coin', 'é/', true], ['value', 'longer-value', false]] as [$value, $prefix, $expected]) {
        same($expected, Compat::startsWith($value, $prefix));
        if (function_exists('str_starts_with')) { same(str_starts_with($value, $prefix), Compat::startsWith($value, $prefix)); }
    }
    same(true, Compat::contains('', ''));
    same(true, Compat::contains('a/b', '/'));
    same(false, Compat::contains('', 'x'));
};

$tests['PHP 8 callers retain their existing named argument configuration'] = static function (): void {
    $names = array_map(static fn ($parameter) => $parameter->getName(), (new ReflectionMethod(Options::class, '__construct'))->getParameters());
    same(['timeoutSeconds', 'connectTimeoutSeconds', 'maxRetries', 'maxRetryDelaySeconds', 'allowInsecureLocalhost', 'maxResponseBytes'], $names);
    if (PHP_VERSION_ID < 80000) { return; }
    // Fixed test code, not input. Kept in a string so PHP 7.4 can parse this test file.
    $code = 'require ' . var_export(dirname(__DIR__) . '/autoload.php', true) . '; '
        . '$options = new \\WhollyCrypto\\Options(timeoutSeconds: 15, connectTimeoutSeconds: 4, maxRetries: 1); '
        . '$client = new \\WhollyCrypto\\Client(baseUrl: "https://api.example.test", apiToken: "wc_fixture_not_a_real_credential", options: $options); '
        . 'if ($options->timeoutSeconds !== 15 || $options->connectTimeoutSeconds !== 4 || $options->maxRetries !== 1 || $client->lastResponse() !== null) { exit(1); }';
    $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    same(0, proc_close($process), 'Named argument compatibility failed: ' . $output);
};

$tests['argument-free exception traces protect credentials on PHP 7.4 and newer'] = static function (): void {
    $previous = ini_set('zend.exception_ignore_args', '1');
    check($previous !== false, 'Tests require configurable exception argument capture.');
    try {
        $error = throws(static fn () => new Client('http://api.example.test', TOKEN), InvalidArgumentException::class);
        $webhookError = throws(static fn () => Webhook::verify('{}', null, TOKEN, -1), InvalidArgumentException::class);
        foreach ([$error, $webhookError] as $caught) {
            foreach ($caught->getTrace() as $frame) {
                check(!isset($frame['args']), 'Configured PHP must omit trace arguments.');
            }
            check(!Compat::contains((string) $caught, TOKEN));
        }
        if (PHP_VERSION_ID >= 80200) {
            // Own-line attributes remain real engine redaction on supported PHP 8 versions.
            foreach ([
                [Client::class, '__construct', 'apiToken'],
                [WhollyCrypto\Internal\JsonClient::class, '__construct', 'token'],
                [Request::class, '__construct', 'headers'],
                [Request::class, '__construct', 'body'],
                [Webhook::class, 'verify', 'signingSecret'],
                [Webhook::class, 'parse', 'signingSecret'],
                [TransportInterface::class, 'send', 'request'],
                [WhollyCrypto\Http\CurlTransport::class, 'send', 'request'],
                [Options::class, '__set', 'value'],
            ] as [$class, $method, $parameter]) {
                $reflection = new ReflectionParameter([$class, $method], $parameter);
                same(1, count($reflection->getAttributes('SensitiveParameter')));
            }
            ini_set('zend.exception_ignore_args', '0');
            foreach ([
                throws(static fn () => new Client('http://api.example.test', TOKEN), InvalidArgumentException::class),
                throws(static fn () => Webhook::verify('{}', null, TOKEN, -1), InvalidArgumentException::class),
            ] as $caught) {
                check(!Compat::contains((string) $caught, TOKEN));
                check(!Compat::contains(json_encode($caught->getTrace(), JSON_THROW_ON_ERROR), TOKEN));
            }
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
};

$tests['all 17 public merchant endpoints, methods, bodies, auth and response envelopes'] = static function (): void {
    $catalog = json_decode(file_get_contents(__DIR__ . '/fixtures/api-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    same(17, count($catalog['endpoints']));
    foreach ($catalog['endpoints'] as $endpoint) {
        $transport = new FakeTransport([reply($endpoint['response'])]);
        $client = new Client('https://api.example.test/', TOKEN, null, $transport);
        $result = invokeEndpoint($client, $endpoint['id'], $endpoint['body']);
        same($endpoint['response'], $result, $endpoint['id'] . ' response');
        same(1, count($transport->requests));
        $request = $transport->requests[0];
        same($endpoint['method'], $request->method);
        $path = strtr($endpoint['path'], ['{project_id}' => PROJECT, '{store_id}' => STORE, '{public_id}' => INVOICE, '{asset_id}' => ASSET]);
        same($path, parse_url($request->url, PHP_URL_PATH), $endpoint['id'] . ' path');
        same($endpoint['access'] === 'public' ? null : 'Bearer ' . TOKEN, $request->headers()['Authorization'] ?? null);
        if ($endpoint['body'] === null) {
            same(null, $request->body());
        } else {
            same('application/json', $request->headers()['Content-Type']);
            check(json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR) == $endpoint['body']);
        }
        same($endpoint['id'] === 'create-invoice' ? 'persistent-order-1042' : null, $request->headers()['Idempotency-Key'] ?? null);
    }
};

$tests['decimal strings, large numbers, empty objects and deterministic retry bytes'] = static function (): void {
    $transport = new FakeTransport([new Response(200, ['content-type' => 'application/json'], '{"data":{"amount":"0.000000000000000001","atomic":99999999999999999999999999999999}}'), reply()]);
    $client = new Client('https://api.example.test', TOKEN, null, $transport);
    $one = ['amount' => '0.000000000000000001', 'currency' => 'EUR', 'metadata' => [], 'checkout_appearance' => []];
    $result = $client->createInvoice(PROJECT, STORE, $one, 'same-key');
    same('99999999999999999999999999999999', $result['data']['atomic']);
    same('0.000000000000000001', $result['data']['amount']);
    $client->createInvoice(PROJECT, STORE, array_reverse($one, true), 'same-key');
    same($transport->requests[0]->body(), $transport->requests[1]->body());
    check(Compat::contains($transport->requests[0]->body(), '"metadata":{}'));
    check(Compat::contains($transport->requests[0]->body(), '"checkout_appearance":{}'));
    foreach ([1, 1.1, '-1', '1e-8', 'NaN', '1,00', ' 1', '+1', '1.'] as $amount) {
        throws(fn () => $client->createInvoice(PROJECT, STORE, ['amount' => $amount], 'key'), InvalidArgumentException::class);
    }
    throws(fn () => $client->createInvoice(PROJECT, STORE, ['amount' => '1', 'exchange_rate_spread_percent' => 1.5], 'key'), InvalidArgumentException::class);
    throws(fn () => $client->registerCustomToken(PROJECT, ['price_usd' => 1.5]), InvalidArgumentException::class);
    same(2, count($transport->requests));
};

$tests['origin, UUID, credential and header injection validation'] = static function (): void {
    foreach (['http://api.example.test', 'https://user:pass@api.example.test', 'https://api.example.test/v1', 'https://api.example.test?x=1', 'https://api.example.test#x', 'https://api.example.test\\@evil.test', "https://api.example.test\n", 'file:///tmp/key', '//api.example.test', 'https://api.example.test:0'] as $url) {
        throws(fn () => new Client($url, TOKEN), InvalidArgumentException::class);
    }
    throws(fn () => new Client('http://10.0.0.1', TOKEN, new Options(20, 5, 0, 60, true)), InvalidArgumentException::class);
    foreach (['', 'Bearer a token', "secret\r\nX-Injected: true"] as $key) {
        throws(fn () => new Client('https://api.example.test', $key), InvalidArgumentException::class);
    }
    $client = new Client('https://api.example.test', TOKEN, null, new FakeTransport());
    foreach (['../other', PROJECT . '?x=1', 'project-name', '%2f', ''] as $id) {
        throws(fn () => $client->getInvoice($id, INVOICE), InvalidArgumentException::class);
        throws(fn () => $client->getInvoice(PROJECT, $id), InvalidArgumentException::class);
    }
    foreach (['', 'a b', "id\r\nx: y", str_repeat('x', 129)] as $key) {
        throws(fn () => $client->createInvoice(PROJECT, STORE, ['amount' => '1'], $key), InvalidArgumentException::class);
    }
    throws(fn () => $client->listInvoices(PROJECT, ['search' => ['nested']]), InvalidArgumentException::class);
    throws(fn () => $client->createInvoice(PROJECT, STORE, ['amount' => '1', 'metadata' => ['large' => str_repeat('x', 33000)]], 'key'), InvalidArgumentException::class);
    throws(fn () => $client->createInvoice(PROJECT, STORE, ['amount' => '1', 'metadata' => ['not-an-object']], 'key'), InvalidArgumentException::class);
    throws(fn () => $client->createInvoice(PROJECT, STORE, ['amount' => '1', 'metadata' => ['bad' => "\xff"]], 'key'), InvalidArgumentException::class);
    throws(fn () => $client->createInvoice(PROJECT, STORE, ['amount' => '1', 'metadata' => ['bad' => new DateTimeImmutable()]], 'key'), InvalidArgumentException::class);
    same(48, strlen(Client::newIdempotencyKey()));
    check(Client::newIdempotencyKey() !== Client::newIdempotencyKey());
};

$tests['status, API errors, malformed/non-JSON failures and rate limits'] = static function (): void {
    $transport = new FakeTransport([
        reply(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Private remote detail ' . TOKEN]], 429, ['Retry-After' => '19', 'X-RateLimit-Limit' => '120', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1800000060']),
        new Response(200, ['content-type' => 'text/html'], '<html>Login</html>'),
        new Response(200, ['content-type' => 'application/json'], '{'),
        new Response(200, ['content-type' => 'application/json'], '[]'),
        new Response(403, ['content-type' => 'text/plain'], 'Forbidden'),
        new Response(503, ['content-type' => 'application/json'], '{'),
        reply(['data' => []], 200, ['Content-Type' => 'application/problem+json; charset=utf-8']),
    ]);
    $client = new Client('https://api.example.test', TOKEN, null, $transport);
    $error = throws(fn () => $client->listProjectWallets(PROJECT), ApiException::class);
    same(429, $error->statusCode); same('rate_limit_exceeded', $error->errorCode); same(19, $error->getRetryAfter());
    same(['limit' => 120, 'remaining' => 0, 'reset' => 1800000060], $client->lastResponse()->rateLimit());
    check(!Compat::contains($error->getMessage(), TOKEN));
    check(Compat::contains($error->getApiMessage(), TOKEN)); // Explicit opt-in access, not a log message.
    for ($i = 0; $i < 3; $i++) { throws(fn () => $client->getInvoice(PROJECT, INVOICE), InvalidResponseException::class); }
    same(403, throws(fn () => $client->getInvoice(PROJECT, INVOICE), ApiException::class)->statusCode);
    same(503, throws(fn () => $client->getInvoice(PROJECT, INVOICE), ApiException::class)->statusCode);
    same(['data' => []], $client->getInvoice(PROJECT, INVOICE));
    same(20, (new Response(429, ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', 1800000020)], ''))->retryAfterSeconds(1800000000));
    same(null, (new Response(429, ['retry-after' => 'tomorrow'], ''))->retryAfterSeconds());
};

$tests['bounded opt-in retries preserve invoice request identity; other writes never retry'] = static function (): void {
    $transport = new FakeTransport([reply([], 429, ['Retry-After' => '0']), reply(['data' => ['ok' => true]])]);
    $client = new Client('https://api.example.test', TOKEN, new Options(20, 5, 1, 0), $transport);
    $result = $client->createInvoice(PROJECT, STORE, ['amount' => '10.00', 'metadata' => ['z' => 1, 'a' => 2]], 'persisted-key');
    same(true, $result['data']['ok']); same(2, count($transport->requests));
    same($transport->requests[0], $transport->requests[1], 'Retry must reuse the same immutable request.');
    foreach (['register-token-asset', 'register-custom-token', 'update-project-payment-asset', 'update-store-payment-assets', 'update-store-confirmation-policy'] as $id) {
        $fake = new FakeTransport([reply([], 503), reply()]);
        $c = new Client('https://api.example.test', TOKEN, new Options(20, 5, 2, 0), $fake);
        throws(fn () => invokeEndpoint($c, $id, ['assets' => [], 'enabled' => true]), ApiException::class);
        same(1, count($fake->requests));
    }
    $fake = new FakeTransport([reply([], 429, ['Retry-After' => '60']), reply()]);
    $c = new Client('https://api.example.test', TOKEN, new Options(20, 5, 2, 0), $fake);
    same(60, throws(fn () => $c->health(), ApiException::class)->getRetryAfter()); same(1, count($fake->requests));
    $fake = new FakeTransport([reply([], 429, ['Retry-After' => 'unparseable']), reply()]);
    $c = new Client('https://api.example.test', TOKEN, new Options(20, 5, 2, 0), $fake);
    throws(fn () => $c->health(), ApiException::class); same(1, count($fake->requests));
    $fake = new FakeTransport([new TransportException('fixture', true), reply()]);
    $c = new Client('https://api.example.test', TOKEN, new Options(20, 5, 1, 0), $fake);
    $c->health(); same(2, count($fake->requests));
    $fake = new FakeTransport([new TransportException('TLS rejection', false), reply()]);
    $c = new Client('https://api.example.test', TOKEN, new Options(20, 5, 1, 0), $fake);
    throws(fn () => $c->health(), TransportException::class); same(1, count($fake->requests));
};

$tests['lazy invoice pagination and malformed cursor protection'] = static function (): void {
    $fake = new FakeTransport([
        reply(['data' => [['public_id' => INVOICE]], 'pagination' => ['offset' => 0, 'limit' => 1, 'has_more' => true]]),
        reply(['data' => [['public_id' => ASSET]], 'pagination' => ['offset' => 1, 'limit' => 1, 'has_more' => false]]),
    ]);
    $c = new Client('https://api.example.test', TOKEN, null, $fake);
    $iterator = $c->iterateInvoices(PROJECT, ['limit' => 1, 'status' => 'settled']);
    same(0, count($fake->requests));
    same([INVOICE, ASSET], array_column(iterator_to_array($iterator), 'public_id'));
    parse_str(parse_url($fake->requests[1]->url, PHP_URL_QUERY), $query);
    same('1', $query['offset']); same('settled', $query['status']);
    $fake = new FakeTransport([reply(['data' => [], 'pagination' => ['offset' => 0, 'limit' => 50, 'has_more' => true]])]);
    $c = new Client('https://api.example.test', TOKEN, null, $fake);
    throws(fn () => iterator_to_array($c->iterateInvoices(PROJECT)), InvalidResponseException::class);
};

$tests['public checkout separation, query encoding and redacted debugging'] = static function (): void {
    $fake = new FakeTransport([reply(), reply(), reply()]);
    $c = new CheckoutClient('https://pay.example.test', null, $fake);
    $c->getInvoice(INVOICE); $c->getPreview(PROJECT, STORE);
    foreach ($fake->requests as $request) { check(!isset($request->headers()['Authorization'])); }
    same('https://pay.example.test/invoice/' . INVOICE, $c->invoiceUrl(INVOICE));
    check(Compat::contains($c->previewUrl(PROJECT, STORE, 'paid'), 'state=paid'));
    throws(fn () => $c->previewUrl(PROJECT, STORE, 'settled'), InvalidArgumentException::class);
    $merchant = new Client('https://api.example.test', TOKEN, null, $fake);
    $merchant->listInvoices(PROJECT, ['search' => 'EUR & BTC/+?✓']);
    parse_str(parse_url($fake->requests[2]->url, PHP_URL_QUERY), $query); same('EUR & BTC/+?✓', $query['search']);
    ob_start(); var_dump($merchant, $fake->requests[2]); $dump = ob_get_clean();
    check(!Compat::contains($dump, TOKEN)); check(!Compat::contains(json_encode($fake->requests[2]), TOKEN));
    throws(fn () => serialize($merchant), LogicException::class);
    throws(fn () => serialize($fake->requests[2]), LogicException::class);
};

$tests['IPN/webhook exact-byte HMAC, timestamp/replay window and malformed header rejection'] = static function (): void {
    $secret = 'fixture-signing-secret-not-an-api-token';
    $body = json_encode(['invoice_id' => INVOICE, 'sequence' => 3, 'status' => 'settled', 'amount' => '12.34', 'currency' => 'EUR'], JSON_THROW_ON_ERROR);
    $now = 1800000000;
    $header = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, $secret);
    check(Webhook::verify($body, $header, $secret, 300, $now));
    check(Webhook::verify($body, $header, $secret, 300, $now + 300));
    foreach ([$now - 301, $now + 301] as $time) { check(!Webhook::verify($body, $header, $secret, 300, $time)); }
    check(!Webhook::verify($body . ' ', $header, $secret, 300, $now));
    check(!Webhook::verify($body, $header, 'different-secret', 300, $now));
    foreach ([null, '', $header . "\n", $header . ',v1=' . str_repeat('a', 64), str_replace('t=', 't=0', $header), 't=999999999999999999999999999999,v1=' . str_repeat('a', 64)] as $bad) {
        check(!Webhook::verify($body, $bad, $secret, 300, $now));
    }
    $headers = ['Wholly-Signature' => $header, 'WHOLLY-EVENT-ID' => PROJECT, 'Wholly-Delivery-Id' => STORE];
    $notification = Webhook::parse($body, $headers, $secret, 300, $now);
    same(INVOICE, $notification->invoiceId()); same('settled', $notification->status()); same(3, $notification->sequence()); same(PROJECT, $notification->eventId);
    throws(fn () => Webhook::parse($body, $headers + ['wholly-signature' => $header], $secret, 300, $now), InvalidSignatureException::class);
    throws(fn () => Webhook::parse($body, array_replace($headers, ['WHOLLY-EVENT-ID' => 'bad-id']), $secret, 300, $now), InvalidSignatureException::class);
    throws(fn () => Webhook::parse($body, array_replace($headers, ['Wholly-Signature' => [$header]]), $secret, 300, $now), InvalidSignatureException::class);
    foreach (['[]', '{"status":"settled"}', '{"invoice_id":[],"sequence":3,"status":"settled"}', '{"invoice_id":"' . INVOICE . '","sequence":1.1,"status":"settled"}'] as $badBody) {
        $badHeader = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $badBody, $secret);
        throws(fn () => Webhook::parse($badBody, array_replace($headers, ['Wholly-Signature' => $badHeader]), $secret, 300, $now), InvalidSignatureException::class);
    }
    check(!Webhook::verify(str_repeat('x', 262145), $header, $secret, 300, $now));
};

$tests['real cURL loopback: auth, JSON, public requests, redirects, limits and timeout'] = static function (): void {
    $directory = sys_get_temp_dir() . '/wholly-php-sdk-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    check($socket !== false); $address = stream_socket_get_name($socket, false); fclose($socket);
    $log = $directory . '/requests.jsonl';
    $env = array_merge(getenv(), ['WHOLLY_FIXTURE_LOG' => $log]);
    $process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, __DIR__, $env);
    check(is_resource($process)); fclose($pipes[0]);
    try {
        for ($i = 0; $i < 100; $i++) {
            $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if ($ready) { fclose($ready); break; }
            usleep(20_000);
        }
        check($i < 100, 'Fixture server did not start.');
        $options = new Options(3, 1, 0, 60, true);
        $sharedTransport = new WhollyCrypto\Http\CurlTransport();
        $c = new Client('http://' . $address, TOKEN, $options, $sharedTransport);
        $result = $c->createInvoice(PROJECT, STORE, ['amount' => '25.00', 'metadata' => []], 'fixture-stored-key');
        same('POST', $result['request']['method']); same('Bearer ' . TOKEN, $result['request']['headers']['authorization']);
        same('fixture-stored-key', $result['request']['headers']['idempotency-key']);
        same('9999999999999999999999999999', $result['data']['expected_amount_atomic']);
        $body = json_decode($result['request']['body']); same('25.00', $body->amount); check($body->metadata instanceof stdClass);
        same(119, $c->lastResponse()->rateLimit()['remaining']);
        check(!isset($c->health()['request']['headers']['authorization']));
        $public = (new CheckoutClient('http://' . $address, $options, $sharedTransport))->getInvoice(INVOICE);
        check(!isset($public['request']['headers']['authorization']));
        same('GET', $public['request']['method']); same('', $public['request']['body']);
        same('redirect_not_followed', throws(fn () => $c->listInvoices(PROJECT, ['search' => 'redirect']), ApiException::class)->errorCode);
        check(!Compat::contains(file_get_contents($log), '/trap'), 'Redirect must not be followed.');
        throws(fn () => $c->listInvoices(PROJECT, ['search' => 'html']), InvalidResponseException::class);
        same(19, throws(fn () => $c->listInvoices(PROJECT, ['search' => 'rate-limit']), ApiException::class)->getRetryAfter());
        $limited = new Client('http://' . $address, TOKEN, new Options(3, 1, 0, 60, true, 1024));
        throws(fn () => $limited->listInvoices(PROJECT, ['search' => 'large']), TransportException::class);
        $short = new Client('http://' . $address, TOKEN, new Options(1, 1, 0, 60, true));
        check(throws(fn () => $short->listInvoices(PROJECT, ['search' => 'timeout']), TransportException::class)->retryable);
    } finally {
        proc_terminate($process); proc_close($process);
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
};

require __DIR__ . '/security-integration.php';

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo 'PASS: ' . $name . "\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, 'FAIL: ' . $name . ': ' . get_class($error) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . "\n"); }
}
echo count($tests) . ' suites; ' . $failed . " failures. No live payments or writes.\n";
exit($failed ? 1 : 0);
