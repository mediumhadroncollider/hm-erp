<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function normalizeEbookpointText(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value, " \"'");
    $value = mb_strtolower($value, 'UTF-8');
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

    return trim((string)preg_replace('/\s+/u', ' ', $value));
}

/**
 * @return array{ok:bool,headers:array<int,string>,rows:array<int,array<int,string>>,details:array<int,string>}
 */
function readEbookpointCsvRows(string $filePath): array
{
    $fh = fopen($filePath, 'rb');
    if ($fh === false) {
        return ['ok' => false, 'headers' => [], 'rows' => [], 'details' => ['Nie udało się otworzyć CSV.']];
    }

    $rows = [];
    while (($data = fgetcsv($fh, 0, ';')) !== false) {
        if (!is_array($data)) {
            continue;
        }
        $rows[] = array_map(static fn($v): string => is_string($v) ? trim($v) : '', $data);
    }
    fclose($fh);

    if ($rows === []) {
        return ['ok' => false, 'headers' => [], 'rows' => [], 'details' => ['CSV jest pusty lub nieczytelny.']];
    }

    return ['ok' => true, 'headers' => [], 'rows' => $rows, 'details' => []];
}

/**
 * @param array<int,string> $headerCells
 * @return array<string,int>
 */
function mapEbookpointHeaderColumns(array $headerCells): array
{
    $mapped = [];

    foreach ($headerCells as $idx => $rawName) {
        $name = normalizeEbookpointText($rawName);
        if ($name === '') {
            continue;
        }

        if (!isset($mapped['id']) && $name === 'id') {
            $mapped['id'] = $idx;
            continue;
        }
        if (!isset($mapped['title']) && str_contains($name, 'tytul')) {
            $mapped['title'] = $idx;
            continue;
        }
        if (!isset($mapped['isbn']) && $name === 'isbn') {
            $mapped['isbn'] = $idx;
            continue;
        }
        if (!isset($mapped['units']) && $name === 'liczba') {
            $mapped['units'] = $idx;
            continue;
        }
        if (!isset($mapped['net']) && $name === 'wartosc netto') {
            $mapped['net'] = $idx;
            continue;
        }
        if (!isset($mapped['channel']) && str_contains($name, 'kanal dystrybucji')) {
            $mapped['channel'] = $idx;
            continue;
        }
        if (!isset($mapped['commission_ebookpoint']) && $name === 'prowizja ebookpoint.pl') {
            $mapped['commission_ebookpoint'] = $idx;
            continue;
        }
        if (!isset($mapped['commission_nasbi']) && $name === 'prowizja ebookpoint biblio') {
            $mapped['commission_nasbi'] = $idx;
            continue;
        }
    }

    return $mapped;
}

/**
 * @param array<int,array<int,string>> $rows
 * @param array<string> $requiredColumns
 * @return array{header_row:?int,columns:array<string,int>,header_cells:array<int,string>}
 */
function detectEbookpointHeaderRow(array $rows, array $requiredColumns): array
{
    $maxRows = min(count($rows), 220);

    for ($i = 0; $i < $maxRows; $i++) {
        $headerCells = $rows[$i];
        if (!is_array($headerCells) || $headerCells === []) {
            continue;
        }

        $columns = mapEbookpointHeaderColumns($headerCells);
        $hasRequired = true;
        foreach ($requiredColumns as $key) {
            if (!isset($columns[$key])) {
                $hasRequired = false;
                break;
            }
        }

        if ($hasRequired) {
            return [
                'header_row' => $i,
                'columns' => $columns,
                'header_cells' => $headerCells,
            ];
        }
    }

    return [
        'header_row' => null,
        'columns' => [],
        'header_cells' => [],
    ];
}

function parsePolishNumberToCents($value): ?int
{
    $v = scalarToTrimmedString($value);
    if ($v === null || $v === '') {
        return null;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);

    if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
        return null;
    }

    return decimalStringToCents($v);
}

function parsePolishNumberToUnits($value): ?int
{
    $v = scalarToTrimmedString($value);
    if ($v === null || $v === '') {
        return null;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);

    if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
        return null;
    }

    $negative = str_starts_with($v, '-');
    if ($negative) {
        $v = substr($v, 1);
    }

    $parts = explode('.', $v, 2);
    $whole = (int)$parts[0];
    $frac = isset($parts[1]) ? (string)$parts[1] : '0';
    $frac = rtrim($frac, '0');

    $units = $whole;
    if ($frac !== '') {
        $units += 1;
    }

    return $negative ? -$units : $units;
}

/**
 * @param array<int,array<int,string>> $rows
 * @param array<string,int> $columns
 * @return array{records:array<int,array<string,mixed>>,stats:array<string,int>}
 */
function parseEbookpointRows(array $rows, int $headerRow, array $columns): array
{
    $byIsbn = [];
    $rowsTotalSeen = 0;
    $rowsParsedData = 0;
    $rowsSkippedEmpty = 0;
    $rowsSkippedWithoutIsbn = 0;
    $rowsSkippedInvalidIsbn = 0;
    $rowsSkippedSummary = 0;
    $sumUnits = 0;
    $sumNetCents = 0;

    for ($i = $headerRow + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (!is_array($row)) {
            continue;
        }

        $rowsTotalSeen++;

        $joined = trim(implode('', $row));
        if ($joined === '') {
            $rowsSkippedEmpty++;
            continue;
        }

        $isbnRaw = trim((string)($row[$columns['isbn']] ?? ''));
        $unitsRaw = $row[$columns['units']] ?? '';
        $netRaw = $row[$columns['net']] ?? '';

        $netCents = parsePolishNumberToCents($netRaw) ?? 0;

        if ($isbnRaw === '') {
            // końcowa suma bywa bez ISBN i z kwotą
            if ($netCents !== 0 || scalarToTrimmedString($unitsRaw) !== null) {
                $rowsSkippedSummary++;
            } else {
                $rowsSkippedWithoutIsbn++;
            }
            continue;
        }

        $isbnNormData = normalizeIsbnRaw($isbnRaw);
        $isbnNorm = $isbnNormData['isbn_norm'] ?? null;
        if (!is_string($isbnNorm) || $isbnNorm === '') {
            $rowsSkippedInvalidIsbn++;
            continue;
        }

        $units = parsePolishNumberToUnits($unitsRaw) ?? 0;

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
        $rowsParsedData++;
    }

    return [
        'records' => array_values($byIsbn),
        'stats' => [
            'rows_total_seen' => $rowsTotalSeen,
            'rows_parsed_data' => $rowsParsedData,
            'rows_skipped_empty' => $rowsSkippedEmpty,
            'rows_skipped_without_isbn' => $rowsSkippedWithoutIsbn,
            'rows_skipped_invalid_isbn' => $rowsSkippedInvalidIsbn,
            'rows_skipped_summary' => $rowsSkippedSummary,
            'records_aggregated' => count($byIsbn),
            'units_total' => $sumUnits,
            'margin_net_total_cents' => $sumNetCents,
        ],
    ];
}

/**
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseEbookpointCsv(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return ['ok' => false, 'message' => 'Nie znaleziono pliku raportu ebookpoint.', 'details' => ['Ścieżka: ' . $filePath]];
    }

    $csv = readEbookpointCsvRows($filePath);
    if (($csv['ok'] ?? false) !== true) {
        return ['ok' => false, 'message' => 'Nie udało się odczytać CSV ebookpoint.', 'details' => $csv['details'] ?? []];
    }

    $required = ['id', 'title', 'isbn', 'units', 'net', 'commission_ebookpoint'];
    $detected = detectEbookpointHeaderRow((array)$csv['rows'], $required);
    if (($detected['header_row'] ?? null) === null) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na raport ebookpoint (brak sygnatury nagłówka CSV).',
            'details' => ['Wymagane m.in.: id, tytul, isbn, liczba, wartosc netto, prowizja ebookpoint.pl'],
        ];
    }

    $parsed = parseEbookpointRows((array)$csv['rows'], (int)$detected['header_row'], (array)$detected['columns']);

    return [
        'ok' => true,
        'message' => 'Rozpoznano raport ebookpoint CSV.',
        'details' => ['Header row: ' . ((int)$detected['header_row'] + 1)],
        'data' => [
            'records' => $parsed['records'],
            'stats' => $parsed['stats'],
        ],
    ];
}

/**
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseNasbiCsv(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return ['ok' => false, 'message' => 'Nie znaleziono pliku raportu nasbi.', 'details' => ['Ścieżka: ' . $filePath]];
    }

    $csv = readEbookpointCsvRows($filePath);
    if (($csv['ok'] ?? false) !== true) {
        return ['ok' => false, 'message' => 'Nie udało się odczytać CSV nasbi.', 'details' => $csv['details'] ?? []];
    }

    $required = ['id', 'title', 'isbn', 'units', 'net', 'commission_nasbi'];
    $detected = detectEbookpointHeaderRow((array)$csv['rows'], $required);
    if (($detected['header_row'] ?? null) === null) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na raport nasbi/ebookpoint BIBLIO (brak sygnatury nagłówka CSV).',
            'details' => ['Wymagane m.in.: id, tytul, isbn, liczba, wartosc netto, prowizja ebookpoint BIBLIO'],
        ];
    }

    $parsed = parseEbookpointRows((array)$csv['rows'], (int)$detected['header_row'], (array)$detected['columns']);

    return [
        'ok' => true,
        'message' => 'Rozpoznano raport nasbi CSV.',
        'details' => ['Header row: ' . ((int)$detected['header_row'] + 1)],
        'data' => [
            'records' => $parsed['records'],
            'stats' => $parsed['stats'],
        ],
    ];
}
