<?php

declare(strict_types=1);

// Zero-dependency PSR-4 autoloader for tests
spl_autoload_register(function (string $class) {
    $prefixMap = [
        'EidCloud\\HttpRecorder\\Tests\\' => __DIR__ . '/',
        'EidCloud\\HttpRecorder\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixMap as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

use EidCloud\HttpRecorder\Tests\HttpRecorderTest;

echo "====================================================\n";
echo "       EidCloud HTTP Recorder Test Suite            \n";
echo "====================================================\n\n";

$start = microtime(true);

try {
    $suite = new HttpRecorderTest();
    $suite->run();
    $duration = (microtime(true) - $start) * 1000.0;

    echo sprintf("\033[32m✔ All tests passed successfully!\033[0m\n");
    echo sprintf("Assertions verified: %d\n", $suite->getAssertionCount());
    echo sprintf("Execution time: %.2f ms\n", $duration);
    echo "====================================================\n";
    exit(0);
} catch (\Throwable $e) {
    echo sprintf("\033[31m✘ Test Failure: %s\033[0m\n", $e->getMessage());
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    echo "====================================================\n";
    exit(1);
}
