<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function normalizeAzymutHeader(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;
    $header = str_replace(["\r", "\n", "\t"], ' ', $header);
    $header = preg_replace('/\s+/u', ' ', $header) ?? $header;
    $header = trim($header, " \"'");
    $header = mb_strtolower($header, 'UTF-8');

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header);
    if (is_string($ascii) && $ascii !== '') {
        $header = $ascii;
    }

    return trim((string)(preg_replace('/\s+/u', ' ', $header) ?? $header));
}

/**
 * @return array{utf8:string,encoding:string}
 */
function readAzymutCsvUtf8(string $filePath): array
{
    $raw = file_get_contents($filePath);
    if (!is_string($raw)) {
        throw new RuntimeException('Nie udało się odczytać pliku CSV.');
    }

    if (trim($raw) === '') {
        throw new RuntimeException('CSV jest pusty.');
    }

    $encoding = 'UTF-8';
    $utf8 = $raw;

    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $raw);
        if (is_string($converted) && $converted !== '') {
            $utf8 = $converted;
            $encoding = 'Windows-1250';
        }
    }

    return ['utf8' => $utf8, 'encoding' => $encoding];
}

function detectAzymutDelimiter(string $csvText): string
{
    $sampleLines = array_slice(array_values(array_filter(preg_split('/\R/u', $csvText) ?: [], static fn(string $line): bool => trim($line) !== '')), 0, 8);
    $sample = implode("\n", $sampleLines);
    return substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';
}

/**
 * @param array<int,string> $headers
 * @return array{isbn:?int,units:?int,price_after_discount:?int,missing:array<int,string>}
 */
function resolveAzymutColumns(array $headers): array
{
    $isbnCol = null;
    $unitsCol = null;
    $priceAfterDiscountCol = null;

    foreach ($headers as $idx => $header) {
        if ($isbnCol === null && preg_match('/\bisbn\b/u', $header)) {
            $isbnCol = $idx;
            continue;
        }

        if ($unitsCol === null && (
            preg_match('/\bilosc\b/u', $header)
            || preg_match('/\bilosc\b/u', $header)
            || preg_match('/\bliczba\b/u', $header)
            || preg_match('/\bsztuk\b/u', $header)
        )) {
            $unitsCol = $idx;
            continue;
        }

        if ($priceAfterDiscountCol === null && preg_match('/\bcena\s+po\s+rab\.?\b/u', $header)) {
            $priceAfterDiscountCol = $idx;
            continue;
        }
    }

    $missing = [];
    if ($isbnCol === null) {
        $missing[] = 'ISBN';
    }
    if ($unitsCol === null) {
        $missing[] = 'Ilość/Ilosc/Liczba';
    }
    if ($priceAfterDiscountCol === null) {
        $missing[] = 'Cena po rab.';
    }

    return [
        'isbn' => $isbnCol,
        'units' => $unitsCol,
        'price_after_discount' => $priceAfterDiscountCol,
        'missing' => $missing,
    ];
}

function parseAzymutUnits(?string $value): int
{
    if ($value === null) {
        return 0;
    }

    $v = trim($value);
    if ($v === '') {
        return 0;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace(',', '.', $v);

    return is_numeric($v) ? (int)round((float)$v, 0) : 0;
}

function parseAzymutAmountToCents(?string $value): int
{
    if ($value === null) {
        return 0;
    }

    $v = trim($value);
    if ($v === '') {
        return 0;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace(',', '.', $v);

    return decimalStringToCents($v);
}

/**
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseAzymutCsv(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return [
            'ok' => false,
            'message' => 'Nie znaleziono pliku raportu Azymut.',
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $ext = strtolower(pathinfo((string)($originalFilename ?? $filePath), PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return [
            'ok' => false,
            'message' => 'Raport Azymut musi mieć rozszerzenie .csv.',
            'details' => ['Otrzymano rozszerzenie: ' . ($ext !== '' ? $ext : '(brak)')],
        ];
    }

    try {
        $csvData = readAzymutCsvUtf8($filePath);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać raportu Azymut.',
            'details' => [$e->getMessage()],
        ];
    }

    $delimiter = detectAzymutDelimiter($csvData['utf8']);

    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        return [
            'ok' => false,
            'message' => 'Błąd techniczny parsera CSV (temp stream).',
            'details' => [],
        ];
    }

    fwrite($stream, $csvData['utf8']);
    rewind($stream);

    $rowsSeen = 0;
    $rowsData = 0;
    $rowsSkipped = 0;
    $rowsWithIsbn = 0;

    $headerRowNo = null;
    $columns = ['isbn' => null, 'units' => null, 'price_after_discount' => null];
    $normalizedHeaders = [];

    /** @var array<string,array{isbn_norm:string,isbn_raw_example:string,units_sold:int,margin_net_cents:int}> $aggregated */
    $aggregated = [];

    while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
        $rowsSeen++;
        $cells = array_map(static fn($v): string => is_string($v) ? $v : '', $row);
        $nonEmpty = array_filter($cells, static fn(string $v): bool => trim($v) !== '');
        if ($nonEmpty === []) {
            continue;
        }

        if ($headerRowNo === null) {
            $candidateHeaders = array_map('normalizeAzymutHeader', $cells);
            $resolved = resolveAzymutColumns($candidateHeaders);
            if ($resolved['missing'] === []) {
                $headerRowNo = $rowsSeen;
                $normalizedHeaders = $candidateHeaders;
                $columns = [
                    'isbn' => $resolved['isbn'],
                    'units' => $resolved['units'],
                    'price_after_discount' => $resolved['price_after_discount'],
                ];
            }
            continue;
        }

        $rowsData++;

        $isbnRaw = isset($cells[(int)$columns['isbn']]) ? trim((string)$cells[(int)$columns['isbn']]) : '';
        $isbn = normalizeIsbnRaw($isbnRaw)['isbn_norm'] ?? null;
        if ($isbn === null) {
            $rowsSkipped++;
            continue;
        }

        $rowsWithIsbn++;

        $units = parseAzymutUnits(isset($cells[(int)$columns['units']]) ? (string)$cells[(int)$columns['units']] : null);
        $amountCents = parseAzymutAmountToCents(isset($cells[(int)$columns['price_after_discount']]) ? (string)$cells[(int)$columns['price_after_discount']] : null);

        if (!isset($aggregated[$isbn])) {
            $aggregated[$isbn] = [
                'isbn_norm' => $isbn,
                'isbn_raw_example' => $isbnRaw,
                'units_sold' => 0,
                'margin_net_cents' => 0,
            ];
        }

        $aggregated[$isbn]['units_sold'] += $units;
        $aggregated[$isbn]['margin_net_cents'] += $amountCents;
    }

    fclose($stream);

    if ($headerRowNo === null) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na poprawny raport Azymut (nie wykryto nagłówka z wymaganymi kolumnami).',
            'details' => [
                'Wymagane kolumny logiczne: ISBN, Ilość/Ilosc/Liczba, Cena po rab.',
                'Kwoty do XLSX muszą pochodzić wyłącznie z kolumny „Cena po rab.”.',
            ],
        ];
    }

    if ($columns['price_after_discount'] === null) {
        return [
            'ok' => false,
            'message' => 'Brak obowiązkowej kolumny „Cena po rab.” w raporcie Azymut.',
            'details' => ['Bez tej kolumny raport nie może zostać zaklasyfikowany jako Azymut.'],
        ];
    }

    $records = array_values($aggregated);
    $sumUnits = 0;
    $sumMargin = 0;
    foreach ($records as $record) {
        $sumUnits += (int)$record['units_sold'];
        $sumMargin += (int)$record['margin_net_cents'];
    }

    return [
        'ok' => true,
        'message' => 'Rozpoznano raport Azymut.',
        'details' => [
            'Wykryto nagłówek w linii: ' . $headerRowNo,
            'Wykryty separator CSV: ' . $delimiter,
            'Kolumna kwoty: Cena po rab.',
        ],
        'data' => [
            'encoding' => $csvData['encoding'],
            'delimiter' => $delimiter,
            'header_row' => $headerRowNo,
            'headers' => $normalizedHeaders,
            'rows_total' => $rowsSeen,
            'rows_data' => $rowsData,
            'rows_skipped' => $rowsSkipped,
            'rows_with_isbn' => $rowsWithIsbn,
            'records' => $records,
            'sum_units' => $sumUnits,
            'sum_margin_net_cents' => $sumMargin,
        ],
    ];
}
