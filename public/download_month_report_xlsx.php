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

try {
    $config = loadConfig();
    $paths = $config['paths'] ?? [];
    $woo = $config['woo'] ?? [];

    if (!is_array($paths) || !is_array($woo)) {
        throw new RuntimeException("Błąd config.local.php: sekcje 'paths' i 'woo' muszą być tablicami.");
    }

    $siteTimezone = (string)($woo['site_timezone'] ?? 'Europe/Warsaw');
    $defaultMonth = previousFullMonthKey($siteTimezone);

    $month = isset($_GET['month']) ? (string)$_GET['month'] : $defaultMonth;
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        failHtml(400, 'Nieprawidłowy parametr', 'Parametr month musi mieć format YYYY-MM.');
    }

    $periodsDir = (string)($paths['periods_dir'] ?? '');
    if ($periodsDir === '') {
        throw new RuntimeException("Brak paths.periods_dir w config.local.php");
    }

    $xlsxPath = rtrim($periodsDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $month
        . DIRECTORY_SEPARATOR . 'woo'
        . DIRECTORY_SEPARATOR . 'raport_sprzedazy_woo_' . $month . '.xlsx';

    if (!is_file($xlsxPath)) {
        failHtml(404, 'Brak raportu XLSX', "Nie znaleziono raportu dla miesiąca {$month}.\nNajpierw kliknij „Generuj”.");
    }

    $filename = 'raport_sprzedazy_woo_' . $month . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($xlsxPath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $fh = fopen($xlsxPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Nie udało się otworzyć pliku: {$xlsxPath}");
    }

    fpassthru($fh);
    fclose($fh);
    exit;
} catch (Throwable $e) {
    failHtml(500, 'Błąd aplikacji', $e->getMessage());
}