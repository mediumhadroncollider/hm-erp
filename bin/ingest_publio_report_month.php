#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';
require __DIR__ . '/_publio.php';

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
        throw new RuntimeException('Podaj ścieżkę wejściową: --input=/path/to/publio.csv');
    }

    $periodsDir = (string)($paths['periods_dir'] ?? '');
    if ($periodsDir === '') {
        throw new RuntimeException('Brak paths.periods_dir w config.local.php');
    }

    $monthDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month;
    $publioDir = $monthDir . DIRECTORY_SEPARATOR . 'publio';
    ensureDir($publioDir);

    fwrite(STDOUT, "Walidacja i parsowanie raportu Publio za {$month}\n");
    fwrite(STDOUT, "Wejście: {$inputPath}\n\n");

    $result = validateAndParsePublioCsv($inputPath, $inputOriginalName);
    if (($result['ok'] ?? false) !== true) {
        $message = (string)($result['message'] ?? 'Błędny raport Publio.');
        $details = is_array($result['details'] ?? null) ? $result['details'] : [];
        throw new RuntimeException($message . ($details !== [] ? (' ' . implode(' | ', $details)) : ''));
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];

    $outPath = $publioDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    writeJsonFile($outPath, [
        'snapshot_type' => 'publio_sales_by_isbn',
        'generated_at' => gmdate('c'),
        'source' => 'publio',
        'month' => $month,
        'input_file' => $inputPath,
        'input_original_name' => $inputOriginalName,
        'input_encoding' => (string)($data['encoding'] ?? 'UTF-8'),
        'stats' => [
            'rows_total' => (int)($data['rows_total'] ?? 0),
            'rows_data' => (int)($data['rows_data'] ?? 0),
            'rows_skipped' => (int)($data['rows_skipped'] ?? 0),
            'rows_sales' => (int)($data['rows_sales'] ?? 0),
            'records_aggregated' => count((array)($data['records'] ?? [])),
            'sum_units' => (int)($data['sum_units'] ?? 0),
            'sum_margin_net_cents' => (int)($data['sum_margin_net_cents'] ?? 0),
        ],
        'records' => array_values((array)($data['records'] ?? [])),
    ]);

    $manifestPath = $publioDir . DIRECTORY_SEPARATOR . 'manifest.json';
    writeJsonFile($manifestPath, [
        'snapshot_type' => 'publio_manifest',
        'generated_at' => gmdate('c'),
        'month' => $month,
        'source' => 'publio',
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
