#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';

try {
    $month = parseRequiredMonthArg($argv);

    $php = PHP_BINARY; // aktualny interpreter (np. php8.4)
    $steps = [
        "fetch_woo_catalog.php" => "{$php} " . escapeshellarg(__DIR__ . '/fetch_woo_catalog.php'),
        "fetch_woo_orders_month.php" => "{$php} " . escapeshellarg(__DIR__ . '/fetch_woo_orders_month.php') . ' --month=' . escapeshellarg($month),
        "build_woo_month_report.php" => "{$php} " . escapeshellarg(__DIR__ . '/build_woo_month_report.php') . ' --month=' . escapeshellarg($month),
    ];

    fwrite(STDOUT, "Uruchamianie v0.0.1 pipeline dla {$month}\n");
    fwrite(STDOUT, "PHP: {$php}\n\n");

    foreach ($steps as $label => $cmd) {
        fwrite(STDOUT, "=== {$label} ===\n");
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException("Krok {$label} zakończył się kodem {$exitCode}");
        }
        fwrite(STDOUT, "\n");
    }

    fwrite(STDOUT, "✅ v0.0.1 gotowe dla {$month}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}