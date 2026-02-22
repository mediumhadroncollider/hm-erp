<?php

declare(strict_types=1);

/**
 * Wspólne helpery dla v0.0.1.
 * Trzymamy je w bin/_common.php, żeby nie dublować kodu.
 */

function loadConfig(): array
{
    $path = __DIR__ . '/../config.local.php';
    if (!file_exists($path)) {
        throw new RuntimeException("Brak pliku config.local.php ({$path}).");
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('config.local.php musi zwracać tablicę.');
    }

    return $config;
}

function ensureDir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Nie udało się utworzyć katalogu: {$dir}");
    }
}

/**
 * GET do Woo API z nagłówkami (żeby czytać np. X-WP-TotalPages)
 *
 * @param array<string, scalar> $query
 * @return array{status:int, headers:array<string,string>, data:mixed, body:string}
 */
function wooApiGetWithMeta(
    string $baseUrl,
    string $consumerKey,
    string $consumerSecret,
    string $path,
    array $query = [],
    int $timeoutSeconds = 30
): array {
    $url = rtrim($baseUrl, '/') . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [];

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Nie udało się zainicjalizować cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $consumerKey . ':' . $consumerSecret,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_HEADERFUNCTION => function ($ch, string $headerLine) use (&$headers): int {
            $len = strlen($headerLine);
            $headerLine = trim($headerLine);
            if ($headerLine === '' || strpos($headerLine, ':') === false) {
                return $len;
            }
            list($name, $value) = explode(':', $headerLine, 2);
            $headers[strtolower(trim($name))] = trim($value);
            return $len;
        },
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Błąd cURL: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);

    if ($status < 200 || $status >= 300) {
        // Woo / WP często zwraca JSON z kodem błędu
        $suffix = '';
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $code = $decoded['code'] ?? null;
            $message = $decoded['message'] ?? null;
            if (is_string($code) || is_string($message)) {
                $suffix = ' [' . (string)$code . '] ' . (string)$message;
            }
        }
        throw new RuntimeException("Woo API zwróciło HTTP {$status}{$suffix}");
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Niepoprawny JSON z Woo API: ' . json_last_error_msg());
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'data' => $decoded,
        'body' => $body,
    ];
}

/**
 * @return array<int, array<string,mixed>>
 */
function wooFetchAllPages(
    string $baseUrl,
    string $consumerKey,
    string $consumerSecret,
    string $path,
    array $baseQuery,
    int $timeoutSeconds,
    string $logLabel
): array {
    $all = [];
    $page = 1;
    $totalPages = null;

    do {
        $query = $baseQuery;
        $query['page'] = $page;

        $resp = wooApiGetWithMeta(
            $baseUrl,
            $consumerKey,
            $consumerSecret,
            $path,
            $query,
            $timeoutSeconds
        );

        if (!is_array($resp['data'])) {
            throw new RuntimeException("Nieoczekiwany format odpowiedzi API ({$logLabel}, page={$page}).");
        }

        $items = $resp['data'];
        $count = count($items);

        if ($totalPages === null && isset($resp['headers']['x-wp-totalpages']) && is_numeric($resp['headers']['x-wp-totalpages'])) {
            $totalPages = (int)$resp['headers']['x-wp-totalpages'];
        }

        fwrite(STDOUT, sprintf(" - %s strona %d: %d rekordów\n", $logLabel, $page, $count));

        foreach ($items as $item) {
            if (is_array($item)) {
                $all[] = $item;
            }
        }

        if ($totalPages !== null) {
            $page++;
            if ($page > $totalPages) {
                break;
            }
            continue;
        }

        // fallback bez nagłówków
        $perPage = isset($baseQuery['per_page']) ? (int)$baseQuery['per_page'] : 100;
        $page++;
        if ($count < $perPage) {
            break;
        }
    } while (true);

    return $all;
}

function parseRequiredMonthArg(array $argv): string
{
    // wspiera --month=2025-12 oraz --month 2025-12
    $month = null;

    foreach ($argv as $i => $arg) {
        if (strpos($arg, '--month=') === 0) {
            $month = substr($arg, 8);
            break;
        }
        if ($arg === '--month' && isset($argv[$i + 1])) {
            $month = $argv[$i + 1];
            break;
        }
    }

    if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        throw new RuntimeException("Podaj miesiąc: --month=YYYY-MM (np. --month=2025-12)");
    }

    return $month;
}

/**
 * @return array{
 *   month:string,
 *   start_local:string,
 *   end_local_exclusive:string,
 *   after_iso:string,
 *   before_iso:string
 * }
 */
function monthBounds(string $month, string $timezone): array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
        throw new RuntimeException("Nieprawidłowy format miesiąca: {$month}");
    }

    $year = (int)$m[1];
    $mon = (int)$m[2];

    $tz = new DateTimeZone($timezone);

    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $mon), $tz);
    $endExclusive = $start->modify('+1 month');

    return [
        'month' => $month,
        'start_local' => $start->format('Y-m-d H:i:sP'),
        'end_local_exclusive' => $endExclusive->format('Y-m-d H:i:sP'),
        'after_iso' => $start->format(DateTimeInterface::ATOM),
        'before_iso' => $endExclusive->format(DateTimeInterface::ATOM),
    ];
}

function readJsonFile(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("Plik nie istnieje: {$path}");
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Nie udało się odczytać pliku: {$path}");
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Niepoprawny JSON w {$path}: " . json_last_error_msg());
    }

    if (!is_array($data)) {
        throw new RuntimeException("JSON w {$path} nie jest obiektem/tablicą.");
    }

    return $data;
}

function writeJsonFile(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException("Nie udało się zakodować JSON dla {$path}");
    }

    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Nie udało się zapisać pliku: {$path}");
    }
}

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
 * @return array{isbn_norm:?string,warnings:array<int,string>}
 */
function normalizeIsbnRaw(string $raw): array
{
    $warnings = [];
    $trimmed = trim($raw);

    if ($trimmed === '') {
        return ['isbn_norm' => null, 'warnings' => ['Pusty ISBN.']];
    }

    $alnum = strtoupper((string)preg_replace('/[^0-9Xx]/', '', $trimmed));
    $digits = (string)preg_replace('/\D/', '', $trimmed);

    if ($alnum === '') {
        return ['isbn_norm' => null, 'warnings' => ["ISBN zawiera tylko znaki nieistotne (raw={$raw})."]];
    }

    if (strpos($alnum, 'X') !== false) {
        $warnings[] = 'ISBN zawiera X (ISBN-10); porównanie po digits-only.';
    }

    $len = strlen($digits);
    if ($len !== 10 && $len !== 13) {
        $warnings[] = "Nietypowa długość ISBN po normalizacji: {$len} (raw={$raw})";
    }

    return ['isbn_norm' => ($digits !== '' ? $digits : null), 'warnings' => $warnings];
}

/**
 * Konwersja kwoty tekstowej ("19.99") do groszy (int)
 */
function decimalStringToCents(?string $amount): int
{
    $amount = $amount ?? '0';
    $amount = trim($amount);

    if ($amount === '') {
        return 0;
    }

    // Woo zwykle daje kropkę, ale zabezpieczenie i na przecinek
    $amount = str_replace(',', '.', $amount);

    $sign = 1;
    if ($amount[0] === '-') {
        $sign = -1;
        $amount = substr($amount, 1);
    }

    if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
        // fallback "best effort"
        $f = (float)$amount;
        return (int)round($f * 100, 0);
    }

    $parts = explode('.', $amount, 2);
    $whole = (int)$parts[0];
    $frac = isset($parts[1]) ? $parts[1] : '0';
    $frac = substr(str_pad($frac, 2, '0'), 0, 2);

    return $sign * ($whole * 100 + (int)$frac);
}

function centsToDecimalString(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    $whole = intdiv($cents, 100);
    $frac = $cents % 100;
    return sprintf('%s%d.%02d', $sign, $whole, $frac);
}

function latestFileMatching(string $dir, string $suffix): ?string
{
    if (!is_dir($dir)) {
        return null;
    }

    $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*' . $suffix);
    if ($files === false || $files === []) {
        return null;
    }

    sort($files, SORT_STRING);
    return end($files) ?: null;
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function writeAssocCsv(string $path, array $rows): void
{
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException("Nie udało się otworzyć CSV do zapisu: {$path}");
    }

    if ($rows === []) {
        fclose($fh);
        return;
    }

    $headers = array_keys($rows[0]);
    fputcsv($fh, $headers);

    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $h) {
            $v = $row[$h] ?? null;
            if (is_array($v)) {
                $line[] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif ($v === null) {
                $line[] = '';
            } elseif (is_bool($v)) {
                $line[] = $v ? '1' : '0';
            } else {
                $line[] = (string)$v;
            }
        }
        fputcsv($fh, $line);
    }

    fclose($fh);
}