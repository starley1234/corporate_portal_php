<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Клиент страницы «Многоголосая озвучка» (nj666): счётчик текста, подписи у
 * ползунков и создание книги без перезагрузки.
 *
 * Форма остаётся обычным POST: без JS сервер всё равно создаст книгу и
 * откроет её страницу (владелец, 09.10.2026).
 */
$limits    = isset($limits) ? (array) $limits : [];
$max_chars = (int) Arr::get($limits, 'max_chars', 600000);
?>

<script>
(function () {
	'use strict';

	var MAX_CHARS = <?php echo (int) $max_chars; ?>;

	var $ = function (id) { return document.getElementById(id); };

	var form    = $('nj666-form');
	var text    = $('nj666-text');
	var counter = $('nj666-count');
	var status  = $('nj666-status');
	var submit  = $('nj666-submit');
	var csrf    = $('nj666-csrf');
	var hint    = $('nj666-hint');
	var single  = $('nj666-single');

	if ( ! form || ! text) { return; }

	function count()
	{
		var n = text.value.length;

		counter.textContent = String(n);
		counter.className = 'font-semibold' + (n > MAX_CHARS ? ' text-rose-600' : '');
	}

	text.addEventListener('input', count);
	count();

	// --- Ползунки -----------------------------------------------------------
	function bindRange(id, valueId, suffix)
	{
		var range = $(id);
		var label = $(valueId);

		if ( ! range || ! label) { return; }

		range.addEventListener('input', function () {
			label.textContent = range.value;
		});
	}

	// Минимум не должен перегонять максимум: иначе блоки нельзя будет закрыть.
	function syncRanges()
	{
		var min = $('nj666-min');
		var max = $('nj666-max');

		if ( ! min || ! max) { return; }

		if (parseFloat(min.value) > parseFloat(max.value))
		{
			max.value = min.value;
			$('nj666-max-value').textContent = max.value;
		}

		if (parseFloat(max.value) < parseFloat(min.value))
		{
			min.value = max.value;
			$('nj666-min-value').textContent = min.value;
		}
	}

	['nj666-min', 'nj666-max'].forEach(function (id) {
		var el = $(id);

		if (el) { el.addEventListener('input', syncRanges); }
	});

	bindRange('nj666-min', 'nj666-min-value');
	bindRange('nj666-max', 'nj666-max-value');
	bindRange('nj666-speed', 'nj666-speed-value');

	if (single)
	{
		single.addEventListener('change', function () {
			var rows = [['nj666-min'], ['nj666-max']];

			[$('nj666-min'), $('nj666-max')].forEach(function (el) {
				if (el) { el.disabled = single.checked; }
			});
		});
	}

	// --- Отправка -----------------------------------------------------------
	function note(text, bad)
	{
		if ( ! status) { return; }

		status.textContent = text || '';
		status.className = 'n2j-hint mt-0' + (bad ? ' font-semibold text-rose-600 dark:text-rose-400' : '');
	}

	function busy(on)
	{
		if ( ! submit) { return; }

		submit.disabled = on;

		var label = submit.querySelector('span');

		if (label) { label.textContent = on ? 'Создаю…' : 'Создать книгу'; }
	}

	form.addEventListener('submit', function (e) {
		if (text.value.length > MAX_CHARS)
		{
			e.preventDefault();
			note('Слишком длинный текст: максимум ' + MAX_CHARS + ' знаков.', true);

			return;
		}

		if (text.value.trim().length < 200)
		{
			e.preventDefault();
			note('Нужен хотя бы кусок текста: от 200 знаков.', true);

			return;
		}

		// Без fetch форма работает обычным POST — сервер сам редиректит на
		// книгу. Здесь перехватываем только чтобы не терять введённое.
		e.preventDefault();

		busy(true);
		note('Создаю книгу…');

		var body = {
			csrf: csrf ? csrf.value : '',
			title: ($('nj666-title') || {}).value || '',
			author: ($('nj666-author') || {}).value || '',
			text: text.value,
			mode: (document.querySelector('input[name="mode"]:checked') || {}).value || 'auto',
			block_min_min: ($('nj666-min') || {}).value,
			block_max_min: ($('nj666-max') || {}).value,
			single_block: single && single.checked ? 1 : 0,
			language: ($('nj666-language') || {}).value || '',
			speed: ($('nj666-speed') || {}).value
		};

		fetch('/multivoice/api/create', {
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
			if ( ! res.data.ok)
			{
				busy(false);
				note(res.data.error || 'Книгу создать не удалось.', true);

				return;
			}

			window.location.href = res.data.url || ('/multivoice/b/' + res.data.code);
		})
		.catch(function () {
			// Сеть упала — уходим обычным POST: сервер создаст книгу сам и
			// откроет её страницу. form.submit() событие submit не роняет,
			// поэтому бесконечного цикла не будет.
			busy(false);
			note('Сеть не отвечает — отправляю обычной формой.', true);
			form.submit();
		});
	});

	// Удаление книги из списка — вторым нажатием (data-n2j-arm, app.js): первое
	// взводит кнопку («Удалить?», 4 с, снимается кликом мимо и Esc) и сюда не
	// доходит. Без confirm() (владелец, 11.10.2026).
	(function () {
		var csrfEl = $('nj666-csrf');
		var noteEl = $('nj666-status');

		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.nj666-del-book') : null;

			if ( ! btn) { return; }

			btn.disabled = true;

			if (noteEl)
			{
				noteEl.textContent = 'Удаляю книгу…';
				noteEl.className = 'n2j-hint mt-0';
			}

			fetch('/multivoice/api/delete', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: JSON.stringify({
					csrf: csrfEl ? csrfEl.value : '',
					code: btn.getAttribute('data-code') || ''
				})
			})
			.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
			.then(function (res) {
				if ( ! res.data || ! res.data.ok)
				{
					btn.disabled = false;

					if (noteEl)
					{
						noteEl.textContent = (res.data && res.data.error) ? res.data.error : 'Книгу удалить не удалось.';
						noteEl.className = 'n2j-hint mt-0 font-semibold text-rose-600 dark:text-rose-400';
					}

					return;
				}

				window.location.reload();
			})
			.catch(function () {
				btn.disabled = false;

				if (noteEl)
				{
					noteEl.textContent = 'Сеть не отвечает, попробуйте ещё раз.';
					noteEl.className = 'n2j-hint mt-0 font-semibold text-rose-600 dark:text-rose-400';
				}
			});
		});
	}());
})();
</script>
