<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj641 «Изображения» (/imagegen/gallery): сгенерированные картинки, модерация
 * и публикация в библиотеку.
 *
 * Владелец (26.09): «нет страницы с сгенерированными изображениями для модерации
 * и публикации». Публикация = `approved`: картинку забирают другие сервисы
 * (статьи, презентации) через Service_Nj641_Imagegen::library_pick().
 *
 * Доступ — только администратор (право nj641.admin): проверка в контроллере.
 */
$items  = isset($items) ? $items : [];
$counts = isset($counts) ? $counts : ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$f      = isset($f) ? $f : ['q' => '', 'review' => '', 'page' => 1, 'per' => 20, 'total' => 0, 'pages' => 1];
$csrf   = isset($csrf) ? $csrf : '';
// подсказка «почему список упрощённый» и диагностика пустой страницы
$note   = isset($note) ? trim((string) $note) : '';
$diag   = isset($diag) ? (array) $diag : [];

// Ссылка с сохранением фильтров (образец nj602/admin).
$nj641_qs = function (array $over) use ($f) {
    $qs = array_filter(array_merge([
        'q'      => (string) $f['q'],
        'review' => (string) $f['review'],
        'per'    => (int) $f['per'],
        'page'   => (int) $f['page'],
    ], $over), function ($v) { return (string) $v !== '' AND (string) $v !== '0'; });

    return '/imagegen/gallery'.($qs ? '?'.http_build_query($qs) : '');
};

$nj641_badge = function ($status) {
    if ($status === 'approved')
    {
        return ['В библиотеке', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'];
    }

    if ($status === 'rejected')
    {
        return ['Отклонено', 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'];
    }

    return ['На модерации', 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'];
};

$nj641_author = function ($uid) {
    $uid = (string) $uid;

    if (strncmp($uid, 'u:', 2) === 0)
    {
        return 'пользователь #'.(int) substr($uid, 2);
    }

    if (strncmp($uid, 'g:', 2) === 0)
    {
        return 'гость';
    }

    return 'без входа';
};

$from = ((int) $f['total'] > 0) ? (((int) $f['page'] - 1) * (int) $f['per'] + 1) : 0;
$to   = min((int) $f['total'], (int) $f['page'] * (int) $f['per']);
?>
<input type="hidden" id="nj641-csrf" value="<?php echo HTML::chars($csrf); ?>">
<input type="hidden" id="nj641-filter" value="<?php echo HTML::chars($f['review']); ?>">

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/imagegen" class="transition hover:text-blue-600 dark:hover:text-blue-400">Генератор изображений</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Изображения</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-images mr-2 text-blue-500"></i>Изображения
        </h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-shield-halved text-[11px]"></i>Модерация · библиотека
        </span>
    </div>

    <p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">
        Сгенерированные картинки: отметьте нужные — они уйдут в библиотеку, откуда их переиспользуют
        статьи, презентации и другие сервисы. Отклонённые в библиотеку не попадают.
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <a href="/imagegen" class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-wand-magic-sparkles text-blue-500"></i>Генератор
        </a>
        <span class="inline-flex min-h-[36px] items-center gap-1.5 rounded-full border border-blue-600 bg-blue-600 px-3.5 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-images text-[11px]"></i>Изображения
        </span>
    </div>

    <?php if ($note !== ''): ?>
    <p class="n2j-flash n2j-flash-warn mt-3 block">
        <i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars($note); ?>
    </p>
    <?php endif; ?>

    <!-- чипы статусов -->
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <?php
        $nj641_chips = [
            ''         => ['Все', (int) $counts['all']],
            'pending'  => ['На модерации', (int) $counts['pending']],
            'approved' => ['В библиотеке', (int) $counts['approved']],
            'rejected' => ['Отклонено', (int) $counts['rejected']],
        ];

        foreach ($nj641_chips as $key => $chip):
            $on = ((string) $f['review'] === (string) $key);
        ?>
        <a href="<?php echo HTML::chars($nj641_qs(['review' => $key, 'page' => 1])); ?>"
           class="n2j-chip<?php echo $on ? ' is-on' : ''; ?>">
            <?php echo HTML::chars($chip[0]); ?>
            <span class="rounded-full <?php echo $on ? 'bg-white/20' : 'bg-gray-100 dark:bg-gray-800'; ?> px-1.5 text-xs font-bold" data-nj641-count="<?php echo HTML::chars($key); ?>"><?php echo $chip[1]; ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- поиск и размер страницы -->
    <form method="get" action="/imagegen/gallery" class="n2j-card mt-3 flex flex-wrap items-center gap-2.5 p-3">
        <input type="hidden" name="review" value="<?php echo HTML::chars($f['review']); ?>">
        <label class="relative min-w-[220px] flex-1">
            <i class="fas fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input type="search" name="q" value="<?php echo HTML::chars($f['q']); ?>" placeholder="Поиск по описанию, режиму или номеру"
                   class="n2j-input w-full pl-9">
        </label>
        <label class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">Строк:
            <select name="per" id="nj641-per" class="n2j-input n2j-select font-semibold w-auto">
                <?php foreach ([20, 50, 100] as $pv): ?>
                <option value="<?php echo $pv; ?>" <?php echo (int) $f['per'] === $pv ? 'selected' : ''; ?>><?php echo $pv; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="n2j-btn-process">Найти</button>
        <?php if ($f['q'] !== '' OR $f['review'] !== ''): ?>
        <a href="/imagegen/gallery" class="inline-flex min-h-[40px] items-center rounded-lg px-2.5 text-sm font-semibold text-gray-500 transition hover:text-blue-600 dark:text-gray-400">Сбросить</a>
        <?php endif; ?>
    </form>

    <!-- пакетные действия -->
    <div class="mt-3 flex flex-wrap items-center gap-2.5 rounded-xl border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-700/70 dark:bg-gray-900/40 sm:px-4">
        <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
            <input type="checkbox" id="nj641-select-all" class="h-4 w-4 cursor-pointer accent-blue-600">
            Отметить все на странице
        </label>
        <span class="text-sm text-gray-500 dark:text-gray-400">Выбрано: <b id="nj641-picked">0</b></span>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" data-nj641-bulk="approved" disabled
                    class="n2j-btn-cta n2j-btn-sm nj641-bulk">
                <i class="fas fa-book-open"></i>В библиотеку
            </button>
            <button type="button" data-nj641-bulk="rejected" disabled
                    class="n2j-btn-secondary n2j-btn-sm nj641-bulk">
                <i class="fas fa-ban"></i>Отклонить
            </button>
            <button type="button" data-nj641-bulk="pending" disabled
                    class="n2j-btn-secondary n2j-btn-sm nj641-bulk">
                <i class="fas fa-rotate-left"></i>На модерацию
            </button>
        </div>
        <p id="nj641-flash" class="hidden w-full text-sm font-semibold text-blue-700 dark:text-blue-300 sm:ml-auto sm:w-auto"></p>
    </div>

    <?php if ( ! $items): ?>
    <div class="n2j-card mt-4 text-center">
        <p class="n2j-hint">
            <?php if ((int) $f['total'] === 0 AND ($f['q'] !== '' OR $f['review'] !== '')): ?>
            Ничего не найдено<?php echo $f['q'] !== '' ? ' по запросу «'.HTML::chars($f['q']).'»' : ''; ?>.
            <?php elseif ((int) $f['total'] === 0): ?>
            Пока нет сгенерированных изображений. Сгенерируйте первое на <a href="/imagegen" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">странице генератора</a>.
            <?php if ($diag): ?>
            <span class="mt-1 block text-sm text-gray-400 dark:text-gray-500">Диагностика: прогонов в журнале — <?php echo (int) ($diag['runs'] ?? 0); ?>, с готовой картинкой — <?php echo (int) ($diag['with_image'] ?? 0); ?><?php echo empty($diag['review_store']) ? ', хранилища модерации нет' : ''; ?><?php echo isset($diag['error']) ? ', ошибка запроса: '.HTML::chars((string) $diag['error']) : ''; ?>.</span>
            <?php endif; ?>
            <?php else: ?>
            На этой странице ничего нет — откройте предыдущую.
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3" id="nj641-grid">
        <?php foreach ($items as $it):
            // статус может быть пустым у строк, попавших в базу до миграции 002
            $status  = (string) ($it['review_status'] ?? '');
            $status  = ($status !== '') ? $status : 'pending';
            $badge   = $nj641_badge($status);
            $url     = (string) ($it['image_url'] ?? '');
            $bytes   = (int) ($it['image_bytes'] ?? 0);
            $pending = ($status === 'pending');
        ?>
        <div class="n2j-card p-0 flex flex-col overflow-hidden" data-nj641-card="<?php echo (int) $it['id']; ?>" data-status="<?php echo HTML::chars($status); ?>">
            <label class="relative block cursor-pointer">
                <input type="checkbox" class="nj641-pick absolute left-2.5 top-2.5 h-4 w-4 cursor-pointer accent-blue-600" value="<?php echo (int) $it['id']; ?>">
                <?php if ($url !== ''): ?>
                <a href="<?php echo HTML::chars($url); ?>" target="_blank" rel="noopener" title="Открыть в полном размере">
                    <img src="<?php echo HTML::chars($url); ?>" alt="<?php echo HTML::chars(mb_substr((string) $it['prompt'], 0, 120)); ?>" loading="lazy"
                         class="h-48 w-full bg-gray-100 object-cover transition hover:opacity-95 dark:bg-gray-900">
                </a>
                <?php else: ?>
                <span class="grid h-48 w-full place-items-center bg-gray-100 text-gray-400 dark:bg-gray-900 dark:text-gray-600"><i class="fas fa-image text-2xl"></i></span>
                <?php endif; ?>
                <span class="absolute right-2.5 top-2.5 rounded-full <?php echo $badge[1]; ?> px-2.5 py-0.5 text-xs font-semibold" data-nj641-badge><?php echo $badge[0]; ?></span>
            </label>

            <div class="flex flex-1 flex-col gap-2 p-3.5">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <span class="font-mono font-semibold text-gray-700 dark:text-gray-200">#<?php echo (int) $it['id']; ?></span>
                    <span class="truncate"><?php echo HTML::chars((string) $it['mode']); ?></span>
                    <span class="ml-auto"><?php echo ! empty($it['created_at']) ? date('d.m.Y H:i', strtotime((string) $it['created_at'])) : ''; ?></span>
                </div>

                <p class="line-clamp-3 text-sm text-gray-700 dark:text-gray-200" title="<?php echo HTML::chars((string) $it['prompt']); ?>"><?php echo HTML::chars((string) $it['prompt']); ?></p>

                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-gray-400 dark:text-gray-500">
                    <span class=""><?php echo (int) $it['width']; ?>×<?php echo (int) $it['height']; ?></span>
                    <?php if ($bytes > 0): ?><span class=""><?php echo HTML::chars(number_format($bytes / 1024, 0, '.', ' ')); ?> КБ</span><?php endif; ?>
                    <span class=""><?php echo HTML::chars($nj641_author((string) $it['visitor_uid'])); ?></span>
                </div>

                <div class="mt-auto flex flex-wrap items-center gap-2 pt-1">
                    <button type="button" data-nj641-review="approved" data-id="<?php echo (int) $it['id']; ?>"
                            class="n2j-btn-cta n2j-btn-sm <?php echo ($status === 'approved') ? 'opacity-40' : ''; ?>">
                        <i class="fas fa-book-open"></i>В библиотеку
                    </button>
                    <button type="button" data-nj641-review="rejected" data-id="<?php echo (int) $it['id']; ?>"
                            class="n2j-btn-secondary n2j-btn-sm <?php echo ($status === 'rejected') ? 'opacity-40' : ''; ?>">
                        <i class="fas fa-ban"></i>Отклонить
                    </button>
                    <?php if ( ! $pending): ?>
                    <button type="button" data-nj641-review="pending" data-id="<?php echo (int) $it['id']; ?>" title="Вернуть на модерацию" aria-label="Вернуть на модерацию"
                            class="n2j-btn-secondary n2j-btn-sm">
                        <i class="fas fa-rotate-left"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($url !== ''): ?>
                    <a href="<?php echo HTML::chars($url); ?>" download class="n2j-btn-icon ml-auto text-sm" title="Скачать"><i class="fas fa-download"></i></a>
                    <?php endif; ?>
                    <button data-n2j-arm="Удалить?" type="button" data-nj641-del="<?php echo (int) $it['id']; ?>" title="Удалить изображение" aria-label="Удалить"
                            class="n2j-btn-icon-danger text-sm"><i class="fas fa-xmark"></i></button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- пагинация -->
    <div class="n2j-card p-0 mt-4 flex flex-wrap items-center justify-between gap-2.5 px-4 py-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Показано <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $from; ?></span>–<span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $to; ?></span>
            из <span class="font-semibold text-gray-700 dark:text-gray-200" data-nj641-total><?php echo (int) $f['total']; ?></span>
            <span class="text-gray-300 dark:text-gray-600">·</span> стр. <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $f['page']; ?></span> из <span><?php echo (int) $f['pages']; ?></span>
        </p>
        <?php if ((int) $f['pages'] > 1): ?>
        <div class="flex items-center gap-1.5">
            <a href="<?php echo HTML::chars($nj641_qs(['page' => max(1, (int) $f['page'] - 1)])); ?>"
               class="n2j-btn-secondary n2j-btn-sm <?php echo ((int) $f['page'] <= 1) ? 'pointer-events-none opacity-40' : ''; ?>">
                <i class="fas fa-chevron-left text-[10px]"></i>Назад
            </a>
            <a href="<?php echo HTML::chars($nj641_qs(['page' => min((int) $f['pages'], (int) $f['page'] + 1)])); ?>"
               class="n2j-btn-secondary n2j-btn-sm <?php echo ((int) $f['page'] >= (int) $f['pages']) ? 'pointer-events-none opacity-40' : ''; ?>">
                Вперёд<i class="fas fa-chevron-right text-[10px]"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <p class="mt-4 text-sm text-gray-400 dark:text-gray-500">
        Библиотека для сервисов: <code class="font-mono">Service_Nj641_Imagegen::library_pick()</code> ·
        JSON: <code class="font-mono">GET /imagegen/api/library</code>.
    </p>
</div>

<?php echo View::factory('641/gallery_script'); ?>
