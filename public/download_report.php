<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Europe/Warsaw');
require __DIR__ . '/../bin/_common.php';

function previousFullMonthKey(string $tz = 'Europe/Warsaw'): string
{
    $timezone = new DateTimeZone($tz);
    $now = new DateTimeImmutable('now', $timezone);
    $prevMonth = $now->modify('first day of this month')->modify('-1 month');
    return $prevMonth->format('Y-m');
}

function runStep(string $label, string $cmd): array
{
    $output = [];
    $exitCode = 0;

    // 2>&1 żeby złapać stderr
    exec($cmd . ' 2>&1', $output, $exitCode);

    return [
        'label' => $label,
        'command' => $cmd,
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

function failHtml(string $title, string $message, array $details = []): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 text-slate-900">';
    echo '<div class="max-w-4xl mx-auto px-6 py-10">';
    echo '<div class="bg-white border border-red-200 rounded-2xl shadow-sm p-6">';
    echo '<h1 class="text-xl font-semibold text-red-700 mb-3">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p class="text-slate-700 mb-4">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';

    if ($details !== []) {
        echo '<div class="mt-4">';
        echo '<h2 class="text-sm font-semibold text-slate-800 mb-2">Szczegóły</h2>';
        echo '<pre class="text-xs bg-slate-100 border border-slate-200 rounded-lg p-4 overflow-auto">';
        echo htmlspecialchars(implode("\n", $details), ENT_QUOTES, 'UTF-8');
        echo '</pre>';
        echo '</div>';
    }

    echo '<div class="mt-6"><a href="index.php" class="inline-flex items-center rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900">Wróć</a></div>';
    echo '</div></div></body></html>';
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: index.php', true, 302);
        exit;
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

    // Interpreter PHP do uruchamiania skryptów CLI:
    // - najpierw próbujemy config runtime.php_cli_bin
    // - potem fallback: PHP_BINARY
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
            'label' => 'Budowa raportu CSV',
            'cmd' => escapeshellarg($phpCli) . ' ' . escapeshellarg($root . '/bin/build_woo_month_report.php') . ' --month=' . escapeshellarg($month),
        ],
    ];

    $logs = [];
    foreach ($steps as $step) {
        $result = runStep($step['label'], $step['cmd']);
        $logs[] = $result;

        if ((int)$result['exit_code'] !== 0) {
            $details = [];
            foreach ($logs as $log) {
                $details[] = '=== ' . $log['label'] . ' ===';
                $details[] = '$ ' . $log['command'];
                foreach ($log['output'] as $line) {
                    $details[] = $line;
                }
                $details[] = '';
            }

            failHtml(
                'Nie udało się wygenerować raportu',
                'Jeden z kroków pipeline’u zakończył się błędem.',
                $details
            );
        }
    }

    $csvPath = rtrim($periodsDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $month
        . DIRECTORY_SEPARATOR . 'woo'
        . DIRECTORY_SEPARATOR . 'report_rows.zero_filled.csv';

    if (!file_exists($csvPath)) {
        $details = [];
        foreach ($logs as $log) {
            $details[] = '=== ' . $log['label'] . ' ===';
            $details[] = '$ ' . $log['command'];
            foreach ($log['output'] as $line) {
                $details[] = $line;
            }
            $details[] = '';
        }

        failHtml(
            'Brak pliku CSV',
            "Pipeline zakończył się bez błędu, ale nie znaleziono pliku:\n{$csvPath}",
            $details
        );
    }

    $filename = 'raport_sprzedazy_woo_' . $month . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $fh = fopen($csvPath, 'rb');
    if ($fh === false) {
        failHtml('Błąd odczytu CSV', "Nie udało się otworzyć pliku:\n{$csvPath}");
    }

    // BOM dla lepszej współpracy z Excelem (UTF-8)
    echo "\xEF\xBB\xBF";

    fpassthru($fh);
    fclose($fh);
    exit;
} catch (Throwable $e) {
    failHtml('Błąd aplikacji', $e->getMessage());
}