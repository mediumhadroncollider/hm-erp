<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

require __DIR__ . '/../bin/_common.php';
require __DIR__ . '/../bin/_virtualo.php';
require __DIR__ . '/../bin/_empik.php';
require __DIR__ . '/../bin/_publio.php';
require __DIR__ . '/../bin/_legimi.php';
require __DIR__ . '/../bin/_nexto.php';
require __DIR__ . '/../bin/_woblink.php';
require __DIR__ . '/../bin/_ebookpoint.php';
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
        ];
    }

    if ($uploadedFiles === []) {
        jsonResponse(400, [
            'ok' => false,
            'message' => 'Nie przesłano żadnego poprawnego pliku.',
        ]);
    }

    $classifiedReports = [];
    $classificationErrors = [];
    foreach ($requiredReports as $reportDef) {
        $fieldName = (string)($reportDef['field_name'] ?? '');
        $label = (string)($reportDef['label'] ?? $fieldName);
        $detector = (string)($reportDef['detector'] ?? '');
        $allowedExtensions = array_map(
            static fn($v): string => strtolower((string)$v),
            is_array($reportDef['allowed_extensions'] ?? null) ? $reportDef['allowed_extensions'] : []
        );

        if ($fieldName === '' || $detector === '' || !function_exists($detector)) {
            throw new RuntimeException('Niepoprawna konfiguracja requiredReportsDefinitions() dla: ' . $label);
        }

        $candidates = [];
        foreach ($uploadedFiles as $candidate) {
            $ext = (string)($candidate['extension'] ?? '');
            if ($allowedExtensions !== [] && !in_array($ext, $allowedExtensions, true)) {
                continue;
            }

            $detectionResult = $detector((string)$candidate['tmp_path'], (string)$candidate['orig_name']);
            if (($detectionResult['ok'] ?? false) === true) {
                $candidates[] = [
                    'file' => $candidate,
                    'result' => $detectionResult,
                ];
            } else {
                $classificationErrors[] = [
                    'label' => $label,
                    'file' => (string)$candidate['orig_name'],
                    'message' => (string)($detectionResult['message'] ?? 'Nierozpoznany CSV.'),
                    'details' => is_array($detectionResult['details'] ?? null) ? $detectionResult['details'] : [],
                ];
            }
        }

        $isRequiredForGeneration = (bool)($reportDef['is_required_for_generation'] ?? true);

        if ($candidates === []) {
            if ($isRequiredForGeneration) {
                $details = ['Nie znaleziono poprawnego raportu: ' . $label . '.'];
                foreach ($classificationErrors as $error) {
                    if (($error['label'] ?? '') !== $label) {
                        continue;
                    }
                    $line = 'Plik ' . $error['file'] . ': ' . $error['message'];
                    if (($error['details'] ?? []) !== []) {
                        $line .= ' (' . implode(' | ', $error['details']) . ')';
                    }
                    $details[] = $line;
                }

                jsonResponse(400, [
                    'ok' => false,
                    'message' => 'Brakuje wymaganego raportu: ' . $label . '.',
                    'details' => $details,
                ]);
            }

            continue;
        }

        if (count($candidates) > 1) {
            $matchedNames = array_map(
                static fn(array $c): string => (string)($c['file']['orig_name'] ?? ''),
                $candidates
            );

            jsonResponse(400, [
                'ok' => false,
                'message' => 'Wykryto więcej niż jeden pasujący raport: ' . $label . '.',
                'details' => [
                    'Usuń duplikaty i zostaw tylko jeden plik dla źródła ' . $label . '.',
                    'Pasujące pliki: ' . implode(', ', $matchedNames),
                ],
            ]);
        }

        $classifiedReports[$fieldName] = $candidates[0]['file'];
    }

    $destDir = rtrim((string)$paths['periods_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'uploads';
    ensureDir($destDir);

    $uploadedReportPaths = [];
    foreach ($requiredReports as $reportDef) {
        $fieldName = (string)($reportDef['field_name'] ?? '');
        $label = (string)($reportDef['label'] ?? $fieldName);
        $matched = $classifiedReports[$fieldName] ?? null;
        if (!is_array($matched)) {
            continue;
        }

        $origName = (string)$matched['orig_name'];
        $tmpPath = (string)$matched['tmp_path'];

        $destPath = $destDir . DIRECTORY_SEPARATOR . $fieldName . '__' . $origName;
        if (!move_uploaded_file($tmpPath, $destPath)) {
            jsonResponse(500, [
                'ok' => false,
                'message' => 'Nie udało się zapisać uploadu: ' . $label . '.',
            ]);
        }

        $uploadedReportPaths[$fieldName] = [
            'path' => $destPath,
            'name' => $origName,
            'label' => $label,
            'ingest_script' => (string)($reportDef['ingest_script'] ?? ''),
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
            'label' => 'Walidacja i parsowanie raportu Empik',
            'cmd' => escapeshellarg($phpCli)
                . ' ' . escapeshellarg($root . '/bin/ingest_empik_report_month.php')
                . ' --month=' . escapeshellarg($month)
                . ' --input=' . escapeshellarg((string)$uploadedReportPaths['empik_report']['path'])
                . ' --original-name=' . escapeshellarg((string)$uploadedReportPaths['empik_report']['name']),
        ],
        [
            'label' => 'Walidacja i parsowanie raportu Publio',
            'cmd' => escapeshellarg($phpCli)
                . ' ' . escapeshellarg($root . '/bin/ingest_publio_report_month.php')
                . ' --month=' . escapeshellarg($month)
                . ' --input=' . escapeshellarg((string)$uploadedReportPaths['publio_report']['path'])
                . ' --original-name=' . escapeshellarg((string)$uploadedReportPaths['publio_report']['name']),
        ],
        [
            'label' => 'Walidacja i parsowanie raportu Legimi',
            'cmd' => escapeshellarg($phpCli)
                . ' ' . escapeshellarg($root . '/bin/ingest_legimi_report_month.php')
                . ' --month=' . escapeshellarg($month)
                . ' --input=' . escapeshellarg((string)$uploadedReportPaths['legimi_report']['path'])
                . ' --original-name=' . escapeshellarg((string)$uploadedReportPaths['legimi_report']['name']),
        ],
        [
            'label' => 'Walidacja i parsowanie raportu Nexto',
            'cmd' => escapeshellarg($phpCli)
                . ' ' . escapeshellarg($root . '/bin/ingest_nexto_report_month.php')
                . ' --month=' . escapeshellarg($month)
                . ' --input=' . escapeshellarg((string)$uploadedReportPaths['nexto_report']['path'])
                . ' --original-name=' . escapeshellarg((string)$uploadedReportPaths['nexto_report']['name']),
        ],
        [
            'label' => 'Walidacja i parsowanie raportu Woblink',
            'cmd' => escapeshellarg($phpCli)
                . ' ' . escapeshellarg($root . '/bin/ingest_woblink_report_month.php')
                . ' --month=' . escapeshellarg($month)
                . ' --input=' . escapeshellarg((string)$uploadedReportPaths['woblink_report']['path'])
                . ' --original-name=' . escapeshellarg((string)$uploadedReportPaths['woblink_report']['name']),
        ],
        [
            'label' => 'Walidacja i parsowanie raportu ebookpoint',
            'cmd' => escapeshellarg($phpCli)
                . ' ' . escapeshellarg($root . '/bin/ingest_ebookpoint_report_month.php')
                . ' --month=' . escapeshellarg($month)
                . ' --input=' . escapeshellarg((string)$uploadedReportPaths['ebookpoint_report']['path'])
                . ' --original-name=' . escapeshellarg((string)$uploadedReportPaths['ebookpoint_report']['name']),
        ],
        [
            'label' => 'Walidacja i parsowanie raportu nasbi',
            'cmd' => escapeshellarg($phpCli)
                . ' ' . escapeshellarg($root . '/bin/ingest_nasbi_report_month.php')
                . ' --month=' . escapeshellarg($month)
                . ' --input=' . escapeshellarg((string)$uploadedReportPaths['nasbi_report']['path'])
                . ' --original-name=' . escapeshellarg((string)$uploadedReportPaths['nasbi_report']['name']),
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
