<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /company/<code> — профиль: статус/прогресс, результат (карточки), JSON, источники.
 */

$status_ru = ['new' => 'в очереди', 'running' => 'работает', 'done' => 'готово', 'error' => 'ошибка'];
$pending = in_array($p['status'], ['new', 'running'], TRUE);
$contacts = isset($result['contacts']) && is_array($result['contacts']) ? $result['contacts'] : [];
$dms = isset($result['decision_makers']) && is_array($result['decision_makers']) ? $result['decision_makers'] : [];
$tech = isset($result['tech_stack']) && is_array($result['tech_stack']) ? $result['tech_stack'] : [];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-n591-code="<?php echo HTML::chars($p['code']); ?>" data-n591-status="<?php echo HTML::chars($p['status']); ?>">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <?php if ($p['status'] === 'error'): ?>
    <div class="mb-4 rounded-xl border border-rose-300 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-950/40">
        <p class="text-sm font-bold text-rose-700 dark:text-rose-300"><i class="fas fa-circle-exclamation mr-1.5"></i>Сбор профиля не удался</p>
        <p class="mt-1 break-words text-xs text-rose-600 dark:text-rose-300"><?php echo HTML::chars((string) $p['error']); ?></p>
        <a href="/company" class="n2j-btn-process n2j-btn-sm mt-2">Собрать заново</a>
    </div>
    <?php endif; ?>

    <?php /* Живой статус-ряд — ОБЩИЙ механизм (эталон /trends/<code>): спиннер,
     * секундомер от фактического времени, этап воркера, «нет пульса», остановка.
     * Раньше здесь был самодельный баннер со своим поллингом и своим таймером. */ ?>
    <?php if ($pending): ?>
    <?php echo View::factory('partials/live_status', [
        'url'     => '/company/api/status/'.urlencode((string) $p['code']),
        'title'   => 'Собираем профиль компании',
        'status'  => (string) $p['status'] === 'new' ? 'queued' : 'running',
        'stage'   => (string) $p['progress'],
        'elapsed' => 0,
        'silent'  => NULL,
        'steps'   => [],
        'note'    => 'Результат появится здесь — страница обновится сама',
        'stop'    => '<form method="post" class="inline-flex">'
            .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
            .'<input type="hidden" name="act" value="stop">'
            .'<button type="submit" title="Прервать сбор профиля" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950/40"><i class="fas fa-stop"></i>Остановить</button>'
            .'</form>',
    ]); ?>
    <?php endif; ?>

    <div class="mt-4 gap-4 space-y-4 md:grid md:grid-cols-2 md:items-start md:space-y-0">

        <!-- ============ Левая половина: результат ============ -->
        <div class="space-y-4">

            <?php if ((string) $p['status'] === 'done'): ?>
            <div class="n2j-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-bold text-gray-900 dark:text-white">
                        <i class="fas fa-building mr-1.5 text-blue-500"></i><?php echo HTML::chars($p['company'] !== '' ? $p['company'] : $p['query']); ?>
                    </p>
                    <a href="/company/<?php echo HTML::chars($p['code']); ?>/json" class="n2j-btn-secondary">
                        <i class="fas fa-download mr-1"></i>Скачать JSON
                    </a>
                    <a href="/coldmail?profile=<?php echo HTML::chars($p['code']); ?>&amp;company=<?php echo urlencode((string) ($p['company'] !== '' ? $p['company'] : $p['query'])); ?>" class="n2j-btn-secondary">
                        <i class="fas fa-envelope mr-1"></i>Написать письмо
                    </a>
                    <a href="/dossier?company=<?php echo urlencode((string) ($p['company'] !== '' ? $p['company'] : $p['query'])); ?>" class="n2j-btn-secondary">
                        <i class="fas fa-user-secret mr-1"></i>AI-досье
                    </a>
                    <?php /* Связь с модулем вакансий (nj642): сбор профиля читает и
                     * страницы «Карьера/Вакансии» этой компании, поэтому здесь
                     * виден результат — со своей историей и мониторами. */ ?>
                    <?php if ( ! empty($vacancies['total'])): ?>
                    <a href="/vacancies?company=<?php echo urlencode((string) ($vacancies['key'] ?? '')); ?>" class="n2j-btn-secondary">
                        <i class="fas fa-user-tie mr-1"></i>Вакансии: <?php echo (int) $vacancies['active']; ?>
                    </a>
                    <?php endif; ?>
                    <?php echo View::factory('partials/ontology_link', [
                        'title' => (string) ($p['company'] !== '' ? $p['company'] : $p['query']),
                        'kind'  => 'company',
                    ]); ?>
                </div>
                <?php if (isset($result['sphere']) && $result['sphere'] !== ''): ?>
                <p class="mt-1 text-sm font-semibold text-blue-700 dark:text-blue-300"><?php echo HTML::chars((string) $result['sphere']); ?></p>
                <?php endif; ?>
                <?php if (isset($result['summary']) && $result['summary'] !== ''): ?>
                <p class="mt-1.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $result['summary']); ?></p>
                <?php endif; ?>
            </div>

            <div class="gap-4 space-y-4 sm:grid sm:grid-cols-2 sm:space-y-0">
                <div class="n2j-card">
                    <p class="text-xs font-bold uppercase text-gray-400">Размер (оценка)</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) ($result['size_estimate'] ?? 'не найдено')); ?></p>
                </div>
                <div class="n2j-card">
                    <p class="text-xs font-bold uppercase text-gray-400">Уверенность</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) ($result['confidence'] ?? '—')); ?></p>
                </div>
            </div>

            <div class="n2j-card">
                <p class="text-xs font-bold uppercase text-gray-400">Контакты</p>
                <?php $has_contacts = ( ! empty($contacts['emails']) OR ! empty($contacts['phones']) OR ! empty($contacts['address'])); ?>
                <?php if ( ! $has_contacts): ?>
                <p class="mt-1 text-sm text-gray-400">не найдено на страницах компании</p>
                <?php else: ?>
                <ul class="mt-1.5 space-y-1.5 text-sm leading-relaxed text-gray-700 dark:text-gray-200">
                    <?php foreach ((array) ($contacts['emails'] ?? []) as $e): ?>
                    <li><i class="fas fa-envelope mr-1.5 text-gray-400"></i><a href="mailto:<?php echo HTML::chars($e); ?>" class="text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($e); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ((array) ($contacts['phones'] ?? []) as $ph): ?>
                    <li><i class="fas fa-phone mr-1.5 text-gray-400"></i><a href="tel:<?php echo HTML::chars(preg_replace('/[^0-9+]/', '', (string) $ph)); ?>" class="text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($ph); ?></a></li>
                    <?php endforeach; ?>
                    <?php if ( ! empty($contacts['address'])): ?>
                    <li><i class="fas fa-location-dot mr-1.5 text-gray-400"></i><?php echo HTML::chars((string) $contacts['address']); ?></li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <p class="text-xs font-bold uppercase text-gray-400">Стек технологий</p>
                <?php if (empty($tech)): ?>
                <p class="mt-1 text-sm text-gray-400">не определён</p>
                <?php else: ?>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    <?php foreach ($tech as $t): ?>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-sm font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) $t); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <p class="text-xs font-bold uppercase text-gray-400">ЛПР (кто принимает решения)</p>
                <?php if (empty($dms)): ?>
                <p class="mt-1 text-sm text-gray-400">на сайте не названы — имена не выдумываем</p>
                <?php else: ?>
                <ul class="mt-1.5 space-y-1.5">
                    <?php foreach ($dms as $d): ?>
                    <li class="rounded-xl border border-gray-100 px-3 py-2 text-sm dark:border-gray-700">
                        <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) ($d['name'] ?? '')); ?></span>
                        <span class="text-gray-500 dark:text-gray-400"> — <?php echo HTML::chars((string) ($d['role'] ?? 'роль не указана')); ?></span>
                        <?php if ( ! empty($d['source'])): ?><div class="mt-0.5 truncate text-xs text-gray-400" title="<?php echo HTML::chars((string) $d['source']); ?>">источник: <?php echo HTML::chars((string) $d['source']); ?></div><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php elseif ($pending): ?>
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-400 dark:border-gray-700 dark:bg-gray-800/60">Результат появится здесь после сборки.</div>
            <?php endif; ?>
        </div>

        <!-- ============ Правая половина: сведения + JSON + источники ============ -->
        <div class="space-y-4">
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Сборка</p>
                <dl class="mt-2 space-y-1.5 text-xs">
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Код</dt><dd class="font-mono text-gray-700 dark:text-gray-200"><?php echo HTML::chars($p['code']); ?></dd></div>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Запрос</dt><dd class="min-w-0 break-all text-gray-700 dark:text-gray-200"><?php echo HTML::chars($p['query']); ?> <span class="text-gray-400">(<?php echo $p['kind'] === 'domain' ? 'домен' : 'название'; ?>)</span></dd></div>
                    <?php if ($p['domain'] !== ''): ?>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Сайт</dt><dd class="min-w-0 break-all text-gray-700 dark:text-gray-200"><a href="https://<?php echo HTML::chars($p['domain']); ?>" target="_blank" rel="noopener nofollow" class="text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($p['domain']); ?></a></dd></div>
                    <?php endif; ?>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Статус</dt><dd class="text-gray-700 dark:text-gray-200"><?php echo $status_ru[$p['status']] ?? HTML::chars($p['status']); ?><?php echo ($p['progress'] !== '' AND $pending) ? ' — '.HTML::chars($p['progress']) : ''; ?></dd></div>
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-gray-400">Создан</dt><dd class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $p['created_at']); ?></dd></div>
                </dl>
            </div>

            <?php if ((string) $p['status'] === 'done'): ?>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-code mr-1.5 text-gray-400"></i>JSON результата</p>
                <button type="button" data-n591-copy="<?php echo HTML::chars(json_encode($result, JSON_UNESCAPED_UNICODE)); ?>"
                        class="n2j-btn-secondary n2j-btn-sm mt-2">
                    <i class="fas fa-copy mr-1"></i>Скопировать JSON
                </button>
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs font-semibold text-gray-500 dark:text-gray-400">показать</summary>
                    <pre class="mt-1 max-h-72 overflow-auto rounded-xl bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 dark:bg-gray-900 dark:text-gray-300"><?php echo HTML::chars(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                </details>
            </div>
            <?php endif; ?>

            <?php if ( ! empty($sources)): ?>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-sitemap mr-1.5 text-gray-400"></i>Источники (обойдённые страницы)</p>
                <ul class="mt-1.5 space-y-1 text-xs">
                    <?php foreach ($sources as $s): ?>
                    <li class="truncate"><i class="fas fa-arrow-up-right-from-square mr-1.5 text-gray-300"></i><a href="<?php echo HTML::chars((string) ($s['url'] ?? '')); ?>" target="_blank" rel="noopener nofollow" class="text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) ($s['url'] ?? '')); ?></a> <span class="text-gray-400">(<?php echo (int) ($s['chars'] ?? 0); ?> зн.)</span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

<?php echo View::factory('591/script'); ?>
