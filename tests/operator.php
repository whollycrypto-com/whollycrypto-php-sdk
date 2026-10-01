<?php
declare(strict_types=1);
require dirname(__DIR__) . '/examples/operator-webhook.php';
$tests['Operator webhook receiver fails closed on tampering'] = static function (): void {
    $raw=json_encode(['event_id'=>PROJECT,'merchant_id'=>PROJECT,'event_type'=>'merchant.created']);
    $stamp=time();$secret='synthetic-operator-hook-secret';
    $signature='t='.$stamp.',v1='.hash_hmac('sha256',$stamp.'.'.$raw,$secret);
    same(PROJECT,verifiedOperatorEvent($raw,$signature,$secret,[PROJECT])['merchant_id']);
    throws(fn()=>verifiedOperatorEvent($raw.' ',$signature,$secret,[PROJECT]),RuntimeException::class);
    throws(fn()=>verifiedOperatorEvent($raw,$signature,$secret,[]),RuntimeException::class);
    throws(fn()=>verifiedOperatorEvent($raw,$signature,'wrong-secret',[PROJECT]),RuntimeException::class);
};

$tests['Operator public routes, authentication, payload and retry contract'] = static function (): void {
    $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/operator-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    same(55, count($fixture['methods']));
    $token = 'wc_operator_' . str_repeat('1',32) . '_' . str_repeat('a',64);
    foreach ($fixture['methods'] as $endpoint) {
        $transport = new FakeTransport([reply($endpoint['response'])]);
        $client = new WhollyCrypto\OperatorClient('https://api.example.test', $token, null, $transport);
        $args = array_fill(0, count($endpoint['ids']), PROJECT);
        if ($endpoint['method'] === 'POST') { $args[] = $endpoint['body']; $args[] = 'saved-operator-request-1042'; }
        check($endpoint['response'] == $client->{$endpoint['name']}(...$args), $endpoint['name'] . ': response mismatch');
        $request = $transport->requests[0];
        same($endpoint['method'], $request->method);
        same(preg_replace('/\{[^}]+\}/', PROJECT, $endpoint['public_path']), parse_url($request->url, PHP_URL_PATH));
        same('Bearer ' . $token, $request->headers()['Authorization']);
        same($endpoint['method'] === 'POST' ? 'saved-operator-request-1042' : null, $request->headers()['Idempotency-Key'] ?? null);
        if ($endpoint['method'] === 'POST') { check($endpoint['body'] == json_decode($request->body(),true), $endpoint['name'] . ': body mismatch'); }
    }
};
$tests['Operator rejects wrong key, missing retry key and unsafe IDs'] = static function (): void {
    throws(fn () => new WhollyCrypto\OperatorClient('https://api.example.test', TOKEN), InvalidArgumentException::class);
    $transport = new FakeTransport();
    $client = new WhollyCrypto\OperatorClient('https://api.example.test','wc_operator_' . str_repeat('1',32) . '_' . str_repeat('a',64),null,$transport);
    throws(fn () => $client->createMerchant([], 'short'), InvalidArgumentException::class);
    throws(fn () => $client->getMerchant('../keys'), InvalidArgumentException::class);
    throws(fn () => $client->adjustCredits(PROJECT, ['amount'=>1.2], 'saved-operator-request-1042'), InvalidArgumentException::class);
    same(0,count($transport->requests));
    throws(fn () => serialize($client), LogicException::class);
};
$tests['Operator onboarding uses no bearer credential'] = static function (): void {
    $transport = new FakeTransport([reply(['kind'=>'invitation']),reply(['password_set'=>true])]);
    $client = new WhollyCrypto\OperatorOnboardingClient('https://api.example.test',null,$transport);
    $client->checkInvitation('private-token');
    $client->acceptInvitation('private-token','private-password',true);
    foreach ($transport->requests as $request) {
        same(null,$request->headers()['Authorization'] ?? null);
        same(null,$request->headers()['Idempotency-Key'] ?? null);
    }
};
