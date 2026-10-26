/**
 * nj662_dialog — «Диалог двух ИИ»: живой ход разговора и действия без перезагрузки.
 *
 * Ключевое решение модуля: ход ведёт СЕРВЕР (очередь nj120 → канал nj662.dialog).
 * Браузер ничего не «крутит» — он только СМОТРИТ и управляет:
 *
 *   1. создание диалога и действия (старт, пауза, настройки, материалы,
 *      публикация, удаление) уходят fetch-ом: страница не перезагружается, а
 *      статус-ряд оживает сразу;
 *   2. пока разговор идёт — опрос /dialog/status/<id> раз в 3 секунды: этап,
 *      заметка, лестница «Подготовка → Ищу в интернете → Обмен → Сводка»,
 *      счётчики и секундомер. Секундомер между опросами тикает локально;
 *   3. пришла новая реплика (revision вырос) — страница перезагружается: лента
 *      рисуется сервером целиком. Так собирать реплики в браузере по кусочкам
 *      не нужно, и лента не расходится с базой (в наработках владельца это
 *      делал jQuery — оттуда и росла очередь в браузере);
 *   4. «взведённая» кнопка вместо window.confirm: удаление диалога требует
 *      второго клика (доктрина проекта — никаких модальных окон браузера);
 *   5. «своя модель»: выбор «custom» открывает поле «провайдер::модель».
 *
 * Тексты счётчиков считает сервер (Service_Nj662_Dialog::counters_text) — JS
 * подставляет готовую строку, правило не дублируется. Внешних библиотек нет.
 */
(function () {
	'use strict';

	var POLL_MS   = 3000;   // как часто спрашиваем ход разговора
	var RELOAD_MS = 1200;   // пауза перед перезагрузкой: человек успевает увидеть новый этап
	var TOAST_MS  = 5000;   // сколько живёт плашка ответа

	var root = document.querySelector('[data-nj662-view]');

	if ( ! root) return;

	var runId = parseInt(root.getAttribute('data-nj662-run') || '0', 10) || 0;

	var timer = null;   // опрос статуса
	var tick  = null;   // локальный ход секундомера
	var base  = 0;      // секунды от сервера
	var baseAt = 0;     // Date.now() того же момента
	var lastRevision = -1;

	function q(sel) { return root.querySelector(sel); }

	// Формат тот же, что у сервера (Service_Nj662_Dialog::elapsed_text):
	// иначе подпись «прыгала» бы между опросом и локальным тиком.
	function fmt(sec) {
		sec = Math.max(0, Math.floor(sec));

		if (sec < 60) return sec + ' с';

		var m = Math.floor(sec / 60);

		if (m < 60) return m + ' мин ' + (sec % 60) + ' с';

		return Math.floor(m / 60) + ' ч ' + (m % 60) + ' мин';
	}

	function toast(text, tone) {
		if ( ! text) return;
		N2J.toast(text, TOAST_MS, tone === 'error' ? 'err' : 'ok');
	}

	function isLive(data) {
		return !!data && (data.status === 'running' || data.status === 'quick');
	}

	function renderRun(data, opts) {
		opts = opts || {};

		if ( ! data || ! data.ok) return;

		root.setAttribute('data-nj662-status', data.status || '');
		root.setAttribute('data-nj662-turn', data.turn || 0);

		var stage = q('[data-nj662-stage]');
		if (stage) stage.textContent = data.stage || data.status_word || '';

		var note = q('[data-nj662-stage-note]');
		if (note) note.textContent = data.stage_note || '';

		var counter = q('[data-nj662-counter]');
		if (counter) counter.textContent = data.counters_text || '';

		var pill = q('[data-nj662-status-pill]');
		if (pill) pill.textContent = data.status_word || '';

		var icon = q('[data-nj662-spin]');
		var live = isLive(data);

		if (icon) {
			icon.className = 'fas text-sm ' + (live
				? 'fa-spinner animate-spin text-blue-600 dark:text-blue-400'
				: (data.tone === 'ok'
					? 'fa-circle-check text-emerald-500'
					: (data.tone === 'error' ? 'fa-triangle-exclamation text-rose-500' : 'fa-circle-pause text-gray-400')));
		}

		// Ошибку хода показываем на странице, а не только в логе воркера.
		var err = q('[data-nj662-error]');

		if (data.error) {
			if ( ! err) {
				err = document.createElement('p');
				err.setAttribute('data-nj662-error', '');
				err.className = 'n2j-flash n2j-flash-err mt-3 block text-xs';
				var box = q('[data-nj662-run-box]');
				if (box) box.appendChild(err);
			}
			err.textContent = data.error;
		} else if (err) {
			err.textContent = '';
		}

		// Лестница этапов: пройденные зелёные с галочкой, текущий жирный.
		var items = root.querySelectorAll('[data-nj662-stage-item]');
		var index = parseInt(data.stage_index, 10);

		if (isNaN(index)) index = -1;

		Array.prototype.forEach.call(items, function (item) {
			var i = parseInt(item.getAttribute('data-nj662-stage-item'), 10);
			var name = item.textContent.replace(/^\s+/, '');

			item.className = (index >= 0 && i <= index)
				? (i === index ? 'font-bold text-blue-700 dark:text-blue-300' : 'text-emerald-600 dark:text-emerald-400')
				: 'text-gray-400 dark:text-gray-500';
			item.innerHTML = (index > i ? '<i class="fas fa-check mr-1 text-[10px]"></i>' : '') + name;
		});

		base   = parseInt(data.elapsed_sec, 10) || 0;
		baseAt = Date.now();
		renderElapsed();

		if (live) {
			startTick();
		} else {
			stopTick();
			stopPolling();
		}

		// Новая реплика: перезагружаем — лента приходит из базы целиком.
		if (data.revision !== undefined) {
			if (lastRevision >= 0 && data.revision > lastRevision) {
				setTimeout(function () { window.location.reload(); }, RELOAD_MS);
				return;
			}
			lastRevision = data.revision;
		}

		if ( ! live && opts.reload && opts.wasLive) {
			setTimeout(function () { window.location.reload(); }, RELOAD_MS);
		}
	}

	function renderElapsed() {
		var holder = q('[data-nj662-elapsed-text]');

		if (holder) holder.textContent = fmt(base + (Date.now() - baseAt) / 1000);
	}

	function startTick() {
		if (tick) return;
		tick = setInterval(renderElapsed, 1000);
	}

	function stopTick() {
		if (tick) {
			clearInterval(tick);
			tick = null;
		}
	}

	function stopPolling() {
		if (timer) {
			clearInterval(timer);
			timer = null;
		}
	}

	function poll(force) {
		if ( ! runId) return;

		var url = root.getAttribute('data-nj662-progress') || ('/dialog/status/' + runId);

		window.fetch(url, {
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				renderRun(data);
			})
			.catch(function () { /* сеть моргнула — спросим на следующем тике */ });

		if (force && ! timer) {
			timer = setInterval(function () { poll(false); }, POLL_MS);
		}
	}

	// ---------------------------------------------------------------- формы

	function submitForm(form, submitter) {
		var body = new FormData(form);

		// Кнопка-отправитель: у форм создания диалога выбор кнопки —
		// это и есть выбор сценария (quick=go | quick=edit).
		if (submitter && submitter.name) body.append(submitter.name, submitter.value || '');

		window.fetch(form.getAttribute('action'), {
			method: 'POST',
			body: body,
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				toast(data.text || data.error || '', data.ok ? 'notice' : 'error');

				if ( ! data.ok) {
					enable(form);
					return;
				}

				// Стоп и удаление (data-nj662-follow) уводят туда, куда велит сервер.
				if (data.redirect && form.hasAttribute('data-nj662-follow')) {
					window.location.assign(data.redirect);
					return;
				}

				// Диалог мог измениться: статус-ряд и кнопки читаем заново.
				window.location.reload();
			})
			.catch(function () {
				toast('Сеть не ответила: попробуйте ещё раз', 'error');
				enable(form);
			});
	}

	function disable(form) {
		Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), function (b) {
			b.disabled = true;
		});
	}

	function enable(form) {
		Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), function (b) {
			b.disabled = false;
		});
	}

	function initForms() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-nj662-action]'), function (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();

				var submitter = e.submitter
					|| (document.activeElement && form.contains(document.activeElement) ? document.activeElement : null);

				// Подтверждение (data-n2j-arm / data-n2j-confirm) делает общий app.js:
				// сюда отправка доходит уже подтверждённой.

				disable(form);
				submitForm(form, submitter);
			});
		});
	}

	function initCreate() {
		var form = document.querySelector('[data-nj662-create]');

		if ( ! form || ! window.fetch) return;

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			var topic = form.querySelector('[data-nj662-topic]');

			if (topic && topic.value.trim().length < 3) {
				toast('Напишите тему: хотя бы несколько слов', 'error');
				if (topic) topic.focus();
				return;
			}

			var submitter = e.submitter || null;

			disable(form);
			submitForm(form, submitter);
		});
	}

	function initSamples() {
		var topic = document.querySelector('[data-nj662-topic]');

		if ( ! topic) return;

		Array.prototype.forEach.call(document.querySelectorAll('[data-nj662-sample]'), function (chip) {
			chip.addEventListener('click', function () {
				topic.value = chip.getAttribute('data-nj662-sample') || '';
				topic.focus();
			});
		});
	}

	// Выбор «своя модель» открывает поле спецификации: список из конфига
	// (config/nj662_dialog.php) остаётся списком, а не превращается в свободный ввод.
	function initModels() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-nj662-model]'), function (select) {
			var custom = select.parentNode.querySelector('[data-nj662-model-custom]');

			if ( ! custom) return;

			var sync = function () {
				custom.classList.toggle('hidden', select.value !== 'custom');
			};

			select.addEventListener('change', sync);
			sync();
		});
	}

	function boot() {
		initSamples();
		initModels();
		initCreate();
		initForms();

		// Опрос имеет смысл только на странице диалога: там есть карточка хода.
		if (runId && q('[data-nj662-run-box]')) poll(true);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
