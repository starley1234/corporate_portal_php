<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница пакета документов (/docs/<code>): статусы, файлы, пересборка.
 * Списки с id="docs-*"/"pkg-*" перерисовываются по AJAX (views/580/script.php).
 */

$card       = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$doc_status = [
    'new'     => 'в очереди', 'writing' => 'пишется',
    'done'    => 'готово',    'error'   => 'ошибка',
];
$kind_label = ['docx' => 'Word', 'pdf' => 'PDF', 'xlsx' => 'Excel', 'zip' => 'ZIP'];
$kind_icon  = ['docx' => 'fa-file-word', 'pdf' => 'fa-file-pdf', 'xlsx' => 'fa-file-excel', 'zip' => 'fa-file-zipper'];

$has_pending = in_array($pkg['status'], ['new', 'running'], TRUE);
$fmt_size    = function ($b) { return $b >= 1048576 ? round($b / 1048576, 1).' МБ' : max(1, (int) round($b / 1024)).' КБ'; };

/** Строка файлов документа/пакета (используется и в шаблоне, и понятна AJAX-партнёру в JS). */
$file_link = function (array $f) use ($pkg, $kind_label, $kind_icon, $fmt_size) {
    $kind = (string) $f['kind'];

    return '<a href="/docs/'.HTML::chars($pkg['code']).'/file/'.(int) $f['id'].'"'
        .' class="rounded-lg border border-blue-200 px-2.5 py-1 text-xs font-semibold text-blue-600 transition hover:bg-blue-50 dark:border-blue-800">'
        .'<i class="fas '.(isset($kind_icon[$kind]) ? $kind_icon[$kind] : 'fa-file').' mr-1"></i>'
        .(isset($kind_label[$kind]) ? $kind_label[$kind] : strtoupper($kind))
        .' ('.$fmt_size((int) $f['size']).')</a>';
};

$src_info  = json_decode((string) $pkg['org_info'], TRUE) ?: [];
$src_types = json_decode((string) $pkg['doc_types'], TRUE) ?: [];
$type_titles = [];
foreach ($src_types as $t)
{
    if ($t === 'auto')
    {
        $type_titles[] = 'набор подобрал ИИ';
        continue;
    }
    $type_titles[] = isset($catalog[$t]) ? $catalog[$t] : $t;
}
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-docs-code="<?php echo HTML::chars($pkg['code']); ?>" data-pkg-status="<?php echo HTML::chars($pkg['status']); ?>" data-pkg-id="<?php echo (int) $pkg['id']; ?>">

    <?php if ( ! empty($_GET['flash'])): ?>
    <div data-nj580-autohide class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars((string) $_GET['flash']); ?>
    </div>
    <?php endif; ?>

    <?php /* Живой статус-ряд — ОБЩИЙ механизм (эталон /trends/<code>): спиннер,
     * секундомер от фактического времени, этап воркера, «нет пульса». Свой
     * баннер со своим поллингом удалён — он сдавался через час, а задача в
     * очереди продолжала висеть. */ ?>
    <?php if (in_array((string) $pkg['status'], ['new', 'running'], TRUE)): ?>
    <?php echo View::factory('partials/live_status', [
        'url'     => '/docs/api/status/'.urlencode((string) $pkg['code']),
        'title'   => 'Собираем пакет документов',
        'status'  => (string) $pkg['status'] === 'new' ? 'queued' : 'running',
        'stage'   => (string) $pkg['progress'],
        'elapsed' => 0,
        'silent'  => NULL,
        'steps'   => [],
        'note'    => 'Документы и архив появятся здесь — страница обновится сама',
    ]); ?>
    <?php endif; ?>

    <div class="n2j-card">
        <?php // Онтология: организация-владелец пакета в общем словаре сервисов ?>
        <?php if (trim((string) $pkg['org_name']) !== ''): ?>
        <div class="mb-2"><?php echo View::factory('partials/ontology_link', [
            'title' => (string) $pkg['org_name'],
            'kind'  => 'org',
        ]); ?></div>
        <?php endif; ?>

        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/docs" class="transition hover:text-blue-600 dark:hover:text-blue-400">Документы организации</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-mono font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($pkg['code']); ?></span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-4 dark:border-gray-700/60">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="n2j-badge n2j-badge-info font-mono"><?php echo HTML::chars($pkg['code']); ?></span>
                    <span id="pkg-badge" class="n2j-badge uppercase <?php echo $pkg['status'] === 'done' ? 'n2j-badge-ok' : ($pkg['status'] === 'error' ? 'n2j-badge-err' : ($pkg['status'] === 'canceled' ? 'n2j-badge-wait' : 'n2j-badge-info')); ?>">
                        <?php echo $pkg['status'] === 'done' ? 'готово' : ($pkg['status'] === 'error' ? 'ошибка' : ($pkg['status'] === 'canceled' ? 'отменено' : 'собирается')); ?>
                    </span>
                </div>
                <h1 class="n2j-h1 mt-1.5 truncate"><?php echo HTML::chars($pkg['org_name'] ?: $pkg['code']); ?></h1>
                <p class="n2j-hint">Создан: <?php echo HTML::chars((string) $pkg['created_at']); ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <?php $can_edit = ($is_owner OR Auth::instance()->logged_in('admin')); ?>
                <?php if ($can_edit): ?>
                <form method="post" action="/docs/<?php echo HTML::chars($pkg['code']); ?>/regen" id="pkg-regen"
                      <?php if ($has_pending) echo 'hidden'; ?>>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit" class="n2j-btn-secondary">
                        <i class="fas fa-arrows-rotate text-xs text-gray-400"></i>Пересобрать
                    </button>
                </form>
                <?php endif; ?>
                <a href="/docs" class="n2j-btn-secondary">
                    <i class="fas fa-arrow-left text-xs text-gray-400"></i>К списку
                </a>
            </div>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Левая колонка (2/3): документы ============ -->
        <div class="min-w-0 space-y-4 md:col-span-2">
            <div class="<?php echo $card; ?> sm:p-6">
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                    <p class="text-base font-bold text-gray-900 sm:text-lg dark:text-white"><i class="fas fa-file-lines mr-1.5 text-blue-500"></i>Документы пакета</p>
                    <span id="docs-count" class="ml-auto text-xs font-semibold text-gray-400"><?php echo count($docs); ?> шт.</span>
                </div>
                <ul id="docs-list" class="mt-2 space-y-2">
                    <?php if (empty($docs)): ?>
                    <li class="text-xs text-gray-500 sm:text-sm dark:text-gray-400">Документы появятся здесь, когда воркер начнёт сборку (план → тексты → файлы).</li>
                    <?php else: ?>
                    <?php foreach ($docs as $d): ?>
                    <li class="rounded-lg border border-gray-100 px-3 py-2.5 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-200" title="<?php echo HTML::chars($d['title']); ?>">
                                <?php echo HTML::chars($d['title'] ?: $d['doc_key']); ?>
                            </span>
                            <span class="n2j-badge <?php echo $d['status'] === 'done' ? 'n2j-badge-ok' : ($d['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-info'); ?> uppercase">
                                <?php echo HTML::chars($doc_status[$d['status']] ?? $d['status']); ?>
                            </span>
                        </div>
                        <?php if ( ! empty($d['error'])): ?>
                        <p class="mt-1 break-words text-xs text-rose-400"><?php echo HTML::chars(mb_substr((string) $d['error'], 0, 300)); ?></p>
                        <?php endif; ?>
                        <div class="doc-files mt-1.5 flex flex-wrap gap-1.5">
                            <?php foreach ((array) $d['files'] as $f) echo $file_link($f); ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <div id="pkg-error" class="n2j-flash n2j-flash-err block"
                 <?php echo ($pkg['status'] === 'error' AND $pkg['error']) ? '' : 'hidden'; ?>>
                <p class="text-xs font-semibold text-rose-600 dark:text-rose-300"><i class="fas fa-triangle-exclamation mr-1"></i>Ошибка сборки</p>
                <p id="pkg-error-text" class="mt-1 break-words text-xs text-rose-500 dark:text-rose-300"><?php echo HTML::chars(mb_substr((string) $pkg['error'], 0, 800)); ?></p>
            </div>
        </div>

        <!-- ============ Правая колонка (1/3): архив, исходные данные, действия ============ -->
        <div class="min-w-0 space-y-4 md:col-span-1">
            <div class="<?php echo $card; ?> sm:p-5">
                <p class="border-b border-gray-100 pb-3 text-base font-bold text-gray-900 sm:text-lg dark:text-white dark:border-gray-700/60"><i class="fas fa-box-archive mr-1.5 text-blue-500"></i>Весь пакет</p>
                <div id="pkg-files" class="mt-2 flex flex-wrap gap-1.5">
                    <?php foreach ((array) $pkgfiles as $f) echo $file_link($f); ?>
                    <?php if (empty($pkgfiles)): ?>
                    <p class="n2j-hint">Архив ZIP появится после сборки всех документов.</p>
                    <?php endif; ?>
                </div>

                <?php if ($pkg['summary']): ?>
                <p id="pkg-summary" class="mt-3 whitespace-pre-line text-xs text-gray-600 sm:text-sm dark:text-gray-300"><?php echo HTML::chars((string) $pkg['summary']); ?></p>
                <?php endif; ?>

                <details class="mt-3">
                    <summary class="cursor-pointer text-xs font-semibold text-gray-500 dark:text-gray-400">
                        <i class="fas fa-database mr-1 text-gray-400"></i>Исходные данные пакета
                    </summary>
                    <div class="mt-2 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                        <?php if (trim((string) $pkg['org_name']) !== ''): ?><p><span class="text-gray-400">Организация:</span> <?php echo HTML::chars($pkg['org_name']); ?></p><?php endif; ?>
                        <?php if ( ! empty($src_info['industry'])): ?><p><span class="text-gray-400">Сфера:</span> <?php echo HTML::chars($src_info['industry']); ?></p><?php endif; ?>
                        <?php if ( ! empty($src_info['size'])): ?><p><span class="text-gray-400">Численность:</span> <?php echo HTML::chars($src_info['size']); ?></p><?php endif; ?>
                        <?php if ( ! empty($src_info['structure'])): ?><p><span class="text-gray-400">Структура:</span> <?php echo HTML::chars($src_info['structure']); ?></p><?php endif; ?>
                        <?php if ( ! empty($src_info['needs'])): ?><p><span class="text-gray-400">Особые требования:</span> <?php echo HTML::chars($src_info['needs']); ?></p><?php endif; ?>
                        <?php if ( ! empty($src_info['inn'])): ?><p><span class="text-gray-400">ИНН:</span> <?php echo HTML::chars($src_info['inn']); ?></p><?php endif; ?>
                        <?php if ( ! empty($src_info['director'])): ?><p><span class="text-gray-400">Руководитель:</span> <?php echo HTML::chars($src_info['director']); ?></p><?php endif; ?>
                        <?php if ( ! empty($src_info['address'])): ?><p><span class="text-gray-400">Адрес:</span> <?php echo HTML::chars($src_info['address']); ?></p><?php endif; ?>
                        <?php if ($pkg['ogrn']): ?><p><span class="text-gray-400">ОГРН/ИНН:</span> <?php echo HTML::chars($pkg['ogrn']); ?></p><?php endif; ?>
                        <?php if ($type_titles): ?><p><span class="text-gray-400">Типы документов:</span> <?php echo HTML::chars(implode('; ', $type_titles)); ?></p><?php endif; ?>
                        <p><span class="text-gray-400">Поиск в интернете:</span> <?php echo $pkg['use_search'] ? 'да' : 'нет'; ?></p>
                        <?php if (trim((string) $pkg['raw_text']) !== ''): ?>
                        <details class="pt-1">
                            <summary class="cursor-pointer"><span class="text-gray-400">Сырые данные (фрагмент)</span></summary>
                            <p class="mt-1 whitespace-pre-line break-words"><?php echo HTML::chars(mb_substr((string) $pkg['raw_text'], 0, 600)); ?></p>
                        </details>
                        <?php endif; ?>
                    </div>
                </details>

                <?php if ($can_edit): ?>
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs font-semibold text-gray-500 dark:text-gray-400">
                        <i class="fas fa-comment-dots mr-1 text-gray-400"></i>Уточнения участников (<?php echo count($feedback); ?>)
                    </summary>
                    <p class="n2j-hint">Добавьте правку — при «Пересобрать пакет» воркер учтёт её в текстах. Так несколько сотрудников доводят пакет до качества итерациями.</p>
                    <form method="post" action="/docs/<?php echo HTML::chars($pkg['code']); ?>/feedback" class="mt-2">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <textarea name="text" rows="2" required maxlength="2000" placeholder="Например: директора зовут Иванов Иван Иванович, отчётность сдаём ежеквартально…"
                                  class="n2j-textarea min-h-[44px] w-full"></textarea>
                        <button type="submit" class="n2j-btn-secondary n2j-btn-sm mt-1.5">Добавить уточнение</button>
                    </form>
                    <?php if ( ! empty($feedback)): ?>
                    <ul class="mt-2 space-y-1">
                        <?php foreach ((array) $feedback as $fb): ?>
                        <li class="rounded-lg border border-gray-100 px-2.5 py-1.5 text-xs text-gray-600 sm:text-sm dark:border-gray-700 dark:text-gray-300<?php echo empty($fb['used_at']) ? '' : ' opacity-50'; ?>">
                            <?php echo HTML::chars(mb_substr((string) $fb['text'], 0, 200)); ?>
                            <span class="ml-1 text-xs text-gray-400"><?php echo HTML::chars((string) $fb['created_at']); ?><?php echo empty($fb['used_at']) ? ' · войдёт в след. сборку' : ' · учтено'; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </details>

                <?php endif; ?>
            </div>

            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Оформление</p>
                <ul class="mt-2 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                    <li>· Поля 30/15/20/20 мм, Times New Roman 14 pt, интервал 1,5, абзац 1,25 см — ГОСТ Р 7.0.97-2016.</li>
                    <li>· Нумерация страниц сверху по центру; реквизиты «УТВЕРЖДАЮ», дата и подпись.</li>
                    <li>· Таблицы дублируются в Excel (штатное расписание, графики, журналы).</li>
                </ul>
                <p class="n2j-hint mt-2">Документы — шаблоны для проверки юристом/кадровиком; сервис не заменяет юридическую экспертизу.</p>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

<?php echo View::factory('580/script');
