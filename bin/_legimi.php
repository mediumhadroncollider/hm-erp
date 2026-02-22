<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

function normalizeLegimiHeader(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;
    $header = str_replace(["\r", "\n", "\t"], ' ', $header);
    $header = preg_replace('/\s+/u', ' ', $header) ?? $header;
    $header = trim($header, " \"'");
    $header = mb_strtolower($header, 'UTF-8');
    $header = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header) ?: $header;

    return trim((string)preg_replace('/\s+/u', ' ', $header));
}

function parseLegimiUnits($value): int
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

function parseLegimiNetToCents($value): int
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
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseLegimiXlsx(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return [
            'ok' => false,
            'message' => 'Nie znaleziono pliku raportu Legimi.',
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $ext = strtolower(pathinfo((string)($originalFilename ?? $filePath), PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        return [
            'ok' => false,
            'message' => 'Raport Legimi musi mieć rozszerzenie .xlsx.',
            'details' => ['Otrzymano rozszerzenie: ' . ($ext !== '' ? $ext : '(brak)')],
        ];
    }

    try {
        $spreadsheet = IOFactory::load($filePath);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać pliku XLSX Legimi.',
            'details' => [$e->getMessage()],
        ];
    }

    $sheet = $spreadsheet->getSheet(0);
    $highestRow = $sheet->getHighestRow();
    $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    $requiredHeaders = ['isbn', 'liczba', 'wynagrodzenie netto'];
    $optionalHeaders = ['tytul', 'format', 'typ sprzedazy'];

    $headerRow = null;
    $headerMap = [];
    $normalizedHeaders = [];

    for ($row = 1; $row <= $highestRow; $row++) {
        $currentHeaders = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellRef = Coordinate::stringFromColumnIndex($col) . (string)$row;
            $cell = $sheet->getCell($cellRef);
            $raw = scalarToTrimmedString($cell->getCalculatedValue());
            if ($raw === null || $raw === '') {
                continue;
            }
            $norm = normalizeLegimiHeader($raw);
            if ($norm === '') {
                continue;
            }
            $currentHeaders[$col] = $norm;
        }

        if ($currentHeaders === []) {
            continue;
        }

        $present = array_values($currentHeaders);
        $missingRequired = [];
        foreach ($requiredHeaders as $required) {
            if (!in_array($required, $present, true)) {
                $missingRequired[] = $required;
            }
        }

        if ($missingRequired === []) {
            $headerRow = $row;
            $normalizedHeaders = $currentHeaders;
            foreach ($currentHeaders as $col => $name) {
                if (!isset($headerMap[$name])) {
                    $headerMap[$name] = $col;
                }
            }
            break;
        }
    }

    if ($headerRow === null) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na raport Legimi (brak wymaganych nagłówków).',
            'details' => [
                'Wymagane nagłówki: ' . implode(', ', $requiredHeaders),
            ],
        ];
    }

    $missingOptional = [];
    foreach ($optionalHeaders as $optional) {
        if (!isset($headerMap[$optional])) {
            $missingOptional[] = $optional;
        }
    }

    $isbnCol = $headerMap['isbn'];
    $unitsCol = $headerMap['liczba'];
    $netCol = $headerMap['wynagrodzenie netto'];

    $byIsbn = [];
    $rowsInputTotal = 0;
    $rowsData = 0;
    $rowsSkipped = 0;
    $sumUnits = 0;
    $sumNetCents = 0;

    for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
        $rowsInputTotal++;

        $rowValues = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellRef = Coordinate::stringFromColumnIndex($col) . (string)$row;
            $rowValues[$col] = scalarToTrimmedString($sheet->getCell($cellRef)->getCalculatedValue()) ?? '';
        }

        $joined = trim(implode('', $rowValues));
        if ($joined === '') {
            continue;
        }

        $firstCell = normalizeLegimiHeader((string)($rowValues[1] ?? ''));
        if ($firstCell === 'razem') {
            continue;
        }

        $isbnRaw = trim((string)($rowValues[$isbnCol] ?? ''));
        $unitsRaw = trim((string)($rowValues[$unitsCol] ?? ''));
        $netRaw = trim((string)($rowValues[$netCol] ?? ''));

        if (normalizeLegimiHeader($isbnRaw) === 'razem') {
            continue;
        }

        $isbnNorm = normalizeIsbnRaw($isbnRaw)['isbn_norm'];
        if ($isbnNorm === null || ($unitsRaw === '' && $netRaw === '')) {
            $rowsSkipped++;
            continue;
        }

        $units = parseLegimiUnits($unitsRaw);
        $netCents = parseLegimiNetToCents($netRaw);

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
            'message' => 'Raport Legimi nie zawiera poprawnych wierszy sprzedażowych z ISBN.',
            'details' => [
                'Wiersze wejściowe: ' . $rowsInputTotal,
                'Wiersze pominięte: ' . $rowsSkipped,
            ],
        ];
    }

    ksort($byIsbn);

    $details = [];
    if ($missingOptional !== []) {
        $details[] = 'Brak opcjonalnych nagłówków: ' . implode(', ', $missingOptional);
    }

    return [
        'ok' => true,
        'message' => 'Raport Legimi poprawny.',
        'details' => $details,
        'data' => [
            'source' => 'legimi',
            'header_row' => $headerRow,
            'headers_detected' => array_values($normalizedHeaders),
            'rows_total' => $rowsInputTotal,
            'rows_data' => $rowsData,
            'rows_skipped' => $rowsSkipped,
            'sum_units' => $sumUnits,
            'sum_margin_net_cents' => $sumNetCents,
            'records' => array_values($byIsbn),
        ],
    ];
}
