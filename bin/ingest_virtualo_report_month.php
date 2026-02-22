#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';
require __DIR__ . '/_virtualo.php';

try {
    $config = loadConfig();
    $paths = $config['paths'] ?? [];
    if (!is_array($paths)) {
        throw new RuntimeException("Błąd config.local.php: sekcja 'paths' musi być tablicą.");
    }

    $month = parseRequiredMonthArg($argv);

    $inputPath = null;
    $inputOriginalName = null;
    foreach ($argv as $i => $arg) {
        if (strpos($arg, '--input=') === 0) {
            $inputPath = substr($arg, 8);
        } elseif ($arg === '--input' && isset($argv[$i + 1])) {
            $inputPath = $argv[$i + 1];
        }

        if (strpos($arg, '--original-name=') === 0) {
            $inputOriginalName = substr($arg, 16);
        } elseif ($arg === '--original-name' && isset($argv[$i + 1])) {
            $inputOriginalName = $argv[$i + 1];
        }
    }

    if (!is_string($inputPath) || trim($inputPath) === '') {
        throw new RuntimeException('Podaj ścieżkę wejściową: --input=/path/to/virtualo.csv');
    }

    $periodsDir = (string)($paths['periods_dir'] ?? '');
    if ($periodsDir === '') {
        throw new RuntimeException('Brak paths.periods_dir w config.local.php');
    }

    $monthDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'woo';
    ensureDir($monthDir);

    fwrite(STDOUT, "Walidacja i parsowanie raportu Virtualo za {$month}\n");
    fwrite(STDOUT, "Wejście: {$inputPath}\n\n");

    $result = validateAndParseVirtualoCsv($inputPath, $inputOriginalName);
    if (($result['ok'] ?? false) !== true) {
        $message = (string)($result['message'] ?? 'Błędny raport Virtualo.');
        $details = is_array($result['details'] ?? null) ? $result['details'] : [];
        throw new RuntimeException($message . ($details !== [] ? (' ' . implode(' | ', $details)) : ''));
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];

    $outPath = $monthDir . DIRECTORY_SEPARATOR . 'virtualo_sales_by_isbn.json';
    writeJsonFile($outPath, [
        'snapshot_type' => 'virtualo_sales_by_isbn',
        'generated_at' => gmdate('c'),
        'source' => 'virtualo',
        'month' => $month,
        'input_file' => $inputPath,
        'input_original_name' => $inputOriginalName,
        'stats' => [
            'rows_total' => (int)($data['rows_total'] ?? 0),
            'rows_accepted' => (int)($data['rows_accepted'] ?? 0),
            'rows_skipped_footer' => (int)($data['rows_skipped_footer'] ?? 0),
            'unique_isbn' => count((array)($data['records'] ?? [])),
        ],
        'records' => array_values((array)($data['records'] ?? [])),
    ]);

    fwrite(STDOUT, "✅ Gotowe\n");
    fwrite(STDOUT, "Wyjście: {$outPath}\n");

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}
