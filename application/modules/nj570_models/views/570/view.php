<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница модели (/3d/<code>): 3D-просмотр, ревизии, правки, ползунки.
 */
$card   = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$stl    = NULL;
$png    = NULL;

foreach (array_reverse($revisions) as $r)
{
    foreach ($r['files'] as $f)
    {
        if ($f['kind'] === 'stl' AND $stl === NULL) $stl = $f;
        if ($f['kind'] === 'png' AND $png === NULL) $png = $f;
    }
}

// уточняющие вопросы (если запрошены и ещё не отвечены)
$clarify = $model['clarify'] ? json_decode((string) $model['clarify'], TRUE) : NULL;
$need_answers = (is_array($clarify) AND ! empty($clarify['questions']) AND ! isset($clarify['answers']));

// ползунки последней done-ревизии
$slider_rev = NULL;
$params = NULL;

foreach ($revisions as $r)
{
    if ($r['status'] === 'done' AND ! empty($r['params']))
    {
        $slider_rev = $r;
        $params = json_decode((string) $r['params'], TRUE);
        break;
    }
}

$status_ru = [
    'private'   => 'черновик', 'pending' => 'на модерации',
    'published' => 'опубликовано', 'rejected' => 'отклонено',
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-m3d-code="<?php echo HTML::chars($model['code']); ?>">

    <?php if ( ! empty($_GET['flash'])): ?>
    <div class="n2j-flash n2j-flash-info mb-3 block">
        <?php echo HTML::chars((string) $_GET['flash']); ?>
    </div>
    <?php endif; ?>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <h1 class="n2j-h1"><?php echo HTML::chars($model['title'] ?: $model['code']); ?></h1>
        <span class="n2j-badge <?php echo $model['status'] === 'published' ? 'n2j-badge-ok' : ($model['status'] === 'pending' ? 'n2j-badge-run' : ($model['status'] === 'rejected' ? 'n2j-badge-err' : 'n2j-badge-wait')); ?> uppercase">
            <?php echo HTML::chars($status_ru[$model['status']] ?? $model['status']); ?>
        </span>
        <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars($model['code']); ?></span>
        <?php if ($rating['votes'] > 0): ?>
            <span class="text-xs text-amber-500"><i class="fas fa-star"></i> <?php echo HTML::chars($rating['avg']); ?> <span class="text-gray-400">(<?php echo (int) $rating['votes']; ?>)</span></span>
        <?php endif; ?>
        <a href="/3d" class="ml-auto text-sm text-gray-400 hover:text-blue-500"><i class="fas fa-arrow-left mr-1"></i>к конструктору</a>
    </div>

    <div class="gap-5 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Левая часть (2/3): 3D-вьюер и ревизии ============ -->
        <div class="space-y-4 md:col-span-2">
            <div class="<?php echo $card; ?>">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-cube mr-1.5 text-blue-500"></i>3D-просмотр</p>
                    <span id="m3d-dims" class="text-xs font-semibold text-gray-400"></span>
                    <div class="ml-auto flex flex-wrap items-center gap-1.5">
                        <button type="button" data-m3d-act="reset" title="Сбросить вид" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-rotate-left"></i> Сброс</button>
                        <button type="button" data-m3d-act="wire" title="Каркас" aria-pressed="false" class="n2j-chip py-1.5"><i class="fas fa-border-all"></i> Каркас</button>
                        <button type="button" data-m3d-act="spin" title="Автовращение (вкл.)" aria-pressed="true" class="n2j-chip py-1.5"><i class="fas fa-arrows-rotate"></i> Вращение</button>
                        <button type="button" data-m3d-act="png" title="Показать PNG-рендер воркера вместо 3D" aria-pressed="false" class="n2j-chip py-1.5"><i class="fas fa-image"></i> PNG</button>
                        <select id="view-material" title="Материал" class="n2j-input n2j-select min-h-[44px] py-1.5 text-sm font-semibold w-auto">
                            <option value="plastic">Пластик</option>
                            <option value="metal">Металл</option>
                            <option value="glass">Стекло</option>
                            <option value="normal">Нормали</option>
                        </select>
                        <select id="view-light" title="Свет" class="n2j-input n2j-select min-h-[44px] py-1.5 text-sm font-semibold w-auto">
                            <option value="studio">Свет: студия</option>
                            <option value="top">Свет: сверху</option>
                            <option value="ambient">Свет: рассеянный</option>
                        </select>
                        <input type="color" id="view-color" value="#60a5fa" title="Цвет модели" class="min-h-[44px] h-6 w-9 cursor-pointer rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-600 dark:bg-gray-900">
                        <?php if ($stl): ?>
                            <a href="/3d/<?php echo HTML::chars($model['code']); ?>/file/<?php echo (int) $stl['id']; ?>" download class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-download mr-1"></i>STL (<?php echo round($stl['size'] / 1024) ?> КБ)</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="m3d-viewer" class="relative mt-2 h-80 w-full overflow-hidden rounded-xl bg-gray-900"
                     data-stl="<?php echo $stl ? '/3d/'.HTML::chars($model['code']).'/file/'.(int) $stl['id'] : ''; ?>"
                     data-png="<?php echo $png ? '/3d/'.HTML::chars($model['code']).'/file/'.(int) $png['id'] : ''; ?>">
                    <div id="m3d-msg" class="pointer-events-none absolute inset-x-0 bottom-0 px-3 py-1.5 text-center text-xs text-blue-300"></div>
                    <div class="pointer-events-none flex h-full items-center justify-center px-6 text-center text-sm text-gray-500">
                        <?php if ($stl): ?>
                        <?php elseif ($png): ?>Рендер есть, но STL ещё не собран — нажмите «Пересобрать STL с этими параметрами» справа, и модель появится здесь.
                        <?php else: ?>Модель появится здесь после первой генерации.
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($png): ?>
                <div class="mt-2 flex items-center gap-2">
                    <img src="/3d/<?php echo HTML::chars($model['code']); ?>/file/<?php echo (int) $png['id']; ?>" alt="превью" class="h-20 rounded-xl border border-gray-100 bg-gray-50 object-contain dark:border-gray-700 dark:bg-gray-900">
                    <p class="text-sm text-gray-400">Рендер воркера. Ползунки справа позволяют пересобрать STL со своими размерами.</p>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($is_owner AND $need_answers): ?>
            <!-- ============ Уточняющие вопросы (динамические кнопки) ============ -->
            <div class="rounded-xl border border-amber-300 bg-amber-50/70 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-question mr-1.5 text-amber-500"></i>Уточним параметры — ИИ задал вопросы</p>
                <form method="post" action="/3d/<?php echo HTML::chars($model['code']); ?>/answers" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <?php foreach ($clarify['questions'] as $qi => $q): ?>
                    <div>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?php echo ($qi + 1).'. '.HTML::chars($q['q']); ?></p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5" data-qgroup="<?php echo $qi; ?>">
                            <?php foreach ((array) ($q['options'] ?? []) as $oi => $opt): ?>
                                <button type="button" data-qval="<?php echo HTML::chars($opt); ?>"
                                        class="n2j-chip m3d-opt">
                                    <?php echo HTML::chars($opt); ?>
                                </button>
                            <?php endforeach; ?>
                            <input type="text" data-qtext="<?php echo $qi; ?>" placeholder="свой вариант…"
                                   class="n2j-input min-h-[44px] w-44 py-1.5 text-sm">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" class="n2j-btn-process">
                        <i class="fas fa-cube mr-1"></i>Приступить к созданию 3D
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($is_owner): ?>
            <!-- ============ Правка конструкции текстом/изображением ============ -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-pen-to-square mr-1.5 text-blue-500"></i>Править конструкцию</p>
                <form method="post" action="/3d/<?php echo HTML::chars($model['code']); ?>/edit" enctype="multipart/form-data" class="mt-2">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <textarea name="prompt" rows="2" maxlength="4000" placeholder="Что изменить: «сделай стенки толще — 4 мм, добавь отверстие под магнит 10 мм»"
                              class="n2j-textarea min-h-[44px] w-full"></textarea>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp"
                               class="min-h-[44px] max-w-[16rem] text-sm text-gray-500 file:mr-2 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-600 dark:file:bg-gray-700 dark:file:text-blue-300">
                        <button type="submit" class="n2j-btn-process ml-auto">
                            <i class="fas fa-wand-magic-sparkles mr-1"></i>Применить (новая ревизия)
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ============ Ревизии ============ -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-timeline mr-1.5 text-gray-400"></i>Ревизии</p>
                <?php if (empty($revisions)): ?>
                    <p class="mt-2 text-sm text-gray-400">Ревизий пока нет.</p>
                <?php else: ?>
                <ul class="mt-2 space-y-1.5">
                    <?php foreach ($revisions as $r): ?>
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-700"<?php echo in_array($r['status'], ['new', 'coding', 'rendering'], TRUE) ? ' data-rev-pending="1"' : ''; ?>>
                        <span class="n2j-badge n2j-badge-wait font-mono">v<?php echo (int) $r['rev_no']; ?></span>
                        <span class="n2j-badge <?php echo $r['status'] === 'done' ? 'n2j-badge-ok' : ($r['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-info'); ?> uppercase">
                            <?php echo $r['status'] === 'done' ? 'готово' : ($r['status'] === 'error' ? 'ошибка' : ($r['status'] === 'clarify' ? 'ждёт ответов' : 'в работе')); ?>
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr(($r['summary'] ?: '').($r['prompt'] ?: '').($r['error'] ?: ''), 0, 90)); ?></span>
                        <?php foreach ($r['files'] as $f): ?>
                            <?php if ($f['kind'] === 'png'): ?>
                                <img src="/3d/<?php echo HTML::chars($model['code']); ?>/file/<?php echo (int) $f['id']; ?>" alt="рендер v<?php echo (int) $r['rev_no']; ?>" class="h-10 w-14 rounded border border-gray-100 bg-gray-50 object-contain dark:border-gray-700 dark:bg-gray-900">
                            <?php elseif ($f['kind'] === 'stl'): ?>
                                <button type="button" data-load-stl="/3d/<?php echo HTML::chars($model['code']); ?>/file/<?php echo (int) $f['id']; ?>" class="n2j-btn-secondary n2j-btn-sm">в 3D</button>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <span class="text-xs text-gray-400"><?php echo HTML::chars((string) $r['created_at']); ?></span>
                        <?php if ($r['status'] === 'error' AND $r['error']): ?>
                            <span class="w-full truncate text-sm text-rose-400" title="<?php echo HTML::chars($r['error']); ?>"><?php echo HTML::chars(mb_substr($r['error'], 0, 120)); ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ Правая часть (1/3): ползунки, описание, рейтинг ============ -->
        <div class="space-y-4">
            <?php if ($params): ?>
            <!-- ============ Параметрические ползунки ============ -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-sliders mr-1.5 text-blue-500"></i>Параметры (пересборка STL)</p>
                <form method="post" action="/3d/<?php echo HTML::chars($model['code']); ?>/rerender" id="m3d-sliders" class="mt-2 space-y-2.5">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <?php foreach ((array) $params as $p): ?>
                        <?php
                            $name  = (string) ($p['name'] ?? '');
                            $min   = (float) ($p['min'] ?? 1);
                            $max   = (float) ($p['max'] ?? 100);
                            $step  = (float) ($p['step'] ?? 1);
                            $def   = (float) ($p['default'] ?? $min);
                            if ($name === '' OR $max <= $min) continue;
                        ?>
                        <div>
                            <div class="flex items-baseline justify-between">
                                <label class="n2j-label"><?php echo HTML::chars($p['label'] ?? $name); ?></label>
                                <output class="m3d-val rounded bg-blue-50 px-1.5 text-sm font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"><?php echo $def; ?></output>
                            </div>
                            <input type="range" name="p[<?php echo HTML::chars($name); ?>]"
                                   min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="<?php echo $step; ?>" value="<?php echo $def; ?>"
                                   class="m3d-range mt-2 h-2 w-full cursor-pointer">
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="n2j-btn-process w-full">
                        <i class="fas fa-arrows-rotate mr-1"></i>Пересобрать STL с этими параметрами
                    </button>
                    <p id="m3d-vals-note" hidden class="text-center text-sm text-gray-400">Подставлены ваши прошлые значения (память браузера) — кнопка выше пересоберёт STL с ними.</p>
                </form>
            </div>
            <?php endif; ?>

            <!-- ============ Описание ============ -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-file-lines mr-1.5 text-gray-400"></i>Описание</p>
                <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <?php echo HTML::chars($model['description'] ?: 'Описание появится после первой генерации.'); ?>
                </p>
                <?php if ($is_owner): ?>
                <form method="post" action="/3d/<?php echo HTML::chars($model['code']); ?>/publish" class="mt-3"
                      <?php if ($model['status'] !== 'private'): ?>hidden<?php endif; ?>>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit" class="n2j-btn-secondary">
                        <i class="fas fa-paper-plane mr-1"></i>Опубликовать (через модерацию)
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- ============ Оценка ============ -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-star mr-1.5 text-amber-400"></i>Оценка модели</p>
                <?php if (! empty($uid)): ?>
                <form method="post" action="/3d/<?php echo HTML::chars($model['code']); ?>/rate" class="mt-2">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="flex items-center gap-1" id="m3d-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="submit" name="score" value="<?php echo $i; ?>"
                                    class="text-xl leading-none transition <?php echo $i <= $my_score ? 'text-amber-400' : 'text-gray-300 hover:text-amber-300 dark:text-gray-600'; ?>"
                                    title="<?php echo $i; ?>"><i class="fas fa-star"></i></button>
                        <?php endfor; ?>
                    </div>
                    <input type="text" name="comment" maxlength="500" placeholder="Комментарий (необязательно)"
                           class="n2j-input min-h-[44px] mt-2 w-full py-1.5 text-sm">
                </form>
                <?php else: ?>
                <p class="mt-2 text-sm text-gray-400">Войдите, чтобы оценить модель. Средняя: <b><?php echo HTML::chars($rating['avg']); ?></b> (<?php echo (int) $rating['votes']; ?>)</p>
                <?php endif; ?>
            </div>

            <?php if ($is_admin AND $model['status'] === 'pending'): ?>
            <!-- ============ Модерация ============ -->
            <div class="rounded-xl border border-amber-300 bg-amber-50/70 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-gavel mr-1.5 text-amber-500"></i>Модерация</p>
                <div class="mt-2 flex gap-2">
                    <form method="post" action="/nj570/admin/moderate/<?php echo HTML::chars($model['code']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="decision" value="approve">
                        <button type="submit" class="n2j-btn-cta">Опубликовать</button>
                    </form>
                    <form method="post" action="/nj570/admin/moderate/<?php echo HTML::chars($model['code']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="decision" value="reject">
                        <button type="submit" class="n2j-btn-danger">Отклонить</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo View::factory('570/script');
