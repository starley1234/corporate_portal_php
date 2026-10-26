<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Генератор favicon (nj663) — клиент: превью на канвасе и сборка комплекта.
 *
 * Зачем канвас: цветные эмодзи умеет рисовать только браузер (GD работает с
 * монохромными глифами), а предпросмотр «как в строке браузера» нужен до
 * обращения к серверу. Браузер собирает полотно 512×512, сервер размножает
 * его по размерам — поэтому в запросе стоит composed: TRUE (не применять
 * фон/отступ/скругление второй раз).
 *
 * Управление символом (шрифт, размер, жирный, курсив, цвет) живёт здесь же:
 * буква рисуется системным стеком браузера — ничего не качаем из сети.
 * Стеки шрифтов приходят с сервера (config/nj663_favicon.php → 'fonts'),
 * чтобы список был в одном месте, а не продублирован в JS.
 *
 * Без JS форма постится как есть: сервер сам соберёт полотно из файла.
 */
$fonts     = isset($fonts) ? (array) $fonts : [];
$base_size = (int) (isset($base_size) ? $base_size : 512);
$default_font = (string) (isset($defaults) ? Arr::get($defaults, 'font', '') : '');
$font_css  = [];

foreach ($fonts as $key => $info)
{
	$font_css[(string) $key] = (string) Arr::get($info, 'css', 'sans-serif');
}

$max_mb = (int) (isset($limits) ? Arr::get($limits, 'max_input_mb', 8) : 8);
?>
<script>
(function () {
	'use strict';

	var SIZE = <?php echo (int) $base_size; ?>;
	var MAX_MB = <?php echo (int) $max_mb; ?>;
	var FONT_CSS = <?php echo json_encode($font_css, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	var EMOJI = '"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif';

	var $ = function (id) { return document.getElementById(id); };

	var form   = $('nj663-form');
	var canvas = $('nj663-canvas');

	if ( ! form || ! canvas) { return; }

	var ctx     = canvas.getContext('2d');
	var file    = $('nj663-file');
	var drop    = $('nj663-drop');
	var text    = $('nj663-text');
	var fg      = $('nj663-fg');
	var bg      = $('nj663-bg');
	var alpha   = $('nj663-alpha');
	var fit     = $('nj663-fit');
	var radius  = $('nj663-radius');
	var pad     = $('nj663-pad');
	var padRow  = $('nj663-pad-row');
	var fontSel = $('nj663-font');
	var share   = $('nj663-font-share');
	var boldBtn = $('nj663-bold');
	var italBtn = $('nj663-italic');
	var status  = $('nj663-status');
	var result  = $('nj663-result');
	var csrf    = $('nj663-csrf');
	var button  = $('nj663-go');
	var note    = $('nj663-image-note');

	var mode  = 'text';        // «Буква или эмодзи» — по умолчанию (владелец, 09.10.2026)
	var image = null;

	// --- вкладки ----------------------------------------------------------
	function tab(next) {
		mode = next;

		Array.prototype.forEach.call(document.querySelectorAll('.nj663-tab'), function (b) {
			var on = b.getAttribute('data-tab') === next;

			b.className = 'nj663-tab n2j-chip flex-1 justify-center' + (on ? ' is-on' : '');
		});

		Array.prototype.forEach.call(document.querySelectorAll('.nj663-pane'), function (p) {
			p.classList.toggle('hidden', p.getAttribute('data-pane') !== next);
		});

		// Отступ от края влияет только на картинку: букву центрует размер шрифта.
		if (padRow) { padRow.classList.toggle('hidden', next !== 'image'); }

		draw();
	}

	Array.prototype.forEach.call(document.querySelectorAll('.nj663-tab'), function (b) {
		b.addEventListener('click', function () { tab(b.getAttribute('data-tab')); });
	});

	// --- начертание: жирный / курсив -------------------------------------
	function toggleStyle(btn) {
		if ( ! btn) { return; }

		btn.addEventListener('click', function () {
			var on = btn.getAttribute('data-on') === '1' ? '0' : '1';

			btn.setAttribute('data-on', on);
			btn.className = 'n2j-chip flex-1 justify-center' + (btn === italBtn ? ' italic' : ' font-bold') + (on === '1' ? ' is-on' : '');

			draw();
		});
	}

	toggleStyle(boldBtn);
	toggleStyle(italBtn);

	/** Строка шрифта для канваса: начертание + размер + стек + эмодзи в запас. */
	function fontString() {
		var px     = Math.round(SIZE * (parseInt(share.value, 10) || 56) / 100);
		var stack  = FONT_CSS[fontSel ? fontSel.value : ''] || 'sans-serif';
		var weight = (boldBtn && boldBtn.getAttribute('data-on') === '1') ? 'bold ' : '';
		var style  = (italBtn && italBtn.getAttribute('data-on') === '1') ? 'italic ' : '';

		return weight + style + px + 'px ' + stack + ',' + EMOJI;
	}

	// --- отрисовка -------------------------------------------------------
	function rounded(c, x, y, w, h, r) {
		c.beginPath();
		c.moveTo(x + r, y);
		c.lineTo(x + w - r, y);
		c.quadraticCurveTo(x + w, y, x + w, y + r);
		c.lineTo(x + w, y + h - r);
		c.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
		c.lineTo(x + r, y + h);
		c.quadraticCurveTo(x, y + h, x, y + h - r);
		c.lineTo(x, y + r);
		c.quadraticCurveTo(x, y, x + r, y);
		c.closePath();
	}

	function draw() {
		var r = SIZE * (parseInt(radius.value, 10) || 0) / 100;
		var p = SIZE * (parseInt(pad.value, 10) || 0) / 100;

		ctx.clearRect(0, 0, SIZE, SIZE);

		// подложка
		if ( ! alpha.checked) {
			ctx.fillStyle = bg.value;
			ctx.fillRect(0, 0, SIZE, SIZE);
		}

		var inner = SIZE - p * 2;
		var value = text.value.trim();

		if (mode === 'text' && value !== '') {
			var px = Math.round(SIZE * (parseInt(share.value, 10) || 56) / 100);

			ctx.fillStyle = fg.value;
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.font = fontString();
			ctx.fillText(value, SIZE / 2, SIZE / 2 + px * 0.04);
		}
		else if (image) {
			var w = inner;
			var h = inner;
			var scale = fit.value === 'cover'
				? Math.max(inner / image.width, inner / image.height)
				: Math.min(inner / image.width, inner / image.height);

			if (fit.value !== 'stretch') {
				w = image.width * scale;
				h = image.height * scale;
			}

			ctx.drawImage(image, (SIZE - w) / 2, (SIZE - h) / 2, w, h);
		}
		else {
			// Ничего не выбрано — подсказка «буква N» прямо на полотне.
			var hint = Math.round(SIZE * 0.42);

			ctx.fillStyle = alpha.checked ? '#9ca3af' : fg.value;
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.font = 'bold ' + hint + 'px ' + (FONT_CSS[fontSel ? fontSel.value : ''] || 'sans-serif') + ',' + EMOJI;
			ctx.fillText(value || 'N', SIZE / 2, SIZE / 2);
		}

		// скругление углов (маской по готовому полотну)
		if (r > 0) {
			ctx.globalCompositeOperation = 'destination-in';
			ctx.fillStyle = '#000';
			rounded(ctx, 0, 0, SIZE, SIZE, r);
			ctx.fill();
			ctx.globalCompositeOperation = 'source-over';
		}

		minis();
	}

	function minis() {
		[['nj663-mini-32', 32], ['nj663-mini-16', 16], ['nj663-mini-tab', 16]].forEach(function (pair) {
			var el = $(pair[0]);

			if ( ! el) { return; }

			var c = el.getContext('2d');
			c.clearRect(0, 0, pair[1], pair[1]);
			c.drawImage(canvas, 0, 0, pair[1], pair[1]);
		});
	}

	// --- картинка: выбор, перетаскивание, вставка из буфера ---------------
	function maxBytes() { return MAX_MB * 1024 * 1024; }

	function loadFile(f) {
		if ( ! f) { return; }

		if (f.size > maxBytes()) {
			say('Файл больше ' + MAX_MB + ' МБ — выберите картинку поменьше.', 'error');
			return;
		}

		var reader = new FileReader();

		reader.onload = function (e) {
			var img = new Image();

			img.onload = function () {
				image = img;
				tab('image');

				if (note) {
					note.textContent = f.name + ' · ' + img.width + '×' + img.height;
					note.className = 'n2j-hint pb-2';
				}

				draw();
			};

			img.onerror = function () { say('Картинку не удалось прочитать.', 'error'); };
			img.src = e.target.result;
		};

		reader.readAsDataURL(f);
	}

	if (file) {
		file.addEventListener('change', function () { loadFile(file.files && file.files[0]); });
	}

	if (drop) {
		['dragenter', 'dragover'].forEach(function (type) {
			drop.addEventListener(type, function (e) {
				e.preventDefault();
				drop.classList.add('border-amber-600', 'bg-amber-50', 'dark:border-amber-500', 'dark:bg-amber-950/40');
			});
		});

		['dragleave', 'drop'].forEach(function (type) {
			drop.addEventListener(type, function (e) {
				e.preventDefault();
				drop.classList.remove('border-amber-600', 'bg-amber-50', 'dark:border-amber-500', 'dark:bg-amber-950/40');
			});
		});

		drop.addEventListener('drop', function (e) {
			var files = e.dataTransfer && e.dataTransfer.files;

			if (files && files.length) { loadFile(files[0]); }
		});

		// Клик по площадке открывает выбор файла (она же label, но без JS
		// клик всё равно работает — input просто скрыт).
		drop.addEventListener('click', function (e) {
			e.preventDefault();
			if (file) { file.click(); }
		});
	}

	document.addEventListener('paste', function (e) {
		var items = e.clipboardData && e.clipboardData.items;

		if ( ! items) { return; }

		for (var i = 0; i < items.length; i++) {
			if (items[i].type && items[i].type.indexOf('image/') === 0) {
				loadFile(items[i].getAsFile());
				return;
			}
		}
	});

	// --- ввод -------------------------------------------------------------
	[bg, fg].forEach(function (el) { if (el) { el.addEventListener('input', draw); } });
	[alpha, fit, fontSel].forEach(function (el) { if (el) { el.addEventListener('change', draw); } });
	[text].forEach(function (el) { if (el) { el.addEventListener('input', draw); } });

	[radius].forEach(function (el) { if (el) { el.addEventListener('input', function () {
		var out = $('nj663-radius-out');
		if (out) { out.textContent = el.value; }
		draw();
	}); } });

	// Размер шрифта показываем в пикселях полотна: процент ни о чём не говорит.
	[share].forEach(function (el) { if (el) { el.addEventListener('input', function () {
		var out = $('nj663-font-share-out');
		if (out) { out.textContent = Math.round(SIZE * (parseInt(el.value, 10) || 0) / 100); }
		draw();
	}); } });

	[pad].forEach(function (el) { if (el) { el.addEventListener('input', function () {
		var out = $('nj663-pad-out');
		if (out) { out.textContent = el.value; }
		draw();
	}); } });

	function say(message, kind) {
		if ( ! status) { return; }

		status.textContent = message || '';
		status.className = 'mt-2 text-center text-sm ' + (kind === 'error'
			? 'text-rose-600 dark:text-rose-400'
			: 'text-gray-500 dark:text-gray-400');
	}

	// --- отправка ---------------------------------------------------------
	form.addEventListener('submit', function (event) {
		event.preventDefault();

		if (button) { button.disabled = true; }
		say('Собираю комплект…');

		if (status) { status.innerHTML = '<i class="fas fa-circle-notch n2j-spin"></i> Собираю комплект…'; }

		var payload = {
			csrf:       csrf ? csrf.value : '',
			composed:   true,
			kind:       mode === 'text' ? 'text' : 'upload',
			image_b64:  canvas.toDataURL('image/png'),
			text:       text ? text.value : '',
			fg:         fg ? fg.value : '',
			bg:         bg ? bg.value : '',
			bg_alpha:   alpha ? alpha.checked : false,
			radius:     radius ? parseInt(radius.value, 10) : 0,
			pad:        pad ? parseInt(pad.value, 10) : 0,
			fit:        fit ? fit.value : 'contain',
			font:       fontSel ? fontSel.value : '',
			font_share: share ? parseInt(share.value, 10) : 0,
			bold:       boldBtn ? boldBtn.getAttribute('data-on') === '1' : false,
			italic:     italBtn ? italBtn.getAttribute('data-on') === '1' : false,
			base_path:  '/'
		};

		fetch('/favicon-gen/api/generate', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (button) { button.disabled = false; }

				if ( ! data || ! data.ok) {
					say((data && data.error) || 'Не удалось собрать комплект.', 'error');
					return;
				}

				say('Готово: файлов ' + data.files.length);
				show(data);
			})
			.catch(function () {
				if (button) { button.disabled = false; }
				say('Сервер не ответил — попробуйте ещё раз.', 'error');
			});
	});

	// --- результат --------------------------------------------------------
	function el(tag, className, textContent) {
		var node = document.createElement(tag);

		if (className) { node.className = className; }
		if (textContent !== undefined) { node.textContent = textContent; }

		return node;
	}

	function kb(bytes) {
		return Math.max(1, Math.round(bytes / 1024)) + ' КБ';
	}

	function show(data) {
		var head = result.querySelector('h2');
		var hint = result.querySelector('p');

		// Первый раз убираем заглушку «здесь появятся файлы».
		if (head) { head.remove(); }
		if (hint && hint.id !== 'nj663-status') { hint.remove(); }

		var old = result.querySelector('.nj663-files');

		if (old) { old.remove(); }

		var box  = el('div', 'nj663-files mt-5 border-t border-gray-200 pt-5 dark:border-gray-700');
		var headBox = el('div', 'flex flex-wrap items-center gap-2');
		var zip  = null;

		data.files.forEach(function (f) { if (/\.zip$/.test(f.name)) { zip = f; } });

		headBox.appendChild(el('h3', 'text-base font-bold text-gray-900 dark:text-white', 'Комплект готов'));

		if (zip) {
			var a = el('a', 'inline-flex min-h-[40px] items-center gap-2 rounded-lg bg-amber-600 px-3 text-sm font-semibold text-white transition hover:bg-amber-700');
			a.href = zip.url;
			a.innerHTML = '<i class="fas fa-file-zipper"></i> Скачать zip (' + kb(zip.bytes) + ')';
			headBox.appendChild(a);
		}

		var ico = null;
		data.files.forEach(function (f) { if (/\.ico$/.test(f.name)) { ico = f; } });

		if (ico) {
			var b = el('a', 'inline-flex min-h-[40px] items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition hover:border-amber-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200');
			b.href = ico.url;
			b.innerHTML = '<i class="fas fa-file-image text-amber-600"></i> favicon.ico';
			headBox.appendChild(b);
		}

		var link = el('a', 'inline-flex min-h-[40px] items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition hover:border-amber-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200');
		link.href = data.url;
		link.innerHTML = '<i class="fas fa-link"></i> Ссылка на набор';
		headBox.appendChild(link);

		box.appendChild(headBox);

		// PNG-превью
		var grid = el('div', 'mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4');

		data.files.forEach(function (f) {
			if ( ! /\.png$/.test(f.name)) { return; }

			var card = el('a', 'group rounded-lg border border-gray-200 p-2 text-center transition hover:border-amber-500 dark:border-gray-700');
			card.href = f.url;
			card.setAttribute('download', f.name);
			card.title = 'Скачать ' + f.name;

			var img = el('img', 'mx-auto h-12 w-12 object-contain');
			img.src = f.url;
			img.alt = f.label;
			img.loading = 'lazy';

			card.appendChild(img);
			card.appendChild(el('span', 'mt-1 block truncate text-xs font-medium text-gray-600 group-hover:text-amber-600 dark:text-gray-300', f.label));
			grid.appendChild(card);
		});

		box.appendChild(grid);

		// остальные файлы
		var list = el('ul', 'mt-4 space-y-1.5 text-sm');

		data.files.forEach(function (f) {
			if (/\.png$/.test(f.name)) { return; }

			var li = el('li', 'flex items-center gap-2 text-gray-600 dark:text-gray-300');

			var a = el('a', 'font-mono text-xs text-amber-600 hover:underline dark:text-amber-400', f.name);
			a.href = f.url;
			a.setAttribute('download', f.name);

			li.appendChild(el('i', 'fas fa-file-lines text-gray-400'));
			li.appendChild(a);
			li.appendChild(el('span', 'text-xs text-gray-400 dark:text-gray-500', f.label));
			list.appendChild(li);
		});

		box.appendChild(list);

		// HTML-код
		if (data.html) {
			var wrap = el('div', 'mt-5');
			var line = el('div', 'flex items-center justify-between gap-3');

			line.appendChild(el('h4', 'text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white', 'HTML для <head>'));

			var copy = el('button', 'nj663-copy inline-flex min-h-[36px] items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition hover:border-amber-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200');
			copy.type = 'button';
			copy.innerHTML = '<i class="far fa-copy"></i> Скопировать';
			copy.addEventListener('click', function () {
				navigator.clipboard.writeText(data.html).then(function () {
					copy.innerHTML = '<i class="fas fa-check"></i> Скопировано';
					setTimeout(function () { copy.innerHTML = '<i class="far fa-copy"></i> Скопировать'; }, 2000);
				});
			});

			line.appendChild(copy);

			var pre  = el('pre', 'mt-2 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-relaxed text-gray-100');
			var code = el('code', '', data.html);

			pre.appendChild(code);
			wrap.appendChild(line);
			wrap.appendChild(pre);
			box.appendChild(wrap);
		}

		result.appendChild(box);
		box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	// Кнопка «Скопировать» в серверном блоке (результат без JS).
	document.addEventListener('click', function (event) {
		var btn = event.target.closest ? event.target.closest('.nj663-copy') : null;

		if ( ! btn) { return; }

		var pre = btn.parentNode.parentNode.querySelector('.nj663-html');

		if ( ! pre) { return; }

		navigator.clipboard.writeText(pre.textContent).then(function () {
			btn.innerHTML = '<i class="fas fa-check"></i> Скопировано';
			setTimeout(function () { btn.innerHTML = '<i class="far fa-copy"></i> Скопировать'; }, 2000);
		});
	});

	tab('text');
})();
</script>
