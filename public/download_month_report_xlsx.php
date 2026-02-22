<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

require __DIR__ . '/../bin/_common.php';
require __DIR__ . '/_web_common.php';

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

    $xlsxPath = monthReportXlsxPath($paths, $month);

    if (!is_file($xlsxPath)) {
        failHtml(404, 'Brak raportu XLSX', "Nie znaleziono raportu dla miesiąca {$month}.\nNajpierw kliknij „Generuj”.");
    }

    $filename = monthReportXlsxFilename($month);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)filesize($xlsxPath));
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