<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function normalizePublioHeader(string $header): string
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
function readPublioCsvWithUtf8Fallback(string $filePath): array
{
    $raw = file_get_contents($filePath);
    if (!is_string($raw)) {
        throw new RuntimeException('Nie udało się odczytać pliku CSV Publio.');
    }

    if (trim($raw) === '') {
        throw new RuntimeException('CSV Publio jest pusty.');
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

function parsePublioUnits(?string $value): int
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

function parsePublioNetToCents(?string $value): int
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
function validateAndParsePublioCsv(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return [
            'ok' => false,
            'message' => 'Nie znaleziono pliku raportu Publio.',
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $ext = strtolower(pathinfo((string)($originalFilename ?? $filePath), PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return [
            'ok' => false,
            'message' => 'Raport Publio musi mieć rozszerzenie .csv.',
            'details' => ['Otrzymano rozszerzenie: ' . ($ext !== '' ? $ext : '(brak)')],
        ];
    }

    try {
        $csvData = readPublioCsvWithUtf8Fallback($filePath);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać raportu Publio.',
            'details' => [$e->getMessage()],
        ];
    }

    $sampleLines = array_slice(preg_split('/\R/u', $csvData['utf8']) ?: [], 0, 6);
    $sample = implode("\n", $sampleLines);
    if (substr_count($sample, ';') < 3) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na CSV Publio z separatorem średnik (;).',
            'details' => ['W próbce nagłówka wykryto zbyt mało separatorów.'],
        ];
    }

    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        return [
            'ok' => false,
            'message' => 'Błąd techniczny parsera CSV Publio (temp stream).',
            'details' => [],
        ];
    }

    fwrite($stream, $csvData['utf8']);
    rewind($stream);

    $headersRaw = fgetcsv($stream, 0, ';');
    if (!is_array($headersRaw) || $headersRaw === []) {
        fclose($stream);
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać nagłówka CSV Publio.',
            'details' => [],
        ];
    }

    $headers = [];
    foreach ($headersRaw as $idx => $header) {
        $headers[$idx] = normalizePublioHeader((string)$header);
    }

    $findColumn = static function (array $patterns) use ($headers): ?int {
        foreach ($headers as $idx => $name) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    return $idx;
                }
            }
        }

        return null;
    };

    $isbnCol = $findColumn([
        '/isbn\s*\/\s*issn\s*\/\s*ismn/u',
        '/\bisbn\b/u',
    ]);
    $unitsCol = $findColumn([
        '/liczba\s+sprzedanych\s+egzemplarzy/u',
    ]);
    $netCol = $findColumn([
        '/kwota\s+dla\s+wydawcy\s+netto/u',
    ]);

    $missing = [];
    if ($isbnCol === null) {
        $missing[] = 'ISBN/ISSN/ISMN';
    }
    if ($unitsCol === null) {
        $missing[] = 'Liczba sprzedanych egzemplarzy';
    }
    if ($netCol === null) {
        $missing[] = 'Kwota dla wydawcy netto';
    }

    if ($missing !== []) {
        fclose($stream);
        return [
            'ok' => false,
            'message' => 'Brakuje wymaganych kolumn raportu Publio.',
            'details' => [
                'Brakujące: ' . implode(', ', $missing),
                'Wykryte nagłówki: ' . implode(' | ', array_map(static fn($h): string => trim((string)$h), $headersRaw)),
            ],
        ];
    }

    $rowsTotal = 1;
    $rowsData = 0;
    $rowsSkipped = 0;
    $rowsSales = 0;
    $sumUnits = 0;
    $sumMarginNetCents = 0;
    $byIsbn = [];

    while (($row = fgetcsv($stream, 0, ';')) !== false) {
        $rowsTotal++;

        if (!is_array($row)) {
            $rowsSkipped++;
            continue;
        }

        // Publio często ma końcowy pusty field (wiersz kończy się średnikiem)
        if (count($row) > count($headersRaw)) {
            $row = array_slice($row, 0, count($headersRaw));
        }

        $rowTrimmed = array_map(static fn($v): string => trim((string)$v), $row);
        $joined = implode('', $rowTrimmed);
        if ($joined === '') {
            continue;
        }

        $rowsData++;

        $isbnRaw = isset($row[$isbnCol]) ? trim((string)$row[$isbnCol]) : '';
        $unitsRaw = isset($row[$unitsCol]) ? trim((string)$row[$unitsCol]) : '';
        $netRaw = isset($row[$netCol]) ? trim((string)$row[$netCol]) : '';

        $isbnNorm = normalizeIsbnRaw($isbnRaw)['isbn_norm'];
        if ($isbnNorm === null || ($unitsRaw === '' && $netRaw === '')) {
            $rowsSkipped++;
            continue;
        }

        $units = parsePublioUnits($unitsRaw);
        $netCents = parsePublioNetToCents($netRaw);

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

    fclose($stream);

    if ($rowsSales === 0 || $byIsbn === []) {
        return [
            'ok' => false,
            'message' => 'Brak poprawnych wierszy sprzedażowych w raporcie Publio.',
            'details' => [
                'Sprawdź, czy plik zawiera dane sprzedażowe oraz poprawne ISBN i kwoty netto.',
            ],
        ];
    }

    ksort($byIsbn);

    return [
        'ok' => true,
        'message' => 'Raport Publio poprawny.',
        'details' => [
            'Plik: ' . ($originalFilename ?? basename($filePath)),
            'Encoding: ' . $csvData['encoding'],
            'Wiersze: ' . $rowsTotal,
            'Wiersze danych: ' . $rowsData,
            'Wiersze sprzedażowe: ' . $rowsSales,
            'Wiersze pominięte: ' . $rowsSkipped,
            'ISBN (agregacja): ' . count($byIsbn),
        ],
        'data' => [
            'encoding' => $csvData['encoding'],
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
