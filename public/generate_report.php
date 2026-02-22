<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

require __DIR__ . '/../bin/_common.php';

function previousFullMonthKey(string $tz = 'Europe/Warsaw'): string
{
    $timezone = new DateTimeZone($tz);
    $now = new DateTimeImmutable('now', $timezone);
    $prevMonth = $now->modify('first day of this month')->modify('-1 month');
    return $prevMonth->format('Y-m');
}

/**
 * @return array{label:string,command:string,exit_code:int,output:array<int,string>}
 */
function runStep(string $label, string $cmd): array
{
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    return [
        'label' => $label,
        'command' => $cmd,
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function flattenLogsForUi(array $logs): array
{
    $lines = [];
    foreach ($logs as $log) {
        if (!is_array($log)) {
            continue;
        }
        $lines[] = '=== ' . (string)($log['label'] ?? '') . ' ===';
        $lines[] = '$ ' . (string)($log['command'] ?? '');
        $output = $log['output'] ?? [];
        if (is_array($output)) {
            foreach ($output as $line) {
                $lines[] = (string)$line;
            }
        }
        $lines[] = '';
    }
    return $lines;
}

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

    $periodsDir = (string)($paths['periods_dir'] ?? '');
    if ($periodsDir === '') {
        throw new RuntimeException("Brak paths.periods_dir w config.local.php");
    }

    $xlsxPath = rtrim($periodsDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $month
        . DIRECTORY_SEPARATOR . 'woo'
        . DIRECTORY_SEPARATOR . 'raport_sprzedazy_woo_' . $month . '.xlsx';

    // CACHE: jeśli plik już istnieje, nie przeliczamy
    if (is_file($xlsxPath) && filesize($xlsxPath) > 0) {
        jsonResponse(200, [
            'ok' => true,
            'cached' => true,
            'month' => $month,
            'filename' => 'raport_sprzedazy_woo_' . $month . '.xlsx',
            'download_url' => 'download_xlsx.php?month=' . rawurlencode($month),
            'message' => 'Użyto istniejącego raportu XLSX.',
        ]);
    }

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
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/fetch_woo_catalog.php'),
        ],
        [
            'label' => 'Pobranie zamówień Woo za miesiąc',
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/fetch_woo_orders_month.php') . ' --month=' . escapeshellarg($month),
        ],
        [
            'label' => 'Budowa danych raportu (JSON, zero-fill)',
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/build_woo_month_report.php') . ' --month=' . escapeshellarg($month),
        ],
        [
            'label' => 'Budowa pliku XLSX (A:I, bez formuł)',
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/build_woo_month_xlsx.php') . ' --month=' . escapeshellarg($month),
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
        'filename' => 'raport_sprzedazy_woo_' . $month . '.xlsx',
        'download_url' => 'download_xlsx.php?month=' . rawurlencode($month),
        'message' => 'Raport XLSX wygenerowany pomyślnie.',
        'details' => flattenLogsForUi($logs),
    ]);

} catch (Throwable $e) {
    jsonResponse(500, [
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}