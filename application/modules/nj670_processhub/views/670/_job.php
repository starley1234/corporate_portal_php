<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Полоса «воркер работает»: этап, секундомер и три пульсирующие точки.
 *
 * Правило UI: полос прогресса нет. Уровень/доля показываются числом, а
 * факт работы — точками и текстом этапа. Полоса сама опрашивает
 * /process/api/status и перезагружает страницу, когда задача завершилась.
 *
 * Ожидает: $job (status_of), $code, необязательный $hint.
 */
$job = isset($job) ? (array) $job : [];

if ( ! class_exists('Service_Nj670_Process') OR ! Service_Nj670_Process::job_running($job))
{
	return;
}

$code = isset($code) ? (string) $code : '';
$hint = isset($hint) ? (string) $hint : 'Страница обновится сама, когда работа закончится.';
$stage = trim((string) Arr::get($job, 'stage', ''));
$trail = [];

foreach ((array) Arr::get($job, 'stages', []) as $step)
{
	$message = trim((string) Arr::get((array) $step, 'message', ''));

	if ($message !== '')
	{
		$trail[] = $message;
	}
}

if ($stage === '')
{
	$stage = strtolower((string) Arr::get($job, 'status', '')) === 'queued' ? 'ждёт воркера' : 'воркер работает';
}
?>
<div id="nj670-live-job" role="status" aria-live="polite"
	class="no-print mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 n2j-ds-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
	data-code="<?php echo HTML::chars($code); ?>"
	data-elapsed="<?php echo (int) Arr::get($job, 'elapsed_sec', 0); ?>"<?php echo $trail ? ' title="'.HTML::chars(implode(' → ', $trail)).'"' : ''; ?>>
	<span class="n2j-dots text-blue-500" aria-hidden="true"><i></i><i></i><i></i></span>
	<span class="font-semibold text-gray-900 dark:text-white">Воркер:</span>
	<span id="nj670-live-stage"><?php echo HTML::chars($stage); ?></span>
	<span id="nj670-live-time" class="font-mono text-gray-500 dark:text-gray-400/80"></span>
	<span class="text-gray-600 dark:text-gray-300/70"><?php echo HTML::chars($hint); ?></span>
</div>
<script>
(function () {
	var box = document.getElementById('nj670-live-job');

	if (!box || window.__nj670LiveJob) { return; }

	window.__nj670LiveJob = true;

	var code = box.getAttribute('data-code') || '';
	var stageEl = document.getElementById('nj670-live-stage');
	var timeEl = document.getElementById('nj670-live-time');
	var base = Math.max(0, parseInt(box.getAttribute('data-elapsed'), 10) || 0);
	var at = Date.now();
	var tries = 0;
	var RELOADS = 'n2j.jobReload';

	function clock(sec) {
		sec = Math.max(0, parseInt(sec, 10) || 0);
		var m = Math.floor(sec / 60), s = sec % 60;
		return m + ':' + (s < 10 ? '0' : '') + s;
	}

	function paintTime() {
		if (timeEl) { timeEl.textContent = clock(base + Math.round((Date.now() - at) / 1000)); }
	}

	paintTime();
	window.setInterval(paintTime, 1000);

	function reloadOnce() {
		var n = 0;

		try { n = parseInt(window.sessionStorage.getItem(RELOADS) || '0', 10) || 0; } catch (e) {}

		if (n >= 3) {
			if (stageEl) { stageEl.textContent = 'работа закончилась'; }
			return;
		}

		try { window.sessionStorage.setItem(RELOADS, String(n + 1)); } catch (e) {}
		window.location.reload();
	}

	function tick() {
		tries++;

		if (tries > 180) { return; }

		fetch('/process/api/status', {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			credentials: 'same-origin',
			body: JSON.stringify({code: code, jobs_only: true})
		}).then(function (r) { return r.json(); }).then(function (data) {
			var active = false;

			if (data && data.ok && data.jobs) {
				for (var key in data.jobs) {
					var job = data.jobs[key];

					if (!job || !job.found) { continue; }
					if (job.status !== 'running' && job.status !== 'queued') { continue; }

					active = true;

					if (stageEl && job.stage) { stageEl.textContent = job.stage; }
					if (job.elapsed_sec != null) { base = parseInt(job.elapsed_sec, 10) || 0; at = Date.now(); }
				}
			}

			if (!active) {
				try { window.sessionStorage.setItem(RELOADS, '0'); } catch (e) {}
				reloadOnce();
				return;
			}

			try { window.sessionStorage.setItem(RELOADS, '0'); } catch (e) {}
			window.setTimeout(tick, 5000);
		}).catch(function () { window.setTimeout(tick, 7000); });
	}

	window.setTimeout(tick, 4000);
})();
</script>
