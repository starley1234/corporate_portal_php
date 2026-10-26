<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница блока (nj666): добавить/убрать реплику, переозвучка.
 * Сохранение идёт обычным POST формы — без JS тоже работает.
 */
$book  = isset($book) ? (array) $book : [];
$block = isset($block) ? (array) $block : [];
$csrf  = isset($csrf) ? (string) $csrf : '';

$code = (string) Arr::get($book, 'code');
?>

<script>
(function () {
	'use strict';

	var CODE = <?php echo json_encode($code); ?>;
	var CSRF = <?php echo json_encode($csrf); ?>;
	var $    = function (id) { return document.getElementById(id); };

	var noteEl = $('nj666-status');
	var list   = $('nj666-turns');
	var addBtn = $('nj666-add-turn');
	var voiceBtn = $('nj666-block-voice');

	function note(text, bad)
	{
		if ( ! noteEl) { return; }

		noteEl.textContent = text || '';
		noteEl.className = 'n2j-hint mt-2' + (bad ? ' font-semibold text-rose-600 dark:text-rose-400' : '');
	}

	function reindex()
	{
		if ( ! list) { return; }

		var rows = list.querySelectorAll('.nj666-turn');

		for (var i = 0; i < rows.length; i++)
		{
			var no  = rows[i].querySelector('.nj666-turn-no');
			var sel = rows[i].querySelector('.nj666-turn-speaker');
			var ta  = rows[i].querySelector('.nj666-turn-text');
			var p   = rows[i].querySelector('.nj666-turn-p');

			if (no) { no.textContent = String(i + 1); }

			if (sel)
			{
				sel.name = 'turns[' + i + '][speaker]';
				sel.id   = 'nj666-t-sp-' + i;
			}

			if (ta)
			{
				ta.name = 'turns[' + i + '][text]';
				ta.id   = 'nj666-t-tx-' + i;
			}

			if (p)
			{
				p.name = 'turns[' + i + '][p]';
			}
		}
	}

	if (addBtn && list)
	{
		addBtn.addEventListener('click', function () {
			var rows = list.querySelectorAll('.nj666-turn');
			var last = rows[rows.length - 1];

			if ( ! last) { return; }

			var clone = last.cloneNode(true);
			var ta    = clone.querySelector('textarea');
			var p     = clone.querySelector('.nj666-turn-p');
			var badge = clone.querySelector('[title="номер абзаца в исходнике"]');

			if (ta) { ta.value = ''; }

			if (p && p.parentNode) { p.parentNode.removeChild(p); }

			if (badge && badge.parentNode) { badge.parentNode.removeChild(badge); }

			list.appendChild(clone);
			reindex();

			if (ta) { ta.focus(); }
		});

		list.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.nj666-turn-del') : null;

			if ( ! btn) { return; }

			var rows = list.querySelectorAll('.nj666-turn');

			if (rows.length <= 1)
			{
				var ta = rows[0] ? rows[0].querySelector('textarea') : null;

				if (ta) { ta.value = ''; }

				return;
			}

			var row = btn.closest('.nj666-turn');

			if (row && row.parentNode) { row.parentNode.removeChild(row); }

			reindex();
		});
	}

	if (voiceBtn)
	{
		voiceBtn.addEventListener('click', function () {
			if (voiceBtn.disabled) { return; }

			voiceBtn.disabled = true;
			voiceBtn.classList.add('opacity-50');
			note('Озвучиваю блок — подождите…');

			fetch('/multivoice/api/block_voice', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: JSON.stringify({
					csrf: CSRF,
					code: CODE,
					id: voiceBtn.dataset.id
				})
			})
			.then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
			.then(function (res) {
				if ( ! res.data.ok)
				{
					voiceBtn.disabled = false;
					voiceBtn.classList.remove('opacity-50');
					note((res.data && res.data.error) ? res.data.error : 'Блок не озвучился.', true);

					return;
				}

				window.location.reload();
			})
			.catch(function () {
				voiceBtn.disabled = false;
				voiceBtn.classList.remove('opacity-50');
				note('Сеть не отвечает, попробуйте ещё раз.', true);
			});
		});
	}
})();
</script>
