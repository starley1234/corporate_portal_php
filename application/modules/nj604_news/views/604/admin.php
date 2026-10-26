<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj604/admin — новости: ручные заметки + управление.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/news" class="transition hover:text-blue-600 dark:hover:text-blue-400">Новости сайта</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Управление</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-bullhorn mr-2 text-blue-500"></i>Новости · управление
        </h1>
        <span class="n2j-badge n2j-badge-wait font-mono" title="HEAD репозитория на сервере">git: <?php echo $head !== '' ? HTML::chars($head) : '—'; ?></span>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">
        Релизы (список улучшений из git-лога) добавляются автоматически при открытии /news; здесь — заметки
        человеческим языком о важных фичах и управление лентой. RSS: <span class="font-mono">/news/rss.xml</span>.
    </p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <div class="n2j-card p-0 md:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                <div class="flex items-center gap-2.5">
                    <h2 class="n2j-h3">Лента</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($items); ?></span>
                </div>
                <form method="post" action="/nj604/admin">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="sync">
                    <button type="submit" class="n2j-btn-secondary">
                        <i class="fas fa-arrows-rotate"></i>Дочитать релизы из git
                    </button>
                </form>
            </div>

            <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
                <?php foreach ($items as $n): ?>
                <li class="px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="n2j-badge <?php echo $n['kind'] === 'release' ? 'n2j-badge-info' : 'n2j-badge-ok'; ?>"><?php echo $n['kind'] === 'release' ? 'релиз' : 'заметка'; ?></span>
                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($n['title']); ?></span>
                        <?php if ( ! (int) $n['published']): ?><span class="n2j-badge n2j-badge-wait">скрыта</span><?php endif; ?>
                        <form method="post" action="/nj604/admin" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                            <input type="hidden" name="act" value="<?php echo (int) $n['published'] ? 'hide' : 'show'; ?>">
                            <button type="submit" class="text-xs font-semibold text-gray-400 transition hover:text-gray-700 dark:hover:text-gray-200"><?php echo (int) $n['published'] ? 'скрыть' : 'показать'; ?></button>
                        </form>
                        <form method="post" action="/nj604/admin" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                            <input type="hidden" name="act" value="delete">
                            <button data-n2j-arm="Удалить?" type="submit" title="Удалить" class="n2j-btn-icon-danger"><i class="fas fa-trash-can text-xs"></i></button>
                        </form>
                    </div>
                    <?php if ((string) $n['body_md'] !== ''): ?><p class="n2j-hint line-clamp-2"><?php echo HTML::chars(mb_substr((string) $n['body_md'], 0, 180)); ?></p><?php endif; ?>
                </li>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <li class="px-5 py-4 text-xs text-gray-400">Лента пуста — нажмите «Дочитать релизы» или добавьте заметку справа.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="space-y-5">
            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-feather-pointed mr-1.5 text-blue-500"></i>Добавить заметку</h3>
                <form method="post" action="/nj604/admin" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="add">
                    <div>
                        <label for="nj604-title" class="n2j-label">Заголовок <span class="text-rose-500">*</span></label>
                        <input id="nj604-title" name="title" type="text" maxlength="300" required placeholder="Например: AI-досье теперь сверяется со справочником"
                               class="n2j-input mt-1.5 w-full">
                    </div>
                    <div>
                        <label for="nj604-body" class="n2j-label">Текст <span class="font-normal text-gray-400">(строки с «-» станут списком)</span></label>
                        <textarea id="nj604-body" name="body" rows="4" placeholder="- что добавили&#10;- зачем это нужно"
                                  class="n2j-textarea min-h-[44px] mt-1.5 w-full"></textarea>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="nj604-tag" class="n2j-label">Тег</label>
                            <input id="nj604-tag" name="tag" type="text" maxlength="40" placeholder="новая фича"
                                   class="n2j-input mt-1.5 w-full">
                        </div>
                        <div>
                            <label for="nj604-href" class="n2j-label">Ссылка</label>
                            <input id="nj604-href" name="href" type="text" maxlength="300" placeholder="/dossier"
                                   class="n2j-input mt-1.5 min-h-[44px] w-full py-2.5 font-mono text-xs">
                        </div>
                    </div>
                    <button type="submit" class="n2j-btn-cta w-full">Опубликовать</button>
                </form>
            </div>

            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Как авто-релизы работают</h3>
                </div>
                <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                    При открытии /news сервис сравнивает HEAD репозитория с последним зафиксированным
                    и на новые коммиты создаёт одну новость-релиз со списком улучшений. Merge-коммиты
                    и первый запуск (фиксация точки отсчёта) в ленту не попадают.
                </p>
            </div>
        </div>
    </div>
</div>

