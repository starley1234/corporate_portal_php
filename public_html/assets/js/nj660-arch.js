/**
 * nj660_arch — конструктор архитектуры ИИ.
 *
 * Страница /arch/new и /arch/edit/<code>:
 *   - палитра блоков → стек (добавить/удалить/двигать/repeat);
 *   - живой пересчёт метрик: POST /arch/api/evaluate (дебаунс 400 мс);
 *   - сохранение: DSL сериализуется в скрытое поле формы.
 *
 * Страница /arch/view/<code>:
 *   - симулятор: чат с «ожившей» моделью через POST /arch/api/simulate;
 *   - подтверждение удаления проекта.
 *
 * Внешних скриптов нет — только этот файл и локальный CSS платформы.
 */
(function () {
	'use strict';

	// ---------------------------------------------------------------- утилиты

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function fmtParams(n) {
		n = Number(n) || 0;
		if (n >= 1e12) return (n / 1e12).toFixed(2) + 'T';
		if (n >= 1e9) return (n / 1e9).toFixed(2) + 'B';
		if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
		if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
		return String(Math.round(n));
	}

	function fmtBytes(n) {
		n = Number(n) || 0;
		var u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'], i = 0;
		while (n >= 1024 && i < 4) { n /= 1024; i++; }
		return (i > 1 ? n.toFixed(1) : Math.round(n)) + ' ' + u[i];
	}

	function fmtNum(n) {
		return String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
	}

	function debounce(fn, ms) {
		var t = null;
		return function () {
			var args = arguments, self = this;
			if (t) clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, args); }, ms);
		};
	}

	// ---------------------------------------------------------------- редактор

	var state = null;
	var palette = null;

	var VARIANTS = {
		pos: ['rope', 'alibi', 'learned', 'none'],
		norm: ['rms', 'layer'],
		attn: ['mha', 'gqa', 'mqa', 'mla', 'sliding', 'linear'],
		ffn: ['swiglu', 'gelu', 'glu'],
		head: ['lm', 'diffusion'],
		hookKind: ['steer', 'lora', 'tap', 'exit']
	};

	var GROUP_COLORS = {
		'Входы': 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
		'Слои': 'bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-300',
		'Хуки': 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
		'Выходы': 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
	};

	function initEditor() {
		var root = document.getElementById('nj660-palette');

		if ( ! root || ! window.NJ660) return;

		palette = window.NJ660.palette;
		state = JSON.parse(JSON.stringify(window.NJ660.dsl));

		if ( ! state.config) state.config = { d: 1024, S: 4096 };
		if ( ! state.stack) state.stack = [];

		renderPalette();
		bindConfig();
		renderStack();
		evaluateSoon();

		var form = document.getElementById('nj660-form');

		if (form) {
			form.addEventListener('submit', function () {
				document.getElementById('nj660-dsl').value = JSON.stringify(state);
			});
		}
	}

	function renderPalette() {
		var root = document.getElementById('nj660-palette');
		var byGroup = {};

		Object.keys(palette).forEach(function (t) {
			var g = palette[t].group || 'Слои';
			(byGroup[g] = byGroup[g] || []).push(t);
		});

		root.innerHTML = Object.keys(byGroup).map(function (g) {
			return '<div><p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">'
				+ esc(g) + '</p><div class="mt-1.5 space-y-1.5">'
				+ byGroup[g].map(function (t) {
					return '<button type="button" data-add="' + esc(t)
						+ '" class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-2 text-left text-xs font-semibold text-gray-700 transition hover:border-blue-400 hover:text-blue-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:text-blue-300">'
						+ esc(palette[t].label)
						+ '<span class="mt-0.5 block text-xs font-normal leading-snug text-gray-400 dark:text-gray-500">' + esc(palette[t].note) + '</span></button>';
				}).join('') + '</div></div>';
		}).join('');

		root.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-add]');

			if ( ! btn) return;

			var t = btn.getAttribute('data-add');
			var block = { t: t };

			Object.keys(palette[t].fields || {}).forEach(function (k) {
				block[k] = palette[t].fields[k];
			});

			if (palette[t].layer) block.n = 1;

			if (t === 'hook') block.at = Math.max(1, countLayers());

			state.stack.push(block);
			renderStack();
			evaluateSoon();
		});
	}

	function countLayers() {
		var n = 0;

		state.stack.forEach(function (b) {
			if (palette[b.t] && palette[b.t].layer) n += Math.max(1, parseInt(b.n, 10) || 1);
		});

		return n;
	}

	function bindConfig() {
		var fam = document.getElementById('nj660-family');
		var d = document.getElementById('nj660-d');
		var s = document.getElementById('nj660-s');

		if (fam) {
			fam.value = state.family || 'decoder';
			fam.addEventListener('change', function () { state.family = fam.value; evaluateSoon(); });
		}

		if (d) {
			d.value = state.config.d;
			d.addEventListener('input', function () { state.config.d = parseInt(d.value, 10) || 0; evaluateSoon(); });
		}

		if (s) {
			s.value = state.config.S;
			s.addEventListener('input', function () { state.config.S = parseInt(s.value, 10) || 0; evaluateSoon(); });
		}
	}

	function fieldInput(block, key, type, extra) {
		var val = block[key];

		if (type === 'check') {
			return '<label class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><input type="checkbox" data-k="' + esc(key)
				+ (extra ? '" data-extra="' + esc(extra) : '')
				+ '" ' + (val ? 'checked' : '') + ' class="h-4 w-4 rounded border-gray-300 text-blue-600 dark:border-gray-600 dark:bg-gray-900">'
				+ esc(key === 'tied' ? 'tied с эмбеддингом' : 'общий эксперт') + '</label>';
		}

		if (type === 'select') {
			var opts = extra || [];
			return '<select data-k="' + esc(key) + '" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white">'
				+ opts.map(function (o) {
					return '<option value="' + esc(o) + '"' + (String(val) === o ? ' selected' : '') + '>' + esc(o) + '</option>';
				}).join('') + '</select>';
		}

		var step = key === 's' ? '0.1' : '1';
		return '<input type="number" step="' + step + '" data-k="' + esc(key) + '" value="' + esc(val)
			+ '" class="w-20 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white">';
	}

	function labelWrap(label, inner) {
		return '<label class="flex flex-col gap-1"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">' + esc(label) + '</span>' + inner + '</label>';
	}

	function renderStack() {
		var root = document.getElementById('nj660-stack');
		var empty = document.getElementById('nj660-empty');

		if (empty) empty.classList.toggle('hidden', state.stack.length > 0);

		root.innerHTML = state.stack.map(function (b, i) {
			var meta = palette[b.t] || { label: b.t, group: '' };
			var color = GROUP_COLORS[meta.group] || GROUP_COLORS['Слои'];
			var fields = [];

			if (b.t === 'emb') {
				fields.push(labelWrap('словарь V', fieldInput(b, 'V')));
				fields.push(fieldInput(b, 'tied', 'check'));
			} else if (b.t === 'patch') {
				fields.push(labelWrap('патч P', fieldInput(b, 'P')));
				fields.push(labelWrap('каналы C', fieldInput(b, 'C')));
			} else if (b.t === 'audio') {
				fields.push(labelWrap('mel-бины', fieldInput(b, 'mel')));
			} else if (b.t === 'pos') {
				fields.push(labelWrap('вид', fieldInput(b, 'v', 'select', VARIANTS.pos)));
			} else if (b.t === 'attn') {
				fields.push(labelWrap('вариант', fieldInput(b, 'v', 'select', VARIANTS.attn)));
				fields.push(labelWrap('голов H', fieldInput(b, 'H')));
				fields.push(labelWrap('KV-голов', fieldInput(b, 'KV')));
				if (b.v === 'sliding') fields.push(labelWrap('окно W', fieldInput(b, 'W')));
			} else if (b.t === 'xattn') {
				fields.push(labelWrap('голов H', fieldInput(b, 'H')));
				fields.push(labelWrap('KV-голов', fieldInput(b, 'KV')));
			} else if (b.t === 'ffn') {
				fields.push(labelWrap('вариант', fieldInput(b, 'v', 'select', VARIANTS.ffn)));
				fields.push(labelWrap('скрытая h', fieldInput(b, 'h')));
			} else if (b.t === 'moe') {
				fields.push(labelWrap('экспертов E', fieldInput(b, 'E')));
				fields.push(labelWrap('top-k', fieldInput(b, 'k')));
				fields.push(labelWrap('h эксперта', fieldInput(b, 'h')));
				fields.push(fieldInput(b, 'shared', 'check'));
			} else if (b.t === 'ssm') {
				fields.push(labelWrap('state', fieldInput(b, 'state')));
				fields.push(labelWrap('expand', fieldInput(b, 'expand')));
			} else if (b.t === 'norm') {
				fields.push(labelWrap('вид', fieldInput(b, 'v', 'select', VARIANTS.norm)));
			} else if (b.t === 'hook') {
				fields.push(labelWrap('вид', fieldInput(b, 'kind', 'select', VARIANTS.hookKind)));
				fields.push(labelWrap('слой at', fieldInput(b, 'at')));
				if (b.kind === 'steer') {
					fields.push(labelWrap('сила s', fieldInput(b, 's')));
					fields.push('<label class="flex flex-[2] flex-col gap-1"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">смысл</span><input type="text" data-k="text" maxlength="120" value="' + esc(b.text) + '" class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white" placeholder="например: точность формулировок"></label>');
				}
				if (b.kind === 'lora') fields.push(labelWrap('ранг r', fieldInput(b, 'r')));
				if (b.kind === 'tap' || b.kind === 'exit') {
					fields.push('<label class="flex flex-[2] flex-col gap-1"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">заметка</span><input type="text" data-k="note" maxlength="120" value="' + esc(b.note) + '" class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white"></label>');
				}
			} else if (b.t === 'head') {
				fields.push(labelWrap('вид', fieldInput(b, 'v', 'select', VARIANTS.head)));
			}

			var repeat = meta.layer
				? '<label class="flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">×повтор</span><input type="number" min="1" max="200" data-k="n" value="' + esc(b.n || 1) + '" class="w-16 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white"></label>'
				: '';

			return '<div class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-xs dark:border-gray-700 dark:bg-gray-800" data-i="' + i + '">'
				+ '<div class="flex items-center gap-2">'
				+ '<span class="rounded-full px-2 py-0.5 text-xs font-bold ' + color + '">' + esc(meta.group) + '</span>'
				+ '<p class="text-sm font-semibold text-gray-900 dark:text-white">' + esc(meta.label) + '</p>'
				+ '<span class="ml-auto flex items-center gap-1">'
				+ '<button type="button" data-move="-1" class="rounded px-1.5 py-0.5 text-xs text-gray-400 hover:text-blue-600 dark:hover:text-blue-300" title="выше">↑</button>'
				+ '<button type="button" data-move="1" class="rounded px-1.5 py-0.5 text-xs text-gray-400 hover:text-blue-600 dark:hover:text-blue-300" title="ниже">↓</button>'
				+ '<button type="button" data-del="1" class="rounded px-1.5 py-0.5 text-xs text-gray-400 hover:text-rose-500" title="удалить">✕</button>'
				+ '</span></div>'
				+ '<div class="mt-2 flex flex-wrap items-end gap-2.5">' + fields.join('') + repeat + '</div>'
				+ '</div>';
		}).join('');
	}

	function bindStackEvents() {
		var root = document.getElementById('nj660-stack');

		if ( ! root) return;

		root.addEventListener('click', function (e) {
			var row = e.target.closest('[data-i]');

			if ( ! row) return;

			var i = parseInt(row.getAttribute('data-i'), 10);

			if (e.target.closest('[data-del]')) {
				state.stack.splice(i, 1);
				renderStack();
				evaluateSoon();
				return;
			}

			var mv = e.target.closest('[data-move]');

			if (mv) {
				var to = i + parseInt(mv.getAttribute('data-move'), 10);

				if (to >= 0 && to < state.stack.length) {
					var tmp = state.stack[i];
					state.stack[i] = state.stack[to];
					state.stack[to] = tmp;
					renderStack();
					evaluateSoon();
				}
			}
		});

		root.addEventListener('input', function (e) {
			var row = e.target.closest('[data-i]');

			if ( ! row || ! e.target.dataset.k) return;

			var i = parseInt(row.getAttribute('data-i'), 10);
			var k = e.target.dataset.k;

			if (e.target.type === 'checkbox') {
				state.stack[i][k] = e.target.checked;
			} else if (e.target.type === 'number') {
				state.stack[i][k] = k === 's' ? parseFloat(e.target.value) : parseInt(e.target.value, 10);
			} else {
				state.stack[i][k] = e.target.value;
			}

			evaluateSoon();
		});

		root.addEventListener('change', function (e) {
			var row = e.target.closest('[data-i]');

			if ( ! row || ! e.target.dataset.k) return;

			var i = parseInt(row.getAttribute('data-i'), 10);
			var k = e.target.dataset.k;

			if (e.target.tagName === 'SELECT') {
				state.stack[i][k] = e.target.value;
				renderStack(); // вариант блока меняет набор полей
				evaluateSoon();
			}
		});
	}

	// ------------------------------------------------------------ пересчёт

	var evaluateSoon = debounce(function () {
		fetch('/arch/api/evaluate', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ dsl: state })
		}).then(function (r) { return r.json(); }).then(function (data) {
			renderMetrics(data);
		}).catch(function () {
			var box = document.getElementById('nj660-metrics');

			if (box) box.innerHTML = '<p class="text-xs text-rose-500">Сеть недоступна — метрики не обновились</p>';
		});
	}, 400);

	var WARN_STYLE = {
		error: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300',
		warn: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
		info: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200',
		ok: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200'
	};

	function renderMetrics(data) {
		var box = document.getElementById('nj660-metrics');

		if ( ! box) return;

		if ( ! data.ok) {
			box.innerHTML = '<div class="space-y-1.5">'
				+ (data.errors || ['неизвестная ошибка']).map(function (e2) {
					return '<p class="n2j-flash n2j-flash-err block px-2.5 py-1.5 text-xs">' + esc(e2) + '</p>';
				}).join('') + '</div>';
			return;
		}

		var t = data.totals;
		var html = '';

		html += '<div class="grid grid-cols-2 gap-2">'
			+ metric('Параметров', fmtParams(t.params_total), 'активных ' + fmtParams(t.params_active))
			+ metric('Слоёв', String(t.depth), 'внимания ' + t.n_attn + ' · MoE ' + t.n_moe + ' · SSM ' + t.n_ssm)
			+ metric('VRAM инференс', fmtBytes(t.vram_infer), 'обучение ' + fmtBytes(t.vram_train))
			+ metric('FLOPs/токен', fmtNum(t.flops_fwd_token), 'KV-cache ' + fmtBytes(t.kv_cache_seq))
			+ '</div>';

		html += '<div class="n2j-flash n2j-flash-info block px-3 py-2 text-xs">'
			+ '<b>Обучение</b> (Chinchilla): ' + fmtNum(t.tokens_opt) + ' токенов ≈ '
			+ fmtNum(t.gpu_hours) + ' GPU-ч (' + esc(t.gpu_name) + ', MFU 40%) ≈ $' + fmtNum(t.gpu_cost)
			+ '</div>';

		if (t.params_lora > 0) {
			html += '<div class="n2j-flash n2j-flash-info block px-3 py-2 text-xs">'
				+ '<b>LoRA-файнтюн:</b> обучается ' + fmtParams(t.params_lora) + ' (' + t.lora_train_pct.toFixed(2) + '%)</div>';
		}

		if (data.compare && data.compare.length) {
			html += '<p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Ближайшие в зоопарке</p><ul class="space-y-1">'
				+ data.compare.slice(0, 3).map(function (c) {
					return '<li class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-300"><span class="truncate">' + esc(c.title) + '</span><span class="ml-2 shrink-0 font-mono">' + esc(fmtRatio(c.ratio_total)) + '</span></li>';
				}).join('') + '</ul>';
		}

		if (data.warnings && data.warnings.length) {
			html += '<p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Разбор</p><div class="space-y-1.5">'
				+ data.warnings.map(function (w) {
					var cls = WARN_STYLE[w.kind] || WARN_STYLE.info;
					return '<p class="rounded-lg border px-2.5 py-1.5 text-xs leading-snug ' + cls + '">' + esc(w.text) + '</p>';
				}).join('') + '</div>';
		}

		if (data.blocks && data.blocks.length) {
			html += '<details class="text-xs"><summary class="cursor-pointer font-semibold text-gray-500 dark:text-gray-400">Стек и формулы (' + data.blocks.length + ')</summary><ul class="mt-1.5 space-y-1">'
				+ data.blocks.map(function (b) {
					return '<li class="flex items-baseline justify-between gap-2"><span class="truncate text-gray-600 dark:text-gray-300">' + esc(b.label) + '</span><span class="shrink-0 font-mono text-xs text-gray-400">' + esc(b.formula) + ' = ' + fmtParams(b.params) + '</span></li>';
				}).join('') + '</ul></details>';
		}

		box.innerHTML = html;
	}

	function metric(label, value, sub) {
		return '<div class="rounded-lg border border-gray-200 px-2.5 py-2 dark:border-gray-700">'
			+ '<p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">' + esc(label) + '</p>'
			+ '<p class="mt-0.5 text-base font-bold text-gray-900 dark:text-white">' + esc(value) + '</p>'
			+ '<p class="text-xs text-gray-400 dark:text-gray-500">' + esc(sub) + '</p></div>';
	}

	function fmtRatio(r) {
		if (r >= 1) return '×' + (r >= 10 ? r.toFixed(0) : r.toFixed(1));
		return '×1/' + (r < 0.1 ? Math.round(1 / r) : (1 / r).toFixed(1));
	}

	// ------------------------------------------------------------ страница модели

	function initView() {
		var root = document.querySelector('[data-nj660-view]');

		if ( ! root) return;

		var form = document.getElementById('nj660-chat-form');
		var input = document.getElementById('nj660-chat-input');
		var chat = document.getElementById('nj660-chat');
		var history = [];
		var busy = false;

		// восстановить историю из отрендеренных прогонов
		if (chat) {
			chat.querySelectorAll('p').forEach(function (p) {
				var isUser = p.className.indexOf('bg-blue-600') !== -1;

				history.push({ role: isUser ? 'user' : 'assistant', content: p.textContent });
			});
		}

		if (form && input) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();

				var prompt = input.value.trim();

				if ( ! prompt || busy) return;

				busy = true;
				input.value = '';

				appendMsg('user', prompt);
				history.push({ role: 'user', content: prompt });

				var thinking = appendMsg('assistant', '…модель думает…');
				thinking.classList.add('opacity-60');

				fetch('/arch/api/simulate', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ code: root.getAttribute('data-code'), prompt: prompt, history: history.slice(0, -1) })
				}).then(function (r) { return r.json(); }).then(function (data) {
					thinking.remove();

					if (data.ok) {
						appendMsg('assistant', data.answer);
						history.push({ role: 'assistant', content: data.answer });
						var hint = document.getElementById('nj660-chat-hint');

						if (hint) hint.textContent = 'Отвечал движок ' + data.model + ' за ' + data.ms + ' мс';
					} else {
						appendError(data.error || 'движок не ответил');

						if (data.login) window.location = data.login;
					}
				}).catch(function () {
					thinking.remove();
					appendError('сеть недоступна');
				}).then(function () {
					busy = false;
				});
			});
		}

		function appendMsg(role, text) {
			var p = document.createElement('p');

			p.className = role === 'user'
				? 'ml-auto max-w-[85%] rounded-xl rounded-br-sm bg-blue-600 px-3 py-2 text-sm text-white shadow-xs'
				: 'max-w-[85%] whitespace-pre-line rounded-xl rounded-bl-sm bg-white px-3 py-2 text-sm text-gray-800 shadow-xs dark:bg-gray-800 dark:text-gray-100';
			p.textContent = text;

			if (chat) {
				chat.appendChild(p);
				chat.scrollTop = chat.scrollHeight;
			}

			return p;
		}

		function appendError(text) {
			var p = document.createElement('p');

			p.className = 'n2j-flash n2j-flash-err block max-w-[85%] px-3 py-2 text-xs';
			p.textContent = text;

			if (chat) {
				chat.appendChild(p);
				chat.scrollTop = chat.scrollHeight;
			}
		}

		var del = document.querySelector('[data-nj660-delete]');

		if (del) {
			del.addEventListener('submit', function (e) {
				if ( ! window.confirm('Удалить проект вместе с историей прогонов?')) e.preventDefault();
			});
		}
	}

	// ---------------------------------------------------------------- запуск

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initEditor();
			bindStackEvents();
			initView();
		});
	} else {
		initEditor();
		bindStackEvents();
		initView();
	}
})();
