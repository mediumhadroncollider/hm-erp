#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';

try {
    $config = loadConfig();
    $woo = $config['woo'] ?? [];
    $paths = $config['paths'] ?? [];

    if (!is_array($woo) || !is_array($paths)) {
        throw new RuntimeException("Błąd config.local.php: sekcje 'woo' i 'paths' muszą być tablicami.");
    }

    $month = parseRequiredMonthArg($argv);

    $baseUrl = rtrim((string)($woo['base_url'] ?? ''), '/');
    $consumerKey = (string)($woo['consumer_key'] ?? '');
    $consumerSecret = (string)($woo['consumer_secret'] ?? '');
    $timeout = (int)($woo['timeout'] ?? 30);
    $perPage = (int)($woo['per_page'] ?? 100);
    $siteTimezone = (string)($woo['site_timezone'] ?? 'Europe/Warsaw');
    $allowedStatuses = is_array($woo['sales_order_statuses'] ?? null) ? $woo['sales_order_statuses'] : ['processing', 'completed'];

    if ($baseUrl === '' || $consumerKey === '' || $consumerSecret === '') {
        throw new RuntimeException('Uzupełnij base_url / consumer_key / consumer_secret w config.local.php');
    }

    if ($perPage < 1 || $perPage > 100) {
        $perPage = 100;
    }

    $periodsDir = (string)($paths['periods_dir'] ?? '');
    if ($periodsDir === '') {
        throw new RuntimeException("Brak paths.periods_dir w config.local.php");
    }

    $bounds = monthBounds($month, $siteTimezone);

    $outDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'woo';
    ensureDir($outDir);

    fwrite(STDOUT, "Pobieranie zamówień WooCommerce za miesiąc {$month}\n");
    fwrite(STDOUT, "Sklep: {$baseUrl}\n");
    fwrite(STDOUT, "Zakres (local {$siteTimezone}): {$bounds['start_local']} -> {$bounds['end_local_exclusive']} (exclusive)\n");
    fwrite(STDOUT, "Filtr statusów sprzedażowych (po stronie skryptu): " . implode(', ', $allowedStatuses) . "\n\n");

    // Celowo bez parametru status - pobieramy wszystko z zakresu dat i filtrujemy lokalnie
    $orders = wooFetchAllPages(
        $baseUrl,
        $consumerKey,
        $consumerSecret,
        '/wp-json/wc/v3/orders',
        [
            'per_page' => $perPage,
            'after' => $bounds['after_iso'],
            'before' => $bounds['before_iso'],
            'orderby' => 'date',
            'order' => 'asc',
        ],
        $timeout,
        'orders'
    );

    $rawPayload = [
        'snapshot_type' => 'woo_orders_raw',
        'generated_at' => gmdate('c'),
        'source' => 'woocommerce_histmag',
        'month' => $month,
        'site_timezone' => $siteTimezone,
        'period_bounds' => $bounds,
        'total_orders_fetched' => count($orders),
        'orders' => $orders,
    ];

    $rawPath = $outDir . DIRECTORY_SEPARATOR . 'orders.raw.json';
    writeJsonFile($rawPath, $rawPayload);

    // Normalizacja line items
    $normalizedLines = [];
    $stats = [
        'orders_total_fetched' => 0,
        'orders_included_by_status' => 0,
        'orders_excluded_by_status' => 0,
        'orders_without_line_items' => 0,
        'line_items_total_seen' => 0,
        'line_items_included' => 0,
        'line_items_skipped_non_product' => 0,
        'line_items_with_isbn_norm' => 0,
        'line_items_without_isbn' => 0,
    ];

    $allowedMap = [];
    foreach ($allowedStatuses as $s) {
        if (is_string($s) && $s !== '') {
            $allowedMap[$s] = true;
        }
    }

    foreach ($orders as $order) {
        if (!is_array($order)) {
            continue;
        }

        $stats['orders_total_fetched']++;

        $orderStatus = is_string($order['status'] ?? null) ? $order['status'] : null;
        if ($orderStatus === null || !isset($allowedMap[$orderStatus])) {
            $stats['orders_excluded_by_status']++;
            continue;
        }

        $stats['orders_included_by_status']++;

        $lineItems = $order['line_items'] ?? null;
        if (!is_array($lineItems) || $lineItems === []) {
            $stats['orders_without_line_items']++;
            continue;
        }

        foreach ($lineItems as $line) {
            $stats['line_items_total_seen']++;

            if (!is_array($line)) {
                $stats['line_items_skipped_non_product']++;
                continue;
            }

            // Woo line item
            $productId = isset($line['product_id']) ? (int)$line['product_id'] : 0;
            $variationId = isset($line['variation_id']) ? (int)$line['variation_id'] : 0;
            $quantity = isset($line['quantity']) ? (int)$line['quantity'] : 0;

            $name = scalarToTrimmedString($line['name'] ?? null);
            $skuRaw = scalarToTrimmedString($line['sku'] ?? null);

            $isbnRaw = $skuRaw; // v0.0.1: zakładamy, że sku zwykle niesie ISBN
            $isbnNorm = null;
            $isbnWarnings = [];

            if ($isbnRaw !== null) {
                $isbnResult = normalizeIsbnRaw($isbnRaw);
                $isbnNorm = $isbnResult['isbn_norm'];
                $isbnWarnings = $isbnResult['warnings'];
            }

            if ($isbnNorm !== null) {
                $stats['line_items_with_isbn_norm']++;
            } else {
                $stats['line_items_without_isbn']++;
            }

            $netStr = scalarToTrimmedString($line['total'] ?? null) ?? '0';
            $taxStr = scalarToTrimmedString($line['total_tax'] ?? null) ?? '0';

            $netCents = decimalStringToCents($netStr);
            $taxCents = decimalStringToCents($taxStr);
            $grossCents = $netCents + $taxCents;

            $normalizedLines[] = [
                'source' => 'woocommerce_histmag',
                'period' => $month,

                'order_id' => isset($order['id']) ? (int)$order['id'] : null,
                'order_number' => scalarToTrimmedString($order['number'] ?? null),
                'order_status' => $orderStatus,
                'order_currency' => scalarToTrimmedString($order['currency'] ?? null),

                'order_date_created' => scalarToTrimmedString($order['date_created_gmt'] ?? $order['date_created'] ?? null),
                'order_date_paid' => scalarToTrimmedString($order['date_paid_gmt'] ?? $order['date_paid'] ?? null),
                'order_date_completed' => scalarToTrimmedString($order['date_completed_gmt'] ?? $order['date_completed'] ?? null),

                'line_item_id' => isset($line['id']) ? (int)$line['id'] : null,
                'product_id' => $productId > 0 ? $productId : null,
                'variation_id' => $variationId > 0 ? $variationId : null,

                'title_raw' => $name,
                'sku_raw' => $skuRaw,

                'isbn_raw' => $isbnRaw,
                'isbn_norm' => $isbnNorm,
                'isbn_source' => $isbnRaw !== null ? 'line_item.sku' : null,

                'quantity' => $quantity,

                'line_total_net' => $netStr,
                'line_total_tax' => $taxStr,
                'line_total_gross' => centsToDecimalString($grossCents),

                'line_total_net_cents' => $netCents,
                'line_total_tax_cents' => $taxCents,
                'line_total_gross_cents' => $grossCents,

                'warnings' => $isbnWarnings,
            ];

            $stats['line_items_included']++;
        }
    }

    $normalizedPayload = [
        'snapshot_type' => 'woo_order_lines_normalized',
        'generated_at' => gmdate('c'),
        'source' => 'woocommerce_histmag',
        'month' => $month,
        'site_timezone' => $siteTimezone,
        'allowed_order_statuses' => array_values(array_keys($allowedMap)),
        'period_bounds' => $bounds,
        'stats' => $stats,
        'records' => $normalizedLines,
    ];

    $normalizedPath = $outDir . DIRECTORY_SEPARATOR . 'order_lines.normalized.json';
    writeJsonFile($normalizedPath, $normalizedPayload);

    fwrite(STDOUT, "\n✅ Gotowe\n");
    fwrite(STDOUT, "RAW orders:        {$rawPath}\n");
    fwrite(STDOUT, "Normalized lines:  {$normalizedPath}\n\n");
    fwrite(STDOUT, "Statystyki:\n");
    foreach ($stats as $k => $v) {
        fwrite(STDOUT, sprintf(" - %-32s %d\n", $k . ':', $v));
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}