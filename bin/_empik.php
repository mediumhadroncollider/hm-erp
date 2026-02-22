<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function removePolishDiacritics(string $value): string
{
    $map = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z',
    ];

    return strtr($value, $map);
}

function normalizeEmpikHeader(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;
    $header = str_replace(["\r", "\n", "\t"], ' ', $header);
    $header = preg_replace('/\s+/u', ' ', $header) ?? $header;
    $header = trim($header, " \"'");
    $header = mb_strtolower($header, 'UTF-8');

    return removePolishDiacritics($header);
}

/**
 * @return array{raw:string,utf8:string,encoding:string}
 */
function readCsvWithUtf8Fallback(string $filePath): array
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
        } else {
            $utf8 = mb_convert_encoding($raw, 'UTF-8', 'Windows-1250');
            $encoding = 'Windows-1250';
        }
    }

    return ['raw' => $raw, 'utf8' => $utf8, 'encoding' => $encoding];
}

/**
 * @return array{headers:array<int,string>,map:array<string,int>,raw_headers:array<int,string>}
 */
function parseEmpikHeaderMap(string $utf8Csv): array
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Błąd techniczny parsera CSV (temp stream).');
    }

    fwrite($stream, $utf8Csv);
    rewind($stream);

    $headers = fgetcsv($stream, 0, ';');
    fclose($stream);

    if (!is_array($headers) || $headers === []) {
        throw new RuntimeException('Nie udało się odczytać nagłówka CSV (separator ";").');
    }

    $normalized = [];
    $map = [];

    foreach ($headers as $idx => $header) {
        $norm = normalizeEmpikHeader((string)$header);
        $normalized[$idx] = $norm;
        if ($norm !== '') {
            $map[$norm] = $idx;
        }
    }

    return [
        'headers' => $normalized,
        'map' => $map,
        'raw_headers' => array_map(static fn($v): string => (string)$v, $headers),
    ];
}

/**
 * @param array<int,string> $headers
 */
function findEmpikColumn(array $headers, array $patterns): ?int
{
    foreach ($headers as $idx => $name) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return $idx;
            }
        }
    }

    return null;
}

/**
 * @return array{isbn:?int,units:?int,net:?int,missing:array<int,string>}
 */
function resolveEmpikColumns(array $normalizedHeaders): array
{
    $isbnCol = findEmpikColumn($normalizedHeaders, [
        '/\bisbn\b/u',
        '/\bean\b/u',
        '/isbn\s*\/\s*ean/u',
    ]);

    $unitsCol = findEmpikColumn($normalizedHeaders, [
        '/\bilosc\b/u',
        '/\bliczba\b/u',
        '/\bqty\b/u',
    ]);

    $netCol = findEmpikColumn($normalizedHeaders, [
        '/wynagrodzenie\s+wyd\.?\s+netto/u',
        '/wynagrodzenie\s+wydawcy\s+netto/u',
        '/netto\s+dla\s+wydawcy/u',
    ]);

    $missing = [];
    if ($isbnCol === null) {
        $missing[] = 'ISBN';
    }
    if ($unitsCol === null) {
        $missing[] = 'Ilość';
    }
    if ($netCol === null) {
        $missing[] = 'Wynagrodzenie wyd. netto';
    }

    return [
        'isbn' => $isbnCol,
        'units' => $unitsCol,
        'net' => $netCol,
        'missing' => $missing,
    ];
}

function parseEmpikUnits(?string $value): int
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

    if (!is_numeric($v)) {
        return 0;
    }

    return (int)round((float)$v, 0);
}

function parseEmpikNetToCents(?string $value): int
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
function validateAndParseEmpikCsv(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return [
            'ok' => false,
            'message' => 'Nie znaleziono pliku raportu Empik.',
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $ext = strtolower(pathinfo((string)($originalFilename ?? $filePath), PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return [
            'ok' => false,
            'message' => 'Raport Empik musi mieć rozszerzenie .csv.',
            'details' => ['Otrzymano rozszerzenie: ' . ($ext !== '' ? $ext : '(brak)')],
        ];
    }

    try {
        $csvData = readCsvWithUtf8Fallback($filePath);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać raportu Empik.',
            'details' => [$e->getMessage()],
        ];
    }

    $sampleLines = array_slice(preg_split('/\R/u', $csvData['utf8']) ?: [], 0, 6);
    $sample = implode("\n", $sampleLines);
    $semicolonCount = substr_count($sample, ';');
    if ($semicolonCount < 3) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na CSV Empik z separatorem średnik (;).',
            'details' => ['W próbce wykryto zbyt mało separatorów ";".'],
        ];
    }

    try {
        $headerInfo = parseEmpikHeaderMap($csvData['utf8']);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać nagłówka CSV Empik.',
            'details' => [$e->getMessage()],
        ];
    }

    $cols = resolveEmpikColumns($headerInfo['headers']);
    if ($cols['missing'] !== []) {
        return [
            'ok' => false,
            'message' => 'Brakuje wymaganych kolumn raportu Empik.',
            'details' => [
                'Brakujące kolumny: ' . implode(', ', $cols['missing']),
                'Rozpoznane nagłówki: ' . implode(' | ', $headerInfo['headers']),
            ],
        ];
    }

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

    fgetcsv($stream, 0, ';');

    $rowsInputTotal = 0;
    $rowsData = 0;
    $rowsSkipped = 0;

    $sumUnits = 0;
    $sumNetCents = 0;

    $byIsbn = [];

    while (($row = fgetcsv($stream, 0, ';')) !== false) {
        $rowsInputTotal++;

        $isEmpty = true;
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                $isEmpty = false;
                break;
            }
        }
        if ($isEmpty) {
            continue;
        }

        $isbnRaw = isset($row[$cols['isbn']]) ? trim((string)$row[$cols['isbn']]) : '';
        $unitsRaw = isset($row[$cols['units']]) ? trim((string)$row[$cols['units']]) : '';
        $netRaw = isset($row[$cols['net']]) ? trim((string)$row[$cols['net']]) : '';

        $isbnNorm = normalizeIsbnRaw($isbnRaw)['isbn_norm'];
        if ($isbnNorm === null || ($unitsRaw === '' && $netRaw === '')) {
            $rowsSkipped++;
            continue;
        }

        $units = parseEmpikUnits($unitsRaw);
        $netCents = parseEmpikNetToCents($netRaw);

        if (!isset($byIsbn[$isbnNorm])) {
            $byIsbn[$isbnNorm] = [
                'isbn_norm' => $isbnNorm,
                'isbn_raw_example' => $isbnRaw,
                'units_sold' => 0,
                'margin_net_cents' => 0,
            ];
        }

        $byIsbn[$isbnNorm]['units_sold'] += $units;
        $byIsbn[$isbnNorm]['margin_net_cents'] += $netCents;

        $sumUnits += $units;
        $sumNetCents += $netCents;
        $rowsData++;
    }
    fclose($stream);

    if ($rowsData === 0 || $byIsbn === []) {
        return [
            'ok' => false,
            'message' => 'Raport Empik nie zawiera poprawnych wierszy sprzedażowych z ISBN.',
            'details' => [
                'Wiersze wejściowe: ' . $rowsInputTotal,
                'Wiersze pominięte: ' . $rowsSkipped,
            ],
        ];
    }

    return [
        'ok' => true,
        'message' => 'Raport Empik poprawny.',
        'details' => [],
        'data' => [
            'source' => 'empik',
            'encoding' => $csvData['encoding'],
            'rows_total' => $rowsInputTotal,
            'rows_data' => $rowsData,
            'rows_skipped' => $rowsSkipped,
            'sum_units' => $sumUnits,
            'sum_margin_net_cents' => $sumNetCents,
            'records' => array_values($byIsbn),
        ],
    ];
}
