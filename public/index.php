<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

require __DIR__ . '/_web_common.php';

function previousFullMonthInfo(string $tz = 'Europe/Warsaw'): array
{
    $timezone = new DateTimeZone($tz);
    $now = new DateTimeImmutable('now', $timezone);
    $prevMonth = $now->modify('first day of this month')->modify('-1 month');

    $monthNames = [
        1 => 'styczeń', 2 => 'luty', 3 => 'marzec', 4 => 'kwiecień',
        5 => 'maj', 6 => 'czerwiec', 7 => 'lipiec', 8 => 'sierpień',
        9 => 'wrzesień', 10 => 'październik', 11 => 'listopad', 12 => 'grudzień',
    ];

    $m = (int)$prevMonth->format('n');
    return [
        'month_key' => $prevMonth->format('Y-m'),
        'label' => $monthNames[$m] . ' ' . $prevMonth->format('Y'),
    ];
}

$info = previousFullMonthInfo();
$requiredReports = requiredReportsDefinitions();
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
        <h1 class="text-2xl md:text-3xl font-semibold tracking-tight">Generuj raport sprzedaży za <?= htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="mt-2 text-sm text-slate-500">Okres raportu (automatycznie): <span class="font-mono"><?= htmlspecialchars($info['month_key'], ENT_QUOTES, 'UTF-8') ?></span></p>
      </div>

      <form id="generateForm" method="post" action="generate_month_report.php" class="space-y-5" enctype="multipart/form-data">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
          <p class="text-sm font-medium text-slate-900 mb-2">Wymagane raporty:</p>
          <ul id="requirementsList" class="list-disc list-inside text-sm text-slate-700">
            <?php foreach ($requiredReports as $report): ?>
              <li data-source-id="<?= htmlspecialchars((string)$report['source_id'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars((string)$report['label'], ENT_QUOTES, 'UTF-8') ?>
                (<?= htmlspecialchars(implode(', ', (array)$report['allowed_extensions']), ENT_QUOTES, 'UTF-8') ?>)
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="mt-2 text-xs text-slate-500">Wrzuć wszystkie raporty jednocześnie. Backend rozpozna typy i sprawdzi kompletność.</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-800 mb-2">Raporty źródłowe (wspólne pole)</label>
          <div id="dropZone" class="rounded-xl border-2 border-dashed border-slate-300 bg-white px-4 py-8 text-center transition">
            <p class="text-sm text-slate-700">Przeciągnij i upuść pliki raportów tutaj</p>
            <p class="text-xs text-slate-500 mt-1">lub</p>
            <label class="inline-flex mt-3 items-center rounded-lg border border-slate-300 bg-slate-100 px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-200 cursor-pointer">
              Wybierz pliki
              <input id="reportsInput" name="report_files[]" type="file" accept=".csv,text/csv" multiple class="hidden">
            </label>
          </div>
          <p class="mt-2 text-xs text-slate-500">Na tym etapie wymagany jest raport Virtualo (CSV).</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <p class="text-sm font-medium text-slate-900">Lista dodanych plików</p>
          <ul id="fileList" class="mt-2 text-sm text-slate-700 list-disc list-inside">
            <li class="text-slate-500">Brak plików.</li>
          </ul>
          <p id="validationStatus" class="mt-3 text-sm font-medium text-amber-700">Status: oczekiwanie na pliki.</p>
        </div>

        <div class="flex items-center gap-3">
          <button id="generateBtn" type="submit" disabled class="inline-flex items-center justify-center gap-2 rounded-lg border border-transparent bg-blue-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition disabled:opacity-60 disabled:cursor-not-allowed">
            <svg id="btnSpinner" class="hidden animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
            <span id="btnLabel">Generuj</span>
          </button>
          <span class="text-sm text-slate-500">Po kliknięciu zostanie pobrany plik xlsx.</span>
        </div>

        <div id="loadingBox" class="hidden rounded-xl border border-blue-200 bg-blue-50 p-4"><p id="loadingStep" class="text-sm text-blue-800">Uruchamianie pipeline’u…</p></div>
        <div id="successBox" class="hidden rounded-xl border border-emerald-200 bg-emerald-50 p-4"><p id="successText" class="text-sm text-emerald-800"></p><div class="mt-3"><a id="downloadLink" href="#" class="inline-flex items-center rounded-lg border border-emerald-300 bg-white px-4 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100">Pobierz ponownie xlsx</a></div></div>
        <div id="errorBox" class="hidden rounded-xl border border-red-200 bg-red-50 p-4"><p id="errorText" class="text-sm text-red-800"></p><details id="errorDetailsWrap" class="mt-3 hidden"><summary class="cursor-pointer text-sm font-medium text-red-900">Pokaż szczegóły techniczne</summary><pre id="errorDetails" class="mt-2 text-xs bg-white border border-red-200 rounded-lg p-3 overflow-auto whitespace-pre-wrap"></pre></details></div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      const requiredReports = <?= json_encode($requiredReports, JSON_UNESCAPED_UNICODE) ?>;
      const form = document.getElementById('generateForm');
      const input = document.getElementById('reportsInput');
      const dropZone = document.getElementById('dropZone');
      const fileList = document.getElementById('fileList');
      const validationStatus = document.getElementById('validationStatus');
      const btn = document.getElementById('generateBtn');
      const btnLabel = document.getElementById('btnLabel');
      const btnSpinner = document.getElementById('btnSpinner');
      const loadingBox = document.getElementById('loadingBox');
      const loadingStep = document.getElementById('loadingStep');
      const successBox = document.getElementById('successBox');
      const successText = document.getElementById('successText');
      const downloadLink = document.getElementById('downloadLink');
      const errorBox = document.getElementById('errorBox');
      const errorText = document.getElementById('errorText');
      const errorDetailsWrap = document.getElementById('errorDetailsWrap');
      const errorDetails = document.getElementById('errorDetails');
      const requirementsList = document.getElementById('requirementsList');

      const loadingMessages = [
        'Uruchamianie pipeline’u…',
        'Pobieranie katalogu produktów z WooCommerce…',
        'Pobieranie zamówień za poprzedni miesiąc…',
        'Parsowanie raportu Virtualo…',
        'Budowa raportu i przygotowanie pliku xlsx…'
      ];
      let loadingTimer = null;

      function selectedFiles() {
        return Array.from(input.files || []);
      }

      function updateRequirementsHint(files) {
        const hasCsv = files.some((f) => (f.name.split('.').pop() || '').toLowerCase() === 'csv');
        requiredReports.forEach((report) => {
          const li = requirementsList.querySelector(`[data-source-id="${report.source_id}"]`);
          if (!li) return;
          if (hasCsv) {
            li.classList.remove('text-slate-700');
            li.classList.add('text-emerald-700');
          } else {
            li.classList.remove('text-emerald-700');
            li.classList.add('text-slate-700');
          }
        });
      }

      function updateUi() {
        const files = selectedFiles();
        fileList.innerHTML = '';

        if (files.length === 0) {
          fileList.innerHTML = '<li class="text-slate-500">Brak plików.</li>';
          validationStatus.textContent = 'Status: oczekiwanie na pliki.';
          validationStatus.className = 'mt-3 text-sm font-medium text-amber-700';
          btn.disabled = true;
          updateRequirementsHint(files);
          return;
        }

        let hasCsv = false;
        files.forEach((f) => {
          const ext = (f.name.split('.').pop() || '').toLowerCase();
          const li = document.createElement('li');
          li.textContent = `${f.name} (${Math.round(f.size / 1024)} KB)`;
          if (ext === 'csv') {
            hasCsv = true;
            li.className = 'text-emerald-700';
          } else {
            li.className = 'text-slate-500';
          }
          fileList.appendChild(li);
        });

        if (hasCsv) {
          validationStatus.textContent = 'Status: wykryto plik(i) CSV, backend zweryfikuje typy i kompletność wymaganych raportów.';
          validationStatus.className = 'mt-3 text-sm font-medium text-emerald-700';
          btn.disabled = false;
        } else {
          validationStatus.textContent = 'Status: brak sensownego pliku CSV.';
          validationStatus.className = 'mt-3 text-sm font-medium text-red-700';
          btn.disabled = true;
        }

        updateRequirementsHint(files);
      }

      function setBusy(isBusy) {
        btn.disabled = isBusy || selectedFiles().length === 0;
        btnSpinner.classList.toggle('hidden', !isBusy);
        btnLabel.textContent = isBusy ? 'Generuję…' : 'Generuj';
      }

      function startLoadingTicker() {
        let i = 0;
        loadingStep.textContent = loadingMessages[0];
        loadingTimer = setInterval(() => {
          i = (i + 1) % loadingMessages.length;
          loadingStep.textContent = loadingMessages[i];
        }, 1200);
      }

      function stopLoadingTicker() {
        if (loadingTimer !== null) clearInterval(loadingTimer);
        loadingTimer = null;
      }

      function hideAllAlerts() {
        loadingBox.classList.add('hidden');
        successBox.classList.add('hidden');
        errorBox.classList.add('hidden');
        errorDetailsWrap.classList.add('hidden');
        errorDetails.textContent = '';
      }

      dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('border-blue-400', 'bg-blue-50');
      });
      dropZone.addEventListener('dragleave', () => dropZone.classList.remove('border-blue-400', 'bg-blue-50'));
      dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-blue-400', 'bg-blue-50');
        const files = e.dataTransfer.files;
        if (files && files.length > 0) {
          const transfer = new DataTransfer();
          Array.from(files).forEach((f) => transfer.items.add(f));
          input.files = transfer.files;
          updateUi();
        }
      });
      input.addEventListener('change', updateUi);
      updateUi();

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (selectedFiles().length === 0) return;

        hideAllAlerts();
        setBusy(true);
        loadingBox.classList.remove('hidden');
        startLoadingTicker();

        try {
          const response = await fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
            body: new FormData(form)
          });

          const contentType = response.headers.get('content-type') || '';
          if (!contentType.includes('application/json')) {
            throw { message: 'Serwer zwrócił nieoczekiwaną odpowiedź (nie JSON).', details: [await response.text()] };
          }

          const data = await response.json();
          if (!response.ok || !data || data.ok !== true) {
            throw { message: data?.message || 'Nie udało się wygenerować raportu.', details: data?.details || [] };
          }

          successText.textContent = `Pomyślnie przygotowano raport xlsx za ${data.month || ''}. Rozpoczynam pobieranie.`;
          if (data.download_url) {
            downloadLink.href = data.download_url;
            successBox.classList.remove('hidden');
            window.location.href = data.download_url;
          }
        } catch (err) {
          errorText.textContent = err?.message || 'Błąd połączenia z serwerem.';
          if (err?.details && err.details.length) {
            errorDetailsWrap.classList.remove('hidden');
            errorDetails.textContent = Array.isArray(err.details) ? err.details.join('\n') : String(err.details);
          }
          errorBox.classList.remove('hidden');
        } finally {
          stopLoadingTicker();
          loadingBox.classList.add('hidden');
          setBusy(false);
          updateUi();
        }
      });
    })();
  </script>
</body>
</html>
