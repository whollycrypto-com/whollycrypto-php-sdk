<?php

declare(strict_types=1);

// Run in a fresh process, inside an application namespace, with fixture data only.
namespace WhollyCryptoSdkTests\Storefront;

set_error_handler(static function (int $number, string $message): void {
    throw new \RuntimeException($message);
});

$root = $argv[1];
$mode = $argv[2];
if ($mode === 'isolated' && is_dir($root . '/vendor')) {
    throw new \RuntimeException('The isolated fixture must not contain vendor/.');
}
if ($mode === 'composer-first') {
    require_once $root . '/vendor/autoload.php';
    new \WhollyCrypto\Client('https://api.example.test', 'wc_fixture_not_a_real_credential');
}

$before = count(spl_autoload_functions() ?: []);
require_once $root . '/autoload.php';
require_once $root . '/autoload.php';
$loaders = spl_autoload_functions();
if (count($loaders) !== $before + 1) {
    throw new \RuntimeException('require_once must register exactly one loader.');
}
$loader = end($loaders);
$client = new \WhollyCrypto\Client('https://api.example.test', 'wc_fixture_not_a_real_credential');
if ($mode === 'manual-first') {
    require_once $root . '/vendor/autoload.php';
    new \WhollyCrypto\Client('https://api.example.test', 'wc_fixture_not_a_real_credential');
}
$checkout = new \WhollyCrypto\CheckoutClient('https://pay.example.test');
if ($client->lastResponse() !== null || strpos($checkout->invoiceUrl('33333333-3333-4333-8333-333333333333'), 'https://pay.example.test/') !== 0) {
    throw new \RuntimeException('Direct client construction failed.');
}

foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $relative = substr($file->getPathname(), strlen($root . '/src/'), -4);
    $name = 'WhollyCrypto\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
        throw new \RuntimeException('Could not load SDK type: ' . $name);
    }
}

// Unknown classes and invalid path segments must be ignored, without warnings.
foreach (['OtherVendor\\Client', 'WhollyCryptoOther\\Client', 'WhollyCrypto\\Missing',
    'WhollyCrypto\\', 'WhollyCrypto\\..\\autoload', 'WhollyCrypto\\../autoload',
    'WhollyCrypto\\Http\\..\\Options', "WhollyCrypto\\Options\0", "WhollyCrypto\\Options\n"] as $name) {
    $loader($name);
}
if (class_exists('WhollyCrypto\\Missing')) {
    throw new \RuntimeException('Unknown classes must not be defined.');
}
echo 'PASS: ' . $mode . " autoloading, fully qualified clients and every SDK type.\n";
