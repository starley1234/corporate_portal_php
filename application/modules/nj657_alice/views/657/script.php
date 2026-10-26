<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj657: пресеты промпта, генерация токена, копирование вебхука. Опасные кнопки — data-n2j-arm (app.js). -->
<script>
(function () {
	'use strict';

	// Пресеты ролей: одна кнопка — готовый системный промпт.
	document.querySelectorAll('.nj657-preset').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var area = document.getElementById('f-system');
			if ( ! area) return;
			area.value = btn.getAttribute('data-text') || '';
			area.focus();
		});
	});

	// Генерация токена вебхука (криптослучайные символы без похожих).
	var gen = document.getElementById('nj657-token-gen');
	if (gen) {
		gen.addEventListener('click', function () {
			var alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
			var bytes = new Uint8Array(22);
			(window.crypto || window.msCrypto).getRandomValues(bytes);
			var token = '';
			for (var i = 0; i < bytes.length; i++) {
				token += alphabet[bytes[i] % alphabet.length];
			}

			var field = document.getElementById('f-token');
			if (field) {
				field.value = token;
				field.dispatchEvent(new Event('change'));
			}
		});
	}

	// Адрес вебхука обновляется вместе с токеном.
	var tokenField = document.getElementById('f-token');
	if (tokenField) {
		var base = tokenField.getAttribute('data-webhook-base') || '';

		var update = function () {
			var value = (tokenField.value || '').replace(/[^A-Za-z0-9]/g, '');
			var url = value ? base + '/' + value : base;

			['nj657-webhook-copy', 'nj657-webhook-full'].forEach(function (id) {
				var el = document.getElementById(id);
				if (el) el.textContent = url;
			});
		};

		tokenField.addEventListener('input', update);
		tokenField.addEventListener('change', update);
	}

	// Копирование адреса вебхука.
	var copy = document.getElementById('nj657-copy');
	if (copy) {
		copy.addEventListener('click', function () {
			var text = (document.getElementById('nj657-webhook-copy') || {}).textContent || '';

			N2J.copyText(text).then(function () { N2J.toast('Адрес вебхука скопирован', 0, 'ok'); }, function () { N2J.toast('Не удалось скопировать'); });
		});
	}

})();
</script>
