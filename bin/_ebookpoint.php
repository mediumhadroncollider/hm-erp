<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function normalizeEbookpointHeader(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;
    $header = str_replace(["\r", "\n", "\t"], ' ', $header);
    $header = preg_replace('/\s+/u', ' ', $header) ?? $header;
    $header = trim($header, " \"'");
    $header = mb_strtolower($header, 'UTF-8');
    $header = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header) ?: $header;

    return trim((string)preg_replace('/\s+/u', ' ', $header));
}

/**
 * @return array{utf8:string,encoding:string}
 */
function readEbookpointCsvWithUtf8Fallback(string $filePath): array
{
    $raw = file_get_contents($filePath);
    if (!is_string($raw)) {
        throw new RuntimeException('Nie udało się odczytać pliku CSV ebookpoint/nasbi.');
    }

    if (trim($raw) === '') {
        throw new RuntimeException('CSV ebookpoint/nasbi jest pusty.');
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

    return ['utf8' => $utf8, 'encoding' => $encoding];
}

function parsePolishDecimalToCents(?string $value): int
{
    if ($value === null) {
        return 0;
    }

    $v = trim($value);
    if ($v === '') {
        return 0;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);

    return decimalStringToCents($v);
}

function parsePolishUnits(?string $value): int
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

/**
 * @param array<int,string> $requiredCommonHeaders
 * @param array<int,string> $exclusiveHeaderAlternatives
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseEbookpointFamilyCsv(
    string $filePath,
    ?string $originalFilename,
    string $sourceId,
    string $sourceLabel,
    array $requiredCommonHeaders,
    array $exclusiveHeaderAlternatives
): array {
    if (!is_file($filePath)) {
        return [
            'ok' => false,
            'message' => "Nie znaleziono pliku raportu {$sourceLabel}.",
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $ext = strtolower(pathinfo((string)($originalFilename ?? $filePath), PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return [
            'ok' => false,
            'message' => "Raport {$sourceLabel} musi mieć rozszerzenie .csv.",
            'details' => ['Otrzymano rozszerzenie: ' . ($ext !== '' ? $ext : '(brak)')],
        ];
    }

    try {
        $csvData = readEbookpointCsvWithUtf8Fallback($filePath);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => "Nie udało się odczytać raportu {$sourceLabel}.",
            'details' => [$e->getMessage()],
        ];
    }

    $lines = preg_split('/\R/u', $csvData['utf8']) ?: [];
    $headerRow = null;
    $headersRaw = [];
    $headersNormalized = [];

    foreach ($lines as $lineNo => $line) {
        $lineTrimmed = trim((string)$line);
        if ($lineTrimmed === '' || substr_count($lineTrimmed, ';') < 2) {
            continue;
        }

        $rawHeaders = str_getcsv($lineTrimmed, ';');
        if (!is_array($rawHeaders) || $rawHeaders === []) {
            continue;
        }

        $normalized = array_map(static fn($v): string => normalizeEbookpointHeader((string)$v), $rawHeaders);
        $headerSet = array_fill_keys(array_values($normalized), true);

        $hasCommon = true;
        foreach ($requiredCommonHeaders as $requiredHeader) {
            if (!isset($headerSet[$requiredHeader])) {
                $hasCommon = false;
                break;
            }
        }

        if (!$hasCommon) {
            continue;
        }

        $matchedExclusive = false;
        foreach ($exclusiveHeaderAlternatives as $exclusiveHeader) {
            if (isset($headerSet[$exclusiveHeader])) {
                $matchedExclusive = true;
                break;
            }
        }

        if (!$matchedExclusive) {
            continue;
        }

        $headerRow = $lineNo;
        $headersRaw = $rawHeaders;
        $headersNormalized = $normalized;
        break;
    }

    if ($headerRow === null) {
        return [
            'ok' => false,
            'message' => "Nie wykryto tabeli CSV {$sourceLabel} (nagłówka po sygnaturze kolumn).",
            'details' => [
                'Wymagane kolumny wspólne: ' . implode(', ', $requiredCommonHeaders),
                'Wymagana kolumna rozróżniająca: ' . implode(' / ', $exclusiveHeaderAlternatives),
            ],
        ];
    }

    $findColumn = static function (array $patterns) use ($headersNormalized): ?int {
        foreach ($headersNormalized as $idx => $name) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    return $idx;
                }
            }
        }

        return null;
    };

    $isbnCol = $findColumn(['/\bisbn\b/u']);
    $unitsCol = $findColumn(['/\bliczba\b/u']);
    $netCol = $findColumn(['/wartosc\s+netto/u']);

    $missing = [];
    if ($isbnCol === null) {
        $missing[] = 'isbn';
    }
    if ($unitsCol === null) {
        $missing[] = 'liczba';
    }
    if ($netCol === null) {
        $missing[] = 'wartosc netto';
    }

    if ($missing !== []) {
        return [
            'ok' => false,
            'message' => "Brakuje wymaganych kolumn raportu {$sourceLabel}.",
            'details' => [
                'Brakujące: ' . implode(', ', $missing),
                'Wykryte nagłówki: ' . implode(' | ', array_map(static fn($h): string => trim((string)$h), $headersRaw)),
            ],
        ];
    }

    $rowsTotal = 0;
    $rowsData = 0;
    $rowsSkipped = 0;
    $rowsSales = 0;
    $sumUnits = 0;
    $sumMarginNetCents = 0;
    $byIsbn = [];

    for ($lineNo = $headerRow + 1; $lineNo < count($lines); $lineNo++) {
        $rowsTotal++;
        $line = (string)$lines[$lineNo];
        $lineTrimmed = trim($line);

        if ($lineTrimmed === '') {
            continue;
        }

        $row = str_getcsv($line, ';');
        if (!is_array($row) || $row === []) {
            $rowsSkipped++;
            continue;
        }

        if (count($row) < count($headersRaw)) {
            $row = array_pad($row, count($headersRaw), '');
        }
        if (count($row) > count($headersRaw)) {
            $row = array_slice($row, 0, count($headersRaw));
        }

        $rowsData++;

        $isbnRaw = isset($row[$isbnCol]) ? trim((string)$row[$isbnCol]) : '';
        $unitsRaw = isset($row[$unitsCol]) ? trim((string)$row[$unitsCol]) : '';
        $netRaw = isset($row[$netCol]) ? trim((string)$row[$netCol]) : '';

        $isbnNorm = normalizeIsbnRaw($isbnRaw)['isbn_norm'];

        // Odrzuć końcowy wiersz sumy (często puste ISBN + kwota netto)
        if ($isbnNorm === null) {
            $rowsSkipped++;
            continue;
        }

        $units = parsePolishUnits($unitsRaw);
        $netCents = parsePolishDecimalToCents($netRaw);

        if ($units <= 0 && $netCents === 0) {
            $rowsSkipped++;
            continue;
        }

        $rowsSales++;

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
        $sumMarginNetCents += $netCents;
    }

    if ($rowsSales === 0 || $byIsbn === []) {
        return [
            'ok' => false,
            'message' => "Brak poprawnych wierszy sprzedażowych w raporcie {$sourceLabel}.",
            'details' => ['Sprawdź, czy plik zawiera poprawne ISBN oraz kolumny liczba/wartość netto.'],
        ];
    }

    ksort($byIsbn);

    return [
        'ok' => true,
        'message' => "Raport {$sourceLabel} poprawny.",
        'details' => [
            'Plik: ' . ($originalFilename ?? basename($filePath)),
            'Source: ' . $sourceId,
            'Encoding: ' . $csvData['encoding'],
            'Wiersz nagłówka: ' . ($headerRow + 1),
            'Wiersze po nagłówku: ' . $rowsTotal,
            'Wiersze danych: ' . $rowsData,
            'Wiersze sprzedażowe: ' . $rowsSales,
            'Wiersze pominięte: ' . $rowsSkipped,
            'ISBN (agregacja): ' . count($byIsbn),
        ],
        'data' => [
            'source' => $sourceId,
            'encoding' => $csvData['encoding'],
            'header_row' => $headerRow + 1,
            'rows_total' => $rowsTotal,
            'rows_data' => $rowsData,
            'rows_skipped' => $rowsSkipped,
            'rows_sales' => $rowsSales,
            'sum_units' => $sumUnits,
            'sum_margin_net_cents' => $sumMarginNetCents,
            'records' => array_values($byIsbn),
        ],
    ];
}

/**
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseEbookpointCsv(string $filePath, ?string $originalFilename = null): array
{
    return validateAndParseEbookpointFamilyCsv(
        $filePath,
        $originalFilename,
        'ebookpoint',
        'ebookpoint',
        ['id', 'tytul', 'isbn', 'liczba', 'wartosc netto', 'kanal dystrybucji'],
        ['prowizja ebookpoint.pl']
    );
}

/**
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseNasbiCsv(string $filePath, ?string $originalFilename = null): array
{
    return validateAndParseEbookpointFamilyCsv(
        $filePath,
        $originalFilename,
        'nasbi',
        'ebookpoint BIBLIO / nasbi',
        ['id', 'tytul', 'isbn', 'liczba', 'wartosc netto'],
        ['prowizja ebookpoint biblio']
    );
}
