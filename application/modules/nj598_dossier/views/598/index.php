<?php defined('SYSPATH') or die('No direct script access.');

/**
 * AI Досье (nj598): создание + мои досье.
 */

$badge = function ($st) {
    $map = ['done' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60',
            'error' => 'bg-rose-50 text-rose-500 dark:bg-rose-950/60',
            'running' => 'bg-blue-50 text-blue-600 dark:bg-blue-950/60',
            'new' => 'bg-gray-100 text-gray-500 dark:bg-gray-800'];

    $c = $map[$st] ?? $map['new'];

    return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold uppercase '.$c.'">'.HTML::chars((string) __((string) $st)).'</span>';
};
?>

<script>
/* «Сначала быстрая проверка»: подставляем введённую компанию в /check (nj635) */
(function () {
    var a = document.querySelector('[data-nj598-quick]');

    if ( ! a) { return; }

    a.addEventListener('click', function () {
        var el = document.getElementById('dossier-company');
        var v  = el ? el.value.replace(/^\s+|\s+$/g, '') : '';

        a.href = '/check' + (v ? '?q=' + encodeURIComponent(v) : '');
    });
})();
</script>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/jobs" class="transition hover:text-blue-600 dark:hover:text-blue-400">Вакансии</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">AI Досье</span>
    </nav>
    <h1 class="n2j-h1 mt-2"><i class="fas fa-user-secret mr-2 text-blue-500"></i>AI Досье на работодателя</h1>
    <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Разведка компании перед откликом: агент ищет отзывы сотрудников, техстатьи инженеров (реальный стек и «боли») и новости (суды, сокращения, инвестиции) — и собирает красные флаги, плюсы и шпаргалку к собеседованию.</p>

    <?php if ((string) $flash !== ''): ?>
    <div class="mt-3 flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs font-semibold sm:text-sm dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300">
        <i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
    </div>
    <?php endif; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Форма + список (2/3) ============ -->
        <div class="min-w-0 space-y-4 md:col-span-2">

            <?php if (empty($logged)): ?>
            <?php echo Gate::wall('dossier', [
                'icon'   => 'fas fa-user-secret',
                'title'  => 'AI Досье — по бесплатному аккаунту',
                'text'   => 'Досье собирает агент: 5–8 поисков, чтение источников, разбор отзывов и новостей — обычно 2–4 минуты. Аккаунт отделяет людей от ботов, а собранные досье привязывает к вам.',
                'points' => [
                    'Красные флаги: суды, сокращения, жалобы сотрудников',
                    'Сильные стороны: реальный стек, процессы, культура',
                    'Шпаргалка к собеседованию — о чём спросить тимлида',
                    'Мои досье: вернуться к разведке с любого устройства',
                ],
                'stats'  => ['Beta, бесплатно', '5–8 поисков на досье'],
                'cta'    => 'Войти и собрать досье',
                'note'   => 'Быстрая проверка по реестру и вакансиям работает без входа — кнопкой «Сначала быстрая проверка» на странице /check.',
            ]); ?>

            <a href="/jobs" class="n2j-card flex items-center gap-3 transition hover:border-blue-300 dark:hover:border-blue-700">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300">
                    <i class="fas fa-briefcase" aria-hidden="true"></i>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-bold text-gray-900 dark:text-white">Куда смотреть без аккаунта</span>
                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">Каталог вакансий и карточки компаний — на /jobs, реквизиты организаций — в справочнике /orgs.</span>
                </span>
                <i class="fas fa-arrow-right ml-auto shrink-0 text-blue-500" aria-hidden="true"></i>
            </a>
            <?php else: ?>

            <form method="post" action="/dossier" class="rounded-xl border-2 border-dashed border-blue-300/80 bg-blue-50/40 p-4 dark:border-blue-700/60 dark:bg-blue-950/20 sm:p-5">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label for="dossier-company" class="block text-xs font-semibold text-blue-800 sm:text-sm dark:text-blue-200"><i class="fas fa-bolt mr-1.5"></i>Компания или ссылка на вакансию</label>
                <input type="text" id="dossier-company" name="company" value="<?php echo HTML::chars($prefill); ?>" required autocomplete="off" placeholder="напр. «ООО Ромашка», «Сбер» или https://hh.ru/vacancy/…"
                       class="n2j-input mt-1.5 w-full">
                <div class="mt-2.5 flex flex-wrap items-center gap-3">
                    <button type="submit" class="n2j-btn-process"><i class="fas fa-magnifying-glass-chart mr-2"></i>Собрать досье</button>
                    <span class="text-xs text-gray-500 dark:text-gray-400">агент делает 5–8 поисков и читает источники — обычно 2–4 минуты</span>
                    <a href="/check" data-nj598-quick class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Сначала быстрая проверка по реестру и вакансиям →</a>
                </div>
            </form>

            <div class="n2j-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="n2j-h3">Мои досье</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($my); ?></span>
                </div>
                <?php if (empty($my)): ?>
                <p class="n2j-hint px-4 py-6 text-center">Пока ни одного. Начните сверху — или перейдите из любой карточки на <a href="/jobs" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">/jobs</a> кнопкой «Досье».</p>
                <?php else: ?>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($my as $d): ?>
                    <li class="flex items-center gap-2 px-4 py-3 transition-colors hover:bg-gray-50/70 sm:px-5 dark:hover:bg-gray-700/25">
                        <a href="/dossier/<?php echo HTML::chars($d['code']); ?>" class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="min-w-0 flex-1 truncate text-sm font-bold text-gray-900 sm:text-base dark:text-white"><?php echo HTML::chars($d['company'] ?: $d['query']); ?></span>
                                <?php echo $badge($d['status']); ?>
                                <?php $age = Service_Nj598_Dossier::age_days((array) $d); if ($d['status'] === 'done' AND $age >= 14): ?>
                                <span class="n2j-badge n2j-badge-run" title="Досье старое — данные могли устареть"><?php echo (int) $age; ?> дн.</span>
                                <?php endif; ?>
                                <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $d['created_at'], 'd.m.y H:i')); ?></span>
                            </div>
                            <?php if ((string) $d['progress'] !== '' AND in_array($d['status'], ['running', 'new'], TRUE)): ?>
                            <p class="n2j-hint mt-0.5 truncate"><?php echo HTML::chars($d['progress']); ?></p>
                            <?php endif; ?>
                        </a>
                        <?php if ( ! in_array($d['status'], ['running', 'new'], TRUE)): ?>
                        <form method="post" action="/dossier/delete/<?php echo HTML::chars($d['code']); ?>" class="shrink-0">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <button data-n2j-arm="Удалить?" type="submit" title="Удалить досье" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-trash-can"></i></button>
                        </form>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ============ Компаньон (1/3) ============ -->
        <div class="hidden space-y-5 md:block">
            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-list-check text-blue-500"></i>
                    <h3 class="text-sm font-bold">Что будет в досье</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-rose-500">•</span><span><b>Красные флаги</b> — скрытые минусы процессов: судебные споры, волатильность, жалобы сотрудников.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span><b>Сильные стороны</b> — реальные плюсы: стек, процессы, культура.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><b>Шпаргалка к собеседованию</b> — о чём спросить тимлида с упором на их боли; вероятные вопросы скрининга.</span></li>
                </ul>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Источники агента</h3>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Отзывы сотрудников: Хабр Карьера, Dream Job, форумы, Reddit — с фильтром явных ботов.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Техстатьи инженеров компании — реальный стек и открытые проблемы.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Новости: суды, сокращения, инвестиции, смена владельцев.</span></li>
                </ul>
                <p class="n2j-hint mt-2.5 border-t border-gray-100 pt-2.5">Только находки из источников: если данных мало — досье честно скажет «данных мало», без домыслов.</p>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Связка с другими сервисами</h3>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>На <a href="/jobs" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">/jobs</a> у каждой карточки вакансии — кнопка «Досье».</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Реквизиты компании — в <a href="/orgs" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Справочнике организаций</a>; со страницы досье — прямая ссылка на поиск по названию.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

