<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

require __DIR__ . '/../bin/_common.php';
require __DIR__ . '/../bin/_virtualo.php';
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

    if (!isset($_FILES['report_files']) || !is_array($_FILES['report_files'])) {
        jsonResponse(400, [
            'ok' => false,
            'message' => 'Nie dodano żadnych plików raportów.',
            'details' => ['Dodaj pliki do wspólnego pola uploadu.'],
        ]);
    }

    $files = $_FILES['report_files'];
    if (!isset($files['name']) || !is_array($files['name'])) {
        jsonResponse(400, [
            'ok' => false,
            'message' => 'Niepoprawny format uploadu (oczekiwano report_files[]).',
        ]);
    }

    $uploadCount = count($files['name']);
    $destDir = rtrim((string)$paths['periods_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'uploads';
    ensureDir($destDir);

    $uploadedFiles = [];
    for ($i = 0; $i < $uploadCount; $i++) {
        $errorCode = isset($files['error'][$i]) ? (int)$files['error'][$i] : UPLOAD_ERR_NO_FILE;
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

        $tmpPath = isset($files['tmp_name'][$i]) ? (string)$files['tmp_name'][$i] : '';
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            continue;
        }

        $origName = basename((string)($files['name'][$i] ?? ('uploaded_' . $i)));
        $destPath = $destDir . DIRECTORY_SEPARATOR . sprintf('%02d', $i) . '__' . $origName;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            jsonResponse(500, [
                'ok' => false,
                'message' => 'Nie udało się zapisać jednego z uploadowanych plików.',
                'details' => ['Plik: ' . $origName],
            ]);
        }

        $uploadedFiles[] = [
            'name' => $origName,
            'path' => $destPath,
            'extension' => strtolower((string)pathinfo($origName, PATHINFO_EXTENSION)),
        ];
    }

    if ($uploadedFiles === []) {
        jsonResponse(400, [
            'ok' => false,
            'message' => 'Nie dodano żadnych poprawnych plików raportów.',
        ]);
    }

    $matchedReports = [];
    foreach ($requiredReports as $reportDef) {
        $sourceId = (string)($reportDef['source_id'] ?? '');
        $label = (string)($reportDef['label'] ?? $sourceId);
        $allowedExtensions = array_map(
            static fn($v): string => strtolower((string)$v),
            is_array($reportDef['allowed_extensions'] ?? null) ? $reportDef['allowed_extensions'] : []
        );

        $candidates = array_values(array_filter(
            $uploadedFiles,
            static fn(array $f): bool => $allowedExtensions === [] || in_array($f['extension'], $allowedExtensions, true)
        ));

        if ($sourceId === 'virtualo') {
            $errors = [];
            foreach ($candidates as $candidate) {
                $check = validateAndParseVirtualoCsv((string)$candidate['path'], (string)$candidate['name']);
                if (($check['ok'] ?? false) === true) {
                    $matchedReports[$sourceId] = $candidate;
                    break;
                }

                $details = is_array($check['details'] ?? null) ? $check['details'] : [];
                $errors[] = (string)$candidate['name'] . ': ' . (string)($check['message'] ?? 'nieznany błąd')
                    . ($details !== [] ? (' (' . implode(' | ', $details) . ')') : '');
            }

            if (!isset($matchedReports[$sourceId])) {
                jsonResponse(400, [
                    'ok' => false,
                    'message' => 'Brakuje poprawnego wymaganego raportu: ' . $label . '.',
                    'details' => $errors !== [] ? $errors : ['Nie znaleziono pasujących plików o dozwolonym rozszerzeniu.'],
                ]);
            }

            continue;
        }

        jsonResponse(500, [
            'ok' => false,
            'message' => 'Nieobsługiwany wymagany raport: ' . $sourceId,
        ]);
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
                . ' --input=' . escapeshellarg((string)$matchedReports['virtualo']['path'])
                . ' --original-name=' . escapeshellarg((string)$matchedReports['virtualo']['name']),
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
