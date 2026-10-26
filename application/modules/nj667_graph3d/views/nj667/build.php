<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сборка графа в изделие (nj667): модель, спецификация, чертежи узлов.
 *
 * Идея вёрстки (владелец, 13.10.2026): «одна страница — один экран без
 * перелистываний». Поэтому верхний экран отдан двум вещам — параметрам и
 * окну просмотра, а счёт, раскрой и замечания живут одним плотным рядом ниже.
 * Эталон оформления (/pages/required_design_sample): короткую форму не расти-
 * раем на всю ширину, правая колонка — только когда она правда помогает,
 * радиусы rounded-xl снаружи и rounded-lg на контролах, а спиннер с таймером
 * не отрываются от слова «ждём».
 *
 * Модель рендерит воркер в OpenSCAD, всё остальное считает сайт. Порядок тут
 * нарочно такой: человек видит число деталей, раскрой и предупреждения ДО
 * рендера — и остаётся с ними, даже если OpenSCAD на сервере не стоит.
 *
 * Для просмотра изделия целиком используется GLB. STL всего изделия хранится
 * только по отдельному запросу модератора; узлы к печати — отдельные STL-файлы.
 */

$project   = isset($project) ? (array) $project : [];
$geom      = isset($geom) ? (array) $geom : [];
$opt       = isset($opt) ? (array) $opt : [];
$build     = isset($build) ? (array) $build : [];
$parts_user_id = (int) Arr::get($build, 'user_id', 0);

if ($parts_user_id <= 0)
{
	$parts_user_id = (int) Arr::get($project, 'user_id', 0);
}

$history   = isset($history) ? (array) $history : [];
$stale     = isset($stale) ? (bool) $stale : FALSE;
$types     = isset($types) ? (array) $types : Service_Nj667_Graph3d::build_types();
$qualities = isset($qualities) ? (array) $qualities : Service_Nj667_Graph3d::build_qualities();
$lib       = isset($lib) ? (array) $lib : [];
$schema     = isset($schema) ? (array) $schema : ['glb' => TRUE, 'parts' => TRUE];
$engine    = isset($engine) ? (array) $engine : [];
$owner     = isset($owner) ? (bool) $owner : FALSE;
$can_prepare_parts = isset($can_prepare_parts) ? (bool) $can_prepare_parts : $owner;
$logged    = isset($logged) ? (bool) $logged : FALSE;
$can_moderate = isset($can_moderate) ? (bool) $can_moderate : FALSE;
$glb_ready = isset($glb_ready) ? (bool) $glb_ready
	: ((int) Arr::get($build, 'glb_size', 0) > 0);
$stl_ready = isset($stl_ready) ? (bool) $stl_ready
	: ($can_moderate AND (int) Arr::get($build, 'stl_size', 0) > 0);
$parts_run_limit = isset($parts_run_limit) ? max(1, (int) $parts_run_limit)
	: Service_Nj667_Graph3d::build_parts_run_limit($parts_user_id);
$preview_of_set = array_flip(array_map('intval', (array) (isset($preview_of) ? $preview_of : [])));
$csrf      = isset($csrf) ? (string) $csrf : '';
$error     = isset($error) ? (string) $error : '';
$pub       = isset($pub) ? (string) $pub : '';
$pub_error = isset($pub_error) ? (string) $pub_error : '';
$request_error = isset($request_error) ? (string) $request_error : '';
$simple_flow = isset($simple_flow) ? (array) $simple_flow : Service_Nj667_Graph3d::simple_flow_state($project);

$code  = (string) Arr::get($project, 'code');
$base  = '/graph3d/b/'.rawurlencode($code);
$back  = $owner ? '/graph3d/p/'.rawurlencode($code) : '/graph3d/c/'.rawurlencode($code);
$ok    = ! empty($geom['ok']);
$build_id = (int) Arr::get($build, 'id', 0);
$status   = (string) Arr::get($build, 'status', '');
$job_id   = (int) Arr::get($build, 'job_id', 0);
// job_id — рендер всей модели; узлы имеют отдельную задачу и отдельный id.
$parts_job_id = ($build_id > 0 AND Service_Nj667_Graph3d::build_parts_ready())
	? Service_Nj667_Graph3d::build_parts_job($build_id) : 0;
// Секундомер от настоящего старта задачи: после перезагрузки он не начинается заново.
$job_info    = $job_id > 0 ? Service_Nj667_Graph3d::job_state($job_id) : [];
$job_elapsed = (int) Arr::get($job_info, 'elapsed_sec', 0);
// Пока задача идёт, панель настроек неактивна и после перезагрузки.
$job_live    = ($job_id > 0 && Service_Nj667_Graph3d::job_live($job_info));

$counts = (array) Arr::get($geom, 'counts', []);
$span   = (array) Arr::get($geom, 'span', []);
$dims   = (array) Arr::get($geom, 'dims', []);
$parts  = (array) Arr::get($geom, 'types', []);
// Строки nj_667_build_parts (узел к печати) и план пачки: что мы собрали бы,
// если бы человек нажал «узлы» прямо сейчас.
$part_rows = isset($part_rows) ? (array) $part_rows : [];
$plan      = isset($plan) ? (array) $plan : ['parts' => [], 'total' => 0, 'skipped' => 0];
$part_of   = [];
$part_wait = 0;

foreach ($part_rows as $row_part)
{
	$part_of[(int) Arr::get($row_part, 'part_index', 0)] = (array) $row_part;

	if ((string) Arr::get($row_part, 'status', '') === 'queued')
	{
		$part_wait++;
	}
}

// Кнопка не исчезает из-за рендера изделия: узлы — отдельная задача.
// Повторный запуск блокируем только на время подготовки самих деталей.
$parts_busy = ($part_wait > 0 OR ($parts_job_id > 0
	AND Service_Nj667_Graph3d::job_live(Service_Nj667_Graph3d::job_state($parts_job_id))));

// Подпись кнопки пачки: если часть типов уже на диске, честнее показать окно
// («N из M, осталось K»), чем голое число — иначе выглядит, что кнопка ничего
// не делает, а она как раз доделывает остаток.
$plan_parts = (array) Arr::get($plan, 'parts', []);
$part_done = (int) Arr::get($plan, 'done', 0);
// Кнопка запускает окно до 30 узлов: короткие worker-задачи могут идти
// цепочкой только по этому окну; оставшиеся типы — новым нажатием.
$parts_left  = max(0, count($parts) - $part_done);
$parts_label = ($part_done > 0 AND $parts_left > 0)
	? 'Доделать узлы (осталось '.$parts_left.' из '.count($parts).')'
	: 'Собрать узлы к печати ('.count($parts).')';

$part_text = [
	''       => 'не заказывали',
	'spec'   => 'ждёт очереди',
	'queued' => 'в очереди',
	'done'   => 'STL готов',
	'error'  => 'не собрался',
];
$sticks = (array) Arr::get($geom, 'sticks', []);
$issues = (array) Arr::get($geom, 'issues', []);

/** Число для человека: запятая, ровно столько знаков, сколько смысл имеет. */
$n = function ($value, $dec = 1) { return number_format((float) $value, $dec, ',', ' '); };

$type_key = (string) Arr::get($opt, 'type', 'flat');
$unit     = (string) Arr::get($opt, 'unit', 'mm');
$units    = Service_Nj667_Graph3d::drawing_units();
$type_hint = (string) Arr::get((array) Arr::get($types, $type_key, []), 'hint', '');

// Путь проверяется на сервере до рендера страницы: ссылка не обещает файл,
// которого нет на диске. STL всего изделия показываем только модератору.
$stl_ready   = ($build_id AND $can_moderate AND $stl_ready);
$stl_href    = $base.'?file=stl'.($build_id ? '&build='.$build_id : '');
$scad_href   = $base.'?format=scad'.($build_id ? '&build='.$build_id : '');
$csv_href    = $base.'?format=csv';
$zip_href    = $base.'?format=zip'.($build_id ? '&build='.$build_id : '');

// Для изделия целиком используется только GLB. STL узлов остаются отдельными.
$glb_ready   = ($build_id AND $glb_ready);
// Сколько КРАСОК приехало в файле: их считает приёмка (`glb_stats`) и кладёт в
// `stats_json.glb.groups` — новых колонок для этого не нужно. Единица значит,
// что VRML с `color()` до воркера не дошёл (выгрузку .wrl умеет не каждый
// OpenSCAD), и выдумывать цвет просмотрщик не будет: скажем словами.
$glb_json    = (array) (json_decode((string) Arr::get($build, 'stats_json', ''), TRUE) ?: []);
$glb_colors  = (int) Arr::get((array) Arr::get($glb_json, 'glb', []), 'groups', 0);
$glb_href    = $base.'?file=glb'.($build_id ? '&build='.$build_id : '');
// Пустой href — не косметика: скрипт не должен ходить за файлом, которого
// нет (404 в блоке просмотра пугает сильнее, чем честная строчка «пересоберите»).
$view_href   = $glb_ready ? $glb_href : '';
// Публикация сборки (модератор): нужен именно GLB на диске.
$published_at = (string) Arr::get($build, 'published_at', '');
$is_published = $published_at !== '';
$can_publish  = ($build_id AND in_array($status, ['done', 'published'], TRUE) AND $glb_ready);
$view_kind   = 'glb';
$view_size   = $glb_ready ? (int) Arr::get($build, 'glb_size', 0) : 0;
$state_text  = [
	'spec'   => 'Чертежи и расчёт готовы, GLB-модели ещё нет.',
	'queued' => 'Рендер в очереди: OpenSCAD считает минуты.',
	'done'   => 'Изделие целиком:',
	'published' => 'Изделие целиком:',
	'error'  => 'Рендер не удался — спецификация ниже всё равно верна.',
];

// GLB может быть готов, даже если приёмка оставила заметку; показываем модель
// и отдельно объясняем проблему. STL всего изделия не используется для просмотра.
$build_note = trim((string) Arr::get($build, 'error', ''));

if (in_array($status, ['done', 'published', 'error'], TRUE) AND $view_href !== '' AND $build_note !== '')
{
	$state_text[$status] = (in_array($status, ['done', 'published'], TRUE) ? 'Модель готова (GLB), но воркер добавил: '
		: 'Модель на диске (GLB), но воркер добавил: ')
		.UTF8::substr($build_note, 0, 180);
}

/** Класс поля — общий n2j-input: рамка, подложка и отступы в обеих темах (без рамки
    поле на белой карточке было «белым на белом» — владелец, 13.10.2026). */
$in = 'n2j-input';
?>

<?php /* design-audit: allow style — холст three.js: высота от остатка карточки (clamp), :fullscreen, подписи сетки и подсказка, которые рисует nj667-stl.js; @page для печати */ ?>
<style>
	/* Просмотрщик: холст на всю ширину, в ночи тёмный фон — металл читается
	   лучше на сером, чем на белом. Высота — не фиксированная, а «весь остаток
	   карточки»: фиксированные 420px оставляли под моделью мёртвую полосу
	   (вопрос владельца 13.10.2026 — «зачем столько пустого места»), потому
	   что карточка в сетке тянется по соседу, а холст в ней не рос.
	   min-height держит нижний «пол-экрана», max — чтобы страница не
	   расползлась за один экран. */
	#nj667-stl { position: relative; flex: 1 1 auto; min-height: clamp(220px, 40vh, 460px);
		max-height: clamp(260px, 58vh, 680px); overflow: hidden; touch-action: none; }
	#nj667-stl canvas { display: block; width: 100%; height: 100%; }
	html.dark #nj667-stl { background: #111827; }
	#nj667-stl .nj667-stl-note {
		position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
		padding: 12px; text-align: center; font-size: 13px; color: #4b5563;
	}
	html.dark #nj667-stl .nj667-stl-note { color: #9ca3af; }
	/* Подписи сетки: DOM поверх холста, мм от угла основания. Сама сетка — в three.js. */
	#nj667-stl .nj667-stl-ticks { position: absolute; inset: 0; pointer-events: none; overflow: hidden; }
	#nj667-stl .nj667-stl-tick { position: absolute; transform: translate(-50%, -50%);
		font-size: 12px; color: #4b5563; white-space: nowrap; }
	html.dark #nj667-stl .nj667-stl-tick { color: #9ca3af; }
	#nj667-stl:fullscreen { width: 100vw; height: 100vh; max-height: none; min-height: 0; background: #fff; }
	html.dark #nj667-stl:fullscreen { background: #111827; }
	@media print {
		#nj667-stl { display: none; }
		@page { size: A4 portrait; margin: 10mm; }
	}
</style>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php
		$build_title = trim((string) Arr::get($project, 'title'));
		$build_head  = $build_title !== '' ? UTF8::substr($build_title, 0, 70).' - сборка' : 'Сборка';
	?>
	<div class="no-print flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
		<h1 class="n2j-h1 break-words">
			<i class="fas fa-cubes mr-1.5 text-blue-500"></i><?php echo HTML::chars($build_head); ?>
		</h1>
	</div>

	<?php echo View::factory('nj667/_nav', ['code' => $code, 'nav' => 'b', 'owner' => $owner, 'nav_build' => $build_id])->render(); ?>

	<?php if ( ! $owner AND $can_moderate): ?>
	<?php // режим контроля: модератор на чужой сборке — смотрит, но не запускает (25.10.2026) ?>
	<div class="n2j-flash n2j-flash-warn mt-3 items-start">
		<i class="fas fa-user-shield mt-0.5 shrink-0"></i>
		<div class="min-w-0 flex-1">
			<p class="font-semibold">Режим контроля: сборка чужого проекта — владелец
				<?php $own_uid = (int) Arr::get($project, 'user_id', 0); ?>
				<?php if ($own_uid > 0): ?><a href="/nj600/admin/owner/u:<?php echo $own_uid; ?>" class="underline">u:<?php echo $own_uid; ?></a>
				<?php else: ?><span class="font-mono"><?php echo HTML::chars((string) Arr::get($project, 'visitor_uid', '—')); ?></span><?php endif; ?>.
				Файлы, узлы и журнал видны по праву модератора; запуск сборки и узлов — только владельцу.</p>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($owner AND ! empty($simple_flow['enabled']) AND (int) Arr::get($simple_flow, 'build_id', 0) === $build_id): ?>
		<div class="n2j-flash <?php echo Arr::get($simple_flow, 'error', '') !== '' ? 'n2j-flash-warn' : 'n2j-flash-info'; ?> mt-3 items-start">
			<i class="fas fa-wand-magic-sparkles mt-0.5 shrink-0"></i>
			<span><strong>Автоматическая сборка:</strong> плоские палочки для мороженого, модель изделия и STL узлов поставлены в очередь.
				<?php if ((string) Arr::get($simple_flow, 'error', '') !== ''): ?><?php echo ' '.HTML::chars((string) Arr::get($simple_flow, 'error')); ?><?php endif; ?></span>
		</div>
	<?php endif; ?>

	<?php if ($error !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-2 text-xs block">
			<i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars($error); ?>
		</p>
	<?php endif; ?>

	<?php if ($request_error !== ''): ?>
		<p class="n2j-flash n2j-flash-warn mt-2 text-xs block">
			<i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars($request_error); ?>
		</p>
	<?php endif; ?>

	<?php if ($pub_error !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-2 text-xs block">
			<i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars($pub_error); ?>
		</p>
	<?php elseif ($pub === 'on'): ?>
		<p class="n2j-flash n2j-flash-ok mt-2 text-xs block">
			<i class="fas fa-circle-check mr-1.5"></i>Сборка опубликована: граф в каталоге, сборка видна в карточке.
		</p>
	<?php elseif ($pub === 'off'): ?>
		<p class="mt-2 rounded-lg border border-gray-300 bg-gray-50 p-2 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
			<i class="fas fa-eye-slash mr-1.5"></i>Сборка снята с публикации. Граф остаётся опубликованным.
		</p>
	<?php endif; ?>

	<?php if ( ! $ok): ?>
		<p class="n2j-flash n2j-flash-warn mt-2 text-xs block">
			<?php echo HTML::chars((string) Arr::get($geom, 'error', 'Граф ещё не собран в изделие.')); ?>
		</p>
	<?php endif; ?>

	<?php if ($stale): ?>
		<p class="n2j-flash n2j-flash-warn mt-2 text-xs block">
			<i class="fas fa-clock-rotate-left mr-1.5"></i>Граф с этой сборки двигали. Модель ниже относится к прежним координатам — пересоберите.
		</p>
	<?php endif; ?>

	<div class="mt-2 grid gap-3 xl:grid-cols-[minmax(300px,360px)_minmax(0,1fr)]">

		<?php if ($owner): ?>
		<!-- ------------------------------------------------------- параметры -->
		<form method="post" action="<?php echo HTML::chars($base); ?>"
			<?php echo $job_live ? 'data-locked="1"' : ''; ?>
			class="no-print n2j-card data-[locked]:pointer-events-none data-[locked]:opacity-60">
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
			<fieldset class="contents" data-lock-panel <?php echo $job_live ? 'disabled' : ''; ?>>

			<div class="flex items-baseline justify-between gap-2">
				<h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
					<i class="fas fa-sliders mr-1.5 text-blue-500"></i>Из чего печатаем
				</h2>
				<?php if ($lib): ?>
					<span class="text-xs text-rose-700 dark:text-rose-300">нет scad-файлов: <?php echo HTML::chars(implode(', ', $lib)); ?></span>
				<?php endif; ?>
			</div>

			<?php /* Тип узла — radios, а не select: значение видно до клика, а
					 4 варианта влезают двумя строками chips. */ ?>
			<div class="mt-2 grid grid-cols-2 gap-1.5">
				<?php foreach ($types as $key => $row): ?>
					<?php $row = (array) $row; $checked = ((string) $key === $type_key); ?>
					<label class="flex cursor-pointer items-center gap-1.5 rounded-lg border px-2 py-1.5 text-xs <?php echo $checked ? 'border-blue-300 bg-blue-50 font-semibold text-gray-900 dark:border-blue-800 dark:bg-blue-950 dark:text-white' : 'border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white'; ?>"
						title="<?php echo HTML::chars((string) Arr::get($row, 'hint', '')); ?>">
						<input type="radio" name="type" value="<?php echo HTML::chars((string) $key); ?>" class="align-middle" <?php echo $checked ? 'checked' : ''; ?>>
						<?php echo HTML::chars((string) Arr::get($row, 'title', (string) $key)); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<?php /* Оба блока всегда в DOM: при смене типа их переписывает JS (см. внизу). */ ?>
			<p id="nj667-type-lib" class="mt-1.5 text-xs text-rose-700 dark:text-rose-300"<?php echo $lib ? '' : ' hidden'; ?>><?php echo $lib ? 'Нет файлов scad-библиотеки: '.HTML::chars(implode(', ', $lib)).'. STL собрать нельзя.' : ''; ?></p>
			<p id="nj667-type-hint" class="n2j-hint mt-1.5"<?php echo ($lib OR $type_hint === '') ? ' hidden' : ''; ?>><?php echo HTML::chars($type_hint); ?></p>

			<div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
				<label class="block text-xs text-gray-600 dark:text-gray-300">Качество дуг
					<select name="quality" class="<?php echo HTML::chars($in); ?> n2j-select mt-0.5">
						<?php foreach ($qualities as $qkey => $qtitle): ?>
							<option value="<?php echo HTML::chars((string) $qkey); ?>" <?php echo ((string) Arr::get($opt, 'quality') === (string) $qkey) ? 'selected' : ''; ?>>
								<?php echo HTML::chars((string) $qtitle); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="block text-xs text-gray-600 dark:text-gray-300">Клетка графа — это
					<span class="mt-0.5 flex gap-1.5">
						<?php /* step="any": значение вне сетки шага (10 при шаге 0,1
								 и min 0,001) браузер отвергал прямо в форме, и
								 человек слышал «введите 10.001». Масштаб — число
								 произвольное, а клампы стоят на сайте. */ ?>
						<input type="number" name="unit_size" step="any" min="0.001" max="10000" inputmode="decimal"
							title="Сколько миллиметров в одной клетке графа"
							value="<?php echo HTML::chars((string) Arr::get($opt, 'unit_size', 10)); ?>"
							class="n2j-input w-24 shrink-0">
						<select name="unit" class="n2j-input n2j-select min-w-0 flex-1">
							<?php foreach ($units as $ukey => $urow): ?>
								<option value="<?php echo HTML::chars((string) $ukey); ?>" <?php echo ((string) $ukey === $unit) ? 'selected' : ''; ?>>
									<?php echo HTML::chars((string) Arr::get((array) $urow, 'title', (string) $ukey)); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</span>
				</label>
			</div>

			<div id="nj667-type-params" class="mt-2 grid grid-cols-2 gap-2">
				<?php foreach (Service_Nj667_Graph3d::build_type_params($type_key) as $pname => $spec): ?>
					<?php $spec = (array) $spec; $val = Arr::get((array) Arr::get($opt, 'params', []), (string) $pname, Arr::get($spec, 'def')); ?>
					<label class="block text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($spec, 'title', (string) $pname)); ?>
						<input type="number" name="params[<?php echo HTML::chars((string) $pname); ?>]" data-param="<?php echo HTML::chars((string) $pname); ?>"
							value="<?php echo HTML::chars((string) $val); ?>"
							min="<?php echo HTML::chars((string) Arr::get($spec, 'min', 0)); ?>"
							max="<?php echo HTML::chars((string) Arr::get($spec, 'max', 100)); ?>"
							step="<?php echo HTML::chars((string) Arr::get($spec, 'step', 0.1)); ?>"
							class="<?php echo HTML::chars($in); ?> mt-0.5">
					</label>
				<?php endforeach; ?>
			</div>

			<div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-gray-700 dark:text-gray-300">
				<?php
				// У каждой галки — hidden с нулём: снятая галка не отправляет
				// ничего, и «выключено» было бы неотличимо от «не трогал».
				// Пояснение живёт в title: на одном экране четыре подписи
				// вторострочным текстом не помещаются, а сами галки — важны.
				$flags = [
					'merge'       => ['Узлы внахлёст сращивать', 'Два узла, чьи «юбки» пересекаются, печатаются одним объектом — иначе они физически невозможны.'],
					'show_sticks' => ['Показывать палочки в сборке', 'Отключите, если хотите видеть только напечатанные узлы.'],
					'perforation' => ['Перфорация в пазах', 'Отверстия в пазу держат клей; на тонких палочках их лучше убрать.'],
				];
				?>
				<?php foreach ($flags as $fname => $finfo): ?>
					<label class="flex cursor-pointer items-start gap-1.5" title="<?php echo HTML::chars($finfo[1]); ?>">
						<input type="hidden" name="<?php echo HTML::chars((string) $fname); ?>" value="0">
						<input type="checkbox" name="<?php echo HTML::chars((string) $fname); ?>" value="1" class="mt-0.5"
							<?php echo ! empty($opt[$fname]) ? 'checked' : ''; ?>>
						<span><?php echo HTML::chars($finfo[0]); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="mt-3 flex flex-wrap items-center gap-2">
				<button type="submit" name="render" value="1" data-job-btn="render" class="n2j-btn-process">
					<i class="fas fa-cube mr-1.5"></i>Пересчитать и в 3D
				</button>
			</div>
			</fieldset>
		</form>
		<?php endif; ?>

		<!-- --------------------------------------------------- просмотр модели -->
		<div class="n2j-card flex min-h-0 flex-col overflow-hidden p-0 <?php echo $owner ? '' : 'xl:col-span-2'; ?>">
			<div id="nj667-stl"
				data-src="<?php echo HTML::chars($view_href); ?>"
				data-kind="<?php echo HTML::chars($view_kind); ?>"
				data-status="<?php echo HTML::chars($build_id ? '/graph3d/api/build_status?code='.rawurlencode($code).'&build_id='.$build_id : ''); ?>"
				data-job="<?php echo $job_id; ?>"
				data-job-elapsed="<?php echo $job_elapsed; ?>"
				data-state="<?php echo HTML::chars($status); ?>"
				data-stl-size="<?php echo $view_size; ?>"
				data-glb-size="<?php echo (int) Arr::get($build, 'glb_size', 0); ?>"
				data-render-ms="<?php echo (int) Arr::get($build, 'render_ms', 0); ?>">
				<button type="button" id="nj667-stl-full" title="На весь экран" aria-label="На весь экран" class="absolute right-2 top-2 z-[5] inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg border border-gray-300 bg-white/90 text-gray-700 hover:bg-white dark:border-gray-700 dark:bg-gray-900/85 dark:text-gray-200"><i class="fas fa-expand"></i></button>
				<?php /* Сетка — в самом холсте, включает человек; по умолчанию выключена. */ ?>
				<div class="nj667-stl-grid absolute left-2 top-2 z-[5] inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white/85 px-2 py-1 dark:border-gray-700 dark:bg-gray-900/85">
					<label class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200"
						title="Сетка на нижней грани модели: клетки в мм, отсчёт от угла основания">
						<input type="checkbox" id="nj667-grid-toggle" class="h-4 w-4">
						Сетка
					</label>
					<span id="nj667-grid-info" class="text-xs text-gray-500 dark:text-gray-400" style="display: none"></span>
				</div>
				<?php /* Ход рендера — как на странице проекта: слой поверх 3D, секундомер ведёт JS. */ ?>
				<div id="nj667-working" style="display: none" class="absolute inset-0 z-10 items-center justify-center bg-white/80 px-6 text-center dark:bg-gray-900/80">
					<div>
						<svg class="nj667-spin n2j-spin mx-auto h-10 w-10 text-blue-500 dark:text-blue-300" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
						<p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">Модель строит граф</p>
						<p data-busy-clock="render" class="mt-2 font-mono text-2xl font-semibold tabular-nums tracking-wide text-gray-900 dark:text-white">0:00</p>
					</div>
				</div>
			</div>

			<div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-3 py-2 text-xs dark:border-gray-800">
				<span class="nj667-status inline-flex min-w-0 flex-wrap items-baseline gap-x-2.5 gap-y-1.5">
					<span id="nj667-stl-state" class="text-gray-600 dark:text-gray-300"><?php echo HTML::chars(Arr::get($state_text, $status, 'Модели пока нет.')); ?></span> <span id="nj667-stl-meta" class="text-gray-500 dark:text-gray-400"><?php echo $view_size > 0 ? number_format($view_size / 1048576, 2, ',', ' ').' МБ'.((int) Arr::get($build, 'render_ms', 0) > 0 ? ', рендер '.(round((int) Arr::get($build, 'render_ms', 0) / 100) / 10).' с' : '') : ''; ?></span>
				</span>


					<div class="ml-auto flex flex-wrap items-center gap-2">
						<?php if ($can_moderate AND $stl_ready): ?>
							<a href="<?php echo HTML::chars($stl_href); ?>" title="STL всего изделия — модераторская выгрузка для слайсера"
								class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-download mr-1.5"></i>Скачать STL</a>
						<?php elseif ($can_moderate AND $ok AND $build_id AND ! $job_live): ?>
							<form method="post" action="<?php echo HTML::chars($base); ?>">
								<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
								<input type="hidden" name="build" value="<?php echo $build_id; ?>">
								<button type="submit" name="render_stl" value="1" title="Поставить отдельный STL-рендер для модераторской выгрузки"
									class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-download mr-1.5"></i>Скачать STL</button>
							</form>
						<?php endif; ?>
						<a data-glb-download href="<?php echo HTML::chars($glb_href); ?>" title="GLB — модель для просмотра и скачивания"
							style="display:<?php echo $glb_ready ? 'inline-flex' : 'none'; ?>"
							class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-download mr-1.5"></i>Скачать GLB</a>
					<?php if ($ok): ?>
						<?php if ($can_moderate): ?><a href="<?php echo HTML::chars($scad_href); ?>" class="text-gray-600 hover:underline dark:text-gray-300">.scad</a><?php endif; ?>
						<?php if ($build_id): ?>
							<a href="<?php echo HTML::chars($zip_href); ?>" title="ZIP: детали на печать (STL каждого узла), GLB сборки и CSV спецификации"
							class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-file-zipper mr-1.5"></i>Всё ZIP</a>
						<?php endif; ?>
						<a href="<?php echo HTML::chars($csv_href); ?>" class="text-gray-600 hover:underline dark:text-gray-300">CSV</a>
					<?php endif; ?>
				</div>
			</div>

			<?php if ($can_moderate AND $build_id): ?>
				<div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-3 py-1.5 text-xs dark:border-gray-800">
					<?php if ($is_published): ?>
						<span class="font-semibold text-emerald-700 dark:text-emerald-300"><i class="fas fa-globe mr-1"></i>Опубликована в каталоге</span>
						<form method="post" action="<?php echo HTML::chars($base); ?>" class="ml-auto">
							<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
							<input type="hidden" name="build" value="<?php echo $build_id; ?>">
							<button type="submit" name="unpublish_build" value="1" class="text-gray-600 hover:underline dark:text-gray-300">Снять с публикации</button>
						</form>
					<?php elseif ($can_publish): ?>
						<form method="post" action="<?php echo HTML::chars($base); ?>" class="flex flex-wrap items-center gap-2">
							<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
							<input type="hidden" name="build" value="<?php echo $build_id; ?>">
							<button type="submit" name="publish_build" value="1" title="Граф станет опубликованным и появится в каталоге. Прежняя опубликованная сборка этого графа снимется."
								class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-globe mr-1.5"></i>Опубликовать сборку</button>
							<span class="text-gray-500 dark:text-gray-400">Видна в каталоге; граф тоже станет опубликованным.</span>
						</form>
					<?php else: ?>
						<span class="text-gray-500 dark:text-gray-400">Публиковать можно собранную сборку с моделью.</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $build_id OR $status === 'spec'): ?>
				<p class="n2j-hint border-t border-gray-100 px-3 py-1.5 dark:dark:border-gray-800">
					<?php if (trim((string) Arr::get($engine, 'bin', '')) === ''): ?>
						OpenSCAD на этом сервере не настроен (NJ667_OPENSCAD_BIN) — модель не появится. <?php echo $can_moderate ? '.scad можно собрать локально: файл тот же, что получает воркер.' : ''; ?>
					<?php else: ?>
						Зарегистрированный пользователь может нажать «Собрать 3D файлы сборки» — воркер заберёт .scad и вернёт GLB.
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ($glb_ready AND $glb_colors === 1): ?>
				<p class="border-t border-gray-100 px-3 py-1.5 text-xs font-semibold text-amber-800 dark:border-gray-800 dark:text-amber-300">
					В glTF одна краска: цвета из OpenSCAD до браузера не доехали. Краску несёт
					только выгрузка .wrl, а её в стабильной сборке 2021.01 нет вовсе (в .off,
					.amf и .3mf цветов тоже нет) — нужен development- snapshot новее. Проверьте
					NJ667_OPENSCAD_BIN и пересоберите. На печать это не влияет: узлы печатаются
					из STL, где цвета в природе нет.
				</p>
			<?php endif; ?>

			<?php if ( ! $schema['glb'] OR ! $schema['parts']): ?>
				<p class="border-t border-gray-100 px-3 py-1.5 text-xs font-semibold text-amber-800 dark:border-gray-800 dark:text-amber-300">
					<?php if ( ! $schema['glb']): ?>
						В базе нет колонки glb_path (миграция 010) — цвет в воркер не уезжает,
						генерация GLB недоступна.
					<?php endif; ?>
					<?php if ( ! $schema['parts']): ?>
						Нет таблицы nj_667_build_parts (миграция 009) — детали к печати не соберутся.
					<?php endif; ?>
					Накатите миграции (make migrate), затем «Пересчитать и в 3D».
				</p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ($ok): ?>
	<!-- ------------------------------------------------- счёт, детали, листы -->
	<div class="mt-3 grid gap-3 xl:grid-cols-[minmax(220px,0.75fr)_minmax(0,2fr)]">
		<div class="n2j-card">
			<h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
				<i class="fas fa-ruler mr-1.5 text-blue-500"></i>Габарит и счёт
			</h2>
			<dl class="mt-1.5 text-xs">
				<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Узлов / связей</dt><dd class="font-semibold"><?php echo (int) $counts['nodes'].' / '.(int) $counts['edges']; ?></dd></div>
				<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Деталей на печать</dt><dd class="font-semibold"><?php echo (int) $counts['clusters']; ?></dd></div>
				<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Уникальных типов</dt><dd class="font-semibold"><?php echo (int) $counts['types']; ?></dd></div>
				<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Сращено узлов</dt><dd class="font-semibold"><?php echo (int) Arr::get($counts, 'merged', 0); ?></dd></div>
				<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Габарит, мм</dt><dd class="font-semibold"><?php echo $n($span['x']).' × '.$n($span['y']).' × '.$n($span['z']); ?></dd></div>
				<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Палочек</dt><dd class="font-semibold"><?php echo (int) Arr::get($counts, 'sticks', 0).' шт. / '.number_format((float) Arr::get($counts, 'meters', 0), 2, ',', ' ').' м' ?></dd></div>
				<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Паз</dt><dd class="font-semibold"><?php echo $n(Arr::get($dims, 'slot_w'), 2).' × '.$n(Arr::get($dims, 'slot_t'), 2).' мм' ?></dd></div>
			</dl>

			<h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
				<i class="fas fa-scissors mr-1.5 text-blue-500"></i>Палочки: лист раскроя
			</h3>
			<table class="n2j-table mt-1.5 min-w-full">
				<thead><tr class="text-left text-xs text-gray-500 dark:text-gray-400"><th class="py-0.5 pr-2 font-medium">Длина, мм</th><th class="py-0.5 pr-2 font-medium">Сколько</th><th class="py-0.5 font-medium">Между узлами</th></tr></thead>
				<tbody>
					<?php foreach ($sticks as $stick): ?>
						<tr class="border-t border-gray-100 dark:border-gray-800">
							<td class="py-0.5 pr-2 font-semibold"><?php echo $n($stick['len']); ?></td>
							<td class="py-0.5 pr-2"><?php echo (int) $stick['count']; ?> шт.</td>
							<td class="py-0.5 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($stick, 'pair', '')); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $sticks): ?>
						<tr><td class="py-0.5 text-gray-500 dark:text-gray-400">Палочек нет: все связи внутри сращённых деталей.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="n2j-card">
			<div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
				<h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
					<i class="fas fa-gears mr-1.5 text-blue-500"></i>Детали на печать
				</h2>
				<?php if ( ! $schema['parts']): ?>
					<span class="text-xs font-semibold text-amber-800 dark:text-amber-300">нет таблицы узлов — make migrate</span>
				<?php endif; ?>
				<?php /* build_id тут не обязателен: если сборки нет (или её строка не
						доехала до вьюхи), отказываться от кнопки значило бы оставить
						человека с пустым списком «не заказывали» навсегда — сервер сам
						создаёт свежую сборку и ставит пачку на неё. */ ?>
				<?php if ($ok AND $can_prepare_parts AND $parts): ?>
					<form method="post" action="<?php echo HTML::chars($base); ?>" class="ml-auto flex flex-wrap items-center gap-2">
						<?php if ($csrf !== ''): ?>
							<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<?php endif; ?>
						<?php if ($build_id): ?>
							<input type="hidden" name="build" value="<?php echo $build_id; ?>">
						<?php endif; ?>
						<button type="submit" name="parts" value="1" data-job-btn="parts" <?php echo $parts_busy ? 'disabled aria-busy="true"' : ''; ?> class="n2j-btn-secondary n2j-btn-sm"
							title="По одному STL на тип детали, плашмя на столе. Готовые узлы не перерендериваются.">
							<i class="fas fa-cubes mr-1.5"></i><?php echo $parts_label; ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<?php
			/* Превью и статус узла собираем один раз: и таблица, и плитки берут одну
			   разметку, правка не разъедется. JS обновляет все копии по data-part-*. */
			$part_preview_html = [];
			$part_status_html  = [];

			foreach ($parts as $part):
				$pi   = (int) $part['i'];
				$prow = (array) Arr::get($part_of, $pi, []);
				$pst  = (string) Arr::get($prow, 'status', '');
				$pst_font = $pst === 'done' ? 'text-emerald-700 dark:text-emerald-400'
					: ($pst === 'error' ? 'text-rose-700 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400');

				ob_start();
				?>
<div class="h-24 w-24" data-part-preview="<?php echo $pi; ?>"><?php if (isset($preview_of_set[$pi])): ?><img src="<?php echo HTML::chars($base.'?part='.$pi.'&file=preview'.($build_id ? '&build='.$build_id : '')); ?>" width="96" height="96" loading="lazy" alt="Узел #<?php echo $pi + 1; ?>" class="h-24 w-24 object-contain"><?php endif; ?></div>
				<?php
				$part_preview_html[$pi] = ob_get_clean();

				ob_start();
				?>
<span class="<?php echo $pst_font; ?>" data-part-state="<?php echo $pi; ?>"><?php echo HTML::chars(Arr::get($part_text, $pst, 'не заказывали')); ?></span>
					<?php if ($pst === 'done'): ?>
						<a class="ml-1 font-semibold text-blue-600 hover:underline dark:text-blue-400" data-part-dl href="<?php echo HTML::chars($base.'?part='.$pi.'&file=stl'.($build_id ? '&build='.$build_id : '')); ?>">STL</a>
						<span class="text-gray-400 dark:text-gray-500"><?php echo $n((int) Arr::get($prow, 'stl_size', 0) / 1048576, 2); ?> МБ</span>
						<?php /* «в 3D» — просьба владельца: узел должен быть
							 виден в этом же окне, а не только в слайсере. */ ?>
						<button type="button" data-part-view="<?php echo $pi; ?>" data-build="<?php echo $build_id; ?>"
							data-size="<?php echo (int) Arr::get($prow, 'stl_size', 0); ?>" data-render-ms="<?php echo (int) Arr::get($prow, 'render_ms', 0); ?>" aria-pressed="false"
							title="Показать только этот узел в окне выше; второй клик вернёт изделие"
							class="ml-1 rounded-lg border border-blue-300 bg-white px-2 py-0.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 dark:border-gray-700 dark:bg-gray-800 dark:text-blue-300">в 3D</button>
					<?php elseif ($pst !== '' AND $can_moderate): ?>
						<a class="ml-1 text-gray-600 hover:underline dark:text-gray-300" href="<?php echo HTML::chars($base.'?part='.$pi.'&format=scad'.($build_id ? '&build='.$build_id : '')); ?>">.scad</a>
					<?php endif; ?>
<span class="block text-xs text-gray-500 dark:text-gray-400" data-part-error="<?php echo $pi; ?>"><?php echo HTML::chars((string) Arr::get($prow, 'error', '')); ?></span>
				<?php
				$part_status_html[$pi] = ob_get_clean();
			endforeach;
			?>

			<?php /* Вид списка: «Плитки» по умолчанию, «Таблица» — все колонки. Выбор
				 пишется в куку модуля; класс на <html> ставит скрипт до разметки. */ ?>
			<script>document.documentElement.classList.toggle('nj667-pview-table', /(?:^|;\s*)nj667_pview=table(?:;|$)/.test(document.cookie));</script>
			<div class="mt-1.5 flex flex-wrap items-center justify-between gap-2">
				<?php if ($logged AND ! $glb_ready AND ! $job_live): ?>
					<form method="post" action="<?php echo HTML::chars($base); ?>" data-request-files-form class="flex flex-wrap items-center gap-2">
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<?php if ($build_id): ?><input type="hidden" name="build" value="<?php echo $build_id; ?>"><?php endif; ?>
						<button type="submit" name="request_glb" value="1" class="n2j-btn-process n2j-btn-sm">
							<i class="fas fa-cube mr-1.5"></i>Собрать 3D файлы сборки
						</button>
						<span class="text-xs text-gray-500 dark:text-gray-400">Создаст вашу GLB-копию, не меняя модель владельца; STL-детали — отдельно.</span>
					</form>
				<?php endif; ?>
				<button type="button" data-show-whole class="n2j-btn-secondary n2j-btn-sm" title="Вернуть на сцену всё изделие (после «в 3D» одного узла)"><i class="fas fa-cube mr-1"></i>Показать изделие целиком</button>
				<div class="n2j-seg" role="group" aria-label="Вид списка деталей">
					<button type="button" class="n2j-seg-item" data-pview="tiles" aria-pressed="true">Плитки</button>
					<button type="button" class="n2j-seg-item" data-pview="table" aria-pressed="false">Таблица</button>
				</div>
			</div>

			<div class="nj667-parts-table mt-1.5 hidden overflow-x-auto [.nj667-pview-table_&]:block">
				<table class="n2j-table min-w-full">
					<thead><tr class="text-left text-xs text-gray-500 dark:text-gray-400">
						<th class="py-0.5 pr-2 font-medium">Тип</th><th class="py-0.5 pr-2 font-medium">Превью</th><th class="py-0.5 pr-2 font-medium">Копий</th>
						<th class="py-0.5 pr-2 font-medium">Пазов</th><th class="py-0.5 pr-2 font-medium">Углы, °</th>
						<th class="py-0.5 pr-2 font-medium">Мин., °</th><th class="py-0.5 pr-2 font-medium">Узлы</th>
						<th class="py-0.5 pr-2 font-medium">К печати</th>
					</tr></thead>
					<tbody>
						<?php foreach ($parts as $part): ?>
							<?php $pi = (int) $part['i']; ?>
							<tr class="border-t border-gray-100 dark:border-gray-800">
								<td class="py-1 pr-2 font-semibold">#<?php echo $pi + 1; ?></td>
								<td class="py-1 pr-2"><?php echo $part_preview_html[$pi]; ?></td>
								<td class="py-1 pr-2"><?php echo (int) $part['count']; ?> шт.</td>
								<td class="py-1 pr-2"><?php echo (int) $part['slots']; ?></td>
								<td class="py-1 pr-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars(implode(' ', array_map($n, (array) $part['arms']))); ?></td>
								<td class="py-1 pr-2 <?php echo ((float) $part['min_angle'] < (float) Arr::get($opt, 'min_angle', 22)) ? 'text-amber-700 dark:text-amber-400 font-semibold' : ''; ?>"><?php echo (int) $part['min_angle'] >= 999 ? '—' : $n($part['min_angle']); ?></td>
								<td class="py-1 pr-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(implode(' ', (array) $part['nodes'])); ?></td>
								<td class="py-1 pr-2 text-xs" data-part="<?php echo $pi; ?>"><?php echo $part_status_html[$pi]; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="nj667-parts-tiles mt-1.5 grid justify-start gap-3 [grid-template-columns:repeat(auto-fill,10.5rem)] [.nj667-pview-table_&]:hidden [&_[data-part-error]:empty]:hidden [&_[data-part-error]]:basis-full [&_[data-part-view]]:ml-auto">
				<?php foreach ($parts as $part): ?>
					<?php $pi = (int) $part['i']; ?>
					<div class="n2j-card p-2.5" data-part-tile="<?php echo $pi; ?>">
						<div class="flex items-baseline justify-between text-xs">
							<span class="font-semibold text-gray-900 dark:text-white">#<?php echo $pi + 1; ?></span>
							<span class="text-gray-500 dark:text-gray-400"><?php echo (int) $part['count']; ?> шт.</span>
						</div>
						<div class="mt-2 flex h-28 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800"><?php echo $part_preview_html[$pi]; ?></div>
						<div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"><?php echo $part_status_html[$pi]; ?></div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php /* Действие — сразу под списком. В шапке карточки его не видно:
					поле зрения человека заканчивается на таблице, а пустое место
					под ней он читает как «ничего не предусмотрено» (вопрос
					владельца, 13.10.2026). */ ?>
			<?php if ($ok AND $can_prepare_parts AND $parts): ?>
				<div class="mt-2 flex flex-wrap items-center gap-2">
					<form method="post" action="<?php echo HTML::chars($base); ?>" class="flex items-center gap-2">
						<?php if ($csrf !== ''): ?>
							<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<?php endif; ?>
						<?php if ($build_id): ?>
							<input type="hidden" name="build" value="<?php echo $build_id; ?>">
						<?php endif; ?>
						<button type="submit" name="parts" value="1" data-job-btn="parts" <?php echo $parts_busy ? 'disabled aria-busy="true"' : ''; ?> class="n2j-btn-secondary n2j-btn-sm"
							title="По одному STL на тип детали, плашмя на столе. Готовые узлы не перерендериваются.">
							<i class="fas fa-cubes mr-1.5"></i><?php echo $parts_label; ?>
						</button>
					</form>
					<?php if ( ! $build_id): ?>
						<span class="text-xs font-semibold text-amber-800 dark:text-amber-300">файла на диске ещё нет — пачка встанет на свежую сборку</span>
					<?php endif; ?>
					<?php if ($view_href !== ''): ?>
						<a href="<?php echo HTML::chars($view_href); ?>" class="n2j-btn-secondary n2j-btn-sm"
							title="Изделие целиком, в красках по типам узлов (glTF). Палочки — своим цветом."><i class="fas fa-cube mr-1.5"></i>Сборка в 3D</a>
					<?php endif; ?>
					<?php if ($build_id AND $part_done > 0): ?>
						<span class="text-xs text-gray-500 dark:text-gray-400">отдельный узел без палочек — «в 3D» в его строке</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<p class="n2j-hint">Одинаковые узлы — одна деталь и несколько копий: печатайте сколько нужно, STL не плодится.
				<?php if ((int) Arr::get($plan, 'done', 0) > 0): ?>
					На диске <?php echo (int) Arr::get($plan, 'done', 0); ?> тип(ов) — их не пересобираем.
				<?php endif; ?>
				<?php if ($part_wait > 0): ?>
					<?php echo $part_wait; ?> тип(ов) ещё в очереди — строки обновляются сами, перезагружать не нужно.
				<?php endif; ?>
				<span>Один запуск подготовки ограничен <?php echo $parts_run_limit; ?> типами; автопродолжение не расширяет это окно.</span>
				<?php if ((int) Arr::get($plan, 'skipped', 0) > 0): ?>
					<span class="text-amber-700 dark:text-amber-400">Вне текущего окна осталось <?php echo (int) Arr::get($plan, 'skipped', 0); ?> тип(ов); запустите их отдельным нажатием после завершения.</span>
				<?php endif; ?>
			</p>
			<?php if ($parts AND ! $can_prepare_parts): ?>
				<p class="n2j-hint">Узлы к печати собирает владелец проекта или автор своей сборки.</p>
			<?php endif; ?>

			<?php if ($issues): ?>
				<h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
					<i class="fas fa-list-check mr-1.5 text-blue-500"></i>Замечания
				</h3>
				<ul class="mt-1 grid gap-0.5 text-xs">
					<?php foreach ($issues as $issue): ?>
						<?php $lvl = (string) Arr::get($issue, 'level', 'note'); ?>
						<li class="flex gap-2 <?php echo $lvl === 'warn' ? 'text-amber-700 dark:text-amber-400' : 'text-gray-600 dark:text-gray-300'; ?>">
							<i class="fas <?php echo $lvl === 'warn' ? 'fa-triangle-exclamation mt-0.5' : 'fa-circle-info mt-0.5'; ?>"></i>
							<span><?php echo HTML::chars((string) Arr::get($issue, 'text', '')); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else: ?>
				<p class="mt-2 text-xs text-emerald-700 dark:text-emerald-400"><i class="fas fa-circle-check mr-1.5"></i>Замечаний нет: собирается без подгонок.</p>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($owner AND $history): ?>
	<!-- ------------------------------------------------------------- история -->
	<?php /* <details>: прежние сборки нужны не всякий раз, а место на экране
			стоит дороже списка из десяти строк. */ ?>
	<details class="no-print mt-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-800 dark:bg-gray-900">
		<summary class="cursor-pointer font-semibold text-gray-700 dark:text-gray-200">
			<i class="fas fa-clock-rotate-left mr-1.5 text-blue-500"></i>Прежние сборки (<?php echo count($history); ?>)
		</summary>
		<ul class="mt-2 grid gap-1 sm:grid-cols-2">
			<?php foreach ($history as $row): ?>
				<?php $row = (array) $row; $rid = (int) Arr::get($row, 'id'); ?>
				<li class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-100 p-1.5 dark:border-gray-800 <?php echo $rid === $build_id ? 'bg-blue-50 dark:bg-blue-950' : ''; ?>">
					<a href="<?php echo HTML::chars($base.'?build='.$rid); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">#<?php echo $rid; ?></a>
					<span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'created_at')); ?></span>
					<span class="text-xs"><?php echo HTML::chars((string) Arr::get($row, 'type_key')); ?></span>
					<span class="text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(Arr::get($state_text, (string) Arr::get($row, 'status'), (string) Arr::get($row, 'status'))); ?></span>
					<?php if (trim((string) Arr::get($row, 'error', '')) !== ''): ?>
						<span class="w-full text-xs text-rose-700 dark:text-rose-300"><?php echo HTML::chars((string) Arr::get($row, 'error')); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</details>
	<?php endif; ?>

	<p class="n2j-hint mt-3">
		Узел = деталь; то, что печатается одним куском из нескольких узлов, считается одной деталью. Чертёж узла (A4) — отдельная страница, в таблице деталей её нет.
	</p>
</div>

<script type="importmap">
{
	"imports": {
		"three": "/assets/vendor/three/mod/three.module.js",
		"three/addons/": "/assets/vendor/three/mod/addons/"
	}
}
</script>

<script type="application/json" id="nj667-build-config">
<?php echo json_encode([
	'code'         => $code,
	'status'       => $status,
	'build_id'     => $build_id,
	'job_id'       => $job_id,
	'parts_job_id' => $parts_job_id,
	'api'          => '/graph3d/api/build_status',
	'csrf'         => $csrf,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<script type="module">
	window.NJ667_BUILD = JSON.parse(document.getElementById('nj667-build-config').textContent);
</script>
<script type="module" src="/assets/js/nj667-stl.js?v=20261015a"></script>
<?php
	// Конфиг типов для перерисовки полей без перезагрузки: параметры, подсказка и
	// недостающие scad-файлы каждого типа. Значения текущего типа — как на сервере.
	$type_cfg = [];
	foreach ($types as $tkey => $trow)
	{
		$trow  = (array) $trow;
		$tlist = [];

		foreach (Service_Nj667_Graph3d::build_type_params((string) $tkey) as $pname => $spec)
		{
			$spec    = (array) $spec;
			$tlist[] = [
				'name'  => (string) $pname,
				'title' => (string) Arr::get($spec, 'title', (string) $pname),
				'min'   => Arr::get($spec, 'min', 0),
				'max'   => Arr::get($spec, 'max', 100),
				'step'  => Arr::get($spec, 'step', 0.1),
				'def'   => Arr::get($spec, 'def', 0),
			];
		}

		$type_cfg[(string) $tkey] = [
			'hint'   => (string) Arr::get($trow, 'hint', ''),
			'lib'    => array_values((array) Service_Nj667_Graph3d::build_lib_missing((string) $tkey)),
			'params' => $tlist,
		];
	}

	$vals = [];
	foreach (Service_Nj667_Graph3d::build_type_params($type_key) as $pname => $spec)
	{
		$spec = (array) $spec;
		$vals[(string) $pname] = (string) Arr::get((array) Arr::get($opt, 'params', []), (string) $pname, Arr::get($spec, 'def'));
	}
?>
<script type="application/json" id="nj667-types-config">
<?php echo json_encode(['types' => $type_cfg, 'values' => (object) $vals, 'inputClass' => $in], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>
</script>
<script>
(function () {
	var cfgEl = document.getElementById('nj667-types-config');
	var box   = document.getElementById('nj667-type-params');
	if (!cfgEl || !box) { return; }

	var cfg   = JSON.parse(cfgEl.textContent);
	var form  = box.closest('form');
	var hint  = document.getElementById('nj667-type-hint');
	var libEl = document.getElementById('nj667-type-lib');
	var vals  = cfg.values || {};   // последнее введённое значение по имени параметра

	var ON  = 'border-blue-300 bg-blue-50 font-semibold text-gray-900 dark:border-blue-800 dark:bg-blue-950 dark:text-white'.split(' ');
	var OFF = 'border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white'.split(' ');

	function render(key) {
		var t = cfg.types[key];
		if (!t) { return; }

		// Запоминаем то, что человек уже ввёл, — общие посадки переживают смену типа.
		box.querySelectorAll('input[data-param]').forEach(function (i) {
			vals[i.getAttribute('data-param')] = i.value;
		});

		box.textContent = '';
		t.params.forEach(function (p) {
			var label = document.createElement('label');
			label.className = 'block text-xs text-gray-600 dark:text-gray-300';
			label.appendChild(document.createTextNode(p.title));

			var input = document.createElement('input');
			input.type = 'number';
			input.name = 'params[' + p.name + ']';
			input.setAttribute('data-param', p.name);
			var v = vals[p.name];
			input.value = (v === undefined || v === '') ? String(p.def) : String(v);
			input.min  = String(p.min);
			input.max  = String(p.max);
			input.step = String(p.step);
			input.className = cfg.inputClass + ' mt-0.5';

			label.appendChild(input);
			box.appendChild(label);
		});

		var bad = Array.isArray(t.lib) && t.lib.length > 0;
		if (libEl) {
			libEl.textContent = bad ? 'Нет файлов scad-библиотеки: ' + t.lib.join(', ') + '. STL собрать нельзя.' : '';
			libEl.hidden = !bad;
		}
		if (hint) {
			hint.textContent = t.hint || '';
			hint.hidden = bad || !t.hint;
		}
	}

	function paintTypes() {
		form.querySelectorAll('input[name="type"]').forEach(function (r) {
			var label = r.closest('label');
			if (!label) { return; }
			ON.forEach(function (c) { label.classList.toggle(c, r.checked); });
			OFF.forEach(function (c) { label.classList.toggle(c, !r.checked); });
		});
	}

	if (form) {
		form.addEventListener('change', function (e) {
			if (e.target && e.target.name === 'type') {
				paintTypes();
				render(e.target.value);
			}
		});
	}

	box.addEventListener('input', function (e) {
		if (e.target && e.target.hasAttribute('data-param')) {
			vals[e.target.getAttribute('data-param')] = e.target.value;
		}
	});
})();
</script>
