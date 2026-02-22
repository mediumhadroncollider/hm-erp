#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * v0.0.1a+ — pobiera katalog produktów z WooCommerce i zapisuje:
 *  - snapshot surowy JSON
 *  - snapshot znormalizowany JSON (ISBN, data premiery, warningi)
 *
 * Uruchom:
 *   php8.4 bin/ingest_woo_catalog.php
 * albo (jeśli php wskazuje już na 8.x):
 *   php bin/ingest_woo_catalog.php
 */

$config = require __DIR__ . '/../config.local.php';

$woo = $config['woo'] ?? null;
$paths = $config['paths'] ?? null;

if (!is_array($woo) || !is_array($paths)) {
    fwrite(STDERR, "Błąd konfiguracji: brak sekcji 'woo' lub 'paths'.\n");
    exit(1);
}

$baseUrl = rtrim((string)($woo['base_url'] ?? ''), '/');
$consumerKey = (string)($woo['consumer_key'] ?? '');
$consumerSecret = (string)($woo['consumer_secret'] ?? '');
$status = (string)($woo['products_status'] ?? 'publish');
$perPage = (int)($woo['per_page'] ?? 100);
$timeout = (int)($woo['timeout'] ?? 30);

if ($baseUrl === '' || $consumerKey === '' || $consumerSecret === '') {
    fwrite(STDERR, "Błąd konfiguracji: uzupełnij base_url / consumer_key / consumer_secret.\n");
    exit(1);
}

if ($perPage < 1 || $perPage > 100) {
    $perPage = 100;
}

$outDir = (string)($paths['catalog_snapshots_dir'] ?? '');
if ($outDir === '') {
    fwrite(STDERR, "Błąd konfiguracji: brak 'catalog_snapshots_dir'.\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Nie udało się utworzyć katalogu: {$outDir}\n");
    exit(1);
}

/**
 * Wykonuje GET do WooCommerce API i zwraca zdekodowany JSON.
 *
 * @param array<string, scalar> $query
 * @return mixed
 */
function wooApiGet(
    string $baseUrl,
    string $consumerKey,
    string $consumerSecret,
    string $path,
    array $query = [],
    int $timeoutSeconds = 30
) {
    $url = $baseUrl . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Nie udało się zainicjalizować cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $consumerKey . ':' . $consumerSecret,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $responseBody = curl_exec($ch);

    if ($responseBody === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Błąd cURL: ' . $err);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("Woo API zwróciło HTTP {$httpCode}. Treść: {$responseBody}");
    }

    $decoded = json_decode($responseBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Niepoprawny JSON: ' . json_last_error_msg());
    }

    return $decoded;
}

/**
 * @param mixed $value
 */
function scalarToTrimmedString($value): ?string
{
    if (is_string($value)) {
        $v = trim($value);
        return $v !== '' ? $v : null;
    }

    if (is_int($value) || is_float($value)) {
        return trim((string)$value);
    }

    return null;
}

/**
 * @param mixed $metaData
 * @return array<string, mixed>
 */
function metaDataToMap($metaData): array
{
    $result = [];

    if (!is_array($metaData)) {
        return $result;
    }

    foreach ($metaData as $row) {
        if (!is_array($row)) {
            continue;
        }

        $key = $row['key'] ?? null;
        $value = $row['value'] ?? null;

        if (!is_string($key) || $key === '') {
            continue;
        }

        $result[$key] = $value;
    }

    return $result;
}

/**
 * @param mixed $value
 */
function normalizeDateOnly($value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value);
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param mixed $value
 */
function normalizeIsoDateTime($value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value);
        $dt = $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format(DateTimeInterface::ATOM);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Najważniejsza funkcja na przyszłość:
 * - zachowujemy raw
 * - do matchowania używamy digits-only
 *
 * @return array{isbn_norm:?string,warnings:array<int,string>}
 */
function normalizeIsbn(string $raw): array
{
    $warnings = [];
    $trimmed = trim($raw);

    if ($trimmed === '') {
        return [
            'isbn_norm' => null,
            'warnings' => ['Pusty ISBN.'],
        ];
    }

    $alnum = strtoupper((string)preg_replace('/[^0-9Xx]/', '', $trimmed));
    $digitsOnly = (string)preg_replace('/\D/', '', $trimmed);

    if ($alnum === '') {
        return [
            'isbn_norm' => null,
            'warnings' => ["ISBN zawiera tylko znaki nieistotne (raw={$raw})."],
        ];
    }

    if (strpos($alnum, 'X') !== false) {
        $warnings[] = 'ISBN zawiera X (prawdopodobnie ISBN-10); porównanie i tak pójdzie po digits-only.';
    }

    $len = strlen($digitsOnly);
    if ($len !== 10 && $len !== 13) {
        $warnings[] = "Nietypowa długość ISBN po normalizacji: {$len} (raw={$raw})";
    }

    if ($digitsOnly === '') {
        return [
            'isbn_norm' => null,
            'warnings' => $warnings,
        ];
    }

    return [
        'isbn_norm' => $digitsOnly,
        'warnings' => $warnings,
    ];
}

/**
 * @param array<string, mixed> $product
 * @param array<string, mixed> $metaMap
 * @param array<int, string> $isbnMetaKeys
 * @return array{0:?string,1:?string}
 */
function extractIsbn(array $product, array $metaMap, array $isbnMetaKeys): array
{
    foreach ($isbnMetaKeys as $key) {
        if (array_key_exists($key, $metaMap)) {
            $value = scalarToTrimmedString($metaMap[$key]);
            if ($value !== null) {
                return [$value, 'meta:' . $key];
            }
        }
    }

    $sku = scalarToTrimmedString($product['sku'] ?? null);
    if ($sku !== null) {
        return [$sku, 'sku'];
    }

    return [null, null];
}

/**
 * @param array<string, mixed> $product
 * @param array<string, mixed> $metaMap
 * @param array<int, string> $premiereMetaKeys
 * @return array{0:?string,1:string,2:array<int,string>}
 */
function extractPremiereDate(array $product, array $metaMap, array $premiereMetaKeys): array
{
    $warnings = [];

    foreach ($premiereMetaKeys as $key) {
        if (!array_key_exists($key, $metaMap)) {
            continue;
        }

        $value = scalarToTrimmedString($metaMap[$key]);
        if ($value === null) {
            continue;
        }

        $normalized = normalizeDateOnly($value);
        if ($normalized !== null) {
            return [$normalized, 'meta:' . $key, $warnings];
        }

        $warnings[] = "Nieprawidłowa data premiery w meta {$key}={$value}";
        // jeśli meta jest błędne, spadamy do fallbacka
        break;
    }

    $fallback = normalizeDateOnly($product['date_created_gmt'] ?? $product['date_created'] ?? null);
    if ($fallback !== null) {
        return [$fallback, 'date_created', $warnings];
    }

    return [null, 'unknown', $warnings];
}

/**
 * @param array<int, array<string,mixed>> $rawProducts
 * @param array<int, string> $isbnMetaKeys
 * @param array<int, string> $premiereMetaKeys
 * @return array{records:array<int,array<string,mixed>>,stats:array<string,int>}
 */
function normalizeCatalogProducts(array $rawProducts, array $isbnMetaKeys, array $premiereMetaKeys): array
{
    $records = [];

    $stats = [
        'total_products' => 0,
        'with_isbn_raw' => 0,
        'with_isbn_norm' => 0,
        'without_isbn' => 0,
        'premiere_from_meta' => 0,
        'premiere_from_date_created' => 0,
        'premiere_unknown' => 0,
        'records_with_warnings' => 0,
    ];

    foreach ($rawProducts as $product) {
        if (!is_array($product)) {
            continue;
        }

        $stats['total_products']++;

        $warnings = [];
        $metaMap = metaDataToMap($product['meta_data'] ?? []);

        list($isbnRaw, $isbnSource) = extractIsbn($product, $metaMap, $isbnMetaKeys);
        $isbnNorm = null;

        if ($isbnRaw !== null) {
            $stats['with_isbn_raw']++;
            $isbnResult = normalizeIsbn($isbnRaw);
            $isbnNorm = $isbnResult['isbn_norm'];
            if ($isbnNorm !== null) {
                $stats['with_isbn_norm']++;
            }
            foreach ($isbnResult['warnings'] as $w) {
                $warnings[] = $w;
            }
        } else {
            $stats['without_isbn']++;
            $warnings[] = 'Brak ISBN (meta/SKU).';
        }

        list($premiereDate, $premiereDateSource, $premiereWarnings) = extractPremiereDate($product, $metaMap, $premiereMetaKeys);
        foreach ($premiereWarnings as $w) {
            $warnings[] = $w;
        }

        if (strpos($premiereDateSource, 'meta:') === 0) {
            $stats['premiere_from_meta']++;
        } elseif ($premiereDateSource === 'date_created') {
            $stats['premiere_from_date_created']++;
        } else {
            $stats['premiere_unknown']++;
        }

        $categories = [];
        if (isset($product['categories']) && is_array($product['categories'])) {
            foreach ($product['categories'] as $cat) {
                if (is_array($cat) && isset($cat['name']) && is_string($cat['name'])) {
                    $categories[] = $cat['name'];
                }
            }
        }

        $status = is_string($product['status'] ?? null) ? $product['status'] : null;
        $sku = scalarToTrimmedString($product['sku'] ?? null);

        $record = [
            'source' => 'woocommerce_histmag',
            'product_id' => $product['id'] ?? null,
            'variation_id' => null, // v0.0.1a+: tylko produkty główne
            'title' => is_string($product['name'] ?? null) ? $product['name'] : null,
            'product_type' => is_string($product['type'] ?? null) ? $product['type'] : null,
            'status' => $status,
            'is_active_in_store' => ($status === 'publish'),

            'isbn_raw' => $isbnRaw,
            'isbn_norm' => $isbnNorm,
            'isbn_source' => $isbnSource,

            'sku' => $sku,

            'premiere_date' => $premiereDate,
            'premiere_date_source' => $premiereDateSource,

            'date_created' => normalizeIsoDateTime($product['date_created_gmt'] ?? $product['date_created'] ?? null),
            'date_modified' => normalizeIsoDateTime($product['date_modified_gmt'] ?? $product['date_modified'] ?? null),

            'categories' => $categories,

            'warnings' => array_values(array_unique($warnings)),
        ];

        if (!empty($record['warnings'])) {
            $stats['records_with_warnings']++;
        }

        $records[] = $record;
    }

    return [
        'records' => $records,
        'stats' => $stats,
    ];
}

try {
    fwrite(STDOUT, "Pobieranie katalogu WooCommerce...\n");
    fwrite(STDOUT, "Sklep: {$baseUrl}\n");
    fwrite(STDOUT, "Filtr statusu: {$status}, per_page={$perPage}\n\n");

    $allProducts = [];
    $page = 1;

    do {
        $query = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($status !== '') {
            $query['status'] = $status;
        }

        $products = wooApiGet(
            $baseUrl,
            $consumerKey,
            $consumerSecret,
            '/wp-json/wc/v3/products',
            $query,
            $timeout
        );

        if (!is_array($products)) {
            throw new RuntimeException("Nieoczekiwany format odpowiedzi API na stronie {$page}.");
        }

        $count = count($products);
        fwrite(STDOUT, " - strona {$page}: {$count} produktów\n");

        foreach ($products as $product) {
            if (is_array($product)) {
                $allProducts[] = $product;
            }
        }

        $page++;
    } while ($count === $perPage);

    // Tu możesz dopasować klucze meta po pierwszym obejrzeniu surowego JSON
    $isbnMetaKeys = [
        'isbn',
        'ISBN',
        '_isbn',
        'product_isbn',
    ];

    $premiereMetaKeys = [
        'premiere_date',
        'data_premiery',
        'release_date',
        '_release_date',
    ];

    $normalized = normalizeCatalogProducts($allProducts, $isbnMetaKeys, $premiereMetaKeys);
    $normalizedRecords = $normalized['records'];
    $normalizedStats = $normalized['stats'];

    $generatedAt = gmdate('c');
    $timestamp = gmdate('Y-m-d\THis\Z');

    $rawPayload = [
        'snapshot_type' => 'woo_catalog_raw',
        'generated_at' => $generatedAt,
        'source' => 'woocommerce_histmag',
        'base_url' => $baseUrl,
        'product_status_filter' => $status,
        'total_products' => count($allProducts),
        'products' => $allProducts,
    ];

    $normalizedPayload = [
        'snapshot_type' => 'woo_catalog_normalized',
        'generated_at' => $generatedAt,
        'source' => 'woocommerce_histmag',
        'base_url' => $baseUrl,
        'product_status_filter' => $status,
        'isbn_meta_keys_checked' => $isbnMetaKeys,
        'premiere_meta_keys_checked' => $premiereMetaKeys,
        'stats' => $normalizedStats,
        'records' => $normalizedRecords,
    ];

    $rawFile = $outDir . DIRECTORY_SEPARATOR . $timestamp . '.catalog.raw.json';
    $normalizedFile = $outDir . DIRECTORY_SEPARATOR . $timestamp . '.catalog.normalized.json';

    $rawJson = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($rawJson === false) {
        throw new RuntimeException('Nie udało się zakodować RAW JSON.');
    }

    $normalizedJson = json_encode($normalizedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($normalizedJson === false) {
        throw new RuntimeException('Nie udało się zakodować NORMALIZED JSON.');
    }

    if (file_put_contents($rawFile, $rawJson) === false) {
        throw new RuntimeException("Nie udało się zapisać pliku: {$rawFile}");
    }

    if (file_put_contents($normalizedFile, $normalizedJson) === false) {
        throw new RuntimeException("Nie udało się zapisać pliku: {$normalizedFile}");
    }

    fwrite(STDOUT, "\n✅ Gotowe\n");
    fwrite(STDOUT, "RAW:        {$rawFile}\n");
    fwrite(STDOUT, "NORMALIZED: {$normalizedFile}\n\n");

    fwrite(STDOUT, "Statystyki normalizacji:\n");
    foreach ($normalizedStats as $key => $value) {
        fwrite(STDOUT, sprintf(" - %-28s %d\n", $key . ':', $value));
    }

    fwrite(STDOUT, "\nPodpowiedź: otwórz *.catalog.raw.json i sprawdź rzeczywiste klucze meta z ISBN / datą premiery.\n");
    fwrite(STDOUT, "Jeśli są inne niż domyślne, dopisz je w tablicach \$isbnMetaKeys / \$premiereMetaKeys.\n");

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}