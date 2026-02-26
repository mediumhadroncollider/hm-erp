#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';
require __DIR__ . '/_azymut.php';

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
        throw new RuntimeException('Podaj ścieżkę wejściową: --input=/path/to/azymut.csv');
    }

    $periodsDir = (string)($paths['periods_dir'] ?? '');
    if ($periodsDir === '') {
        throw new RuntimeException('Brak paths.periods_dir w config.local.php');
    }

    $monthDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month;
    $azymutDir = $monthDir . DIRECTORY_SEPARATOR . 'azymut';
    ensureDir($azymutDir);

    fwrite(STDOUT, "Walidacja i parsowanie raportu Azymut za {$month}\n");
    fwrite(STDOUT, "Wejście: {$inputPath}\n\n");

    $result = validateAndParseAzymutCsv($inputPath, $inputOriginalName);
    if (($result['ok'] ?? false) !== true) {
        $message = (string)($result['message'] ?? 'Błędny raport Azymut.');
        $details = is_array($result['details'] ?? null) ? $result['details'] : [];
        throw new RuntimeException($message . ($details !== [] ? (' ' . implode(' | ', $details)) : ''));
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];

    $outPath = $azymutDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    writeJsonFile($outPath, [
        'snapshot_type' => 'azymut_sales_by_isbn',
        'generated_at' => gmdate('c'),
        'source' => 'azymut',
        'month' => $month,
        'input_file' => $inputPath,
        'input_original_name' => $inputOriginalName,
        'input_encoding' => (string)($data['encoding'] ?? 'UTF-8'),
        'stats' => [
            'rows_total' => (int)($data['rows_total'] ?? 0),
            'rows_data' => (int)($data['rows_data'] ?? 0),
            'rows_skipped' => (int)($data['rows_skipped'] ?? 0),
            'rows_with_isbn' => (int)($data['rows_with_isbn'] ?? 0),
            'records_aggregated' => count((array)($data['records'] ?? [])),
            'sum_units' => (int)($data['sum_units'] ?? 0),
            'sum_margin_net_cents' => (int)($data['sum_margin_net_cents'] ?? 0),
        ],
        'records' => array_values((array)($data['records'] ?? [])),
    ]);

    $manifestPath = $azymutDir . DIRECTORY_SEPARATOR . 'manifest.json';
    writeJsonFile($manifestPath, [
        'snapshot_type' => 'azymut_manifest',
        'generated_at' => gmdate('c'),
        'month' => $month,
        'source' => 'azymut',
        'input_file' => $inputPath,
        'input_original_name' => $inputOriginalName,
        'output_sales_by_isbn' => $outPath,
    ]);

    fwrite(STDOUT, "✅ Gotowe\n");
    fwrite(STDOUT, "Wyjście: {$outPath}\n");

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}
