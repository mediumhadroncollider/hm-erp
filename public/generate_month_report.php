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

    $requiredReports = requiredReportsDefinitions();

    $rawUploads = $_FILES['report_files'] ?? null;
    if (!is_array($rawUploads) || !isset($rawUploads['name']) || !is_array($rawUploads['name'])) {
        jsonResponse(400, [
            'ok' => false,
            'message' => 'Brakuje plików uploadu. Dodaj raporty w polu „Raporty źródłowe”.',
        ]);
    }

    $uploadedFiles = [];
    $names = $rawUploads['name'];
    $tmpNames = is_array($rawUploads['tmp_name'] ?? null) ? $rawUploads['tmp_name'] : [];
    $errors = is_array($rawUploads['error'] ?? null) ? $rawUploads['error'] : [];

    foreach ($names as $idx => $name) {
        $errorCode = isset($errors[$idx]) ? (int)$errors[$idx] : UPLOAD_ERR_NO_FILE;
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            jsonResponse(400, [
                'ok' => false,
                'message' => 'Błąd uploadu jednego z plików.',
                'details' => ['Kod błędu uploadu: ' . $errorCode],
            ]);
        }

        $tmpPath = isset($tmpNames[$idx]) ? (string)$tmpNames[$idx] : '';
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            jsonResponse(400, [
                'ok' => false,
                'message' => 'Nie udało się odczytać jednego z uploadowanych plików.',
            ]);
        }

        $origName = basename((string)$name);
        $fileExt = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));

        $uploadedFiles[] = [
            'index' => $idx,
            'tmp_path' => $tmpPath,
            'orig_name' => $origName,
            'extension' => $fileExt,
            'used' => false,
        ];
    }

    if ($uploadedFiles === []) {
        jsonResponse(400, [
            'ok' => false,
            'message' => 'Nie przesłano żadnego poprawnego pliku.',
        ]);
    }

    $destDir = rtrim((string)$paths['periods_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'uploads';
    ensureDir($destDir);

    $uploadedReportPaths = [];
    foreach ($requiredReports as $reportDef) {
        $fieldName = (string)($reportDef['field_name'] ?? '');
        $label = (string)($reportDef['label'] ?? $fieldName);
        $allowedExtensions = array_map(
            static fn($v): string => strtolower((string)$v),
            is_array($reportDef['allowed_extensions'] ?? null) ? $reportDef['allowed_extensions'] : []
        );

        $matchIndex = null;
        foreach ($uploadedFiles as $i => $candidate) {
            if (($candidate['used'] ?? false) === true) {
                continue;
            }

            $ext = (string)($candidate['extension'] ?? '');
            if ($allowedExtensions === [] || in_array($ext, $allowedExtensions, true)) {
                $matchIndex = $i;
                break;
            }
        }

        if ($matchIndex === null) {
            jsonResponse(400, [
                'ok' => false,
                'message' => 'Brakuje wymaganego pliku: ' . $label . '.',
                'details' => ['Dozwolone rozszerzenia: ' . implode(', ', $allowedExtensions)],
            ]);
        }

        $matched = $uploadedFiles[$matchIndex];
        $origName = (string)$matched['orig_name'];
        $tmpPath = (string)$matched['tmp_path'];

        $destPath = $destDir . DIRECTORY_SEPARATOR . $fieldName . '__' . $origName;
        if (!move_uploaded_file($tmpPath, $destPath)) {
            jsonResponse(500, [
                'ok' => false,
                'message' => 'Nie udało się zapisać uploadu: ' . $label . '.',
            ]);
        }

        $uploadedFiles[$matchIndex]['used'] = true;

        $uploadedReportPaths[$fieldName] = [
            'path' => $destPath,
            'name' => $origName,
            'label' => $label,
        ];
    }

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
            'label' => 'Walidacja i parsowanie raportu Virtualo',
            'cmd' => escapeshellarg($phpCli)
                . ' ' . escapeshellarg($root . '/bin/ingest_virtualo_report_month.php')
                . ' --month=' . escapeshellarg($month)
                . ' --input=' . escapeshellarg((string)$uploadedReportPaths['virtualo_report']['path'])
                . ' --original-name=' . escapeshellarg((string)$uploadedReportPaths['virtualo_report']['name']),
        ],
        [
            'label' => 'Budowa pliku XLSX',
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