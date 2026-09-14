<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$count = 0;
$files = [$root . '/autoload.php'];
foreach (['src', 'tests', 'examples'] as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach ($files as $path) {
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, $output);
        exit(1);
    }
    $count++;
}
echo "PASS: $count PHP files parsed.\n";

// Documentation examples must parse on the minimum runtime too.
$examples = 0;
foreach (['README.md', 'docs/payment-methods.md'] as $document) {
    preg_match_all('/```php\R(.*?)```/s', file_get_contents($root . '/' . $document), $matches);
    foreach ($matches[1] as $index => $snippet) {
        $code = strpos(ltrim($snippet), '<?php') === 0 ? $snippet : "<?php\n" . $snippet;
        $process = proc_open([PHP_BINARY, '-l'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            fwrite(STDERR, $document . ' example ' . ($index + 1) . ': ' . $output);
            exit(1);
        }
        $examples++;
    }
}
echo "PASS: $examples documented PHP examples parsed.\n";
