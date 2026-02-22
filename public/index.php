<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

function previousFullMonthInfo(string $tz = 'Europe/Warsaw'): array
{
    $timezone = new DateTimeZone($tz);
    $now = new DateTimeImmutable('now', $timezone);
    $prevMonth = $now->modify('first day of this month')->modify('-1 month');

    $monthNames = [
        1 => 'styczeń',
        2 => 'luty',
        3 => 'marzec',
        4 => 'kwiecień',
        5 => 'maj',
        6 => 'czerwiec',
        7 => 'lipiec',
        8 => 'sierpień',
        9 => 'wrzesień',
        10 => 'październik',
        11 => 'listopad',
        12 => 'grudzień',
    ];

    $m = (int)$prevMonth->format('n');
    $label = $monthNames[$m] . ' ' . $prevMonth->format('Y');

    return [
        'month_key' => $prevMonth->format('Y-m'),
        'label' => $label,
    ];
}

$info = previousFullMonthInfo();
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Generuj raport sprzedaży</title>

  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">
  <div class="max-w-3xl mx-auto px-6 py-16">
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-8">
      <div class="mb-6">
        <h1 class="text-2xl md:text-3xl font-semibold tracking-tight">
          Generuj raport sprzedaży za <?= htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <p class="mt-2 text-sm text-slate-500">
          Okres raportu (automatycznie): <span class="font-mono"><?= htmlspecialchars($info['month_key'], ENT_QUOTES, 'UTF-8') ?></span>
        </p>
      </div>

      <p class="text-slate-700 mb-8">
        tu będzie formularz.
      </p>

      <form id="generateForm" method="post" action="generate_month_report.php" class="space-y-4">
        <div class="flex items-center gap-3">
          <button
            id="generateBtn"
            type="submit"
            class="inline-flex items-center justify-center gap-2 rounded-lg border border-transparent bg-blue-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition disabled:opacity-60 disabled:cursor-not-allowed"
          >
            <svg id="btnSpinner" class="hidden animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <span id="btnLabel">Generuj</span>
          </button>

          <span class="text-sm text-slate-500">
            Po kliknięciu zostanie pobrany plik xlsx.
          </span>
        </div>

        <!-- Loading / progress -->
        <div id="loadingBox" class="hidden rounded-xl border border-blue-200 bg-blue-50 p-4">
          <div class="flex items-start gap-3">
            <svg class="animate-spin h-5 w-5 mt-0.5 text-blue-700 flex-shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <div>
              <p class="text-sm font-medium text-blue-900">Generowanie trwa…</p>
              <p id="loadingStep" class="text-sm text-blue-800 mt-1">Uruchamianie pipeline’u…</p>
              <p class="text-xs text-blue-700 mt-2">
                To może potrwać chwilę (pobieranie katalogu, zamówień i budowa xlsx).
              </p>
            </div>
          </div>
        </div>

        <!-- Success -->
        <div id="successBox" class="hidden rounded-xl border border-emerald-200 bg-emerald-50 p-4">
          <p id="successTitle" class="text-sm font-medium text-emerald-900">Raport gotowy.</p>
          <p id="successText" class="text-sm text-emerald-800 mt-1"></p>
          <div class="mt-3">
            <a
              id="downloadLink"
              href="#"
              class="inline-flex items-center rounded-lg border border-emerald-300 bg-white px-4 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100"
            >
              Pobierz ponownie xlsx
            </a>
          </div>
        </div>

        <!-- Error -->
        <div id="errorBox" class="hidden rounded-xl border border-red-200 bg-red-50 p-4">
          <p class="text-sm font-medium text-red-900">Nie udało się wygenerować raportu.</p>
          <p id="errorText" class="text-sm text-red-800 mt-1"></p>

          <details id="errorDetailsWrap" class="mt-3 hidden">
            <summary class="cursor-pointer text-sm font-medium text-red-900">Pokaż szczegóły techniczne</summary>
            <pre id="errorDetails" class="mt-2 text-xs bg-white border border-red-200 rounded-lg p-3 overflow-auto whitespace-pre-wrap"></pre>
          </details>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      const form = document.getElementById('generateForm');
      const btn = document.getElementById('generateBtn');
      const btnLabel = document.getElementById('btnLabel');
      const btnSpinner = document.getElementById('btnSpinner');

      const loadingBox = document.getElementById('loadingBox');
      const loadingStep = document.getElementById('loadingStep');

      const successBox = document.getElementById('successBox');
      const successTitle = document.getElementById('successTitle');
      const successText = document.getElementById('successText');
      const downloadLink = document.getElementById('downloadLink');

      const errorBox = document.getElementById('errorBox');
      const errorText = document.getElementById('errorText');
      const errorDetailsWrap = document.getElementById('errorDetailsWrap');
      const errorDetails = document.getElementById('errorDetails');

      let loadingTimer = null;
      const loadingMessages = [
        'Uruchamianie pipeline’u…',
        'Pobieranie katalogu produktów z WooCommerce…',
        'Pobieranie zamówień za poprzedni miesiąc…',
        'Budowa raportu i przygotowanie pliku xlsx…'
      ];

      function setBusy(isBusy) {
        btn.disabled = isBusy;
        btnSpinner.classList.toggle('hidden', !isBusy);
        btnLabel.textContent = isBusy ? 'Generuję…' : 'Generuj';
      }

      function hideAllAlerts() {
        loadingBox.classList.add('hidden');
        successBox.classList.add('hidden');
        errorBox.classList.add('hidden');
        errorDetailsWrap.classList.add('hidden');
        errorDetails.textContent = '';
      }

      function startLoadingTicker() {
        let i = 0;
        loadingStep.textContent = loadingMessages[0];

        loadingTimer = window.setInterval(() => {
          i = (i + 1) % loadingMessages.length;
          loadingStep.textContent = loadingMessages[i];
        }, 1200);
      }

      function stopLoadingTicker() {
        if (loadingTimer !== null) {
          window.clearInterval(loadingTimer);
          loadingTimer = null;
        }
      }

      function showError(message, details) {
        errorText.textContent = message || 'Wystąpił nieznany błąd.';
        if (details && details.length) {
          errorDetailsWrap.classList.remove('hidden');
          errorDetails.textContent = Array.isArray(details) ? details.join('\n') : String(details);
        } else {
          errorDetailsWrap.classList.add('hidden');
          errorDetails.textContent = '';
        }
        errorBox.classList.remove('hidden');
      }

      function showSuccess(payload) {
        const month = payload.month || '';
        const cached = !!payload.cached;
        const fileName = payload.filename || '';

        successTitle.textContent = cached ? 'Raport gotowy (z cache).' : 'Raport wygenerowany.';
        successText.textContent = cached
          ? `Znaleziono istniejący raport xlsx za ${month}. Rozpoczynam pobieranie.`
          : `Pomyślnie przygotowano raport xlsx za ${month}. Rozpoczynam pobieranie.`;

        if (payload.download_url) {
          downloadLink.href = payload.download_url;
          successBox.classList.remove('hidden');

          // Automatyczne pobranie
          window.location.href = payload.download_url;
        } else {
          successText.textContent += ' (Brak linku do pobrania.)';
          successBox.classList.remove('hidden');
        }
      }

      form.addEventListener('submit', async function (e) {
        e.preventDefault();

        hideAllAlerts();
        setBusy(true);
        loadingBox.classList.remove('hidden');
        startLoadingTicker();

        try {
          const response = await fetch(form.action, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'fetch'
            },
            body: new FormData(form)
          });

          const contentType = response.headers.get('content-type') || '';

          if (!contentType.includes('application/json')) {
            const text = await response.text();
            throw {
              message: 'Serwer zwrócił nieoczekiwaną odpowiedź (nie JSON).',
              details: [text.slice(0, 4000)]
            };
          }

          const data = await response.json();

          if (!response.ok || !data || data.ok !== true) {
            throw {
              message: (data && data.message) ? data.message : 'Nie udało się wygenerować raportu.',
              details: (data && data.details) ? data.details : []
            };
          }

          stopLoadingTicker();
          loadingBox.classList.add('hidden');
          showSuccess(data);
        } catch (err) {
          stopLoadingTicker();
          loadingBox.classList.add('hidden');

          const message = (err && err.message) ? err.message : 'Błąd połączenia z serwerem.';
          const details = (err && err.details) ? err.details : [];
          showError(message, details);
        } finally {
          setBusy(false);
        }
      });
    })();
  </script>

  <script src="https://cdn.jsdelivr.net/npm/preline/dist/preline.js"></script>
</body>
</html>