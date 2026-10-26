/**
 * erp222_forge — «Кузница систем»: живой ход цикла, запуск без перезагрузки,
 * «взведённые» кнопки и копирование документа.
 *
 * Владелец (08.10.2026): «пересмотри всю логику, нужно чтобы модуль формировал
 * ценные и полезные артефакты и был интересен» — интерес начинается с того, что
 * видно, как работают роли: спиннер, название этапа и секундомер одним
 * неразрывным рядом (дизайн-эталон /pages/required_design_sample, правило 4),
 * полос хода нет, «сколько» — числом.
 *
 * Что делает движок:
 *   1. «Запустить цикл» и «Один шаг» уходят fetch-ом: страница не
 *      перезагружается, карточка хода оживает сразу («Ставлю задачу в очередь…»).
 *      Кнопка-отправитель передаётся в запрос честно (quick=one), иначе «Один
 *      шаг» на сервере выглядел бы как полный цикл.
 *   2. Пока цикл жив (status new|running) — опрос /forge/progress раз в 3 с:
 *      этап, заметка этапа, лестница «Роли → Синтез → Критика → Сводка»,
 *      счётчики и секундомер. Секундомер между опросами тикает локально.
 *   3. Цикл закончился — короткая пауза (владелец видит «завершён») и
 *      перезагрузка: свежие артефакты уже в разметке.
 *   4. Публикация — вторым нажатием: data-n2j-arm на кнопке (общий app.js),
 *      своего кода взвода здесь нет; window.confirm не используем.
 *   5. Кнопка data-erp222-copy копирует тело документа целиком: спецификацию и
 *      промпт забирают в работу, а не переписывают руками.
 *
 * Тексты счётчиков считает сервер (Service_Erp222_Forge::counters_text) — JS
 * подставляет готовую строку, правило не дублируется. Внешних библиотек нет.
 */
(function () {
	'use strict';

	var POLL_MS   = 3000;  // как часто спрашиваем ход цикла
	var RELOAD_MS = 1500;  // пауза перед перезагрузкой после завершения

	var ICONS = {
		live: 'fa-spinner animate-spin text-blue-600 dark:text-blue-400',
		done: 'fa-circle-check text-emerald-500',
		stop: 'fa-triangle-exclamation text-rose-500'
	};

	var root   = null;   // карточка хода цикла [data-erp222-run]
	var timer  = null;   // опрос /forge/progress
	var tick   = null;   // локальный ход секундомера
	var base   = 0;      // секунды на момент последнего ответа сервера
	var baseAt = 0;      // Date.now() того же момента

	function q(sel) { return root ? root.querySelector(sel) : null; }

	function fmt(sec) {
		sec = Math.max(0, Math.floor(sec));

		var h = Math.floor(sec / 3600);
		var m = Math.floor((sec % 3600) / 60);
		var s = sec % 60;

		return (h > 0 ? h + ':' + ('0' + m).slice(-2) : m) + ':' + ('0' + s).slice(-2);
	}

	// Живой ли цикл: статус приходит от сервера (run_progress.live).
	function isLive(run) {
		return !!run && (run.status === 'new' || run.status === 'running');
	}

	function setIcon(state) {
		var icon = q('[data-erp222-spin]');

		if ( ! icon) return;

		icon.className = 'fas ' + (ICONS[state] || ICONS.stop) + ' text-sm';
	}

	function setPill(run) {
		var pill = q('[data-erp222-status-pill]');

		if ( ! pill) return;

		var live = isLive(run);

		pill.textContent = ! run ? '' : (live ? 'идёт' : (run.status === 'done' ? 'завершён' : 'остановлен'));
		pill.className = 'n2j-badge ' + (live ? 'n2j-badge-run' : 'n2j-badge-wait');
	}

	// Роль текущего шага — подпись рядом с номером цикла.
	function setRole(run) {
		var holder = q('[data-erp222-role]');

		if ( ! holder) return;

		var title = (run && run.role_title) || '';

		holder.textContent = title;
		holder.hidden = ! title;
	}

	// Лестница этапов: пройденные зелёные с галочкой, текущий жирный, дальше серые.
	function setStages(run) {
		var items = root ? root.querySelectorAll('[data-erp222-stage-item]') : [];
		var names = (run && run.stages) || [];
		var index = run ? parseInt(run.stage_index, 10) : -1;

		if (isNaN(index)) index = -1;

		Array.prototype.forEach.call(items, function (item) {
			var i = parseInt(item.getAttribute('data-erp222-stage-item'), 10);
			var name = names[i] || item.textContent.replace(/^\s*/, '');

			item.className = (index >= 0 && i <= index)
				? (i === index ? 'font-bold text-blue-700 dark:text-blue-300' : 'text-emerald-600 dark:text-emerald-400')
				: 'text-gray-400 dark:text-gray-500';
			item.innerHTML = (index > i ? '<i class="fas fa-check mr-1 text-[10px]"></i>' : '') + name;
		});
	}

	function setError(text) {
		var box = q('[data-erp222-error]');

		if ( ! text) {
			if (box) box.parentNode.removeChild(box);
			return;
		}

		if ( ! box) {
			box = document.createElement('p');
			box.setAttribute('data-erp222-error', '');
			box.className = 'n2j-flash n2j-flash-err mt-3 block text-xs';
			root.appendChild(box);
		}

		box.textContent = text;
	}

	function renderElapsed() {
		var holder = q('[data-erp222-elapsed-text]');

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

	// Одна отрисовка состояния цикла — и для серверных данных, и для ответа fetch.
	function renderRun(run, opts) {
		if ( ! root || ! run) return;

		opts = opts || {};
		root.hidden = false;
		root.setAttribute('data-erp222-run', run.id || 0);
		root.setAttribute('data-erp222-status', run.status || '');
		root.setAttribute('data-erp222-stage-index', typeof run.stage_index === 'number' ? run.stage_index : -1);
		root.setAttribute('data-erp222-empty', '0');

		var idHolder = q('[data-erp222-run-id]');

		if (idHolder) {
			idHolder.hidden = false;
			idHolder.textContent = '#' + (run.id || '');
		}

		var stage = q('[data-erp222-stage]');
		if (stage) stage.textContent = run.stage || '';

		var note = q('[data-erp222-stage-note]');
		if (note) note.textContent = run.stage_note || '';

		var counter = q('[data-erp222-counter]');
		if (counter) counter.textContent = run.counters_text || 'ставлю задачу в очередь…';

		base   = parseInt(run.elapsed_sec, 10) || 0;
		baseAt = Date.now();
		renderElapsed();

		var live = isLive(run);

		setPill(run);
		setRole(run);
		setError(run.error || '');
		setStages(run);

		if (live) {
			setIcon('live');
			startTick();
		} else {
			stopTick();
			setIcon(run.status === 'done' ? 'done' : 'stop');
		}

		if ( ! live && opts.reload) {
			// Цикл закончил: даём увидеть «завершён», затем перезагружаем —
			// выпущенные артефакты уже отрисованы сервером.
			setTimeout(function () { window.location.reload(); }, RELOAD_MS);
		}
	}

	function progressUrl() {
		var holder = document.querySelector('[data-erp222-progress]');

		return (holder && holder.getAttribute('data-erp222-progress')) || '/forge/progress';
	}

	function stopPolling() {
		if (timer) {
			clearInterval(timer);
			timer = null;
		}
	}

	function poll(url) {
		fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if ( ! data.ok || ! data.run) {
					// Цикл исчез из активных: сервер уже знает финал — показываем его.
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

	function startPolling(url) {
		if (timer) return;

		url = url || progressUrl();
		timer = setInterval(function () { poll(url); }, POLL_MS);
		poll(url);
	}

	// Имена этапов берём из уже отрисованной лестницы: дублировать список в JS незачем.
	function stageNames() {
		var names = [];

		if (root) {
			Array.prototype.forEach.call(root.querySelectorAll('[data-erp222-stage-item]'), function (item) {
				names.push((item.textContent || '').replace(/^\s+/, ''));
			});
		}

		return names;
	}

	// «Запустить цикл» / «Один шаг»: fetch вместо перехода, карточка оживает сразу.
	function initRunForm() {
		var form = document.querySelector('[data-erp222-run-form]');

		if ( ! form || ! window.fetch) return;

		var button  = form.querySelector('[data-erp222-run-button]');
		var oneStep = form.querySelector('[data-erp222-one-step]');
		var label   = form.querySelector('[data-erp222-run-label]');
		var url     = form.getAttribute('data-erp222-progress') || '/forge/progress';

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			// Кнопка-отправитель: без неё «Один шаг» (quick=one) потерялся бы, а
			// подпись «Запускаю…» встала бы не на ту кнопку, которую нажали.
			var submitter = e.submitter
				|| (document.activeElement && form.contains(document.activeElement) ? document.activeElement : null);

			var buttons = form.querySelectorAll('button[type="submit"]');

			Array.prototype.forEach.call(buttons, function (b) {
				b.disabled = true;
				b.title = 'Цикл уже идёт';
			});

			if (label && submitter !== oneStep) label.textContent = 'Запускаю…';

			renderRun({
				id: 0,
				status: 'new',
				stage: 'Готовлю роли мира…',
				stage_note: 'передаю задание воркеру',
				stage_index: -1,
				elapsed_sec: 0,
				counters_text: 'ставлю задачу в очередь…',
				stages: stageNames()
			});

			var body = new FormData(form);

			if (submitter && submitter.name) {
				body.append(submitter.name, submitter.value);
			}

			fetch(form.action, {
				method: 'POST',
				body: body,
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data && data.run) renderRun(data.run, { reload: true });

					if ( ! data || ! data.ok) {
						setError((data && data.error) || 'Не удалось запустить цикл');

						Array.prototype.forEach.call(buttons, function (b) {
							b.disabled = false;
							b.title = '';
						});

						if (label) label.textContent = 'Запустить цикл';
						stopTick();
						stopPolling();
						setIcon('stop');
						setPill(data && data.run ? data.run : null);
						return;
					}

					if (label) label.textContent = 'Роли работают…';
					startPolling(url);
				})
				.catch(function () {
					// fetch не сложился — отправляем форму как обычно (серверный
					// путь с 303 и плашкой): без JS и при старом браузере работает.
					stopTick();
					stopPolling();
					form.submit();
				});
		});
	}

	// Статус на момент загрузки: цикл идёт — сразу подхватываем опрос.
	function initRunProgress() {
		root = document.querySelector('[data-erp222-run]');

		if ( ! root) return;

		if (isLive({ status: root.getAttribute('data-erp222-status') })) {
			startTick();
			startPolling();
		}
	}

	/**
	 * «Скопировать документ»: спецификацию и промпт уносят в работу, поэтому
	 * копирование должно работать и без HTTPS-контекста (navigator.clipboard
	 * доступен только в защищённом), и без разрешений — тогда выделяем текст.
	 */
	function initCopy() {
		var buttons = document.querySelectorAll('[data-erp222-copy]');

		Array.prototype.forEach.call(buttons, function (button) {
			var id = button.getAttribute('data-erp222-copy');
			var textHolder = button.querySelector('[data-erp222-copy-text]');
			var idle = button.getAttribute('data-erp222-copy-label') || 'Скопировать документ';

			button.addEventListener('click', function () {
				var source = document.querySelector('[data-erp222-copy-source="' + id + '"]');

				if ( ! source) return;

				var text = source.textContent || '';

				function done(ok) {
					if (textHolder) textHolder.textContent = ok ? 'Скопировано' : 'Выделите текст вручную';

					setTimeout(function () {
						if (textHolder) textHolder.textContent = idle;
					}, 2500);
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
					return;
				}

				try {
					var area = document.createElement('textarea');
					area.value = text;
					area.setAttribute('readonly', 'readonly');
					area.style.position = 'fixed';
					area.style.top = '-1000px';
					document.body.appendChild(area);
					area.select();
					var ok = document.execCommand('copy');
					document.body.removeChild(area);
					done(ok);
				} catch (err) {
					done(false);
				}
			});
		});
	}

	function boot() {
		initRunProgress();
		initRunForm();
		initCopy();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
