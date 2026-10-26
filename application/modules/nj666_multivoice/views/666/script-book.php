<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Рабочее место книги (nj666): поллинг статуса, правка персонажей, запуск
 * разбора и озвучки, переозвучка блока.
 *
 * Страница не считает ничего сама, она только спрашивает
 * /multivoice/api/status: воркер может работать час, и держать открытый
 * запрос всё это время нельзя. Как только статус меняется с «работает» на
 * «готово» — страница перезагружается и показывает свежие блоки.
 *
 * Удаление (книги и персонажа) подтверждается ВТОРЫМ нажатием на ту же
 * кнопку: alert останавливает страницу и выглядит как авария (владелец,
 * 10.10.2026). Play/stop голоса и удаление с переназначением — без confirm
 * (владелец, 11.10.2026).
 */
$book = isset($book) ? (array) $book : [];
$busy = isset($busy) ? (bool) $busy : FALSE;
$csrf = isset($csrf) ? (string) $csrf : '';

$code   = (string) Arr::get($book, 'code');
$status = (string) Arr::get($book, 'status', 'new');
?>

<script>
(function () {
	'use strict';

	var CODE   = <?php echo json_encode($code); ?>;
	var CSRF   = <?php echo json_encode($csrf); ?>;
	var STATUS = <?php echo json_encode($status); ?>;

	var $ = function (id) { return document.getElementById(id); };

	var noteEl  = $('nj666-status');
	var stageEl = $('nj666-stage');
	var notePct = $('nj666-percent');
	var noteTxt = $('nj666-note');
	var errBox  = $('nj666-error');
	var badgeTx = $('nj666-badge-text');
	var badgeIc = $('nj666-badge-icon');
	var badge   = $('nj666-badge');

	var planBtn  = $('nj666-plan');
	var voiceBtn = $('nj666-voice');

	var BADGES = {
		new:      ['Новая', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300', 'far fa-clock'],
		planning: ['Разбирается', 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'fas fa-spinner fa-spin'],
		planned:  ['Разобрана', 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300', 'fas fa-list-check'],
		voicing:  ['Озвучивается', 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'fas fa-spinner fa-spin'],
		done:     ['Готова', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', 'fas fa-circle-check'],
		error:    ['Ошибка', 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300', 'fas fa-circle-exclamation']
	};

	var BUSY = { planning: 1, voicing: 1 };

	function note(text, bad)
	{
		if ( ! noteEl) { return; }

		noteEl.textContent = text || '';
		noteEl.className = 'n2j-hint mt-2' + (bad ? ' font-semibold text-rose-600 dark:text-rose-400' : '');
	}

	// --- Запросы к API ------------------------------------------------------
	function api(path, data)
	{
		data = data || {};
		data.csrf = CSRF;
		data.code = CODE;

		return fetch(path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: JSON.stringify(data)
		}).then(function (r) {
			return r.json().then(function (d) { return { status: r.status, data: d }; });
		});
	}

	function fail(res, fallback)
	{
		return (res.data && res.data.error) ? res.data.error : fallback;
	}

	// --- Живой статус -------------------------------------------------------
	function paint(data)
	{
		var row = BADGES[data.status] || BADGES.new;

		if (badgeTx) { badgeTx.textContent = row[0]; }
		if (badgeIc) { badgeIc.className = row[2] + ' mr-1'; }
		if (badge)
		{
			badge.className = 'rounded-full px-2.5 py-1 text-xs font-semibold ' + row[1];
		}

		if (stageEl) { stageEl.textContent = data.stage || ''; }
		if (noteTxt) { noteTxt.textContent = data.note || ''; }

		var pct = (data.progress && data.progress.percent !== undefined)
			? data.progress.percent
			: 0;

		if (notePct) { notePct.textContent = pct + '%'; }

		var busy = !!BUSY[data.status];

		if (planBtn)  { planBtn.disabled  = busy; }
		if (voiceBtn) { voiceBtn.disabled = busy || ! (data.progress && data.progress.total); }

		if (errBox)
		{
			if (data.error)
			{
				errBox.classList.remove('hidden');
				errBox.classList.add('flex');
				errBox.innerHTML = '<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i><span></span>';
				errBox.querySelector('span').textContent = data.error;
			}
			else
			{
				errBox.classList.add('hidden');
				errBox.classList.remove('flex');
			}
		}

		return busy;
	}

	var wasBusy = !!BUSY[STATUS];
	var polls   = 0;
	var timer   = 0;

	function poll()
	{
		fetch('/multivoice/api/status?code=' + encodeURIComponent(CODE), {
			headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			if ( ! data.ok) { return; }

			var busy = paint(data);

			// Как только работа кончилась — показываем свежие блоки
			// и персонажей: перезагружаем страницу один раз.
			if (wasBusy && ! busy)
			{
				window.location.reload();

				return;
			}

			wasBusy = busy;

			// Час поллить бессмысленно: после 10 минут оставляем редкий
			// опрос, чтобы не долбить сайт впустую.
			polls++;
			timer = setTimeout(poll, busy ? (polls > 120 ? 20000 : 4000) : 15000);
		})
		.catch(function () {
			timer = setTimeout(poll, 15000);
		});
	}

	if (wasBusy) { timer = setTimeout(poll, 3000); }

	// --- Разбор и озвучка ---------------------------------------------------
	if (planBtn)
	{
		planBtn.addEventListener('click', function () {
			planBtn.disabled = true;
			note('Ставлю разбор в очередь…');

			api('/multivoice/api/plan')
			.then(function (res) {
				if ( ! res.data.ok)
				{
					planBtn.disabled = false;
					note(fail(res, 'Разбор не запустился.'), true);

					return;
				}

				note('Книга ушла в разбор. Страница обновится сама.');
				wasBusy = true;
				clearTimeout(timer);
				timer = setTimeout(poll, 2000);
			})
			.catch(function () {
				planBtn.disabled = false;
				note('Сеть не отвечает, попробуйте ещё раз.', true);
			});
		});
	}

	if (voiceBtn)
	{
		voiceBtn.addEventListener('click', function () {
			voiceBtn.disabled = true;
			note('Ставлю озвучку в очередь…');

			api('/multivoice/api/voice')
			.then(function (res) {
				if ( ! res.data.ok)
				{
					voiceBtn.disabled = false;
					note(fail(res, 'Озвучка не запустилась.'), true);

					return;
				}

				note('Блоки ушли в озвучку. Страница обновится сама.');
				wasBusy = true;
				clearTimeout(timer);
				timer = setTimeout(poll, 2000);
			})
			.catch(function () {
				voiceBtn.disabled = false;
				note('Сеть не отвечает, попробуйте ещё раз.', true);
			});
		});
	}

	// --- Персонажи ----------------------------------------------------------
	function saveCharacter(id, data, okText)
	{
		data.id = id;

		return api('/multivoice/api/character', data).then(function (res) {
			if ( ! res.data.ok)
			{
				note(fail(res, 'Персонажа сохранить не удалось.'), true);

				return false;
			}

			note(okText || 'Сохранено', false);

			return true;
		});
	}

	// Карточка персонажа — ближайший li[data-id]. Не dataset с select:
	// Chrome без уникального name копирует value по всем одинаковым
	// спискам и шлёт change на каждый (владелец, 11.10.2026).
	function characterId(el)
	{
		if ( ! el) { return ''; }

		var li = el.closest ? el.closest('#nj666-chars li[data-id]') : null;

		if (li)
		{
			var fromLi = li.getAttribute('data-id') || '';

			if (fromLi) { return fromLi; }
		}

		return (el.dataset && el.dataset.id) ? String(el.dataset.id) : '';
	}

	document.addEventListener('change', function (e) {
		var el = e.target;

		if ( ! el || ! el.classList) { return; }

		var id = characterId(el);

		if ( ! id) { return; }

		if (el.classList.contains('nj666-voice-sel'))
		{
			saveCharacter(id, { voice: el.value }, 'Голос сменён — переозвучьте блок, чтобы услышать.');
		}
		else if (el.classList.contains('nj666-gender'))
		{
			saveCharacter(id, { gender: el.value }, 'Пол сохранён.');
		}
		else if (el.classList.contains('nj666-age'))
		{
			saveCharacter(id, { age: el.value }, 'Возраст сохранён.');
		}
		else if (el.classList.contains('nj666-name'))
		{
			// Имя по уходу курсора (change, не input): иначе каждый символ
			// улетал бы в базу отдельным запросом.
			saveCharacter(id, { name: el.value }, 'Имя сохранено.');
		}
	});

	// Замочек: пока стоит — модель не трогает персонажа при новом разборе.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.nj666-lock') : null;

		if ( ! btn) { return; }

		var locked = btn.dataset.locked === '1';
		var id     = btn.dataset.id;
		var icon   = btn.querySelector('i');

		btn.dataset.locked = locked ? '0' : '1';
		btn.classList.toggle('text-amber-600', ! locked);
		btn.classList.toggle('dark:text-amber-400', ! locked);
		btn.setAttribute('aria-pressed', locked ? 'false' : 'true');

		if (icon)
		{
			icon.className = locked ? 'fas fa-lock-open' : 'fas fa-lock';
		}

		btn.title = locked
			? 'Поставить замочек: модель не будет менять этого персонажа.'
			: 'Замочек стоит: модель не меняет персонажа. Нажмите, чтобы снять.';

		saveCharacter(id, { locked: locked ? 0 : 1 }, locked ? 'Замочек снят.' : 'Замочек стоит.');
	});

	// --- Удаление: второе нажатие, а не alert --------------------------------
	// Взвод — общий data-n2j-arm (app.js): первое нажатие красит кнопку и
	// сюда не доходит, второе — выполняет; клик мимо и Esc снимают взвод.

	// Книга целиком.
	var delBook = $('nj666-del-book');

	if (delBook)
	{
		delBook.addEventListener('click', function () {
			delBook.disabled = true;
			note('Удаляю книгу…');

			api('/multivoice/api/delete')
			.then(function (res) {
				if ( ! res.data.ok)
				{
					delBook.disabled = false;
					note(fail(res, 'Книгу удалить не удалось.'), true);

					return;
				}

				window.location.href = '/multivoice';
			})
			.catch(function () {
				delBook.disabled = false;
				note('Сеть не отвечает, попробуйте ещё раз.', true);
			});
		});
	}

	// Отдельный персонаж: его реплики перейдут рассказчику.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.nj666-del-char') : null;

		if ( ! btn) { return; }

		btn.disabled = true;
		note('Удаляю персонажа…');

		api('/multivoice/api/character_del', { id: btn.dataset.id })
		.then(function (res) {
			if ( ! res.data.ok)
			{
				btn.disabled = false;
				note(fail(res, 'Персонажа удалить не удалось.'), true);

				return;
			}

			window.location.reload();
		})
		.catch(function () {
			btn.disabled = false;
			note('Сеть не отвечает, попробуйте ещё раз.', true);
		});
	});

	// Удаление с переназначением: сначала выбрать, кому отдать реплики. Пока
	// не выбрано, кнопка выключена — иначе первое нажатие взвело бы её впустую.
	document.addEventListener('change', function (e) {
		var sel = e.target.closest ? e.target.closest('.nj666-remap-to') : null;

		if ( ! sel) { return; }

		var li  = sel.closest('#nj666-chars li[data-id]');
		var btn = li ? li.querySelector('.nj666-del-remap') : null;

		if (btn) { btn.disabled = ! sel.value; }
	});

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.nj666-del-remap') : null;

		if ( ! btn) { return; }

		var li  = btn.closest ? btn.closest('#nj666-chars li[data-id]') : null;
		var sel = li ? li.querySelector('.nj666-remap-to') : null;
		var to  = sel ? String(sel.value || '') : '';

		if ( ! to)
		{
			note('Выберите, кому отдать реплики.', true);

			return;
		}

		btn.disabled = true;
		note('Удаляю персонажа, реплики переходят…');

		api('/multivoice/api/character_del', { id: btn.dataset.id, to: to })
		.then(function (res) {
			if ( ! res.data.ok)
			{
				btn.disabled = false;
				note(fail(res, 'Персонажа удалить не удалось.'), true);

				return;
			}

			window.location.reload();
		})
		.catch(function () {
			btn.disabled = false;
			note('Сеть не отвечает, попробуйте ещё раз.', true);
		});
	});

	// Play/stop выбранного голоса (пустое = голос книги). Без confirm.
	var previewGen   = 0;
	var previewAudio = null;
	var previewBtn   = null;

	function resetPlayButton(btn)
	{
		if ( ! btn) { return; }

		var ic = btn.querySelector('i');

		if (ic) { ic.className = 'fas fa-play'; }

		btn.classList.remove('bg-blue-600', 'text-white', 'opacity-50');
		btn.disabled = false;
		btn.title = 'Прослушать голос';
	}

	function stopPreview()
	{
		previewGen++;

		if (previewAudio)
		{
			previewAudio.pause();
			previewAudio.removeAttribute('src');

			try { previewAudio.load(); } catch (err) {}

			previewAudio = null;
		}

		resetPlayButton(previewBtn);
		previewBtn = null;
	}

	function playVoice(btn)
	{
		if (previewBtn === btn && (btn.disabled || (previewAudio && ! previewAudio.paused)))
		{
			stopPreview();

			return;
		}

		stopPreview();

		var gen = previewGen;
		var li  = btn.closest ? btn.closest('#nj666-chars li[data-id]') : null;
		var sel = li ? li.querySelector('.nj666-voice-sel') : null;
		var voice = sel ? sel.value : '';
		var ic = btn.querySelector('i');

		previewBtn = btn;
		btn.disabled = true;
		btn.classList.add('opacity-50');

		if (ic) { ic.className = 'fas fa-hourglass-half'; }

		note('Озвучиваю превью…');

		api('/multivoice/api/voice_preview', { voice: voice })
		.then(function (res) {
			if (gen !== previewGen) { return; }

			if ( ! res.data.ok)
			{
				stopPreview();
				note(fail(res, 'Голос не озвучился.'), true);

				return;
			}

			var a = new Audio(res.data.url);

			previewAudio = a;
			btn.disabled = false;
			btn.classList.remove('opacity-50');
			btn.classList.add('bg-blue-600', 'text-white');
			btn.title = 'Остановить';

			if (ic) { ic.className = 'fas fa-stop'; }

			a.addEventListener('ended', function () {
				if (gen !== previewGen) { return; }

				stopPreview();
			});
			a.addEventListener('error', function () {
				if (gen !== previewGen) { return; }

				stopPreview();
				note('Превью не заиграло.', true);
			});

			var p = a.play();

			if (p && p.catch)
			{
				p.catch(function () {
					if (gen !== previewGen) { return; }

					stopPreview();
					note('Браузер не дал включить звук.', true);
				});
			}

			note('');
		})
		.catch(function () {
			if (gen !== previewGen) { return; }

			stopPreview();
			note('Сеть не отвечает, попробуйте ещё раз.', true);
		});
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.nj666-voice-play') : null;

		if ( ! btn) { return; }

		playVoice(btn);
	});

	// --- Переозвучка одного блока -------------------------------------------
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.nj666-voice-one') : null;

		if ( ! btn || btn.disabled) { return; }

		btn.disabled = true;
		btn.classList.add('opacity-50');
		note('Ставлю блок в очередь озвучки…');

		api('/multivoice/api/block_voice', { id: btn.dataset.id })
		.then(function (res) {
			if ( ! res.data.ok)
			{
				btn.disabled = false;
				btn.classList.remove('opacity-50');
				note(fail(res, 'Блок не озвучился.'), true);

				return;
			}

			window.location.reload();
		})
		.catch(function () {
			btn.disabled = false;
			btn.classList.remove('opacity-50');
			note('Сеть не отвечает, попробуйте ещё раз.', true);
		});
	});

	// --- Настройки книги ----------------------------------------------------
	var saveBtn = $('nj666-save');
	var saveNote = $('nj666-save-note');

	if (saveBtn)
	{
		function bindRange(id, valueId)
		{
			var range = $(id);
			var label = $(valueId);

			if ( ! range || ! label) { return; }

			range.addEventListener('input', function () { label.textContent = range.value; });
		}

		bindRange('nj666-f-min', 'nj666-v-min');
		bindRange('nj666-f-max', 'nj666-v-max');
		bindRange('nj666-f-speed', 'nj666-f-speed-value');

		saveBtn.addEventListener('click', function () {
			saveBtn.disabled = true;

			if (saveNote) { saveNote.textContent = 'Сохраняю…'; }

			api('/multivoice/api/settings', {
				title: ($('nj666-f-title') || {}).value || '',
				default_voice: ($('nj666-f-voice') || {}).value || '',
				block_min_min: ($('nj666-f-min') || {}).value,
				block_max_min: ($('nj666-f-max') || {}).value,
				speed: ($('nj666-f-speed') || {}).value
			})
			.then(function (res) {
				saveBtn.disabled = false;

				if (saveNote)
				{
					saveNote.textContent = res.data.ok
						? 'Сохранено'
						: (res.data.error || 'Не сохранилось.');
				}
			})
			.catch(function () {
				saveBtn.disabled = false;

				if (saveNote) { saveNote.textContent = 'Сеть не отвечает.'; }
			});
		});
	}

	// --- Новый персонаж вручную ---------------------------------------------
	var addBtn = $('nj666-add-char');

	if (addBtn)
	{
		addBtn.addEventListener('click', function () {
			addBtn.disabled = true;
			note('Добавляю персонажа…');

			api('/multivoice/api/character', {
				id: 0,
				name: 'Новый персонаж',
				gender: '',
				age: ''
			})
			.then(function (res) {
				if ( ! res.data.ok)
				{
					addBtn.disabled = false;
					note(fail(res, 'Персонажа добавить не удалось.'), true);

					return;
				}

				window.location.reload();
			})
			.catch(function () {
				addBtn.disabled = false;
				note('Сеть не отвечает, попробуйте ещё раз.', true);
			});
		});
	}
})();
</script>
