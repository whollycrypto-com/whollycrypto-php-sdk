<?php

declare(strict_types=1);

// Disposable loopback-only HTTP fixture. No live merchant API is contacted.
if (in_array(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), ['/callbacks', '/callbacks-fail'], true)) {
    if (str_starts_with($_SERVER['REQUEST_URI'], '/callbacks-fail')) {
        putenv('WHOLLY_CALLBACK_DB=' . dirname(getenv('WHOLLY_CALLBACK_DB')));
    }
    require dirname(__DIR__) . '/examples/webhook.php';
    return;
}
$body = file_get_contents('php://input');
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
file_put_contents(getenv('WHOLLY_FIXTURE_LOG'), json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'], 'headers' => $headers, 'body' => $body], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$mode = $_GET['search'] ?? '';
header('Content-Type: application/json');
header('X-RateLimit-Limit: 120');
header('X-RateLimit-Remaining: 119');
header('X-RateLimit-Reset: 1800000060');
if ($mode === 'redirect') {
    header('Location: /trap', true, 302);
    echo '{}';
} elseif ($mode === 'html') {
    header('Content-Type: text/html');
    echo '<html>Not an API</html>';
} elseif ($mode === 'large') {
    echo json_encode(['data' => str_repeat('x', 4096)]);
} elseif ($mode === 'timeout') {
    sleep(2);
    echo '{}';
} elseif ($mode === 'rate-limit') {
    http_response_code(429);
    header('Retry-After: 19');
    echo '{"error":{"code":"rate_limit_exceeded","message":"Wait for the next window."}}';
} else {
    echo json_encode(['data' => ['expected_amount_atomic' => '9999999999999999999999999999'], 'request' => ['method' => $_SERVER['REQUEST_METHOD'], 'headers' => $headers, 'body' => $body, 'query' => $_GET]], JSON_THROW_ON_ERROR);
}
