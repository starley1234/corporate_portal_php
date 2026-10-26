/**
 * nj661_airadar — «ИИ-радар»: ЖИВОЙ ХОД СБОРА, запуск без перезагрузки, «взведённые» кнопки.
 *
 * Владелец (08.10.2026): «при нажатии „Собрать сейчас" должен быть виден процесс
 * поиска» — по дизайн-эталону /pages/required_design_sample: спиннер, название
 * этапа и секундомер одним неразрывным рядом, полос хода нет, «сколько» — числом.
 *
 * Что делает движок:
 *   1. «Собрать сейчас» уходит fetch-ом, страница не перезагружается: карточка
 *      хода сбора появляется сразу («Ставлю задачу в очередь…») и оживает.
 *   2. Пока прогон жив (status new|running) — опрос /airadar/progress раз в 3 с:
 *      этап, заметка этапа, лестница «Источники → Чтение → Анализ → Отчёт»,
 *      счётчики и секундомер. Секундомер между опросами тикает локально.
 *   3. Прогон закончился — короткая пауза (владелец видит «завершён») и
 *      перезагрузка страницы: свежий отчёт уже в разметке.
 *   4. Публикация и «прислать письмо» — вторым нажатием: data-n2j-arm на
 *      кнопке (общий app.js), своего кода взвода здесь нет.
 *
 * Текст счётчиков считает сервер (Service_Nj661_Airadar::counters_text) — JS
 * только подставляет готовую строку, правило не дублируется.
 * Внешних библиотек нет.
 */
(function () {
	'use strict';

	var POLL_MS   = 3000;  // как часто спрашиваем ход прогона
	var RELOAD_MS = 1500;  // пауза перед перезагрузкой после завершения

	var ICONS = {
		live: 'fa-spinner animate-spin text-blue-600 dark:text-blue-400',
		done: 'fa-circle-check text-emerald-500',
		stop: 'fa-triangle-exclamation text-rose-500'
	};

	var root  = null;      // карточка хода сбора [data-nj661-run]
	var timer = null;      // опрос /airadar/progress
	var tick  = null;      // локальный ход секундомера
	var base  = 0;         // секунды на момент последнего ответа сервера
	var baseAt = 0;        // Date.now() того же момента

	function q(sel) { return root ? root.querySelector(sel) : null; }

	function fmt(sec) {
		sec = Math.max(0, Math.floor(sec));
		return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
	}

	// Живой ли прогон: статус приходит от сервера (run_progress.live).
	function isLive(run) {
		return !!run && (run.status === 'new' || run.status === 'running');
	}

	// Иконка статус-ряда: спиннер / галочка / предупреждение.
	function setIcon(state) {
		var icon = q('[data-nj661-spin]');

		if ( ! icon) return;

		icon.className = 'fas ' + (ICONS[state] || ICONS.stop) + ' text-sm';
	}

	// Плашка статуса: текст и тон («идёт» — циановый, иначе серый).
	function setPill(run) {
		var pill = q('[data-nj661-status-pill]');

		if ( ! pill) return;

		var live = isLive(run);

		pill.textContent = ! run ? '' : (live ? 'идёт' : (run.status === 'done' ? 'завершён' : 'остановлен'));
		pill.className = 'n2j-badge ' + (live ? 'n2j-badge-run' : 'n2j-badge-wait');
	}

	// Лестница этапов: пройденные — зелёные с галочкой, текущий — жирный, дальше серые.
	function setStages(run) {
		var items = root ? root.querySelectorAll('[data-nj661-stage-item]') : [];
		var names = (run && run.stages) || [];
		var index = run ? parseInt(run.stage_index, 10) : -1;

		if (isNaN(index)) index = -1;

		Array.prototype.forEach.call(items, function (item) {
			var i = parseInt(item.getAttribute('data-nj661-stage-item'), 10);
			var name = names[i] || item.textContent.replace(/^\s*/, '');

			item.className = (index >= 0 && i <= index)
				? (i === index ? 'font-bold text-blue-700 dark:text-blue-300' : 'text-emerald-600 dark:text-emerald-400')
				: 'text-gray-400 dark:text-gray-500';
			item.innerHTML = (index > i ? '<i class="fas fa-check mr-1 text-[10px]"></i>' : '') + name;
		});
	}

	// Строка ошибки прогона: показываем, обновляем, убираем.
	// tone: 'err' — прогон провален, 'warn' — завершён, но часть материалов
	// не разобрана (раньше такая запись красилась красным, как отказ).
	function setError(text, tone) {
		var box = q('[data-nj661-error]');
		var cls = 'n2j-flash ' + (tone === 'warn' ? 'n2j-flash-warn' : 'n2j-flash-err') + ' mt-3 block text-xs';

		if ( ! text) {
			if (box) box.parentNode.removeChild(box);
			return;
		}

		if ( ! box) {
			box = document.createElement('p');
			box.setAttribute('data-nj661-error', '');
			root.appendChild(box);
		}

		box.className = cls;
		box.textContent = text;
	}

	// Секундомер: между опросами тикает сам, чтобы «идёт» было видно глазом.
	function renderElapsed() {
		var holder = q('[data-nj661-elapsed-text]');

		if (holder) holder.textContent = fmt(base + (Date.now() - baseAt) / 1000);
	}

	function startTick() {
		if (tick) return;

		baseAt = Date.now();
		tick = setInterval(renderElapsed, 1000);
	}

	function stopTick() {
		if (tick) {
			clearInterval(tick);
			tick = null;
		}
	}

	// Одна отрисовка состояния прогона — и для серверных данных, и для ответа fetch.
	function renderRun(run, opts) {
		if ( ! root || ! run) return;

		opts = opts || {};
		root.hidden = false;
		root.setAttribute('data-nj661-run', run.id || 0);
		root.setAttribute('data-nj661-status', run.status || '');
		root.setAttribute('data-nj661-stage-index', typeof run.stage_index === 'number' ? run.stage_index : -1);
		root.setAttribute('data-nj661-empty', '0');

		var idHolder = q('[data-nj661-run-id]');

		if (idHolder) {
			idHolder.hidden = false;
			idHolder.textContent = '#' + (run.id || '');
		}

		var stage = q('[data-nj661-stage]');
		if (stage) stage.textContent = run.stage || '';

		var note = q('[data-nj661-stage-note]');
		if (note) note.textContent = run.stage_note || '';

		var counter = q('[data-nj661-counter]');
		if (counter) counter.textContent = run.counters_text || 'собираю источники…';

		var sources = q('[data-nj661-sources]');
		var srcLine = [];

		Object.keys(run.sources || {}).forEach(function (name) {
			srcLine.push(name + ' ' + run.sources[name]);
		});

		if (sources) {
			sources.textContent = srcLine.length ? 'Источники: ' + srcLine.join(' · ') : '';
			sources.hidden = ! srcLine.length;
		}

		base   = parseInt(run.elapsed_sec, 10) || 0;
		baseAt = Date.now();
		renderElapsed();

		var live = isLive(run);

		setPill(run);
		setError(run.error || '', run.status === 'fail' ? 'err' : 'warn');
		setStages(run);

		if (live) {
			setIcon('live');
			startTick();
		} else {
			stopTick();
			setIcon(run.status === 'done' ? 'done' : 'stop');
		}

		if ( ! live && opts.reload) {
			// Конвейер закончил: даём владельцу увидеть «завершён», затем
			// перезагружаем страницу — свежий отчёт уже отрисован сервером.
			setTimeout(function () { window.location.reload(); }, RELOAD_MS);
		}
	}

	function stopPolling() {
		if (timer) {
			clearInterval(timer);
			timer = null;
		}
	}

	function poll() {
		fetch('/airadar/progress', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if ( ! data.ok || ! data.run) {
					stopPolling();
					stopTick();
					window.location.reload();
					return;
				}

				renderRun(data.run, { reload: true });

				if ( ! isLive(data.run)) stopPolling();
			})
			.catch(function () { /* сеть моргнула — спросим на следующем тике */ });
	}

	function startPolling() {
		if (timer) return;

		timer = setInterval(poll, POLL_MS);
		poll();
	}

	// Имена этапов берём из уже отрисованной лестницы: дублировать список
	// констант в JS незачем.
	function stageNames() {
		var names = [];

		if (root) {
			Array.prototype.forEach.call(root.querySelectorAll('[data-nj661-stage-item]'), function (item) {
				names.push((item.textContent || '').replace(/^\s+/, ''));
			});
		}

		return names;
	}

	// «Собрать сейчас»: fetch вместо перехода, карточка хода сбора оживает сразу.
	function initRunForm() {
		var form = document.querySelector('[data-nj661-run-form]');

		if ( ! form || ! window.fetch) return;

		var button = form.querySelector('[data-nj661-run-button]');
		var label  = form.querySelector('[data-nj661-run-label]');

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			if (button) {
				button.disabled = true;
				button.title = 'Прогон уже идёт';
			}

			if (label) label.textContent = 'Запускаю…';

			// Карточка появляется до ответа сервера: процесс видно с первого клика.
			renderRun({
				id: 0,
				status: 'new',
				stage: 'Ставлю задачу в очередь…',
				stage_note: 'передаю задание воркеру',
				stage_index: -1,
				elapsed_sec: 0,
				counters_text: 'собираю источники…',
				stages: stageNames(),
				sources: {}
			});

			fetch(form.action, {
				method: 'POST',
				body: new FormData(form),
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data && data.run) renderRun(data.run, { reload: true });

					if ( ! data || ! data.ok) {
						setError((data && data.error) || 'Не удалось запустить прогон');

						if (button) {
							button.disabled = false;
							button.title = '';
						}

						if (label) label.textContent = 'Собрать сейчас';
						stopTick();
						stopPolling();
						setIcon('stop');
						setPill(data && data.run ? data.run : null);
						return;
					}

					if (label) label.textContent = 'Конвейер работает…';
					startPolling();
				})
				.catch(function () {
					// fetch не сложился — отправляем форму как обычно (серверный путь
					// с 303 и плашкой): без JS и при старом браузере всё работает.
					stopTick();
					stopPolling();
					form.submit();
				});
		});
	}

	// Статус на момент загрузки: идёт прогон — сразу подхватываем опрос.
	function initRunProgress() {
		root = document.querySelector('[data-nj661-run]');

		if ( ! root) return;

		if (isLive({ status: root.getAttribute('data-nj661-status') })) {
			startTick();
			startPolling();
		}
	}

	function boot() {
		initRunProgress();
		initRunForm();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
