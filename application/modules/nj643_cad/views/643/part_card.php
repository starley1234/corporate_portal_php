<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: карточка детали для списка «Мои детали» (/cad).
 * Карточка самого сервиса для каталога — views/643/card.php.
 *
 * Неактивная деталь занимает компактную карточку: превью-ракурс, название,
 * габарит, статус и одна строка итога. Живой ряд показывается только когда
 * сборка реально идёт (эталон /trends: спиннер, секундомер, этапы).
 */
$p    = isset($p) ? (array) $p : [];
$live = isset($live) ? $live : NULL;
$last = isset($last) ? $last : NULL;
$csrf = isset($csrf) ? (string) $csrf : '';

$code   = (string) ($p['code'] ?? '');
$status = (string) ($p['status'] ?? 'queued');
$title  = (string) ($p['title'] ?? 'Деталь');

$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';

$st_badge = [
    'queued'    => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    'running'   => 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    'done'      => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    'failed'    => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
    'cancelled' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
];
$st_ru = ['queued' => 'в очереди', 'running' => 'собирается', 'done' => 'готова',
          'failed' => 'ошибка', 'cancelled' => 'остановлено'];

$report = (isset($p['report']) AND is_array($p['report'])) ? $p['report'] : json_decode((string) ($p['report'] ?? ''), TRUE);
$bbox   = (is_array($report) AND ! empty($report['geometry']['bbox']) AND is_array($report['geometry']['bbox']))
    ? $report['geometry']['bbox'] : [];

// Габарит для карточки: «60», «60,5». Нули режем только у дробной части —
// у целых хвостовой ноль трогать нельзя (иначе 10 превращается в 1).
$dim = function ($v) {
    $n = (float) $v;
    $s = (abs($n - round($n)) < 0.05)
        ? number_format($n, 0, ',', ' ')
        : number_format($n, 1, ',', ' ');

    if (strpos($s, ',') !== FALSE)
    {
        $s = rtrim(rtrim($s, '0'), ',');
    }

    return $s;
};
?>
<div class="<?php echo $card; ?>" data-nj643-part="<?php echo HTML::chars($code); ?>">
    <div class="flex gap-3">
        <a href="/cad/<?php echo urlencode($code); ?>" class="block h-24 w-24 shrink-0 overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40">
            <?php if ( ! empty($p['preview_id'])): ?>
            <img src="/cad/file/<?php echo (int) $p['preview_id']; ?>" alt="Ракурс детали" class="h-full w-full object-cover" loading="lazy">
            <?php else: ?>
            <span class="flex h-full w-full items-center justify-center text-gray-300 dark:text-gray-600"><i class="fas fa-cube text-2xl"></i></span>
            <?php endif; ?>
        </a>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <a href="/cad/<?php echo urlencode($code); ?>" class="min-w-0 truncate text-base font-bold text-gray-900 hover:text-blue-600 hover:underline dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars($title); ?></a>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold <?php echo $st_badge[$status] ?? $st_badge['queued']; ?>"><?php echo HTML::chars($st_ru[$status] ?? $status); ?></span>
            </div>

            <p class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-sm text-gray-500 dark:text-gray-400">
                <?php if ($bbox): ?>
                <span class=""><?php echo $dim($bbox[0] ?? 0); ?> × <?php echo $dim($bbox[1] ?? 0); ?> × <?php echo $dim($bbox[2] ?? 0); ?> мм</span>
                <?php endif; ?>
                <span class="">ревизия <?php echo (int) ($p['revision'] ?? 1); ?></span>
                <?php if ((int) ($p['models_n'] ?? 0) > 0): ?>
                <span class="">файлов: <?php echo (int) $p['models_n']; ?></span>
                <?php endif; ?>
                <span class="text-gray-400"><?php echo HTML::chars((string) ($p['created_at'] ?? '')); ?></span>
            </p>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <a href="/cad/<?php echo urlencode($code); ?>" class="n2j-btn-process n2j-btn-sm">
                    <i class="fas fa-cube text-[11px]"></i>Открыть
                </a>
                <?php if (trim((string) ($p['code_py'] ?? '')) !== ''): ?>
                <button type="button" data-nj643-run data-code="<?php echo HTML::chars($code); ?>"
                        title="Пересобрать по текущим размерам"
                        class="n2j-btn-secondary n2j-btn-sm">
                    <i class="fas fa-rotate text-[11px]"></i>Пересобрать
                </button>
                <?php endif; ?>
                <?php if (trim((string) ($p['code_py'] ?? '')) !== ''): ?>
                <form method="post" action="/cad" class="inline-flex">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="copy">
                    <input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">
                    <button type="submit" title="Новая деталь из того же кода модели — без ИИ, итерации не портят эту деталь"
                            class="n2j-btn-secondary n2j-btn-sm">
                        <i class="fas fa-clone text-[11px]"></i>По образцу
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($live): ?>
    <div data-nj643-live-host class="mt-3">
        <?php echo View::factory('partials/live_status', [
            'url'     => '/cad/api/status/'.urlencode($code),
            'title'   => 'Собираю деталь',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) ($live['stages'] ?? []),
            'note'    => 'Ракурсы появятся на странице детали — страница обновится сама',
            'stop'    => '<form method="post" class="shrink-0">'
                .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                .'<input type="hidden" name="act" value="stop_run">'
                .'<input type="hidden" name="code" value="'.HTML::chars($code).'">'
                .'<button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop text-[11px]"></i>Остановить</button>'
                .'</form>',
        ]); ?>
    </div>
    <?php else: ?>
    <div data-nj643-live-host class="mt-3">
        <template data-n2j-live-tpl="<?php echo HTML::chars($code); ?>">
            <?php echo View::factory('partials/live_status', [
                'url'     => '/cad/api/status/'.urlencode($code),
                'title'   => 'Собираю деталь',
                'status'  => 'queued',
                'elapsed' => 0,
                'silent'  => NULL,
                'steps'   => [],
                'note'    => 'Ракурсы появятся на странице детали — страница обновится сама',
                'stop'    => '',
            ]); ?>
        </template>
        <?php if ($last): ?>
        <?php echo View::factory('partials/last_status', [
            'status'      => (string) $last['status'],
            'finished_at' => (string) $last['finished_at'],
            'error'       => (string) $last['error'],
            'found'       => (int) ($p['files_count'] ?? 0),
            'found_label' => 'файлов',
            'steps'       => (array) ($last['stages'] ?? []),
            'elapsed'     => (int) $last['elapsed_sec'],
        ]); ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
