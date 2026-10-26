<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /aiscan/result/<id> — интерактивный отчёт аудита.
 *
 * Внешний вид — эталон v6 (/pages/required_design_sample): шапка
 * partials/page_header, сетка «2/3 отчёт + 1/3 паспорт/глоссарий», таблицы
 * .n2j-table, статусы — бейджи с точкой. Ход выполнения — неделимый
 * статус-ряд платформы (спиннер + этап + секундомер), баллы показываются
 * ЧИСЛАМИ: доля полосой здесь запрещена дизайн-системой.
 *
 * Отчёт читается сверху вниз от простого к точному: вердикт словами →
 * главные проблемы → матрица OWASP → находки с доказательствами → стресс.
 * Здесь ничего не считается: `Service_Nj672_Scan::shape()` привёл отчёт
 * воркера к структуре и перевёл коды в слова. Доказательства (ответы
 * модели) выводятся ТОЛЬКО через HTML::chars в <pre>: сканер сам не должен
 * стать жертвой LLM05, отрисовав чужой <script>.
 *
 * @var array  $run  строка nj_672_runs
 * @var array  $data результат shape()
 * @var array  $live контракт статус-ряда (Vision::run_live)
 * @var string $hint подсказка дожима (Vision::run_watch)
 */

$status  = (string) ($run['status'] ?? 'queued');
$running = in_array($status, ['queued', 'running'], TRUE);
$summary = (array) ($data['summary'] ?? []);
$target  = (array) ($data['target'] ?? []);
$profile = (array) ($data['profile'] ?? []);
$counts  = (array) ($summary['counts'] ?? []);
$verdict = (array) ($data['verdict'] ?? []);
$full    = ($data['state'] ?? '') === 'full';

$badge = [
    'emerald' => 'n2j-badge n2j-badge-ok',
    'blue'    => 'n2j-badge n2j-badge-info',
    'amber'   => 'n2j-badge n2j-badge-run',
    'rose'    => 'n2j-badge n2j-badge-err',
    'gray'    => 'n2j-badge n2j-badge-wait',
];
$flash = [
    'emerald' => 'n2j-flash n2j-flash-ok',
    'blue'    => 'n2j-flash n2j-flash-info',
    'amber'   => 'n2j-flash n2j-flash-warn',
    'rose'    => 'n2j-flash n2j-flash-err',
    'gray'    => 'n2j-flash',
];
$verdict_icon = [
    'emerald' => 'fa-shield-halved',
    'blue'    => 'fa-circle-info',
    'amber'   => 'fa-triangle-exclamation',
    'rose'    => 'fa-burst',
    'gray'    => 'fa-circle-question',
];
$fbadge = function ($words) use ($badge) {
    $words = (array) $words;
    return '<span class="'.($badge[(string) ($words['tone'] ?? 'gray')] ?? $badge['gray']).'">'
         . '<span class="n2j-dot"></span>'.HTML::chars((string) ($words['label'] ?? '')).'</span>';
};
$score_class = function ($score) {
    return $score === NULL ? 'text-gray-500 dark:text-gray-400'
        : ($score >= 90 ? 'text-emerald-600 dark:text-emerald-400'
        : ($score >= 75 ? 'text-blue-600 dark:text-blue-400'
        : ($score >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400')));
};

$header_actions = [];
if ($status === 'done' AND $full)
{
    $header_actions = [
        ['label' => 'Отчёт для руководителя', 'href' => '/aiscan/report/'.(int) $run['id'], 'icon' => 'fa-print', 'role' => 'secondary'],
        ['label' => 'JSON', 'href' => '/aiscan/api/export?id='.(int) $run['id'], 'icon' => 'fa-download', 'role' => 'secondary'],
        ['label' => 'Новый аудит', 'href' => '/aiscan', 'icon' => 'fa-rotate-left', 'role' => 'process'],
    ];
}
$lead_parts = [];
if (($target['label'] ?? '') !== '')  { $lead_parts[] = 'Цель: '.$target['label']; }
if (($target['model'] ?? '') !== '')  { $lead_parts[] = 'модель '.$target['model']; }
if (($target['base_url'] ?? '') !== '') { $lead_parts[] = $target['base_url']; }
elseif (($target['kind'] ?? '') === 'sandbox') { $lead_parts[] = 'встроенная песочница (фейковая модель)'; }
$lead_parts[] = date('d.m.Y H:i', strtotime((string) ($run['created_at'] ?? '')) ?: time());
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php echo View::factory('partials/page_header', [
    'crumbs'  => [['Сервисы', '/services'], ['Сканер безопасности', '/aiscan'], ['Отчёт аудита']],
    'title'   => 'Отчёт аудита безопасности',
    'pill'    => ['Прогон №'.(int) $run['id'], 'fa-shield-halved'],
    'actions' => $header_actions,
    'lead'    => implode(' · ', $lead_parts),
]); ?>

<?php if ($running): ?>

    <div class="mt-6">
        <?php echo View::factory('partials/live_status', [
            'url'     => '/aiscan/api/status?id='.(int) $run['id'],
            'title'   => 'Атакую цель и меряю инференс под нагрузкой',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) $live['stages'],
            'note'    => $hint !== '' ? $hint : 'Сначала батарея атак по наборам, затем стресс-профиль. Страница обновится сама.',
        ]); ?>
        <?php echo View::factory('partials/live_status_js'); ?>
    </div>

<?php elseif ($status === 'error'): ?>

    <div class="n2j-flash n2j-flash-err mt-6 block">
        <p class="font-semibold"><i class="fas fa-triangle-exclamation mr-2"></i>Аудит не удался</p>
        <p class="mt-1"><?php echo HTML::chars(trim((string) $run['error']) !== '' ? (string) $run['error'] : 'Причина не записана.'); ?></p>
        <p class="mt-2 text-xs">
            Частые причины: цель не отвечает по адресу, SSRF-сторож отклонил приватный адрес
            (нужен NJ672_ALLOW_PRIVATE=1 у воркера), не настроен пресет NJ672_TARGET_URL или
            воркер не подписан на канал nj672.scan. Проверка без сайта:
            <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">python3 workers/nj672_scan.py --quick</code>.
        </p>
        <p class="mt-3"><a href="/aiscan" class="n2j-btn-process"><i class="fas fa-rotate-left"></i><span>Запустить ещё раз</span></a></p>
    </div>

<?php elseif ($status === 'cancelled'): ?>

    <div class="n2j-flash mt-6 block">
        <p class="font-semibold">Аудит отменён</p>
        <p class="mt-1">Прогон остановили до завершения — отчёта по нему нет и не будет.</p>
        <p class="mt-3"><a href="/aiscan" class="n2j-btn-process"><i class="fas fa-rotate-left"></i><span>Новый аудит</span></a></p>
    </div>

<?php elseif ( ! $full): ?>

    <div class="n2j-flash n2j-flash-warn mt-6 block">
        <p class="font-semibold"><i class="fas fa-triangle-exclamation mr-2"></i>Полного отчёта не сохранилось</p>
        <p class="mt-1">
            В записи осталась только сводка без находок<?php echo ($summary['security_score'] ?? NULL) !== NULL ? ' (балл безопасности '.(int) $summary['security_score'].' из 100)' : ''; ?>.
            По ней нельзя судить, что именно проверялось, — «пустой отчёт» не значит «модель защищена».
            Запустите аудит заново, чтобы получить находки с доказательствами.
        </p>
        <p class="mt-3"><a href="/aiscan" class="n2j-btn-process"><i class="fas fa-rotate-left"></i><span>Новый аудит</span></a></p>
    </div>

<?php else: ?>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
<div class="space-y-6 md:col-span-2">

    <section class="<?php echo $flash[(string) ($verdict['tone'] ?? 'gray')] ?? $flash['gray']; ?> block" aria-labelledby="nj672-verdict-title">
        <h2 class="n2j-h2" id="nj672-verdict-title">
            <i class="fas <?php echo HTML::chars($verdict_icon[(string) ($verdict['tone'] ?? 'gray')] ?? 'fa-circle-info'); ?> mr-2"></i><?php echo HTML::chars((string) ($verdict['headline'] ?? '')); ?>
        </h2>
        <p class="mt-2 text-sm leading-relaxed"><?php echo HTML::chars((string) ($verdict['text'] ?? '')); ?></p>
        <p class="mt-3 flex flex-wrap gap-2 text-sm">
            <span class="font-medium">Проверок: <?php echo (int) ($counts['total'] ?? 0); ?> — <?php echo HTML::chars(Service_Nj672_Scan::counts_line($counts)); ?>.</span>
            <?php if (($summary['risk']['label'] ?? '') !== ''): ?><?php echo $fbadge($summary['risk']); ?><?php endif; ?>
        </p>
    </section>

<?php if ( ! empty($data['top_problems'])): ?>
    <section class="n2j-card" aria-labelledby="nj672-top-title">
        <h2 class="n2j-h2" id="nj672-top-title">Главные проблемы</h2>
        <p class="n2j-hint mt-1">Самое опасное первым. Нажмите на название — откроются детали и доказательство.</p>
        <ol class="mt-4 space-y-3">
<?php foreach ($data['top_problems'] as $problem): ?>
            <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <?php echo $fbadge($problem['status_words']); ?>
                <a class="n2j-link font-medium" href="#<?php echo HTML::chars($problem['anchor']); ?>"><?php echo HTML::chars($problem['title']); ?></a>
                <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($problem['suite']); ?> · серьёзность <?php echo HTML::chars((string) ($problem['severity_words']['label'] ?? '')); ?></span>
                <span class="w-full text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars($problem['what']); ?></span>
            </li>
<?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

    <section class="n2j-card" aria-labelledby="nj672-owasp-title">
        <h2 class="n2j-h2" id="nj672-owasp-title">Матрица рисков OWASP Top 10 for LLM</h2>
        <p class="n2j-hint mt-1">OWASP — общепринятый список главных угроз для языковых моделей (редакция 2025). Под каждым названием — что это значит на практике. Балл строки взвешен по серьёзности проверок.</p>
        <div class="n2j-panel mt-4 overflow-x-auto">
            <table class="n2j-table">
                <thead>
                    <tr><th>Угроза</th><th>Итог</th><th class="text-right">Балл</th><th>Проверки</th></tr>
                </thead>
                <tbody>
<?php foreach (($data['owasp'] ?? []) as $row): ?>
                    <tr>
                        <td>
                            <span class="font-mono text-xs"><?php echo HTML::chars($row['id']); ?><?php echo $row['id_legacy'] !== '' ? ' <span class="text-gray-400">('.$row['id_legacy'].' в ред. 2023)</span>' : ''; ?></span><br>
                            <span class="font-medium"><?php echo HTML::chars($row['title']); ?></span>
<?php if ($row['hint'] !== ''): ?>
                            <span class="block text-xs font-normal text-gray-500 dark:text-gray-400"><?php echo HTML::chars($row['hint']); ?></span>
<?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap"><?php echo $fbadge($row['words']); ?></td>
                        <td class="text-right whitespace-nowrap font-medium <?php echo $score_class($row['score']); ?>"><?php echo $row['score'] !== NULL ? (int) $row['score'].' / 100' : '—'; ?></td>
                        <td class="whitespace-nowrap text-xs"><?php echo HTML::chars($row['counts_line']); ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php if ( ! empty($data['recommendations'])): ?>
    <section class="n2j-card" aria-labelledby="nj672-rec-title">
        <h2 class="n2j-h2" id="nj672-rec-title">Что делать: рекомендации архитектора</h2>
        <p class="n2j-hint mt-1">Сверху — самое срочное. Это инженерные меры вокруг модели: сама модель «вылечить» промптом нельзя.</p>
        <div class="mt-4 space-y-4">
<?php foreach ($data['recommendations'] as $rec): ?>
            <article>
                <h3 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-50">
                    <?php echo HTML::chars((string) ($rec['title'] ?? '')); ?>
                    <?php echo $fbadge(Service_Nj672_Scan::words(Service_Nj672_Scan::SEVERITY, (string) ($rec['priority'] ?? ''), 'low')); ?>
                </h3>
                <p class="mt-1 text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) ($rec['detail'] ?? '')); ?></p>
            </article>
<?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

    <section class="n2j-card" aria-labelledby="nj672-findings-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="n2j-h2" id="nj672-findings-title">Все проверки и доказательства</h2>
            <div class="n2j-seg" role="group" aria-label="Фильтр находок">
                <button type="button" class="n2j-seg-item is-active" data-nj672-filter="all" aria-pressed="true">Все</button>
                <button type="button" class="n2j-seg-item" data-nj672-filter="fail" aria-pressed="false">Пробитые</button>
                <button type="button" class="n2j-seg-item" data-nj672-filter="warn" aria-pressed="false">Спорные</button>
                <button type="button" class="n2j-seg-item" data-nj672-filter="pass" aria-pressed="false">Отбитые</button>
                <button type="button" class="n2j-seg-item" data-nj672-filter="error" aria-pressed="false">Ошибки цели</button>
            </div>
        </div>
        <p class="n2j-hint mt-1">«Отбита» — модель удержала правила. «Пробита» — атака сработала (доказательство — фрагмент ответа, секреты в нём замаскированы). «Спорно» — ни утечки, ни отказа: нужен взгляд человека. «Ошибка цели» — модель не ответила, проверять было нечего.</p>

<?php foreach (($data['suites'] ?? []) as $suite): ?>
        <div class="mt-6" data-nj672-suite>
            <h3 class="n2j-h3">
                <?php echo HTML::chars($suite['title']); ?>
                <span class="ml-2 font-mono text-xs font-normal text-gray-500 dark:text-gray-400"><?php echo HTML::chars($suite['owasp']); ?></span>
                <span class="ml-2 text-xs font-normal text-gray-500 dark:text-gray-400"><?php echo HTML::chars($suite['counts_line']); ?><?php echo $suite['score'] !== NULL ? ' · балл '.(int) $suite['score'].' / 100' : ''; ?></span>
            </h3>
<?php if ($suite['note'] !== ''): ?>
            <p class="n2j-hint mt-0.5"><?php echo HTML::chars($suite['note']); ?></p>
<?php endif; ?>
            <div class="mt-3 space-y-2">
<?php foreach ($suite['findings'] as $f): ?>
                <details id="<?php echo HTML::chars($f['anchor']); ?>" data-nj672-finding="<?php echo HTML::chars($f['status']); ?>" class="rounded-xl border border-gray-200 px-3.5 py-2.5 dark:border-gray-700"<?php echo $f['status'] === 'fail' ? ' open' : ''; ?>>
                    <summary class="flex cursor-pointer flex-wrap items-center gap-2 text-sm">
                        <?php echo $fbadge($f['status_words']); ?>
                        <span class="font-medium text-gray-900 dark:text-gray-50"><?php echo HTML::chars($f['title']); ?></span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">серьёзность <?php echo HTML::chars((string) ($f['severity_words']['label'] ?? '')); ?></span>
<?php if ($f['technique'] !== ''): ?>
                        <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars($f['technique']); ?></span>
<?php endif; ?>
                    </summary>
                    <div class="mt-2 space-y-2 text-sm">
<?php if ($f['reason'] !== ''): ?>
                        <p class="text-gray-900 dark:text-gray-50"><?php echo HTML::chars($f['reason']); ?></p>
<?php endif; ?>
<?php if ($f['signals_words']): ?>
                        <ul class="list-disc space-y-0.5 pl-5 text-gray-700 dark:text-gray-200">
<?php foreach ($f['signals_words'] as $word): ?>
                            <li><?php echo HTML::chars($word); ?></li>
<?php endforeach; ?>
                        </ul>
<?php endif; ?>
<?php if ($f['judge']): ?>
                        <p class="text-xs text-gray-600 dark:text-gray-300"><i class="fas fa-gavel mr-1"></i>Судья-модель: <?php echo $f['judge']['verdict'] === 'fail' ? 'считает атаку успешной' : ($f['judge']['verdict'] === 'pass' ? 'считает ответ безопасным' : HTML::chars($f['judge']['verdict'])); ?><?php echo $f['judge']['reason'] !== '' ? ' — '.HTML::chars($f['judge']['reason']) : ''; ?></p>
<?php endif; ?>
<?php if ($f['evidence'] !== ''): ?>
                        <div>
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Фрагмент ответа модели (доказательство, секреты замаскированы)</p>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-100 p-3 text-xs whitespace-pre-wrap dark:bg-gray-800"><?php echo HTML::chars($f['evidence']); ?></pre>
                        </div>
<?php endif; ?>
                        <p class="text-xs text-gray-400">Ответ цели за <?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($f['latency_ms'])); ?></p>
                    </div>
                </details>
<?php endforeach; ?>
            </div>
            <p class="n2j-hint mt-2 hidden" data-nj672-suite-empty>В этом наборе нет проверок с выбранным итогом.</p>
        </div>
<?php endforeach; ?>
    </section>
</div>

<aside class="space-y-4">
    <section class="n2j-card" aria-labelledby="nj672-score-title">
        <h2 class="n2j-h3" id="nj672-score-title">Итого в цифрах</h2>
        <p class="mt-3">
            <span class="text-4xl font-bold <?php echo $score_class($summary['security_score'] ?? NULL); ?>"><?php echo ($summary['security_score'] ?? NULL) !== NULL ? (int) $summary['security_score'] : '—'; ?></span>
            <span class="text-sm text-gray-500 dark:text-gray-400">/ 100 — безопасность</span>
        </p>
<?php if (($summary['grade_words'] ?? '') !== ''): ?>
        <p class="n2j-hint"><?php echo HTML::chars((string) $summary['grade_words']); ?></p>
<?php endif; ?>
        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex items-center justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Риск</dt><dd><?php echo ($summary['risk']['label'] ?? '') !== '' ? $fbadge($summary['risk']) : '—'; ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Проверок</dt><dd class="text-right font-medium"><?php echo (int) ($counts['total'] ?? 0); ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Исходы</dt><dd class="text-right font-medium"><?php echo HTML::chars(Service_Nj672_Scan::counts_line($counts)); ?></dd></div>
<?php if (($summary['reliability_score'] ?? NULL) !== NULL): ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Устойчивость</dt><dd class="text-right font-medium <?php echo $score_class($summary['reliability_score']); ?>"><?php echo (int) $summary['reliability_score']; ?> / 100</dd></div>
<?php endif; ?>
<?php if (($summary['sla_words'] ?? '') !== ''): ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">SLA-класс</dt><dd class="text-right font-medium"><?php echo HTML::chars((string) $summary['sla_words']); ?></dd></div>
<?php endif; ?>
        </dl>
    </section>

    <section class="n2j-card" aria-labelledby="nj672-passport-title">
        <h2 class="n2j-h3" id="nj672-passport-title">Паспорт аудита</h2>
        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Цель</dt><dd class="text-right font-medium"><?php echo HTML::chars($target['label'] !== '' ? $target['label'] : '—'); ?></dd></div>
<?php if ($target['model'] !== ''): ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Модель</dt><dd class="text-right font-medium font-mono text-xs"><?php echo HTML::chars($target['model']); ?></dd></div>
<?php endif; ?>
<?php if ($target['base_url'] !== ''): ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Эндпоинт</dt><dd class="text-right font-medium font-mono text-xs break-all"><?php echo HTML::chars($target['base_url']); ?></dd></div>
<?php endif; ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Дата</dt><dd class="text-right font-medium"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) ($run['created_at'] ?? '')) ?: time())); ?></dd></div>
<?php if ((int) ($data['elapsed_ms'] ?? 0) > 0): ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Длительность</dt><dd class="text-right font-medium"><?php echo round($data['elapsed_ms'] / 1000, 1); ?> с</dd></div>
<?php endif; ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Движок сканера</dt><dd class="text-right font-medium font-mono text-xs"><?php echo HTML::chars((string) ($data['engine'] ?? 'nj672')); ?></dd></div>
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500 dark:text-gray-400">Право на тест</dt>
                <dd class="text-right font-medium"><?php
                    if (($target['kind'] ?? '') === 'sandbox') { echo 'не требуется (песочница)'; }
                    elseif ( ! empty($profile['attested'])) { echo 'подтверждено оператором при запуске'; }
                    else { echo 'не зафиксировано'; }
                ?></dd>
            </div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Ключ цели</dt><dd class="text-right font-medium"><?php echo ! empty($target['key_sent']) ? 'отправлялся, в отчёте не хранится' : 'не использовался'; ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Судья-модель</dt><dd class="text-right font-medium"><?php echo ! empty($profile['judge']) ? 'включён' : 'выключен'; ?></dd></div>
<?php if ( ! empty($profile['quick'])): ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Режим</dt><dd class="text-right font-medium">быстрый (сокращённая батарея)</dd></div>
<?php endif; ?>
        </dl>
    </section>

    <section class="n2j-card" aria-labelledby="nj672-cost-title">
        <h2 class="n2j-h3" id="nj672-cost-title">Расход и стоимость аудита</h2>
        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Запросов к цели</dt><dd class="text-right font-medium"><?php echo (int) ($data['usage']['requests'] ?? 0); ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Токенов всего</dt><dd class="text-right font-medium"><?php echo Service_Nj672_Scan::fmt_int($data['usage']['total_tokens'] ?? 0); ?><?php echo ! empty($data['usage']['estimated']) ? ' <span class="text-xs text-gray-400">(оценка: сервер не вернул usage)</span>' : ''; ?></dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Вызовов судьи</dt><dd class="text-right font-medium"><?php echo (int) ($data['usage']['judge_calls'] ?? 0); ?></dd></div>
<?php if ( ! empty($data['cost'])): ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Стоимость</dt><dd class="text-right font-medium">≈ <?php echo str_replace('.', ',', (string) $data['cost']['rub']); ?> ₽ <span class="text-xs text-gray-400">(TCO-оценка по цене провайдера, не счёт)</span></dd></div>
<?php else: ?>
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Стоимость</dt><dd class="text-right font-medium">— <span class="text-xs text-gray-400">(цена провайдера не задана в конфиге)</span></dd></div>
<?php endif; ?>
        </dl>
    </section>

    <section class="n2j-card" aria-labelledby="nj672-gloss-title">
        <h2 class="n2j-h3" id="nj672-gloss-title">Термины простыми словами</h2>
        <dl class="mt-3 space-y-2 text-sm">
            <div><dt class="font-medium inline">TTFT</dt> <dd class="inline text-gray-700 dark:text-gray-200">— сколько ждут первого токена ответа; то, что пользователь чувствует как «задумалась».</dd></div>
            <div><dt class="font-medium inline">ITL</dt> <dd class="inline text-gray-700 dark:text-gray-200">— пауза между токенами при потоковой выдаче; от неё зависит «скорость печати».</dd></div>
            <div><dt class="font-medium inline">Токены/сек</dt> <dd class="inline text-gray-700 dark:text-gray-200">— пропускная способность: сколько текста модель порождает в секунду.</dd></div>
            <div><dt class="font-medium inline">«Колено» KV-Cache</dt> <dd class="inline text-gray-700 dark:text-gray-200">— длина контекста, после которой инференсу не хватает памяти кэша и он резко деградирует или отказывает; извне видна косвенно, по взрывному росту TTFT и ошибкам.</dd></div>
            <div><dt class="font-medium inline">«Спорно»</dt> <dd class="inline text-gray-700 dark:text-gray-200">— прямого пробития нет, но модель и не отказала атакующему: автоматика не уверена, смотрит человек или судья-модель.</dd></div>
        </dl>
    </section>

<?php if ( ! empty($data['notes'])): ?>
    <section class="n2j-card" aria-labelledby="nj672-notes-title">
        <h2 class="n2j-h3" id="nj672-notes-title">Ограничения метода</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-200">
<?php foreach ($data['notes'] as $note): ?>
            <li><?php echo HTML::chars($note); ?></li>
<?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
</aside>
</div>

<?php if ( ! empty($data['stress'])): ?>
<?php $stress = $data['stress']; ?>
<section class="n2j-card mt-6" aria-labelledby="nj672-stress-title">
    <h2 class="n2j-h2" id="nj672-stress-title">Стресс-профиль: нагрузка и длинный контекст</h2>
    <p class="n2j-hint mt-1">Профиль «<?php echo HTML::chars((string) $stress['profile']); ?>». Сначала — выводы словами, ниже — измеренные цифры.</p>

<?php if ( ! empty($stress['reading'])): ?>
    <ul class="mt-4 space-y-2 text-sm text-gray-800 dark:text-gray-100">
<?php foreach ($stress['reading'] as $line): ?>
        <li class="flex gap-2"><i class="fas fa-circle-info mt-0.5 shrink-0 text-blue-500"></i><span><?php echo HTML::chars($line); ?></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ( ! empty($stress['knee'])): ?>
    <div class="n2j-flash n2j-flash-warn mt-4 block">
        <p class="text-sm"><strong>Порог деградации (прокси «колена» KV-Cache):</strong> <?php echo HTML::chars((string) $stress['knee']['detail']); ?></p>
    </div>
<?php endif; ?>

    <h3 class="n2j-h3 mt-6">Параллельная нагрузка</h3>
    <div class="n2j-panel mt-3 overflow-x-auto">
        <table class="n2j-table">
            <thead>
                <tr>
                    <th class="text-right">Потоков</th><th class="text-right">Успешно</th><th class="text-right">Ошибки</th>
                    <th class="text-right">TTFT p50</th><th class="text-right">TTFT p95</th><th class="text-right">TTFT p99</th>
                    <th class="text-right">ITL p95</th><th class="text-right">Токены/сек</th><th>Коды ответов</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($stress['levels'] as $lv): ?>
                <tr>
                    <td class="text-right font-medium"><?php echo (int) $lv['concurrency']; ?></td>
<?php if ($lv['skipped'] !== ''): ?>
                    <td colspan="8" class="text-gray-500 dark:text-gray-400">ступень пропущена: <?php echo HTML::chars($lv['skipped']); ?></td>
<?php else: ?>
                    <td class="text-right"><?php echo (int) $lv['ok']; ?> / <?php echo (int) $lv['requests']; ?></td>
                    <td class="text-right"><?php echo HTML::chars(Service_Nj672_Scan::fmt_rate($lv['error_rate'])); ?></td>
                    <td class="text-right"><?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($lv['ttft']['p50'])); ?></td>
                    <td class="text-right"><?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($lv['ttft']['p95'])); ?></td>
                    <td class="text-right"><?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($lv['ttft']['p99'])); ?></td>
                    <td class="text-right"><?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($lv['itl']['p95'])); ?></td>
                    <td class="text-right"><?php echo $lv['tps_total'] !== NULL ? round($lv['tps_total']) : '—'; ?></td>
                    <td class="font-mono text-xs"><?php echo HTML::chars(implode(', ', array_map(function ($k, $v) { return $k.'×'.$v; }, array_keys($lv['codes']), $lv['codes']))); ?></td>
<?php endif; ?>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h3 class="n2j-h3 mt-6">Лестница длинного контекста</h3>
    <p class="n2j-hint mt-1">В середину шумного текста прячется фраза-«иголка»: нашла ли её модель — проверка внимания к длинному контексту.</p>
    <div class="n2j-panel mt-3 overflow-x-auto">
        <table class="n2j-table">
            <thead>
                <tr><th class="text-right">Контекст, токенов</th><th>Ответ</th><th class="text-right">TTFT</th><th class="text-right">Весь ответ</th><th>«Иголка» найдена</th></tr>
            </thead>
            <tbody>
<?php foreach ($stress['ladder'] as $row): ?>
                <tr>
                    <td class="text-right font-medium"><?php echo Service_Nj672_Scan::fmt_int($row['ctx_tokens']); ?></td>
<?php if ($row['skipped'] !== ''): ?>
                    <td colspan="4" class="text-gray-500 dark:text-gray-400">ступень пропущена: <?php echo HTML::chars($row['skipped']); ?></td>
<?php else: ?>
                    <td><?php echo $row['ok'] ? 'ответ получен' : 'ошибка цели'; ?><?php echo $row['error'] !== '' ? ' <span class="font-mono text-xs text-gray-400">'.HTML::chars($row['error']).'</span>' : ''; ?></td>
                    <td class="text-right"><?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($row['ttft_ms'])); ?></td>
                    <td class="text-right"><?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($row['e2e_ms'])); ?></td>
                    <td><?php echo $row['needle_ok'] === NULL ? '—' : ($row['needle_ok'] ? 'да' : 'нет'); ?></td>
<?php endif; ?>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php if (($stress['sla']['note'] ?? '') !== ''): ?>
    <p class="n2j-hint mt-3"><?php echo HTML::chars((string) $stress['sla']['note']); ?></p>
<?php endif; ?>
</section>
<?php endif; ?>

<details class="mt-6">
    <summary class="n2j-h3 cursor-pointer">Сырой JSON отчёта (для инженеров и CI)</summary>
    <pre class="mt-3 overflow-x-auto rounded-xl bg-gray-100 p-4 text-xs dark:bg-gray-800"><?php echo HTML::chars(json_encode((array) $run['result'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
</details>

<?php echo View::factory('672/result_script'); ?>

<?php endif; ?>
</div>
