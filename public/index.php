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
        <h1 class="text-2xl md:text-3xl font-semibold tracking-tight">
          Generuj raport sprzedaży za <?= htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <p class="mt-2 text-sm text-slate-500">
          Okres raportu (automatycznie): <span class="font-mono"><?= htmlspecialchars($info['month_key'], ENT_QUOTES, 'UTF-8') ?></span>
        </p>
      </div>

      <form id="generateForm" method="post" action="generate_month_report.php" class="space-y-5" enctype="multipart/form-data">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
          <p class="text-sm font-medium text-slate-900 mb-2">Wymagane raporty do uploadu:</p>
          <ul class="list-disc list-inside text-sm text-slate-700">
            <?php foreach ($requiredReports as $report): ?>
              <li>
                <?= htmlspecialchars((string)$report['label'], ENT_QUOTES, 'UTF-8') ?>
                (<?= htmlspecialchars(implode(', ', (array)$report['allowed_extensions']), ENT_QUOTES, 'UTF-8') ?>)
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="mt-2 text-xs text-slate-500">Przycisk „Generuj” aktywuje się po dodaniu sensownego pliku (np. CSV), a pełna walidacja wymaganych raportów jest wykonywana po stronie backendu.</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-800 mb-2">Raporty źródłowe (wspólny upload) <span class="text-red-600">*</span></label>
          <div id="dropZone" class="rounded-xl border-2 border-dashed border-slate-300 bg-white px-4 py-8 text-center transition">
            <p class="text-sm text-slate-700">Przeciągnij i upuść wszystkie raporty tutaj</p>
            <p class="text-xs text-slate-500 mt-1">lub</p>
            <label class="inline-flex mt-3 items-center rounded-lg border border-slate-300 bg-slate-100 px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-200 cursor-pointer">
              Wybierz pliki
              <input id="reportsInput" name="report_files[]" type="file" multiple accept=".csv,text/csv" class="hidden">
            </label>
          </div>
          <p class="mt-2 text-xs text-slate-500">Dozwolone: raporty wymagane przez backend (obecnie: CSV Virtualo).</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
          <p class="text-sm font-medium text-slate-900">Lista dodanych plików i rozpoznanie</p>
          <ul id="fileList" class="mt-2 text-sm text-slate-700 list-disc list-inside">
            <li class="text-slate-500">Brak pliku.</li>
          </ul>
          <ul id="requiredStatusList" class="mt-3 text-sm text-slate-700 space-y-1"></ul>
          <p id="validationStatus" class="mt-3 text-sm font-medium text-amber-700">Status walidacji: oczekiwanie na pliki.</p>
        </div>

        <div class="flex items-center gap-3">
          <button
            id="generateBtn"
            type="submit"
            disabled
            class="inline-flex items-center justify-center gap-2 rounded-lg border border-transparent bg-blue-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition disabled:opacity-60 disabled:cursor-not-allowed"
          >
            <svg id="btnSpinner" class="hidden animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <span id="btnLabel">Generuj</span>
          </button>

          <span class="text-sm text-slate-500">Po kliknięciu zostanie pobrany plik xlsx.</span>
        </div>

        <div id="loadingBox" class="hidden rounded-xl border border-blue-200 bg-blue-50 p-4">
          <div class="flex items-start gap-3">
            <svg class="animate-spin h-5 w-5 mt-0.5 text-blue-700 flex-shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <div>
              <p class="text-sm font-medium text-blue-900">Generowanie trwa…</p>
              <p id="loadingStep" class="text-sm text-blue-800 mt-1">Uruchamianie pipeline’u…</p>
            </div>
          </div>
        </div>

        <div id="successBox" class="hidden rounded-xl border border-emerald-200 bg-emerald-50 p-4">
          <p id="successTitle" class="text-sm font-medium text-emerald-900">Raport gotowy.</p>
          <p id="successText" class="text-sm text-emerald-800 mt-1"></p>
          <div class="mt-3">
            <a id="downloadLink" href="#" class="inline-flex items-center rounded-lg border border-emerald-300 bg-white px-4 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100">Pobierz ponownie xlsx</a>
          </div>
        </div>

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
      const requiredReports = <?= json_encode($requiredReports, JSON_UNESCAPED_UNICODE) ?>;
      const form = document.getElementById('generateForm');
      const input = document.getElementById('reportsInput');
      const dropZone = document.getElementById('dropZone');
      const fileList = document.getElementById('fileList');
      const requiredStatusList = document.getElementById('requiredStatusList');
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

      let loadingTimer = null;
      const loadingMessages = [
        'Uruchamianie pipeline’u…',
        'Pobieranie katalogu produktów z WooCommerce…',
        'Pobieranie zamówień za poprzedni miesiąc…',
        'Parsowanie raportu Virtualo…',
        'Budowa raportu i przygotowanie pliku xlsx…'
      ];

      const FILE_READ_LIMIT_BYTES = 128 * 1024;
      const reportSignatures = {
        virtualo: [
          ['isbn'],
          ['formaty'],
          ['typ'],
          ['l.', 'l'],
          ['sprzedaz netto'],
          ['marza netto']
        ],
        empik: [
          ['isbn'],
          ['format'],
          ['model rozliczenia'],
          ['prog rozliczeniowy'],
          ['ilosc'],
          ['wynagrodzenie wyd. netto']
        ]
      };

      let analysisToken = 0;

      function getFileExtension(fileName) {
        return (String(fileName).split('.').pop() || '').toLowerCase();
      }

      function normalizeHeaderValue(value) {
        return String(value || '')
          .replace(/^\uFEFF/, '')
          .replace(/[\r\n\t]+/g, ' ')
          .trim()
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .replace(/\s+/g, ' ');
      }

      function parseCsvLine(line, separator) {
        const out = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
          const ch = line[i];
          if (ch === '"') {
            const next = line[i + 1];
            if (inQuotes && next === '"') {
              current += '"';
              i++;
            } else {
              inQuotes = !inQuotes;
            }
            continue;
          }
          if (ch === separator && !inQuotes) {
            out.push(current);
            current = '';
            continue;
          }
          current += ch;
        }
        out.push(current);
        return out;
      }

      function detectSeparator(line) {
        const semicolons = (line.match(/;/g) || []).length;
        const commas = (line.match(/,/g) || []).length;
        return semicolons >= commas ? ';' : ',';
      }

      function findFirstNonEmptyLine(text) {
        const lines = String(text || '').split(/\r\n|\n|\r/);
        for (const line of lines) {
          if (line.trim() !== '') {
            return line;
          }
        }
        return '';
      }

      function decodeBuffer(buffer) {
        const decoders = [];
        try {
          decoders.push(new TextDecoder('utf-8', { fatal: true }));
        } catch (_) {
          decoders.push(new TextDecoder('utf-8'));
        }
        try {
          decoders.push(new TextDecoder('windows-1250', { fatal: false }));
        } catch (_) {
          // browser without windows-1250 support
        }

        for (const decoder of decoders) {
          try {
            return decoder.decode(buffer);
          } catch (_) {
            // try next decoder
          }
        }

        return new TextDecoder().decode(buffer);
      }

      function readFileChunk(file, maxBytes) {
        return new Promise((resolve, reject) => {
          const reader = new FileReader();
          const blob = file.slice(0, maxBytes);
          reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(reader.error || new Error('Nie udało się odczytać pliku.'));
          reader.readAsArrayBuffer(blob);
        });
      }

      function signatureMatches(headerSet, signature) {
        return signature.every((alternatives) => alternatives.some((candidate) => headerSet.has(candidate)));
      }

      async function analyzeCsvFile(file) {
        const buffer = await readFileChunk(file, FILE_READ_LIMIT_BYTES);
        const text = decodeBuffer(buffer);
        const headerLine = findFirstNonEmptyLine(text);
        if (!headerLine) {
          return { kind: 'unknown', message: '⚠️ CSV nierozpoznany (pusty nagłówek).' };
        }

        const separator = detectSeparator(headerLine);
        const rawHeaders = parseCsvLine(headerLine, separator);
        const headers = rawHeaders.map(normalizeHeaderValue).filter((h) => h.length > 0);
        const headerSet = new Set(headers);

        const matches = [];
        if (signatureMatches(headerSet, reportSignatures.virtualo)) {
          matches.push('virtualo');
        }
        if (signatureMatches(headerSet, reportSignatures.empik)) {
          matches.push('empik');
        }

        if (matches.length === 1) {
          const kind = matches[0];
          return {
            kind,
            message: kind === 'virtualo' ? '✅ rozpoznano jako Virtualo' : '✅ rozpoznano jako Empik'
          };
        }
        if (matches.length > 1) {
          return { kind: 'ambiguous', message: '⚠️ niejednoznaczny CSV (pasuje do wielu sygnatur).' };
        }

        return { kind: 'unknown', message: '⚠️ CSV nierozpoznany (pełna walidacja po stronie backendu).' };
      }

      async function analyzeFile(file) {
        const ext = getFileExtension(file.name);
        if (ext !== 'csv') {
          return { kind: 'unsupported', message: '⚠️ nierozpoznany typ pliku', ext };
        }

        try {
          return await analyzeCsvFile(file);
        } catch (e) {
          return { kind: 'unknown', message: '⚠️ CSV nierozpoznany (błąd odczytu nagłówka).' };
        }
      }

      async function updateUiForFile() {
        const currentToken = ++analysisToken;
        const files = Array.from(input.files || []);
        fileList.innerHTML = '';
        requiredStatusList.innerHTML = '';

        if (files.length === 0) {
          const li = document.createElement('li');
          li.className = 'text-slate-500';
          li.textContent = 'Brak plików.';
          fileList.appendChild(li);

          requiredReports.forEach((report) => {
            const statusLi = document.createElement('li');
            statusLi.className = 'text-amber-700';
            statusLi.textContent = `⏳ ${report.label}: oczekiwanie`;
            requiredStatusList.appendChild(statusLi);
          });

          validationStatus.textContent = 'Status walidacji: oczekiwanie na pliki.';
          validationStatus.className = 'mt-3 text-sm font-medium text-amber-700';
          btn.disabled = true;
          return;
        }

        const analyzed = await Promise.all(files.map(async (file) => ({ file, analysis: await analyzeFile(file) })));
        if (currentToken !== analysisToken) {
          return;
        }

        analyzed.forEach(({ file, analysis }) => {
          const li = document.createElement('li');
          li.textContent = `${file.name} (${Math.round(file.size / 1024)} KB) → ${analysis.message}`;
          fileList.appendChild(li);
        });

        let allRequiredMatched = true;
        requiredReports.forEach((report) => {
          const matched = analyzed.find(({ analysis }) => analysis.kind === report.source_id);
          const statusLi = document.createElement('li');

          if (matched) {
            statusLi.className = 'text-emerald-700';
            statusLi.textContent = `✅ ${report.label}: ${matched.file.name}`;
          } else {
            allRequiredMatched = false;
            statusLi.className = 'text-red-700';
            statusLi.textContent = `❌ ${report.label}: brak pliku rozpoznanego po nagłówkach`;
          }

          requiredStatusList.appendChild(statusLi);
        });

        const hasCsv = files.some((file) => (getFileExtension(file.name) === 'csv'));
        if (!hasCsv) {
          validationStatus.textContent = 'Status walidacji: brak sensownego pliku wejściowego (np. .csv).';
          validationStatus.className = 'mt-3 text-sm font-medium text-red-700';
          btn.disabled = true;
          return;
        }

        const hasUncertainCsv = analyzed.some(({ analysis }) => analysis.kind === 'unknown' || analysis.kind === 'ambiguous');

        if (allRequiredMatched) {
          validationStatus.textContent = 'Status walidacji: komplet wstępnie rozpoznany po nagłówkach CSV. Backend pozostaje źródłem prawdy.';
          validationStatus.className = 'mt-3 text-sm font-medium text-emerald-700';
        } else if (hasUncertainCsv) {
          validationStatus.textContent = 'Status walidacji: wstępne rozpoznanie po nagłówkach jest niepełne/niejednoznaczne; pełna klasyfikacja nastąpi po stronie backendu.';
          validationStatus.className = 'mt-3 text-sm font-medium text-amber-700';
        } else {
          validationStatus.textContent = 'Status walidacji: częściowy komplet po nagłówkach CSV — backend może odrzucić brakujące wymagania.';
          validationStatus.className = 'mt-3 text-sm font-medium text-amber-700';
        }

        btn.disabled = false;
      }

      function setBusy(isBusy) {
        btn.disabled = isBusy || !((input.files && input.files.length > 0));
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
        }
        errorBox.classList.remove('hidden');
      }

      function showSuccess(payload) {
        successText.textContent = `Pomyślnie przygotowano raport xlsx za ${payload.month || ''}. Rozpoczynam pobieranie.`;
        if (payload.download_url) {
          downloadLink.href = payload.download_url;
          successBox.classList.remove('hidden');
          window.location.href = payload.download_url;
        }
      }

      dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('border-blue-400', 'bg-blue-50');
      });
      dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('border-blue-400', 'bg-blue-50');
      });
      dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-blue-400', 'bg-blue-50');
        const files = e.dataTransfer.files;
        if (files && files.length > 0) {
          const transfer = new DataTransfer();
          Array.from(files).forEach((file) => transfer.items.add(file));
          input.files = transfer.files;
          updateUiForFile();
        }
      });
      input.addEventListener('change', updateUiForFile);
      updateUiForFile();

      form.addEventListener('submit', async function (e) {
        e.preventDefault();

        if (!(input.files && input.files.length > 0)) {
          updateUiForFile();
          return;
        }

        hideAllAlerts();
        setBusy(true);
        loadingBox.classList.remove('hidden');
        startLoadingTicker();

        try {
          const response = await fetch(form.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: new FormData(form)
          });

          const contentType = response.headers.get('content-type') || '';
          if (!contentType.includes('application/json')) {
            const text = await response.text();
            throw { message: 'Serwer zwrócił nieoczekiwaną odpowiedź (nie JSON).', details: [text.slice(0, 4000)] };
          }

          const data = await response.json();
          if (!response.ok || !data || data.ok !== true) {
            throw { message: data?.message || 'Nie udało się wygenerować raportu.', details: data?.details || [] };
          }

          stopLoadingTicker();
          loadingBox.classList.add('hidden');
          showSuccess(data);
        } catch (err) {
          stopLoadingTicker();
          loadingBox.classList.add('hidden');
          showError(err?.message || 'Błąd połączenia z serwerem.', err?.details || []);
        } finally {
          setBusy(false);
          updateUiForFile();
        }
      });
    })();
  </script>
</body>
</html>
