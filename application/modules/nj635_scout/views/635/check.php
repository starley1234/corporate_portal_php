<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /check — «Проверка компании»: одна строка ввода, одна страница, полный отчёт.
 *
 * Порядок: карточка (сразу, без ИИ) → кнопка «Собрать полный отчёт» → блоки
 * профиля сайта, досье и наблюдения подтягиваются на этой же странице.
 */

// значения по умолчанию: шаблон должен открываться и в тестовом рендере
$q      = isset($q) ? (string) $q : '';
$card   = isset($card) ? $card : NULL;
$report = isset($report) ? $report : NULL;
$csrf   = isset($csrf) ? (string) $csrf : '';
$logged = isset($logged) ? (bool) $logged : FALSE;
$flash  = isset($flash) ? trim((string) $flash) : '';

$fmt_date = function ($s) {
    $s = trim((string) $s);

    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $s, $m))
    {
        return $m[3].'.'.$m[2].'.'.$m[1];
    }

    return $s;
};

$levels = [
    'danger' => ['border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300', 'fas fa-triangle-exclamation', 'text-rose-500'],
    'warn'   => ['border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300', 'fas fa-circle-exclamation', 'text-amber-500'],
    'ok'     => ['border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300', 'fas fa-circle-check', 'text-emerald-500'],
    'info'   => ['border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-300', 'fas fa-circle-info', 'text-gray-400'],
];

$status_badge = function ($status) {
    $s = mb_strtolower(trim((string) $status));

    if ($s === '')
    {
        return ['bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400', 'статус не указан'];
    }

    if (mb_strpos($s, 'ликвидац') !== FALSE OR mb_strpos($s, 'прекращ') !== FALSE OR mb_strpos($s, 'банкрот') !== FALSE)
    {
        return ['bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300', (string) $status];
    }

    if (mb_strpos($s, 'недостовер') !== FALSE OR mb_strpos($s, 'реорганизац') !== FALSE)
    {
        return ['bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', (string) $status];
    }

    return ['bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', (string) $status];
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Проверка компании</span>
    </nav>

    <h1 class="n2j-h1 mt-2">
        <i class="fas fa-magnifying-glass-chart mr-2 text-blue-500"></i>Проверка компании и работодателя
    </h1>
    <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">
        Одна строка — одна страница: реквизиты ЕГРЮЛ/ЕГРИП, вакансии и сигналы показываем сразу.
        Кнопка «Собрать полный отчёт» добавляет сюда же профиль сайта, AI-досье и наблюдение за рисками — никуда переходить не нужно.
    </p>

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mt-3 flex items-start gap-3 text-xs font-semibold">
        <i class="fas fa-circle-info mt-0.5 shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
    </div>
    <?php endif; ?>

    <!-- ============ Форма ============ -->
    <form method="get" action="/check" class="mt-5 rounded-xl border-2 border-dashed border-blue-300/80 bg-blue-50/40 p-4 dark:border-blue-700/60 dark:bg-blue-950/20 sm:p-5">
        <label for="check-q" class="block text-xs font-semibold text-blue-800 sm:text-sm dark:text-blue-200">
            <i class="fas fa-bolt mr-1.5"></i>Компания, ИНН/ОГРН, сайт или ссылка на вакансию
        </label>
        <div class="mt-1.5 flex flex-col gap-2.5 sm:flex-row">
            <input type="text" id="check-q" name="q" value="<?php echo HTML::chars($q); ?>" required autocomplete="off"
                   placeholder="напр. «Сбербанк», 7707083893, sberbank.ru или https://hh.ru/vacancy/…"
                   class="n2j-input w-full">
            <button type="submit" class="n2j-btn-process shrink-0">
                <i class="fas fa-magnifying-glass mr-2"></i>Проверить
            </button>
        </div>
        <p class="n2j-hint mt-2">
            Что можно ввести: название компании, ИНН (10/12 цифр), ОГРН (13/15), адрес сайта или ссылку на вакансию.
            Если компании ещё нет в справочнике, по ИНН/ОГРН запросим ФНС — это несколько секунд.
            <?php if (is_array($card) AND ! empty($card['fns']['limited'])): ?>
            <span class="mt-1 block font-semibold text-amber-700 dark:text-amber-300"><i class="fas fa-hourglass-half mr-1"></i>Реестр сейчас на паузе: <?php echo HTML::chars((string) $card['fns']['message']); ?></span>
            <?php endif; ?>
        </p>
    </form>

    <?php if ($card !== NULL AND isset($card['error'])): ?>
    <div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs">
        <i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
        <span><?php echo HTML::chars((string) $card['error']); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($card === NULL AND $report === NULL): ?>
    <!-- ============ Пустое состояние ============ -->
    <div class="mt-5 flex flex-wrap items-center gap-2 text-xs">
        <span class="font-semibold text-gray-400 dark:text-gray-500">Примеры:</span>
        <?php foreach (['Сбербанк', 'Яндекс', 'sberbank.ru'] as $ex): ?>
        <a href="/check?q=<?php echo rawurlencode($ex); ?>" class="inline-flex items-center gap-1.5 rounded-full bg-white/80 px-3 py-1 font-semibold text-gray-700 ring-1 ring-inset ring-gray-200 transition hover:bg-blue-50 hover:text-blue-600 dark:bg-gray-800/80 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-blue-950/60"><?php echo HTML::chars($ex); ?></a>
        <?php endforeach; ?>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-3">
        <?php foreach ([
            ['fas fa-bolt', 'Сразу — карточка', 'Реквизиты и статус из ЕГРЮЛ/ЕГРИП, вакансии компании и сигналы по реестру. Без ожидания и без входа.'],
            ['fas fa-clipboard-list', 'Одна кнопка — весь отчёт', 'Профиль сайта, AI-досье с отзывами и наблюдение за рисками собираются параллельно и появляются на этой же странице.'],
            ['fas fa-shield-halved', 'Дальше — наблюдение', 'Найденные риски и новые сигналы приходят сами: суды, банкротство, изменения в реестре.'],
        ] as $b): ?>
        <div class="n2j-card">
            <i class="<?php echo $b[0]; ?> text-blue-500"></i>
            <p class="mt-2 text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($b[1]); ?></p>
            <p class="n2j-hint"><?php echo HTML::chars($b[2]); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($card !== NULL AND ! isset($card['error'])): ?>
    <?php $org = $card['org']; ?>

    <!-- Заметки -->
    <?php if ( ! empty($card['notes'])): ?>
    <div class="n2j-flash n2j-flash-info mt-4 flex items-start gap-3 text-xs">
        <i class="fas fa-circle-info mt-0.5 shrink-0"></i>
        <ul class="space-y-1">
            <?php foreach ($card['notes'] as $n): ?>
            <li><?php echo HTML::chars((string) $n); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Кандидаты -->
    <?php if ( ! empty($card['candidates'])): ?>
    <div class="n2j-card p-0 mt-4">
        <p class="border-b border-gray-100 px-4 py-3 text-sm font-bold text-gray-900 dark:border-gray-700/60 dark:text-white">Нашли несколько похожих — уточните:</p>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
            <?php foreach ($card['candidates'] as $c): ?>
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
                <a href="<?php echo HTML::chars($c['url']); ?>" class="min-w-0 flex-1 break-words text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($c['name']); ?></a>
                <span class="text-xs text-gray-400">ИНН <?php echo HTML::chars($c['inn']); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Организация -->
    <?php if ($org): ?>
    <?php list($badge_cls, $badge_text) = $status_badge($org['status']); ?>
    <div class="n2j-card p-0 mt-5">
        <div class="border-b border-gray-100 px-4 py-4 sm:px-5 dark:border-gray-700/60">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="break-words text-lg font-bold text-gray-900 sm:text-xl dark:text-white"><?php echo HTML::chars($org['short_name'] !== '' ? $org['short_name'] : $org['full_name']); ?></p>
                    <?php if ($org['full_name'] !== '' AND $org['full_name'] !== $org['short_name']): ?>
                    <p class="n2j-hint mt-0.5 break-words"><?php echo HTML::chars($org['full_name']); ?></p>
                    <?php endif; ?>
                </div>
                <span class="inline-flex max-w-full shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-bold <?php echo $badge_cls; ?>"><?php echo HTML::chars($badge_text); ?></span>
            </div>
            <p class="n2j-hint mt-2">
                <i class="fas fa-database mr-1"></i>Обновлено: <?php echo HTML::chars($fmt_date($org['fetched_at'])); ?>
                <?php if ($card['online']): ?> · карточка получена из ФНС при этой проверке<?php endif; ?>
            </p>
        </div>

        <div class="grid grid-cols-2 gap-px bg-gray-100 sm:grid-cols-4 dark:bg-gray-700/60">
            <?php foreach ([
                ['ИНН', $org['inn']],
                ['ОГРН', $org['ogrn']],
                ['Регистрация', $fmt_date($org['reg_date'])],
                ['Форма', $org['form']],
            ] as $m): ?>
            <div class="bg-white px-4 py-3 dark:bg-gray-800">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500"><?php echo HTML::chars($m[0]); ?></p>
                <p class="mt-0.5 break-words text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $m[1] !== '' ? (string) $m[1] : '—'); ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="space-y-2.5 px-4 py-4 sm:px-5">
            <?php foreach ([
                ['fas fa-user-tie', 'Руководитель', $org['director']],
                ['fas fa-briefcase', 'Основной вид деятельности', trim($org['okved'].' '.$org['okved_name'])],
                ['fas fa-location-dot', 'Адрес', $org['address']],
            ] as $row): ?>
            <div class="flex items-start gap-2.5 text-xs sm:text-sm">
                <i class="<?php echo $row[0]; ?> mt-0.5 w-4 shrink-0 text-gray-400"></i>
                <div class="min-w-0">
                    <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($row[1]); ?>:</span>
                    <span class="break-words font-medium text-gray-800 dark:text-gray-200"><?php echo HTML::chars(trim((string) $row[2]) !== '' ? (string) $row[2] : 'нет данных'); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Вывод -->
    <?php if ( ! empty($card['verdict'])): ?>
    <?php $v = $card['verdict']; $vl = $levels[$v['level']] ?? $levels['info']; ?>
    <div class="mt-4 flex items-start gap-3 rounded-xl border px-4 py-3.5 <?php echo $vl[0]; ?>">
        <i class="<?php echo $vl[1]; ?> mt-0.5 shrink-0"></i>
        <div class="min-w-0">
            <p class="text-sm font-bold sm:text-base"><?php echo HTML::chars($v['title']); ?></p>
            <p class="mt-0.5 text-xs leading-relaxed sm:text-sm"><?php echo HTML::chars($v['text']); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Сигналы -->
    <?php if ( ! empty($card['signals'])): ?>
    <div class="n2j-card p-0 mt-4">
        <p class="border-b border-gray-100 px-4 py-3 text-sm font-bold text-gray-900 dark:border-gray-700/60 dark:text-white"><i class="fas fa-list-check mr-1.5 text-gray-400"></i>Что видно по данным</p>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
            <?php foreach ($card['signals'] as $s): ?>
            <?php $sl = $levels[$s['level']] ?? $levels['info']; ?>
            <li class="flex items-start gap-3 px-4 py-3">
                <i class="<?php echo $sl[1].' '.$sl[2]; ?> mt-0.5 shrink-0"></i>
                <div class="min-w-0">
                    <p class="break-words text-xs font-semibold text-gray-900 sm:text-sm dark:text-white"><?php echo HTML::chars($s['title']); ?></p>
                    <p class="n2j-hint mt-0.5 break-words"><?php echo HTML::chars($s['text']); ?></p>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Вакансии -->
    <?php $vac = $card['vacancies']; ?>
    <div class="n2j-card p-0 mt-4">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
            <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-briefcase mr-1.5 text-gray-400"></i>Вакансии компании</p>
            <span class="n2j-badge n2j-badge-wait">активных: <?php echo (int) $vac['active']; ?></span>
        </div>

        <?php if ($card['vacancy']): ?>
        <div class="border-b border-gray-100 px-4 py-3 text-xs dark:border-gray-700/60">
            <p class="font-semibold text-gray-900 dark:text-white">Вакансия из вашей ссылки: <?php echo HTML::chars($card['vacancy']['title']); ?></p>
            <p class="mt-0.5 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($card['vacancy']['company']); ?><?php if ($card['vacancy']['city'] !== ''): ?> · <?php echo HTML::chars($card['vacancy']['city']); ?><?php endif; ?></p>
        </div>
        <?php endif; ?>

        <?php if (empty($vac['items'])): ?>
        <p class="n2j-hint px-4 py-5 text-center">
            Вакансий этой компании в нашей базе пока нет.
            <a href="/vacancies" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Поставить монитор вакансий</a> — сервис сам найдёт компании по городу и направлению.
        </p>
        <?php else: ?>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
            <?php foreach ($vac['items'] as $v): ?>
            <li class="flex flex-wrap items-start gap-x-3 gap-y-1 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <p class="break-words text-xs font-semibold text-gray-900 sm:text-sm dark:text-white"><?php echo HTML::chars($v['title']); ?></p>
                    <p class="n2j-hint mt-0.5">
                        <?php echo HTML::chars($v['company']); ?>
                        <?php if (trim((string) $v['city']) !== ''): ?> · <?php echo HTML::chars($v['city']); ?><?php endif; ?>
                        <?php if (trim((string) $v['salary']) !== ''): ?> · <?php echo HTML::chars($v['salary']); ?><?php endif; ?>
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <?php if (trim((string) $v['last_seen_at']) !== ''): ?>
                    <span class="text-xs text-gray-400"><?php echo HTML::chars($fmt_date($v['last_seen_at'])); ?></span>
                    <?php endif; ?>
                    <?php if (trim((string) $v['url']) !== ''): ?>
                    <a href="<?php echo HTML::chars($v['url']); ?>" target="_blank" rel="noopener noreferrer nofollow" class="n2j-btn-secondary n2j-btn-sm">Открыть</a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Связанные материалы -->
    <?php if ( ! empty($card['links'])): ?>
    <div class="n2j-card p-0 mt-4">
        <p class="border-b border-gray-100 px-4 py-3 text-sm font-bold text-gray-900 dark:border-gray-700/60 dark:text-white"><i class="fas fa-link mr-1.5 text-gray-400"></i>Уже собрано по этой компании</p>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
            <?php foreach ($card['links'] as $l): ?>
            <li class="flex items-center gap-3 px-4 py-3">
                <i class="<?php echo class_exists('Service_Nj594_Orgs') ? Service_Nj594_Orgs::kind_icon((string) $l['kind']) : 'fas fa-circle-dot'; ?> w-4 shrink-0 text-gray-400"></i>
                <a href="<?php echo HTML::chars((string) ($l['item_url'] !== '' ? $l['item_url'] : '#')); ?>" class="min-w-0 flex-1 break-words text-xs font-semibold text-blue-600 hover:underline sm:text-sm dark:text-blue-400"><?php echo HTML::chars((string) ($l['title'] !== '' ? $l['title'] : $l['item_code'])); ?></a>
                <span class="shrink-0 text-xs text-gray-400"><?php echo HTML::chars(class_exists('Service_Nj594_Orgs') ? Service_Nj594_Orgs::module_label((string) $l['module']) : (string) $l['module']); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ============ Отдельные сервисы ============ -->
    <div class="mt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Отдельные сервисы</p>
        <div class="mt-2 flex flex-wrap gap-2">
            <?php foreach ($card['actions'] as $a): ?>
            <?php if (($a['action'] ?? '') === 'dossier') { continue; } ?>
            <a href="<?php echo HTML::chars($a['href']); ?>" title="<?php echo HTML::chars($a['hint']); ?>" class="n2j-btn-secondary n2j-btn-sm">
                <i class="<?php echo HTML::chars($a['icon']); ?> text-gray-400"></i><?php echo HTML::chars($a['label']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <p class="n2j-hint mt-6">
        Данные: ЕГРЮЛ/ЕГРИП (ФНС), собранные вакансии и материалы платформы. Проверено: <?php echo HTML::chars((string) $card['checked_at']); ?>.
        Это справочная информация, а не юридическое заключение.
    </p>
    <?php endif; ?>

    <?php if ($report !== NULL): ?>
        <?php echo View::factory('635/report', [
            'blocks' => $report['blocks'],
            'letters' => $report['letters'],
            'ref_q'  => $report['ref_q'],
            'q'      => $report['query'],
            'csrf'   => $csrf,
        ]); ?>
    <?php elseif ($card !== NULL AND ! isset($card['error'])): ?>
    <!-- ============ Собрать полный отчёт ============ -->
    <div class="mt-5 rounded-xl border-2 border-dashed border-blue-300/80 bg-blue-50/40 p-4 dark:border-blue-700/60 dark:bg-blue-950/20 sm:p-5">
        <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-clipboard-list mr-1.5 text-blue-500"></i>Собрать полный отчёт по компании</p>
        <p class="mt-1 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
            Одной кнопкой запускаем три сервиса параллельно, и результаты появятся здесь же, ниже:
        </p>
        <ul class="mt-2 space-y-1 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
            <li><i class="fas fa-user-secret mr-1.5 text-blue-500"></i><b>AI-досье</b> — отзывы сотрудников, красные флаги, что спросить на собеседовании<?php if ( ! $logged): ?> (доступно без входа)<?php endif; ?></li>
            <li><i class="fas fa-building mr-1.5 text-blue-500"></i><b>Сайт компании</b> — сфера, масштаб, контакты, ЛПР, технологии</li>
            <li><i class="fas fa-shield-halved mr-1.5 text-blue-500"></i><b>Риски</b> — суды, банкротство, изменения реестра: сразу и дальше раз в неделю</li>
        </ul>
        <form method="post" action="/check" class="mt-3">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="hidden" name="act" value="run">
            <input type="hidden" name="q" value="<?php echo HTML::chars((string) ($org ? ($org['short_name'] !== '' ? $org['short_name'] : $org['full_name']) : $q)); ?>">
            <button type="submit" class="n2j-btn-process w-full sm:w-auto">
                <i class="fas fa-bolt mr-2"></i>Собрать полный отчёт
            </button>
        </form>
        <?php if ( ! $logged): ?>
        <p class="n2j-hint mt-2">
            Без входа соберём AI-досье. <a href="/auth/login?return_url=<?php echo rawurlencode('check?run=1&q='.(string) ($org ? ($org['short_name'] !== '' ? $org['short_name'] : $org['full_name']) : $q)); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Войдите</a> — и отчёт соберётся целиком: профиль сайта, контакты и наблюдение за рисками.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>


</div>
