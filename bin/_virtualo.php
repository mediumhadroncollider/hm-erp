<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function normalizeVirtualoHeader(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;
    $header = str_replace(["\r", "\n", "\t"], ' ', $header);
    $header = preg_replace('/\s+/u', ' ', $header) ?? $header;
    $header = trim($header, " \"'");
    return mb_strtolower($header, 'UTF-8');
}

function normalizeCsvTextToUtf8(string $raw): string
{
    if ($raw === '') {
        return $raw;
    }

    if (mb_check_encoding($raw, 'UTF-8')) {
        return $raw;
    }

    $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $raw);
    if (is_string($converted) && $converted !== '') {
        return $converted;
    }

    return mb_convert_encoding($raw, 'UTF-8', 'Windows-1250');
}

function parseDecimalToCentsPl(?string $value): int
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

function parseUnitsPl(?string $value): int
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
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseVirtualoCsv(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return [
            'ok' => false,
            'message' => 'Nie znaleziono pliku raportu Virtualo.',
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $ext = strtolower(pathinfo((string)($originalFilename ?? $filePath), PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        return [
            'ok' => false,
            'message' => 'Raport Virtualo musi mieć rozszerzenie .csv.',
            'details' => ['Otrzymano rozszerzenie: ' . ($ext !== '' ? $ext : '(brak)')],
        ];
    }

    $raw = file_get_contents($filePath);
    if ($raw === false) {
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać pliku raportu Virtualo.',
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $utf8 = normalizeCsvTextToUtf8($raw);

    $sampleLines = array_slice(preg_split('/\R/u', $utf8) ?: [], 0, 6);
    $sample = implode("\n", $sampleLines);
    $semicolonCount = substr_count($sample, ';');
    $commaCount = substr_count($sample, ',');
    if ($semicolonCount < 3 || $semicolonCount < $commaCount) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na CSV Virtualo z separatorem średnik (;).',
            'details' => ['W próbce wykryto zbyt mało separatorów ";".'],
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
    fwrite($stream, $utf8);
    rewind($stream);

    $headers = fgetcsv($stream, 0, ';');
    if (!is_array($headers) || $headers === []) {
        fclose($stream);
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać nagłówka CSV Virtualo.',
            'details' => ['Sprawdź format pliku i separator ";".'],
        ];
    }

    $headerMap = [];
    foreach ($headers as $idx => $h) {
        $normalized = normalizeVirtualoHeader((string)$h);
        if ($normalized !== '') {
            $headerMap[$idx] = $normalized;
        }
    }

    $findColumn = static function (array $map, array $patterns): ?int {
        foreach ($map as $idx => $name) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    return $idx;
                }
            }
        }
        return null;
    };

    $isbnCol = $findColumn($headerMap, ['/\bisbn\b/u']);
    $unitsCol = $findColumn($headerMap, [
        '/^l\.?$/u',
        '/\bl\.?\b/u',
        '/liczb[ay]\s+sprzedanych/u',
        '/egzemplar/u',
    ]);
    $marginCol = $findColumn($headerMap, [
        '/marż[ay]\s*netto/u',
        '/marza\s*netto/u',
    ]);
    $titleCol = $findColumn($headerMap, ['/tytuł/u', '/tytul/u']);

    $missing = [];
    if ($isbnCol === null) {
        $missing[] = 'ISBN';
    }
    if ($unitsCol === null) {
        $missing[] = 'L. (liczba egzemplarzy)';
    }
    if ($marginCol === null) {
        $missing[] = 'Marża netto';
    }

    if ($missing !== []) {
        fclose($stream);
        return [
            'ok' => false,
            'message' => 'Brakuje wymaganych kolumn raportu Virtualo.',
            'details' => [
                'Brakujące kolumny: ' . implode(', ', $missing),
                'Rozpoznane nagłówki: ' . implode(' | ', array_values($headerMap)),
            ],
        ];
    }

    $byIsbn = [];
    $rowsTotal = 0;
    $rowsAccepted = 0;
    $rowsSkippedAsFooter = 0;

    while (($row = fgetcsv($stream, 0, ';')) !== false) {
        $rowsTotal++;

        $isRowEmpty = true;
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                $isRowEmpty = false;
                break;
            }
        }
        if ($isRowEmpty) {
            continue;
        }

        $isbnRaw = isset($row[$isbnCol]) ? trim((string)$row[$isbnCol]) : '';
        $titleRaw = ($titleCol !== null && isset($row[$titleCol])) ? trim((string)$row[$titleCol]) : '';
        $norm = normalizeIsbnRaw($isbnRaw);
        $isbnNorm = $norm['isbn_norm'];

        if ($isbnNorm === null) {
            if ($titleRaw === '' || preg_match('/^(ebooki|pdf|audio|suma|razem)/iu', $titleRaw)) {
                $rowsSkippedAsFooter++;
                continue;
            }

            $rowsSkippedAsFooter++;
            continue;
        }

        $units = parseUnitsPl(isset($row[$unitsCol]) ? (string)$row[$unitsCol] : null);
        $marginCents = parseDecimalToCentsPl(isset($row[$marginCol]) ? (string)$row[$marginCol] : null);

        if (!isset($byIsbn[$isbnNorm])) {
            $byIsbn[$isbnNorm] = [
                'isbn_norm' => $isbnNorm,
                'isbn_raw_example' => $isbnRaw,
                'units_sold' => 0,
                'margin_net_cents' => 0,
            ];
        }

        $byIsbn[$isbnNorm]['units_sold'] += $units;
        $byIsbn[$isbnNorm]['margin_net_cents'] += $marginCents;
        $rowsAccepted++;
    }
    fclose($stream);

    return [
        'ok' => true,
        'message' => 'Raport Virtualo poprawny.',
        'details' => [],
        'data' => [
            'source' => 'virtualo',
            'rows_total' => $rowsTotal,
            'rows_accepted' => $rowsAccepted,
            'rows_skipped_footer' => $rowsSkippedAsFooter,
            'records' => array_values($byIsbn),
        ],
    ];
}

