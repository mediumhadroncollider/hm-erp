# HM ERP

Prosta aplikacja PHP do generowania miesięcznych raportów sprzedaży publikacji cyfrowych na podstawie danych z kanałów sprzedaży oraz eksportu wyniku do pliku XLSX.

## Status

Projekt w fazie MVP (`v0.0.x`).

Aktualny zakres:
- pobranie katalogu produktów z WooCommerce,
- pobranie zamówień za poprzedni pełny miesiąc,
- agregacja i zero-fill po ISBN,
- eksport uproszczonego raportu miesięcznego do XLSX,
- proste UI z jednym wspólnym polem uploadu raportów + walidacja wymaganego raportu Virtualo (CSV).

## Wymagania

- PHP **8.4+** (CLI i built-in server)
- Composer
- rozszerzenia PHP:
  - `zip`
  - `gd`
  - `mbstring`
  - `xml`

## Instalacja

```bash
composer install
```

## Konfiguracja

1. Skopiuj plik konfiguracyjny:
```bash
cp config.local_template.php config.local.php
```

2. Uzupełnij `config.local.php`:
- dane dostępowe do API WooCommerce,
- ścieżki lokalne (`data/`, szablon XLSX, itp.),
- opcjonalnie ścieżkę do binarki PHP CLI.

> `config.local.php` zawiera sekrety i **nie powinien być commitowany**.

## Uruchomienie (lokalnie)

```bash
php8.4 -S 127.0.0.1:8000 -t public
```

Następnie otwórz w przeglądarce:

- `http://127.0.0.1:8000`

## Jak to działa (skrót)

UI uruchamia pipeline składający się z kroków CLI:

1. pobranie katalogu produktów,
2. pobranie zamówień za miesiąc,
3. budowa danych raportowych (JSON, zero-fill),
4. walidacja i parsowanie uploadu Virtualo,
5. eksport raportu do XLSX.

Raport jest generowany od zera przy każdym kliknięciu „Generuj” (brak cache gotowego XLSX).

## Struktura projektu (skrót)

- `public/` — UI i endpointy HTTP
- `bin/` — skrypty CLI pipeline’u (ingest / build / export)
- `data/` — dane robocze, snapshoty, wygenerowane raporty (lokalne)
- `templates/` — lokalne szablony XLSX
- `config.local.php` — lokalna konfiguracja (sekrety, ścieżki)

## Najczęstsze problemy

### `Could not open input file ...`
Brakuje wskazanego skryptu w `bin/` albo endpoint odwołuje się do starej nazwy pliku po refaktorze.

### `ext-zip` / `ext-gd` missing
Doinstaluj brakujące rozszerzenia PHP dla wersji, której używasz z Composerem i CLI.

### Kliknięcie „Generuj” nic nie robi
Sprawdź:
- czy serwer został uruchomiony z poprawnym `-t public`,
- czy endpointy w `public/index.php` mają aktualne nazwy,
- logi odpowiedzi w DevTools (Network / Console).

## Bezpieczeństwo

- Nie commituj:
  - `config.local.php`
  - `data/`
  - `vendor/`
  - lokalnych plików XLSX/CSV z danymi
- Używaj placeholderów w `config.local_template.php` (bez realnych kluczy API)

## Roadmap (krótko)

- kolejne parsery źródeł danych (CSV/XLSX),
- wspólny raport miesięczny z wielu kanałów,
- raport roczny,
- dashboard danych historycznych.
