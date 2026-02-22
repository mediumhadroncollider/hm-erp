<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

require __DIR__ . '/../bin/_common.php';
require __DIR__ . '/_web_common.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonResponse(405, [
            'ok' => false,
            'message' => 'Metoda niedozwolona. Użyj POST.',
        ]);
    }

    if (!function_exists('exec')) {
        jsonResponse(500, [
            'ok' => false,
            'message' => 'Funkcja exec() jest niedostępna w PHP WWW (sprawdź disable_functions w php.ini).',
        ]);
    }

    set_time_limit(0);

    $config = loadConfig();
    $paths = $config['paths'] ?? [];
    $woo = $config['woo'] ?? [];

    if (!is_array($paths) || !is_array($woo)) {
        throw new RuntimeException("Błąd config.local.php: sekcje 'paths' i 'woo' muszą być tablicami.");
    }

    $siteTimezone = (string)($woo['site_timezone'] ?? 'Europe/Warsaw');
    $month = previousFullMonthKey($siteTimezone);

    $xlsxPath = monthReportXlsxPath($paths, $month);
    $filename = monthReportXlsxFilename($month);

    $phpCli = null;
    if (isset($config['runtime']) && is_array($config['runtime']) && isset($config['runtime']['php_cli_bin'])) {
        $phpCli = (string)$config['runtime']['php_cli_bin'];
    }
    if ($phpCli === null || trim($phpCli) === '') {
        $phpCli = PHP_BINARY;
    }

    $root = dirname(__DIR__);

    $steps = [
        [
            'label' => 'Pobranie katalogu Woo',
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/ingest_woo_catalog.php'),
        ],
        [
            'label' => 'Pobranie zamówień Woo za miesiąc',
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/ingest_woo_orders_month.php') . ' --month=' . escapeshellarg($month),
        ],
        [
            'label' => 'Budowa danych raportu (JSON, zero-fill)',
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/build_month_rows_from_woo.php') . ' --month=' . escapeshellarg($month),
        ],
        [
            'label' => 'Budowa pliku XLSX (A:I, bez formuł)',
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/export_month_report_xlsx.php') . ' --month=' . escapeshellarg($month),
        ],
    ];

    $logs = [];
    foreach ($steps as $step) {
        $result = runStep($step['label'], $step['cmd']);
        $logs[] = $result;

        if ((int)$result['exit_code'] !== 0) {
            jsonResponse(500, [
                'ok' => false,
                'message' => 'Jeden z kroków pipeline’u zakończył się błędem.',
                'details' => flattenLogsForUi($logs),
            ]);
        }
    }

    if (!is_file($xlsxPath) || filesize($xlsxPath) <= 0) {
        jsonResponse(500, [
            'ok' => false,
            'message' => 'Pipeline zakończył się bez błędu, ale plik XLSX nie został znaleziony.',
            'details' => array_merge(
                ["Oczekiwany plik: {$xlsxPath}", ''],
                flattenLogsForUi($logs)
            ),
        ]);
    }

    jsonResponse(200, [
        'ok' => true,
        'cached' => false,
        'month' => $month,
        'filename' => $filename,
        'download_url' => 'download_month_report_xlsx.php?month=' . rawurlencode($month),
        'message' => 'Raport XLSX wygenerowany pomyślnie.',
        'details' => flattenLogsForUi($logs),
    ]);

} catch (Throwable $e) {
    jsonResponse(500, [
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}