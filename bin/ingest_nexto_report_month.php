#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';
require __DIR__ . '/_nexto.php';

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
        throw new RuntimeException('Podaj ścieżkę wejściową: --input=/path/to/nexto.xlsx');
    }

    $periodsDir = (string)($paths['periods_dir'] ?? '');
    if ($periodsDir === '') {
        throw new RuntimeException('Brak paths.periods_dir w config.local.php');
    }

    $monthDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month;
    $nextoDir = $monthDir . DIRECTORY_SEPARATOR . 'nexto';
    ensureDir($nextoDir);

    fwrite(STDOUT, "Walidacja i parsowanie raportu Nexto za {$month}\n");
    fwrite(STDOUT, "Wejście: {$inputPath}\n\n");

    $result = validateAndParseNextoXlsx($inputPath, $inputOriginalName);
    if (($result['ok'] ?? false) !== true) {
        $message = (string)($result['message'] ?? 'Błędny raport Nexto.');
        $details = is_array($result['details'] ?? null) ? $result['details'] : [];
        throw new RuntimeException($message . ($details !== [] ? (' ' . implode(' | ', $details)) : ''));
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];

    $outPath = $nextoDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    writeJsonFile($outPath, [
        'snapshot_type' => 'nexto_sales_by_isbn',
        'generated_at' => gmdate('c'),
        'source' => 'nexto',
        'month' => $month,
        'input_file' => $inputPath,
        'input_original_name' => $inputOriginalName,
        'stats' => [
            'rows_total_seen' => (int)($stats['rows_total_seen'] ?? 0),
            'rows_skipped_empty' => (int)($stats['rows_skipped_empty'] ?? 0),
            'rows_skipped_group' => (int)($stats['rows_skipped_group'] ?? 0),
            'rows_skipped_summary' => (int)($stats['rows_skipped_summary'] ?? 0),
            'rows_skipped_without_isbn' => (int)($stats['rows_skipped_without_isbn'] ?? 0),
            'rows_parsed_data' => (int)($stats['rows_parsed_data'] ?? 0),
            'tables_detected' => (int)($stats['tables_detected'] ?? 0),
            'records_aggregated' => count((array)($data['records'] ?? [])),
            'units_total' => (int)($stats['units_total'] ?? 0),
            'margin_net_total_cents' => (int)($stats['margin_net_total_cents'] ?? 0),
        ],
        'records' => array_values((array)($data['records'] ?? [])),
    ]);

    $manifestPath = $nextoDir . DIRECTORY_SEPARATOR . 'manifest.json';
    writeJsonFile($manifestPath, [
        'snapshot_type' => 'nexto_manifest',
        'generated_at' => gmdate('c'),
        'month' => $month,
        'source' => 'nexto',
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
