<?php

declare(strict_types=1);

use WhollyCrypto\Client;
use WhollyCrypto\Options;
use WhollyCrypto\Exception\TransportException;
use WhollyCrypto\Internal\Compat;

$tests['HTTPS rejects an untrusted certificate without any insecure fallback'] = static function (): void {
    $directory = sys_get_temp_dir() . '/wholly-php-tls-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $process = null;
    try {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_pkey_export_to_file($key, $directory . '/key.pem');
        openssl_x509_export_to_file($certificate, $directory . '/cert.pem');
        chmod($directory . '/key.pem', 0600);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        check($socket !== false); $address = stream_socket_get_name($socket, false); fclose($socket);
        $process = proc_open(['openssl', 's_server', '-accept', $address, '-cert', $directory . '/cert.pem', '-key', $directory . '/key.pem', '-www', '-quiet'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes);
        check(is_resource($process)); fclose($pipes[0]);
        for ($i = 0; $i < 100; $i++) {
            $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if ($ready) { fclose($ready); break; }
            usleep(20_000);
        }
        check($i < 100);
        $client = new Client('https://' . $address, TOKEN, new Options(3, 1, 0, 60, true));
        $error = throws(fn () => $client->getInvoice(PROJECT, INVOICE), TransportException::class);
        check(Compat::contains($error->getMessage(), 'cURL 60'), 'TLS certificate must be verified, even with the localhost test option.');
        check(!$error->retryable);
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
};

$tests['durable receiver example verifies signatures, deduplicates and does not acknowledge failed storage'] = static function (): void {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Development tests need PDO SQLite for the receiver example.');
    }
    $directory = sys_get_temp_dir() . '/wholly-php-callback-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    check($socket !== false); $address = stream_socket_get_name($socket, false); fclose($socket);
    $secret = 'only-an-isolated-fixture-secret';
    $database = $directory . '/queue.sqlite';
    $env = array_merge(getenv(), ['WHOLLY_SIGNING_SECRET' => $secret, 'WHOLLY_CALLBACK_DB' => $database, 'WHOLLY_CALLBACK_PROJECT_ID' => PROJECT]);
    $process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, __DIR__, $env);
    check(is_resource($process)); fclose($pipes[0]);
    try {
        for ($i = 0; $i < 100; $i++) {
            $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if ($ready) { fclose($ready); break; }
            usleep(20_000);
        }
        check($i < 100);
        $raw = json_encode(['invoice_id' => INVOICE, 'sequence' => 3, 'status' => 'settled', 'amount' => '25.00', 'currency' => 'EUR'], JSON_THROW_ON_ERROR);
        $now = time(); $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $raw, $secret);
        $post = static function (string $path, string $body, string $sig, string $eventId = PROJECT) use ($address): int {
            $handle = curl_init('http://' . $address . $path);
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 5, CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Wholly-Signature: ' . $sig, 'Wholly-Event-Id: ' . $eventId, 'Wholly-Delivery-Id: ' . STORE]]);
            $result = curl_exec($handle); check($result !== false); same('', $result, 'Receiver must not print payloads or internal errors.');
            return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        };
        same(400, $post('/callbacks', $raw, 'invalid'));
        check(!file_exists($database), 'Do not create storage before authentication.');
        same(204, $post('/callbacks', $raw, $signature));
        same(204, $post('/callbacks', $raw, $signature));
        same(204, $post('/callbacks', $raw, $signature, ASSET)); // Unsigned ID cannot bypass invoice+sequence deduplication.
        $db = new PDO('sqlite:' . $database);
        same(1, (int) $db->query('SELECT COUNT(*) FROM wholly_callback_inbox')->fetchColumn());
        same($raw, $db->query('SELECT payload FROM wholly_callback_inbox')->fetchColumn());
        $other = str_replace(INVOICE, ASSET, $raw);
        $otherSignature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $other, $secret);
        same(204, $post('/callbacks', $other, $otherSignature)); // Same unsigned event ID must not suppress a different signed invoice.
        same(2, (int) $db->query('SELECT COUNT(*) FROM wholly_callback_inbox')->fetchColumn());
        $conflict = $raw . ' ';
        same(409, $post('/callbacks', $conflict, 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $conflict, $secret)));
        same(0600, fileperms($database) & 0777);
        same(400, $post('/callbacks', $raw . ' ', $signature));
        same(503, $post('/callbacks-fail', $raw, $signature));
        unset($db);
    } finally {
        proc_terminate($process); proc_close($process);
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
};
