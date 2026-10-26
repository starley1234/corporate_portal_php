<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Озвучивание текста (nj665) — клиент: счётчик, смена голосов при смене
 * модели, синтез через JSON API без перезагрузки.
 *
 * Форма остаётся обычным POST: если скрипт не отработает (старый браузер,
 * выключенный JS), сервер всё равно озвучит текст и покажет плеер.
 * Ссылка на файл копируется в буфер одной кнопкой — она же годится для
 * вставки в статью или чат.
 */
$limits  = isset($limits) ? (array) $limits : [];
$model   = (string) (isset($model) ? $model : '');
$formats = isset($formats) ? (array) $formats : [];

$playable = [];

foreach ($formats as $fmt)
{
	$playable[$fmt] = (bool) Service_Nj665_Voice::is_playable($fmt);
}

$max_chars = (int) Arr::get($limits, 'max_chars', 4000);
?>
<script>
(function () {
	'use strict';

	var MAX_CHARS = <?php echo (int) $max_chars; ?>;
	var PLAYABLE  = <?php echo json_encode($playable); ?>;
	var MODEL     = <?php echo json_encode($model); ?>;

	var $ = function (id) { return document.getElementById(id); };

	var form     = $('nj665-form');
	var textarea = $('nj665-text');
	var counter  = $('nj665-count');
	var status   = $('nj665-status');
	var submit   = $('nj665-submit');
	var result   = $('nj665-result');
	var audio    = $('nj665-audio');
	var csrf     = $('nj665-csrf');

	if ( ! form || ! textarea) { return; }

	var modelSel   = $('nj665-model');
	var voiceSel   = $('nj665-voice');
	var formatSel  = $('nj665-format');
	var speedIn    = $('nj665-speed');
	var speedValue = $('nj665-speed-value');
	var history    = $('nj665-history');
	var download   = $('nj665-download');
	var copyBtn    = $('nj665-copy');
	var copyNote   = $('nj665-copy-note');

	// --- Счётчик символов ----------------------------------------------------
	function count() {
		var n = textarea.value.length;

		counter.textContent = n + ' / ' + MAX_CHARS;
		counter.className = n > MAX_CHARS
			? 'font-semibold text-rose-600'
			: 'font-semibold text-gray-500 dark:text-gray-400';
	}

	textarea.addEventListener('input', count);
	count();

	// --- Скорость -----------------------------------------------------------
	if (speedIn && speedValue) {
		speedIn.addEventListener('input', function () {
			speedValue.textContent = parseFloat(speedIn.value).toFixed(2) + '×';
		});
	}

	// --- Голоса и форматы выбранной модели ----------------------------------
	function fill(sel, items, keep) {
		if ( ! sel) { return; }

		var html = '';

		for (var i = 0; i < items.length; i++) {
			var v = items[i];
			var sel2 = (keep && keep === v) ? ' selected' : ( ! keep && i === 0 ? ' selected' : '');

			html += '<option value="' + v.replace(/"/g, '&quot;') + '"' + sel2 + '>'
				+ v.replace(/</g, '&lt;') + '</option>';
		}

		sel.innerHTML = html || '<option value="">недоступно</option>';
	}

	if (modelSel) {
		modelSel.addEventListener('change', function () {
			var id = modelSel.value;

			if ( ! id || id === MODEL) { return; }

			MODEL = id;
			status.textContent = 'Спрашиваю голоса модели…';

			fetch('/voice/api/voices?model=' + encodeURIComponent(id), {
				headers: { 'Accept': 'application/json' }
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				fill(voiceSel, data.voices || [], '');
				fill(formatSel, data.formats || [], '');
				PLAYABLE = {};
				(data.formats || []).forEach(function (f) { PLAYABLE[f] = true; });
				status.textContent = '';
			})
			.catch(function () { status.textContent = 'Не удалось получить список голосов.'; });
		});
	}

	// --- Отправка -----------------------------------------------------------
	function setBusy(busy) {
		submit.disabled = busy;
		submit.querySelector('i').className = busy
			? 'fas fa-spinner fa-spin'
			: 'fas fa-wand-magic-sparkles';
	}

	function showResult(data) {
		if ( ! result || ! audio) { return; }

		result.classList.remove('hidden');

		audio.src = data.url;
		audio.load();

		var size = data.bytes >= 1048576
			? (data.bytes / 1048576).toFixed(1) + ' МБ'
			: Math.max(1, Math.round(data.bytes / 1024)) + ' КБ';

		$('nj665-result-meta').textContent = data.format + ' · ' + size + ' · ' + data.chars + ' символов';
		$('nj665-result-title').textContent = data.title || 'Готово';

		download.href = data.url + '?dl=1';

		var notPlayable = PLAYABLE[data.format] === false;

		audio.style.display = notPlayable ? 'none' : '';

		audio.dataset.code = data.code;
	}

	function addHistory(data) {
		if ( ! history) { return; }

		var empty = $('nj665-history-empty');

		if (empty) { empty.parentNode.removeChild(empty); }

		var items = history.querySelectorAll('li[data-code]');

		for (var i = 0; i < items.length; i++) {
			if (items[i].dataset.code === data.code) { return; }
		}

		var li = document.createElement('li');

		li.className = 'py-3';
		li.dataset.code = data.code;
		li.innerHTML = '<div class="flex flex-wrap items-center gap-2">'
			+ '<span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200"></span>'
			+ '<span class="text-xs text-gray-400"></span>'
			+ '</div>'
			+ '<div class="mt-2 flex flex-wrap items-center gap-3">'
			+ '<audio controls preload="none" class="w-full max-w-md"></audio>'
			+ '<a class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400" href="' + data.url + '?dl=1">Скачать</a>'
			+ '<a class="text-xs text-gray-500 hover:underline dark:text-gray-400" href="/voice/h/' + data.code + '">Ссылка</a>'
			+ '<button type="button" data-n2j-arm="Точно удалить?" data-nj665-del="' + data.code + '"'
			+ ' class="ml-auto inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-rose-600 ring-1 ring-inset ring-rose-400 transition hover:bg-rose-50 dark:text-rose-400 dark:ring-gray-700 dark:hover:bg-rose-950/40">'
			+ '<i class="fas fa-trash-can"></i><span data-n2j-arm-label>Удалить</span></button>'
			+ '</div>';

		var spans = li.querySelectorAll('span');
		spans[0].textContent = data.title || 'Озвучка';
		spans[1].textContent = data.voice + ' · ' + data.format;

		li.querySelector('audio').src = data.url;

		history.insertBefore(li, history.firstChild);
	}

	form.addEventListener('submit', function (e) {
		// Без fetch страница сама обработает обычный POST.
		if ( ! window.fetch) { return; }

		e.preventDefault();

		if (textarea.value.trim() === '') {
			status.textContent = 'Введите текст для озвучивания.';
			textarea.focus();

			return;
		}

		status.textContent = 'Озвучиваю…';
		setBusy(true);

		var body = {
			csrf: csrf ? csrf.value : '',
			text: textarea.value,
			model: modelSel ? modelSel.value : '',
			voice: voiceSel ? voiceSel.value : '',
			format: formatSel ? formatSel.value : '',
			speed: speedIn ? speedIn.value : '1',
			language: $('nj665-language') ? $('nj665-language').value : '',
			instructions: $('nj665-instructions') ? $('nj665-instructions').value : ''
		};

		fetch('/voice/api/synthesize', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: JSON.stringify(body)
		})
		.then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
		.then(function (res) {
			setBusy(false);

			if ( ! res.data.ok) {
				status.textContent = res.data.error || 'Не удалось озвучить текст.';
				status.className = 'text-xs text-rose-600';

				return;
			}

			status.textContent = '';
			status.className = 'text-xs text-gray-500 dark:text-gray-400';
			showResult(res.data);
			addHistory(res.data);
		})
		.catch(function () {
			setBusy(false);
			status.textContent = 'Сеть не отвечает. Обновите страницу и попробуйте ещё раз.';
		});
	});

	// --- Удаление -------------------------------------------------------------
	// Подтверждение — ВТОРЫМ нажатием по той же кнопке (data-n2j-arm, app.js),
	// а не confirm(): первое нажатие взводит кнопку («Точно удалить?», 4 с,
	// снимается кликом мимо и Esc) и сюда не доходит, второе — удаляет.

	function note(text) {
		var el = $('nj665-history-note');

		if ( ! el) { return; }

		el.textContent = text || '';
		el.classList.toggle('hidden', ! text);

		if (text) { setTimeout(function () { note(''); }, 4000); }
	}

	if (history) {
		history.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('[data-nj665-del]') : null;

			if ( ! btn) { return; }

			e.preventDefault();

			var code = btn.dataset.nj665Del;
			var li   = btn.closest('li');

			if ( ! code || ! li) { return; }

			btn.disabled = true;
			note('Удаляю…');

			fetch('/voice/api/delete', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: JSON.stringify({ csrf: csrf ? csrf.value : '', code: code })
			})
			.then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
			.then(function (res) {
				if ( ! res.data.ok) {
					btn.disabled = false;
					note(res.data.error || 'Не удалось удалить озвучку.');

					return;
				}

				if (audio && audio.dataset.code === code) {
					audio.pause();
					audio.removeAttribute('src');
					result.classList.add('hidden');
				}

				li.parentNode.removeChild(li);

				if ( ! history.querySelector('li[data-code]')) {
					var li2 = document.createElement('li');

					li2.id = 'nj665-history-empty';
					li2.className = 'py-3 text-xs text-gray-500 dark:text-gray-400';
					li2.textContent = 'Пока ничего нет — озвучьте текст, и файл появится здесь.';
					history.appendChild(li2);
				}

				note('Озвучка удалена');
			})
			.catch(function () {
				btn.disabled = false;
				note('Сеть не отвечает, попробуйте ещё раз.');
			});
		});
	}

	// --- Ссылка в буфер ------------------------------------------------------
	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			var code = audio ? audio.dataset.code : '';

			if ( ! code) { return; }

			var url = window.location.origin + '/voice/f/' + code;

			N2J.copyText(url).then(function () {
				copyNote.textContent = 'Ссылка скопирована';
				setTimeout(function () { copyNote.textContent = ''; }, 2000);
			}, function () { window.prompt('Ссылка на озвучку:', url); });
		});
	}
})();
</script>
