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
