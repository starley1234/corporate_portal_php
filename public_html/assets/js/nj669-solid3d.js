/* NJ669: формы, двухшаговое удаление и опрос своей очереди; GLB — общий просмотрщик. */
(() => {
	'use strict';
	let dirty = false;
	document.querySelectorAll('[data-solid-form]').forEach((form) => {
		form.addEventListener('input', () => { dirty = true; });
		form.addEventListener('submit', () => {
			dirty = false;
			// Не отключаем кнопки синхронно: иначе их name/value не попадут в POST.
			setTimeout(() => { form.querySelectorAll('button[type="submit"], button:not([type])').forEach((b) => { b.disabled = true; }); }, 0);
		});
	});
	// Вкладки левой колонки. Выбор помним на вкладке браузера: после сохранения страница перезагружается.
	const tabsNav = document.querySelector('[data-solid-tabs]');
	if (tabsNav) {
		const key = 'nj669-tab:' + window.location.pathname;
		const tabButtons = Array.from(tabsNav.querySelectorAll('[data-solid-tab]'));
		const select = (name) => {
			tabButtons.forEach((b) => { b.setAttribute('aria-selected', b.dataset.solidTab === name ? 'true' : 'false'); });
			document.querySelectorAll('[data-solid-panel]').forEach((panel) => { panel.hidden = panel.dataset.solidPanel !== name; });
			try { window.sessionStorage.setItem(key, name); } catch (error) { /* приватный режим: просто не запоминаем */ }
		};
		let saved = null;
		try { saved = window.sessionStorage.getItem(key); } catch (error) { saved = null; }
		select(tabButtons.some((b) => b.dataset.solidTab === saved) ? saved : tabsNav.dataset.solidTabs);
		tabButtons.forEach((b) => b.addEventListener('click', () => select(b.dataset.solidTab)));
	}
	// Размеры: все типы полей, точное число рядом с ползунком и слоты значений в браузере (localStorage).
	document.querySelectorAll('[data-solid-params]').forEach((form) => {
		const fields = Array.from(form.querySelectorAll('[data-param-field]'));
		const apply = form.querySelector('[data-param-apply]');
		const hint = form.querySelector('[data-param-hint]');
		const key = form.dataset.slotsKey || 'nj669-slots';
		// export: STL собирается по любым значениям (кнопка не ждёт изменений); parameters: только при изменениях.
		const exportMode = form.dataset.paramMode === 'export';
		if (hint) { hint.dataset.idle = hint.textContent; }
		const inputOf = (field) => field.querySelector('[data-param-input]');
		const valueOf = (input) => (input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value);
		const setValue = (field, value) => {
			const input = inputOf(field);
			if (!input) { return; }
			if (input.type === 'checkbox') { input.checked = value === '1'; } else { input.value = value; }
		};
		const sync = () => {
			let changed = 0;
			fields.forEach((field) => {
				const input = inputOf(field);
				if (!input) { return; }
				const num = field.querySelector('[data-param-number]');
				if (num && document.activeElement !== num) { num.value = input.value; }
				if (valueOf(input) !== field.dataset.initial) { changed += 1; }
			});
			if (apply) { apply.disabled = !exportMode && changed === 0; }
			if (hint) { hint.textContent = changed ? (hint.dataset.hintChanged || '').replace('{n}', String(changed)) : hint.dataset.idle; }
		};
		fields.forEach((field) => {
			const input = inputOf(field);
			if (input) { input.addEventListener('input', sync); input.addEventListener('change', sync); }
			const num = field.querySelector('[data-param-number]');
			if (num && input) { num.addEventListener('input', () => { if (num.value !== '') { input.value = num.value; sync(); } }); }
		});
		const write = (values) => {
			fields.forEach((field) => {
				const value = values ? values[field.dataset.paramName] : undefined;
				if (value !== undefined && value !== null) { setValue(field, String(value)); }
			});
			sync();
		};
		const readAll = () => {
			const out = {};
			fields.forEach((field) => { const input = inputOf(field); if (input) { out[field.dataset.paramName] = valueOf(input); } });
			return out;
		};
		const loadSlots = () => {
			try { const data = JSON.parse(window.localStorage.getItem(key) || '[]'); return Array.isArray(data) ? data.slice(0, 3) : []; }
			catch (error) { return []; }
		};
		const paintSlots = () => {
			const slots = loadSlots();
			form.querySelectorAll('[data-slot-group]').forEach((group) => {
				const n = Number(group.dataset.slotGroup);
				const filled = Boolean(slots[n]);
				const load = group.querySelector('[data-slot-load]');
				if (load) { load.title = 'Слот ' + (n + 1) + (filled ? ': загрузить сохранённые значения' : ': пусто'); load.classList.toggle('text-blue-700', filled); }
			});
		};
		form.querySelectorAll('[data-slot-default]').forEach((button) => {
			button.addEventListener('click', () => { const defaults = {}; fields.forEach((f) => { defaults[f.dataset.paramName] = f.dataset.default; }); write(defaults); });
		});
		form.querySelectorAll('[data-slot-load]').forEach((button) => {
			button.addEventListener('click', () => { const slots = loadSlots(); const n = Number(button.dataset.slotLoad); if (slots[n]) { write(slots[n]); } });
		});
		form.querySelectorAll('[data-slot-save]').forEach((button) => {
			button.addEventListener('click', () => {
				const slots = loadSlots(); const n = Number(button.dataset.slotSave);
				while (slots.length < 3) { slots.push(null); }
				slots[n] = readAll();
				try { window.localStorage.setItem(key, JSON.stringify(slots)); } catch (error) { /* хранилище недоступно: слот не сохранится */ }
				paintSlots();
				if (hint) { hint.textContent = 'Значения сохранены в слот ' + (n + 1) + ' в этом браузере.'; }
			});
		});
		const reset = form.querySelector('[data-param-reset]');
		if (reset) { reset.addEventListener('click', () => { fields.forEach((f) => setValue(f, f.dataset.initial)); sync(); }); }
		paintSlots();
		sync();
	});
	document.querySelectorAll('[data-solid-idea]').forEach((button) => {
		button.addEventListener('click', () => {
			const prompt = document.getElementById('solid-prompt');
			if (prompt) { prompt.value = button.dataset.solidIdea; prompt.focus(); dirty = true; }
		});
	});
	// Удаление подтверждает общий app.js: идея и версия — вторым нажатием
	// (data-n2j-arm), проект — окном (data-n2j-confirm). Сюда клик доходит уже
	// подтверждённым; без armed=1 сервер удаление не выполнит.
	document.querySelectorAll('[data-solid-delete]').forEach((button) => {
		button.addEventListener('click', () => {
			button.form.querySelector('[name="armed"]').value = '1';
			button.form.requestSubmit();
		});
	});

	const root = document.querySelector('[data-solid-poll]');
	if (!root) { return; }
	const clock = document.querySelector('[data-solid-clock]');
	const tick = () => {
		if (!clock) { return; }
		const seconds = Math.max(0, Math.floor(Date.now() / 1000 - Number(clock.dataset.solidClock)));
		clock.textContent = `· ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
	};
	tick();
	const ticker = setInterval(tick, 1000);
	let stopped = false;
	let failures = 0;
	const poll = async () => {
		if (stopped) { return; }
		if (document.hidden) { setTimeout(poll, 4000); return; }
		try {
			const response = await fetch(root.dataset.solidPoll, { credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(15000) });
			if (response.status === 401 || response.status === 403 || response.status === 404) { throw new Error('Доступ к проекту изменился. Обновите страницу.'); }
			if (!response.ok) { throw new Error('Не удалось проверить задачу. Обновите страницу позже.'); }
			const state = await response.json();
			if (stopped) { return; }
			if (!state.ok || !state.signature) { throw new Error('Сервер вернул некорректное состояние.'); }
			failures = 0;
			if (state.live) {
				const phase = document.querySelector('[data-solid-stage]');
				if (phase) { phase.textContent = state.live.stage || 'Ожидаем результат'; }
				const heartbeat = document.querySelector('[data-solid-heartbeat]');
				if (heartbeat) { heartbeat.textContent = Number(state.live.silent_sec) > 90 ? `Воркер не подавал сигнал ${Math.floor(state.live.silent_sec / 60)} мин. Проверяем состояние очереди.` : ''; }
			}
			if (state.signature !== root.dataset.signature) {
				// queued → running — не результат: обновляем сигнатуру без перезагрузки
				// по готовности, когда сервер убрал busy.
				root.dataset.signature = state.signature;
				if (state.busy !== false) { setTimeout(poll, 4000); return; }
				stopped = true;
				clearInterval(ticker);
				if (dirty) { document.getElementById('solid-new-result').hidden = false; }
				else { window.location.reload(); }
				return;
			}
		} catch (error) {
			failures++;
			if (failures >= 5) {
				stopped = true; clearInterval(ticker);
				const message = document.getElementById('solid-new-result');
				message.hidden = false;
				message.textContent = error.message + ' Работа воркера продолжается независимо от страницы.';
				return;
			}
		}
		setTimeout(poll, Math.min(15000, 4000 * (failures + 1)));
	};
	setTimeout(poll, 3000);
	window.addEventListener('pagehide', () => { stopped = true; clearInterval(ticker); }, { once: true });
})();
