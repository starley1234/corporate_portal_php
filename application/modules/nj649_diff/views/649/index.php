<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сравнение текстов (nj649_diff) — страница /diff.
 *
 * @var string     $left    текст «было»
 * @var string     $right   текст «стало»
 * @var array      $opts    галочки сравнения
 * @var array      $choices подписи галочек
 * @var bool       $only    показывать только изменения
 * @var array|NULL $result  Service_Nj649_Diff::compare()
 * @var string     $csrf
 */

$ok    = ($result !== NULL AND ! empty($result['ok']));
$stats = $ok ? $result['stats'] : NULL;

$area = 'n2j-textarea mt-1.5 h-64 w-full resize-y font-mono leading-relaxed';
$label = 'n2j-label';

/** Кусочки слов внутри изменённой строки */
$parts = static function ($list, $kind) {
    $html = '';

    foreach ((array) $list as $part)
    {
        $text = HTML::chars($part[1]);

        if ($part[0] === 'eq')
        {
            $html .= $text;
            continue;
        }

        $html .= $kind === 'del'
            ? '<span class="rounded bg-rose-200/70 dark:bg-rose-500/30">'.$text.'</span>'
            : '<span class="rounded bg-emerald-200/70 dark:bg-emerald-500/30">'.$text.'</span>';
    }

    return $html;
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <!-- ======================== Форма ======================== -->
    <form method="post" action="/diff" class="n2j-card">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label for="diff-left" class="<?php echo $label; ?>">Было</label>
                <textarea id="diff-left" name="left" spellcheck="false" placeholder="Вставьте исходную версию" class="<?php echo $area; ?>"><?php echo HTML::chars($left); ?></textarea>
            </div>
            <div>
                <label for="diff-right" class="<?php echo $label; ?>">Стало</label>
                <textarea id="diff-right" name="right" spellcheck="false" placeholder="Вставьте новую версию" class="<?php echo $area; ?>"><?php echo HTML::chars($right); ?></textarea>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2">
            <?php foreach ($choices as $code => $c): ?>
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300" title="<?php echo HTML::chars($c['hint']); ?>">
                    <input type="checkbox" name="<?php echo HTML::chars($code); ?>" value="1" <?php echo ! empty($opts[$code]) ? 'checked' : ''; ?>
                           class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900">
                    <?php echo HTML::chars($c['title']); ?>
                </label>
            <?php endforeach; ?>
            <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300" title="Совпавшие строки не показывать">
                <input type="checkbox" name="only" value="1" <?php echo $only ? 'checked' : ''; ?>
                       class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900">
                Только изменения
            </label>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="submit" class="n2j-btn-process">
                <i class="fas fa-code-compare"></i>Сравнить
            </button>
            <span class="text-xs text-gray-400 dark:text-gray-500">Тексты обрабатываются на лету и нигде не сохраняются.</span>
        </div>
    </form>

    <?php if ($result !== NULL AND ! $ok): ?>
        <div class="n2j-flash n2j-flash-warn mt-4 block">
            <i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars($result['error']); ?>
        </div>
    <?php endif; ?>

    <?php if ($ok): ?>

    <!-- ======================== Сводка ======================== -->
    <div class="n2j-card mt-4">
        <?php if ($stats['identical']): ?>
            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                <i class="fas fa-circle-check mr-1.5"></i>Тексты совпадают<?php echo ($opts['case'] OR $opts['space'] OR $opts['empty']) ? ' с учётом выбранных послаблений' : ''; ?>.
            </p>
        <?php else: ?>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60">
                    <p class="n2j-hint">Изменено строк</p>
                    <p class="mt-0.5 text-lg font-bold text-amber-600 tabular-nums dark:text-amber-400"><?php echo (int) $stats['changed']; ?></p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60">
                    <p class="n2j-hint">Добавлено</p>
                    <p class="mt-0.5 text-lg font-bold text-emerald-600 tabular-nums dark:text-emerald-400">+<?php echo (int) $stats['added']; ?></p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60">
                    <p class="n2j-hint">Удалено</p>
                    <p class="mt-0.5 text-lg font-bold text-rose-600 tabular-nums dark:text-rose-400">−<?php echo (int) $stats['removed']; ?></p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60">
                    <p class="n2j-hint">Совпадение</p>
                    <p class="mt-0.5 text-lg font-bold text-gray-900 tabular-nums dark:text-white"><?php echo HTML::chars(number_format((float) $stats['similarity'], 1, ',', ' ')); ?> %</p>
                </div>
            </div>
            <p class="n2j-hint mt-3">
                Слева <?php echo (int) $stats['lines_a']; ?> строк, справа <?php echo (int) $stats['lines_b']; ?>;
                совпало без изменений <?php echo (int) $stats['same']; ?>.
            </p>
        <?php endif; ?>
    </div>

    <!-- ===================== Сравнение ====================== -->
    <div class="n2j-card p-0 mt-4 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <h2 class="n2j-h3">Построчно</h2>
            <span class="text-xs text-gray-400 dark:text-gray-500">слева «было», справа «стало»</span>
        </div>

        <div class="overflow-x-auto">
            <table class="n2j-table min-w-[40rem] font-mono">
                <tbody>
                    <?php foreach ($result['rows'] as $row): ?>
                        <?php if ($only AND $row['type'] === 'eq') continue; ?>
                        <tr class="align-top <?php
                            echo $row['type'] === 'eq' ? '' : 'bg-gray-50/60 dark:bg-gray-900/40'; ?>">
                            <td class="w-10 select-none px-2 py-1 text-right text-gray-300 dark:text-gray-600"><?php echo $row['ln'] === NULL ? '' : (int) $row['ln']; ?></td>
                            <td class="w-1/2 whitespace-pre-wrap break-words px-2 py-1 <?php
                                echo in_array($row['type'], ['del', 'mod'], TRUE)
                                    ? 'bg-rose-50 text-rose-900 dark:bg-rose-900/20 dark:text-rose-200'
                                    : 'text-gray-600 dark:text-gray-300'; ?>"><?php
                                if ($row['type'] === 'mod' AND isset($row['left_parts']))
                                {
                                    echo $parts($row['left_parts'], 'del');
                                }
                                elseif ($row['left'] !== NULL)
                                {
                                    echo HTML::chars($row['left']);
                                }
                            ?></td>
                            <td class="w-10 select-none px-2 py-1 text-right text-gray-300 dark:text-gray-600"><?php echo $row['rn'] === NULL ? '' : (int) $row['rn']; ?></td>
                            <td class="w-1/2 whitespace-pre-wrap break-words px-2 py-1 <?php
                                echo in_array($row['type'], ['ins', 'mod'], TRUE)
                                    ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200'
                                    : 'text-gray-600 dark:text-gray-300'; ?>"><?php
                                if ($row['type'] === 'mod' AND isset($row['right_parts']))
                                {
                                    echo $parts($row['right_parts'], 'ins');
                                }
                                elseif ($row['right'] !== NULL)
                                {
                                    echo HTML::chars($row['right']);
                                }
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- =================== Unified diff ==================== -->
    <?php if ($result['unified'] !== ''): ?>
        <div class="n2j-card mt-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="n2j-h3">Готовая заплатка (unified diff)</h2>
                <button type="button" id="diff-copy" class="n2j-btn-secondary n2j-btn-sm">
                    <i class="fas fa-copy mr-1"></i>Копировать
                </button>
            </div>
            <p class="n2j-hint">Тот же формат, что у git: его понимают редакторы и команда patch.</p>
            <pre id="diff-unified" class="mt-3 max-h-96 overflow-auto rounded-lg bg-gray-900 p-3 font-mono text-xs leading-relaxed text-gray-100"><?php echo HTML::chars($result['unified']); ?></pre>
        </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Где пригодится</p>
        <ul class="mt-3 space-y-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
            <li>Вернулся договор от контрагента — увидеть, какие пункты правили.</li>
            <li>Две редакции письма или статьи: что именно изменил редактор.</li>
            <li>Конфиги и логи: найти строку, которая отличается от эталона.</li>
            <li>Галочка «игнорировать пробелы» убирает шум от переносов и отступов.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Тексты куда-то сохраняются?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет. Текст приходит на сервер, сравнивается и возвращается в ответе — ни базы, ни файлов,
                    ни журналов с содержимым. Страницу можно закрыть, следов не останется.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что значит «совпадение 87 %»?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Доля строк, совпавших без изменений, от общего числа строк в обоих текстах.
                    Это грубая мера близости версий, а не оценка оригинальности текста.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Можно сравнивать программно?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: <span class="font-mono text-xs">POST /nj649/api/compare</span> с полями
                    <span class="font-mono text-xs">left</span> и <span class="font-mono text-xs">right</span>
                    вернёт сводку и unified diff, а с <span class="font-mono text-xs">rows=1</span> — построчный разбор.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('649/script'); ?>
