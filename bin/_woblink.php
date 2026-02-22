<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

function normalizeWoblinkText(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value, " \"'");
    $value = mb_strtolower($value, 'UTF-8');
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

    return trim((string)preg_replace('/\s+/u', ' ', $value));
}

function parseWoblinkUnits($value): int
{
    $v = scalarToTrimmedString($value);
    if ($v === null || $v === '') {
        return 0;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace(',', '.', $v);
    if (!is_numeric($v)) {
        return 0;
    }

    return (int)round((float)$v, 0);
}

function parseWoblinkNetToCents($value): int
{
    $v = scalarToTrimmedString($value);
    if ($v === null || $v === '') {
        return 0;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace(',', '.', $v);

    return decimalStringToCents($v);
}

/**
 * @param array<int,string> $headerCells
 * @return array<string,int>
 */
function mapWoblinkHeaderColumns(array $headerCells): array
{
    $mapped = [];

    foreach ($headerCells as $col => $rawName) {
        $name = normalizeWoblinkText($rawName);
        if ($name === '') {
            continue;
        }

        if (!isset($mapped['isbn']) && $name === 'isbn') {
            $mapped['isbn'] = $col;
            continue;
        }

        if (!isset($mapped['units']) && str_contains($name, 'transakcja zakonczona')) {
            $mapped['units'] = $col;
            continue;
        }

        if (!isset($mapped['net']) && str_contains($name, 'wynagrodzenie wydawcy netto')) {
            $mapped['net'] = $col;
            continue;
        }

        if (!isset($mapped['publisher']) && str_contains($name, 'wydawca')) {
            $mapped['publisher'] = $col;
            continue;
        }

        if (!isset($mapped['transaction_date']) && str_contains($name, 'data transakcji')) {
            $mapped['transaction_date'] = $col;
            continue;
        }

        if (!isset($mapped['title']) && $name === 'tytul') {
            $mapped['title'] = $col;
            continue;
        }

        if (!isset($mapped['author']) && str_contains($name, 'autor')) {
            $mapped['author'] = $col;
            continue;
        }

        if (!isset($mapped['price_net']) && str_contains($name, 'cena netto')) {
            $mapped['price_net'] = $col;
            continue;
        }

        if (!isset($mapped['transaction_type']) && str_contains($name, 'typ transakcji')) {
            $mapped['transaction_type'] = $col;
            continue;
        }

        if (!isset($mapped['type']) && $name === 'typ') {
            $mapped['type'] = $col;
            continue;
        }
    }

    return $mapped;
}

/**
 * @return array{header_row:?int,columns:array<string,int>,header_cells:array<int,string>,sheet_name:string}
 */
function detectWoblinkHeaderInSheet(Worksheet $sheet, int $maxRowsToScan = 220): array
{
    $highestRow = min($sheet->getHighestRow(), $maxRowsToScan);
    $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    $required = ['isbn', 'units', 'net'];

    for ($row = 1; $row <= $highestRow; $row++) {
        $headerCells = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellRef = Coordinate::stringFromColumnIndex($col) . (string)$row;
            $value = scalarToTrimmedString($sheet->getCell($cellRef)->getCalculatedValue());
            if ($value === null || $value === '') {
                continue;
            }
            $headerCells[$col] = $value;
        }

        if ($headerCells === []) {
            continue;
        }

        $columns = mapWoblinkHeaderColumns($headerCells);

        $hasRequired = true;
        foreach ($required as $key) {
            if (!isset($columns[$key])) {
                $hasRequired = false;
                break;
            }
        }

        if (!$hasRequired) {
            continue;
        }

        return [
            'header_row' => $row,
            'columns' => $columns,
            'header_cells' => $headerCells,
            'sheet_name' => (string)$sheet->getTitle(),
        ];
    }

    return [
        'header_row' => null,
        'columns' => [],
        'header_cells' => [],
        'sheet_name' => (string)$sheet->getTitle(),
    ];
}

/**
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseWoblinkXlsx(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return [
            'ok' => false,
            'message' => 'Nie znaleziono pliku raportu Woblink.',
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $ext = strtolower(pathinfo((string)($originalFilename ?? $filePath), PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        return [
            'ok' => false,
            'message' => 'Raport Woblink musi mieć rozszerzenie .xlsx.',
            'details' => ['Otrzymano rozszerzenie: ' . ($ext !== '' ? $ext : '(brak)')],
        ];
    }

    try {
        $spreadsheet = IOFactory::load($filePath);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać pliku XLSX Woblink.',
            'details' => [$e->getMessage()],
        ];
    }

    $detected = null;
    $sheet = null;

    $preferredSheet = $spreadsheet->getSheetByName('Transakcje');
    if ($preferredSheet instanceof Worksheet) {
        $candidate = detectWoblinkHeaderInSheet($preferredSheet);
        if ($candidate['header_row'] !== null) {
            $sheet = $preferredSheet;
            $detected = $candidate;
        }
    }

    if ($sheet === null || $detected === null) {
        foreach ($spreadsheet->getWorksheetIterator() as $ws) {
            if (normalizeWoblinkText($ws->getTitle()) === 'podsumowanie') {
                continue;
            }
            $candidate = detectWoblinkHeaderInSheet($ws);
            if ($candidate['header_row'] !== null) {
                $sheet = $ws;
                $detected = $candidate;
                break;
            }
        }
    }

    if (!$sheet instanceof Worksheet || !is_array($detected) || !isset($detected['header_row']) || $detected['header_row'] === null) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na raport Woblink (brak tabeli Transakcje z wymaganymi kolumnami).',
            'details' => [
                'Wymagane kolumny logiczne: ISBN, Wynagrodzenie wydawcy netto, Transakcja zakończona.',
                'Oczekiwany arkusz: Transakcje (fallback: dowolny arkusz z tym nagłówkiem).',
            ],
        ];
    }

    $headerRow = (int)$detected['header_row'];
    $columns = is_array($detected['columns'] ?? null) ? $detected['columns'] : [];
    $highestRow = $sheet->getHighestRow();
    $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    $byIsbn = [];
    $rowsTotalSeen = 0;
    $rowsData = 0;
    $rowsSkippedEmpty = 0;
    $rowsSkippedOutsideTable = 0;
    $rowsSkippedWithoutIsbn = 0;
    $rowsSkippedInvalidIsbn = 0;
    $sumUnits = 0;
    $sumNetCents = 0;

    for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
        $rowsTotalSeen++;

        $rowValues = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellRef = Coordinate::stringFromColumnIndex($col) . (string)$row;
            $rowValues[$col] = scalarToTrimmedString($sheet->getCell($cellRef)->getCalculatedValue()) ?? '';
        }

        $joined = trim(implode('', $rowValues));
        if ($joined === '') {
            $rowsSkippedEmpty++;
            continue;
        }

        $isbnRaw = trim((string)($rowValues[$columns['isbn'] ?? -1] ?? ''));
        $unitsRaw = trim((string)($rowValues[$columns['units'] ?? -1] ?? ''));
        $netRaw = trim((string)($rowValues[$columns['net'] ?? -1] ?? ''));

        if ($isbnRaw === '') {
            if ($unitsRaw === '' && $netRaw === '') {
                $rowsSkippedOutsideTable++;
            } else {
                $rowsSkippedWithoutIsbn++;
            }
            continue;
        }

        $isbnNormalized = normalizeIsbnRaw($isbnRaw);
        $isbnNorm = $isbnNormalized['isbn_norm'] ?? null;
        if (!is_string($isbnNorm) || $isbnNorm === '') {
            $rowsSkippedInvalidIsbn++;
            continue;
        }

        $units = parseWoblinkUnits($unitsRaw);
        $netCents = parseWoblinkNetToCents($netRaw);

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

    if ($rowsData === 0 || $byIsbn === []) {
        return [
            'ok' => false,
            'message' => 'Raport Woblink nie zawiera poprawnych wierszy sprzedażowych z ISBN.',
            'details' => [
                'Arkusz: ' . (string)$sheet->getTitle(),
                'Wiersze wejściowe: ' . $rowsTotalSeen,
                'Wiersze pominięte bez ISBN: ' . $rowsSkippedWithoutIsbn,
                'Wiersze pominięte nietabelaryczne: ' . $rowsSkippedOutsideTable,
            ],
        ];
    }

    ksort($byIsbn);

    return [
        'ok' => true,
        'message' => 'Raport Woblink poprawny.',
        'details' => [],
        'data' => [
            'source' => 'woblink',
            'sheet_name' => (string)$sheet->getTitle(),
            'header_row' => $headerRow,
            'headers_detected' => array_values((array)($detected['header_cells'] ?? [])),
            'rows_total_seen' => $rowsTotalSeen,
            'rows_data' => $rowsData,
            'rows_skipped_empty' => $rowsSkippedEmpty,
            'rows_skipped_outside_table' => $rowsSkippedOutsideTable,
            'rows_skipped_without_isbn' => $rowsSkippedWithoutIsbn,
            'rows_skipped_invalid_isbn' => $rowsSkippedInvalidIsbn,
            'records_aggregated' => count($byIsbn),
            'sum_units' => $sumUnits,
            'sum_margin_net_cents' => $sumNetCents,
            'records' => array_values($byIsbn),
        ],
    ];
}
