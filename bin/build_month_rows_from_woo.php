#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';

try {
    $config = loadConfig();
    $paths = $config['paths'] ?? [];
    if (!is_array($paths)) {
        throw new RuntimeException("Błąd config.local.php: sekcja 'paths' musi być tablicą.");
    }

    $month = parseRequiredMonthArg($argv);

    $catalogSnapshotsDir = (string)($paths['catalog_snapshots_dir'] ?? '');
    $periodsDir = (string)($paths['periods_dir'] ?? '');

    if ($catalogSnapshotsDir === '' || $periodsDir === '') {
        throw new RuntimeException("Brak paths.catalog_snapshots_dir lub paths.periods_dir w config.local.php");
    }

    $catalogPath = latestFileMatching($catalogSnapshotsDir, '.catalog.normalized.json');
    if ($catalogPath === null) {
        throw new RuntimeException("Nie znaleziono katalogu znormalizowanego w: {$catalogSnapshotsDir}");
    }

    $monthDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'woo';
    ensureDir($monthDir);

    $orderLinesPath = $monthDir . DIRECTORY_SEPARATOR . 'order_lines.normalized.json';
    if (!file_exists($orderLinesPath)) {
        throw new RuntimeException("Brak pliku {$orderLinesPath}. Najpierw uruchom ingest_woo_orders_month.php");
    }

    fwrite(STDOUT, "Budowanie raportu Woo za {$month}\n");
    fwrite(STDOUT, "Katalog: {$catalogPath}\n");
    fwrite(STDOUT, "Line items: {$orderLinesPath}\n\n");

    $catalogPayload = readJsonFile($catalogPath);
    $orderLinesPayload = readJsonFile($orderLinesPath);

    $catalogRecords = $catalogPayload['records'] ?? null;
    $orderLines = $orderLinesPayload['records'] ?? null;

    if (!is_array($catalogRecords)) {
        throw new RuntimeException("Katalog normalized JSON nie ma pola records[]");
    }
    if (!is_array($orderLines)) {
        throw new RuntimeException("Order lines normalized JSON nie ma pola records[]");
    }

    // Indeksy katalogu
    $catalogByIsbnNorm = [];
    $catalogByProductId = [];
    $catalogRowsUsable = 0;

    foreach ($catalogRecords as $rec) {
        if (!is_array($rec)) {
            continue;
        }

        $isbnNorm = scalarToTrimmedString($rec['isbn_norm'] ?? null);
        $productId = isset($rec['product_id']) ? (int)$rec['product_id'] : 0;

        if ($isbnNorm !== null) {
            $catalogByIsbnNorm[$isbnNorm] = $rec; // zakładamy unikat ISBN; jeśli duplikaty, ostatni wygrywa
            $catalogRowsUsable++;
        }

        if ($productId > 0) {
            $catalogByProductId[$productId] = $rec;
        }
    }

    // Agregacja sprzedaży po ISBN
    $salesByIsbn = [];
    $unmatchedLines = [];
    $stats = [
        'catalog_records_total' => count($catalogRecords),
        'catalog_records_with_isbn_norm' => $catalogRowsUsable,
        'order_lines_total' => 0,
        'order_lines_matched' => 0,
        'order_lines_unmatched' => 0,
        'order_lines_matched_by_line_isbn' => 0,
        'order_lines_matched_by_catalog_product_id' => 0,
        'order_lines_with_negative_quantity' => 0,
        'report_rows_zero_filled' => 0,
        'report_rows_with_sales' => 0,
    ];

    foreach ($orderLines as $line) {
        if (!is_array($line)) {
            continue;
        }

        $stats['order_lines_total']++;

        $lineWarnings = is_array($line['warnings'] ?? null) ? $line['warnings'] : [];
        $lineIsbnNorm = scalarToTrimmedString($line['isbn_norm'] ?? null);
        $lineIsbnRaw = scalarToTrimmedString($line['isbn_raw'] ?? null);
        $productId = isset($line['product_id']) ? (int)$line['product_id'] : 0;

        $resolvedIsbnNorm = null;
        $resolvedIsbnDisplay = null;
        $resolvedTitle = scalarToTrimmedString($line['title_raw'] ?? null);
        $resolvedPremiereDate = null;
        $resolvedVia = null;

        // 1) próbujemy po ISBN z line item
        if ($lineIsbnNorm !== null && isset($catalogByIsbnNorm[$lineIsbnNorm])) {
            $catalogRec = $catalogByIsbnNorm[$lineIsbnNorm];
            $resolvedIsbnNorm = $lineIsbnNorm;
            $resolvedIsbnDisplay = scalarToTrimmedString($catalogRec['isbn_raw'] ?? null) ?? $lineIsbnRaw ?? $lineIsbnNorm;
            $resolvedTitle = scalarToTrimmedString($catalogRec['title'] ?? null) ?? $resolvedTitle;
            $resolvedPremiereDate = scalarToTrimmedString($catalogRec['premiere_date'] ?? null);
            $resolvedVia = 'line_isbn';
            $stats['order_lines_matched_by_line_isbn']++;
        }

        // 2) fallback po product_id -> katalog
        if ($resolvedIsbnNorm === null && $productId > 0 && isset($catalogByProductId[$productId])) {
            $catalogRec = $catalogByProductId[$productId];
            $catalogIsbnNorm = scalarToTrimmedString($catalogRec['isbn_norm'] ?? null);

            if ($catalogIsbnNorm !== null) {
                $resolvedIsbnNorm = $catalogIsbnNorm;
                $resolvedIsbnDisplay = scalarToTrimmedString($catalogRec['isbn_raw'] ?? null) ?? $catalogIsbnNorm;
                $resolvedTitle = scalarToTrimmedString($catalogRec['title'] ?? null) ?? $resolvedTitle;
                $resolvedPremiereDate = scalarToTrimmedString($catalogRec['premiere_date'] ?? null);
                $resolvedVia = 'catalog_product_id';
                $stats['order_lines_matched_by_catalog_product_id']++;
            }
        }

        if ($resolvedIsbnNorm === null) {
            $stats['order_lines_unmatched']++;
            $unmatchedLines[] = [
                'order_id' => $line['order_id'] ?? null,
                'line_item_id' => $line['line_item_id'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'title_raw' => $line['title_raw'] ?? null,
                'sku_raw' => $line['sku_raw'] ?? null,
                'isbn_raw' => $line['isbn_raw'] ?? null,
                'isbn_norm' => $line['isbn_norm'] ?? null,
                'warnings' => $lineWarnings,
            ];
            continue;
        }

        $stats['order_lines_matched']++;

        $qty = isset($line['quantity']) ? (int)$line['quantity'] : 0;
        if ($qty < 0) {
            $stats['order_lines_with_negative_quantity']++;
        }

        $netCents = isset($line['line_total_net_cents']) ? (int)$line['line_total_net_cents'] : decimalStringToCents(scalarToTrimmedString($line['line_total_net'] ?? null));
        $taxCents = isset($line['line_total_tax_cents']) ? (int)$line['line_total_tax_cents'] : decimalStringToCents(scalarToTrimmedString($line['line_total_tax'] ?? null));
        $grossCents = isset($line['line_total_gross_cents']) ? (int)$line['line_total_gross_cents'] : ($netCents + $taxCents);

        if (!isset($salesByIsbn[$resolvedIsbnNorm])) {
            $salesByIsbn[$resolvedIsbnNorm] = [
                'period' => $month,
                'channel' => 'woocommerce_histmag',

                'isbn_norm' => $resolvedIsbnNorm,
                'isbn_display' => $resolvedIsbnDisplay ?? $resolvedIsbnNorm,
                'title' => $resolvedTitle,
                'premiere_date' => $resolvedPremiereDate,

                'units_sold' => 0,
                'revenue_net_cents' => 0,
                'revenue_tax_cents' => 0,
                'revenue_gross_cents' => 0,

                'line_count' => 0,
                'matched_from_orders' => true,
                'match_methods' => [],
                'warnings' => [],
            ];
        }

        $salesByIsbn[$resolvedIsbnNorm]['units_sold'] += $qty;
        $salesByIsbn[$resolvedIsbnNorm]['revenue_net_cents'] += $netCents;
        $salesByIsbn[$resolvedIsbnNorm]['revenue_tax_cents'] += $taxCents;
        $salesByIsbn[$resolvedIsbnNorm]['revenue_gross_cents'] += $grossCents;
        $salesByIsbn[$resolvedIsbnNorm]['line_count'] += 1;

        if ($resolvedVia !== null) {
            $salesByIsbn[$resolvedIsbnNorm]['match_methods'][$resolvedVia] = true;
        }

        foreach ($lineWarnings as $w) {
            if (is_string($w) && $w !== '') {
                $salesByIsbn[$resolvedIsbnNorm]['warnings'][] = $w;
            }
        }
    }

    // Zero-fill: pełna lista katalogu (po ISBN)
    $reportRows = [];

    foreach ($catalogByIsbnNorm as $isbnNorm => $catalogRec) {
        $catalogTitle = scalarToTrimmedString($catalogRec['title'] ?? null);
        $catalogIsbnRaw = scalarToTrimmedString($catalogRec['isbn_raw'] ?? null);
        $catalogPremiere = scalarToTrimmedString($catalogRec['premiere_date'] ?? null);

        if (isset($salesByIsbn[$isbnNorm])) {
            $row = $salesByIsbn[$isbnNorm];
        } else {
            $row = [
                'period' => $month,
                'channel' => 'woocommerce_histmag',

                'isbn_norm' => $isbnNorm,
                'isbn_display' => $catalogIsbnRaw ?? $isbnNorm,
                'title' => $catalogTitle,
                'premiere_date' => $catalogPremiere,

                'units_sold' => 0,
                'revenue_net_cents' => 0,
                'revenue_tax_cents' => 0,
                'revenue_gross_cents' => 0,

                'line_count' => 0,
                'matched_from_orders' => false,
                'match_methods' => [],
                'warnings' => [],
            ];
        }

        // uzupełnij pola katalogowe, jeśli w sprzedaży były puste
        if (($row['isbn_display'] ?? null) === null) {
            $row['isbn_display'] = $catalogIsbnRaw ?? $isbnNorm;
        }
        if (($row['title'] ?? null) === null) {
            $row['title'] = $catalogTitle;
        }
        if (($row['premiere_date'] ?? null) === null) {
            $row['premiere_date'] = $catalogPremiere;
        }

        $row['in_catalog'] = true;

        // wersje stringowe kwot (czytelniejsze i wygodne do CSV)
        $row['revenue_net'] = centsToDecimalString((int)$row['revenue_net_cents']);
        $row['revenue_tax'] = centsToDecimalString((int)$row['revenue_tax_cents']);
        $row['revenue_gross'] = centsToDecimalString((int)$row['revenue_gross_cents']);

        // spłaszcz match methods do listy
        $methods = array_keys(is_array($row['match_methods']) ? $row['match_methods'] : []);
        sort($methods);
        $row['match_methods'] = $methods;

        // warningi unikalne
        $warnings = [];
        foreach ((array)$row['warnings'] as $w) {
            if (is_string($w) && $w !== '') {
                $warnings[$w] = true;
            }
        }
        $row['warnings'] = array_keys($warnings);

        if ((int)$row['units_sold'] > 0 || (int)$row['revenue_gross_cents'] !== 0) {
            $stats['report_rows_with_sales']++;
        }

        $reportRows[] = $row;
    }

    // sortowanie dla stabilności: po tytule, potem ISBN
    usort($reportRows, function (array $a, array $b): int {
        $ta = mb_strtolower((string)($a['title'] ?? ''), 'UTF-8');
        $tb = mb_strtolower((string)($b['title'] ?? ''), 'UTF-8');
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }
        return (string)($a['isbn_norm'] ?? '') <=> (string)($b['isbn_norm'] ?? '');
    });

    $stats['report_rows_zero_filled'] = count($reportRows);

    // sales_by_isbn (tylko pozycje z realną sprzedażą)
    $salesRows = [];
    foreach ($salesByIsbn as $row) {
        $methods = array_keys(is_array($row['match_methods']) ? $row['match_methods'] : []);
        sort($methods);

        $warnings = [];
        foreach ((array)$row['warnings'] as $w) {
            if (is_string($w) && $w !== '') {
                $warnings[$w] = true;
            }
        }

        $salesRows[] = [
            'period' => $row['period'],
            'channel' => $row['channel'],
            'isbn_norm' => $row['isbn_norm'],
            'isbn_display' => $row['isbn_display'],
            'title' => $row['title'],
            'premiere_date' => $row['premiere_date'],
            'units_sold' => $row['units_sold'],
            'revenue_net_cents' => $row['revenue_net_cents'],
            'revenue_tax_cents' => $row['revenue_tax_cents'],
            'revenue_gross_cents' => $row['revenue_gross_cents'],
            'revenue_net' => centsToDecimalString((int)$row['revenue_net_cents']),
            'revenue_tax' => centsToDecimalString((int)$row['revenue_tax_cents']),
            'revenue_gross' => centsToDecimalString((int)$row['revenue_gross_cents']),
            'line_count' => $row['line_count'],
            'matched_from_orders' => true,
            'match_methods' => $methods,
            'warnings' => array_keys($warnings),
        ];
    }

    usort($salesRows, function (array $a, array $b): int {
        // sort po przychodzie malejąco, potem tytuł
        $ag = (int)$a['revenue_gross_cents'];
        $bg = (int)$b['revenue_gross_cents'];
        if ($ag !== $bg) {
            return $bg <=> $ag;
        }
        return (string)($a['title'] ?? '') <=> (string)($b['title'] ?? '');
    });

    // Zapisy
    $salesPath = $monthDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $reportJsonPath = $monthDir . DIRECTORY_SEPARATOR . 'report_rows.zero_filled.json';
    $reportCsvPath = $monthDir . DIRECTORY_SEPARATOR . 'report_rows.zero_filled.csv';
    $unmatchedPath = $monthDir . DIRECTORY_SEPARATOR . 'unmatched_lines.json';
    $manifestPath = $monthDir . DIRECTORY_SEPARATOR . 'manifest.json';

    writeJsonFile($salesPath, [
        'snapshot_type' => 'woo_sales_by_isbn',
        'generated_at' => gmdate('c'),
        'source' => 'woocommerce_histmag',
        'month' => $month,
        'stats' => [
            'rows' => count($salesRows),
            'gross_total_cents' => array_sum(array_map(fn($r) => (int)$r['revenue_gross_cents'], $salesRows)),
        ],
        'records' => $salesRows,
    ]);

    writeJsonFile($reportJsonPath, [
        'snapshot_type' => 'woo_report_rows_zero_filled',
        'generated_at' => gmdate('c'),
        'source' => 'woocommerce_histmag',
        'month' => $month,
        'stats' => $stats,
        'records' => $reportRows,
    ]);

    writeJsonFile($unmatchedPath, [
        'snapshot_type' => 'woo_unmatched_lines',
        'generated_at' => gmdate('c'),
        'source' => 'woocommerce_histmag',
        'month' => $month,
        'count' => count($unmatchedLines),
        'records' => $unmatchedLines,
    ]);

    // CSV (przyjazny do szybkiego podglądu w Excelu)
    $csvRows = [];
    foreach ($reportRows as $r) {
        $csvRows[] = [
            'period' => $r['period'],
            'channel' => $r['channel'],
            'isbn_norm' => $r['isbn_norm'],
            'isbn_display' => $r['isbn_display'],
            'title' => $r['title'],
            'premiere_date' => $r['premiere_date'],
            'units_sold' => $r['units_sold'],
            'revenue_net' => $r['revenue_net'],
            'revenue_tax' => $r['revenue_tax'],
            'revenue_gross' => $r['revenue_gross'],
            'matched_from_orders' => $r['matched_from_orders'] ? 1 : 0,
            'match_methods' => implode('|', is_array($r['match_methods']) ? $r['match_methods'] : []),
            'warnings' => implode(' | ', is_array($r['warnings']) ? $r['warnings'] : []),
        ];
    }
    writeAssocCsv($reportCsvPath, $csvRows);

    writeJsonFile($manifestPath, [
        'snapshot_type' => 'woo_month_manifest',
        'generated_at' => gmdate('c'),
        'source' => 'woocommerce_histmag',
        'month' => $month,
        'inputs' => [
            'catalog_normalized' => $catalogPath,
            'order_lines_normalized' => $orderLinesPath,
        ],
        'outputs' => [
            'sales_by_isbn' => $salesPath,
            'report_rows_zero_filled_json' => $reportJsonPath,
            'report_rows_zero_filled_csv' => $reportCsvPath,
            'unmatched_lines' => $unmatchedPath,
        ],
        'stats' => $stats,
        'warnings' => [
            count($unmatchedLines) > 0 ? (count($unmatchedLines) . ' line items nie zostało dopasowanych do katalogu') : null,
        ],
    ]);

    fwrite(STDOUT, "✅ Gotowe\n\n");
    fwrite(STDOUT, "Wyjścia:\n");
    fwrite(STDOUT, " - {$salesPath}\n");
    fwrite(STDOUT, " - {$reportJsonPath}\n");
    fwrite(STDOUT, " - {$reportCsvPath}\n");
    fwrite(STDOUT, " - {$unmatchedPath}\n");
    fwrite(STDOUT, " - {$manifestPath}\n\n");

    fwrite(STDOUT, "Statystyki:\n");
    foreach ($stats as $k => $v) {
        fwrite(STDOUT, sprintf(" - %-36s %d\n", $k . ':', $v));
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}