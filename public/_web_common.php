<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

/**
 * Zwraca klucz poprzedniego pełnego miesiąca, np. "2026-01".
 */
function previousFullMonthKey(string $tz = 'Europe/Warsaw'): string
{
    $timezone = new DateTimeZone($tz);
    $now = new DateTimeImmutable('now', $timezone);
    $prevMonth = $now->modify('first day of this month')->modify('-1 month');

    return $prevMonth->format('Y-m');
}


/**
 * @return array<int,array<string,mixed>>
 */
function requiredReportsDefinitions(): array
{
    return [
        [
            'source_id' => 'virtualo',
            'field_name' => 'virtualo_report',
            'label' => 'Raport sprzedaży Virtualo',
            'allowed_extensions' => ['csv'],
            'detector' => 'validateAndParseVirtualoCsv',
            'ingest_script' => 'ingest_virtualo_report_month.php',
        ],
        [
            'source_id' => 'empik',
            'field_name' => 'empik_report',
            'label' => 'Raport sprzedaży Empik',
            'allowed_extensions' => ['csv'],
            'detector' => 'validateAndParseEmpikCsv',
            'ingest_script' => 'ingest_empik_report_month.php',
        ],
    ];
}

/**
 * Nazwa pliku raportu miesięcznego XLSX (aktualnie Woo-only).
 */
function monthReportXlsxFilename(string $month): string
{
    return 'raport_sprzedazy_' . $month . '.xlsx';
}

/**
 * Pełna ścieżka do raportu miesięcznego XLSX (aktualnie Woo-only).
 *
 * @param array<string,mixed> $paths
 */
function monthReportXlsxPath(array $paths, string $month): string
{
    $periodsDir = isset($paths['periods_dir']) ? (string)$paths['periods_dir'] : '';
    if ($periodsDir === '') {
        throw new RuntimeException("Brak paths.periods_dir w config.local.php");
    }

    return rtrim($periodsDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $month
        . DIRECTORY_SEPARATOR . 'woo'
        . DIRECTORY_SEPARATOR . monthReportXlsxFilename($month);
}

/**
 * @param array<string,mixed> $payload
 */
function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function failHtml(int $status, string $title, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-50 text-slate-900">';
    echo '<div class="max-w-3xl mx-auto px-6 py-10">';
    echo '<div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">';
    echo '<h1 class="text-lg font-semibold mb-2">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p class="text-sm text-slate-700">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';
    echo '<div class="mt-4"><a href="index.php" class="inline-flex items-center rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900">Wróć</a></div>';
    echo '</div></div></body></html>';
    exit;
}

/**
 * Uruchamia krok pipeline i zbiera wynik.
 *
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

/**
 * @param array<int,mixed> $logs
 * @return array<int,string>
 */
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
