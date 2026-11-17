<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120/workers — «Документация воркеров»: автосправка для админа.
 * Источник — docstring и реестр HANDLERS в workers/prx_workers/handlers;
 * страницы рисует Service_Prx120_Workerdocs. Мерmaid — локальный vendor
 * (как в prx602, без внешних CDN).
 */

$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800 sm:p-5';
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="mb-4">
        <h1 class="prx-h1">
            <i class="fas fa-book mr-2 text-blue-500"></i>Документация воркеров
        </h1>
        <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            Что делает каждый обработчик фоновых задач — на человеческом языке, со схемами.
            Страница собирается прямо из кода воркера (workers/prx_workers/handlers), поэтому
            описания и схемы всегда соответствуют тому, что реально запускается.
            Живое состояние очереди — <a href="/prx120/monitor" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">монитор воркеров</a>.
        </p>
        <?php if ($dir === ''): ?>
        <p class="prx-flash prx-flash-warn mt-3 block">
            <i class="fas fa-triangle-exclamation mr-1"></i>Каталог workers/prx_workers/handlers не найден рядом с сайтом — на этой машине код воркера не развёрнут.
        </p>
        <?php endif; ?>
    </div>

    <?php if (empty($workers)): ?>
    <div class="<?php echo $card; ?>">
        <p class="border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600">Обработчики не найдены.</p>
    </div>
    <?php else: ?>

    <!-- Жизненный цикл задачи — общий для всех воркеров -->
    <div class="<?php echo $card; ?> mb-5">
        <h2 class="prx-h3"><i class="fas fa-route mr-1.5 text-blue-500"></i>Как воркер отрабатывает задачу</h2>
        <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            Модуль кладёт задачу в очередь (<span class="font-mono text-xs">prx120_jobs</span>) со статусом
            <span class="prx-count font-mono">pending</span>.
            Воркер забирает её в работу, и если обработчик упал — повторяет попытку с паузой,
            пока не кончатся попытки. Результат виден в «Моих задачах», пульс и ручное управление — в мониторе.
        </p>
        <pre class="prx120-wdoc hidden">flowchart LR
    A["pending: задача в очереди"] --> B["running: воркер выполняет"]
    B --> C["done: результат у модуля"]
    B --> D["ошибка: ещё есть попытки"]
    D -->|пауза и повтор| B
    D --> E["failed: попытки кончились"]
    B --> F["отменено пользователем"]</pre>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

        <?php foreach ($workers as $w): ?>
        <div class="<?php echo $card; ?>">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="prx-h3">
                    <i class="fas fa-gear mr-1.5 text-gray-400"></i><?php echo HTML::chars($w['title']); ?>
                </h2>
                <?php foreach ($w['channels'] as $ch): ?>
                <span class="prx-count font-mono"><?php echo HTML::chars($ch); ?></span>
                <?php endforeach; ?>
            </div>

            <?php if ($w['about'] !== ''): ?>
            <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($w['about']); ?></p>
            <?php endif; ?>

            <?php if ($w['mermaid'] !== ''): ?>
            <div class="mt-3 overflow-x-auto rounded-xl border border-gray-100 bg-gray-50/60 p-3 text-sm dark:border-gray-700/60 dark:bg-gray-900/40">
                <pre class="prx120-wdoc hidden"><?php echo HTML::chars($w['mermaid']); ?></pre>
            </div>
            <?php endif; ?>

            <?php if ($w['prompt'] !== ''): ?>
            <div class="mt-3 text-sm">
                <button type="button" class="prx120-prompt-toggle inline-flex items-center gap-1 font-semibold text-gray-500 transition hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400">
                    <i class="fas fa-terminal text-xs"></i><span class="prx120-prompt-label">Промпт модели</span>
                    <i class="fas fa-chevron-down prx120-prompt-chevron text-[10px]"></i>
                </button>
                <pre class="prx120-prompt-text mt-2 hidden max-h-56 whitespace-pre-wrap break-words rounded-xl border border-gray-100 bg-amber-50/60 p-3 font-mono text-xs leading-relaxed text-gray-700 dark:border-gray-700/60 dark:bg-gray-900/40 dark:text-gray-300"><?php echo HTML::chars(mb_substr($w['prompt'], 0, 2400)); ?></pre>
            </div>
            <?php endif; ?>

            <?php if ( ! empty($w['env'])): ?>
            <div class="mt-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Переменные окружения</p>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    <?php foreach ($w['env'] as $k): ?>
                    <span class="prx-count font-mono"><?php echo HTML::chars($k); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <p class="prx-hint mt-3 border-t border-gray-100 pt-2 dark:border-gray-700 font-mono"><?php echo HTML::chars($w['file']); ?></p>
        </div>
        <?php endforeach; ?>

    </div>
    <?php endif; ?>
</div>

<script>
/* свёртывание блоков «Промпт модели» (без переделки каркаса) */
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.prx120-prompt-toggle');
    if (!btn) return;
    var box = btn.parentElement;
    var text = box.querySelector('.prx120-prompt-text');
    var chev = box.querySelector('.prx120-prompt-chevron');
    if (!text) return;
    text.classList.toggle('hidden');
    if (chev) chev.classList.toggle('fa-rotate-180');
});
</script>

<script>
/* mermaid — локальный vendor (без CDN); рисуем все схемы страницы */
(function () {
    var boxes = document.querySelectorAll('pre.prx120-wdoc');

    if (!boxes.length) return;

    var render = function () {
        try {
            mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'strict' });

            boxes.forEach(function (pre, i) {
                var src = pre.textContent || '';

                if (!src.trim()) return;

                mermaid.render('prx120-wdoc-' + i, src).then(function (res) {
                    var div = document.createElement('div');
                    div.innerHTML = res.svg;
                    pre.replaceWith(div.firstChild);
                }).catch(function () {
                    var p = document.createElement('pre');
                    p.className = 'overflow-x-auto whitespace-nowrap text-xs text-gray-500';
                    p.textContent = src;
                    pre.replaceWith(p);
                });
            });
        } catch (e) { /* без схем страница всё равно полезна */ }
    };

    if (window.mermaid) { render(); return; }

    var s = document.createElement('script');
    s.src = '/assets/vendor/mermaid/mermaid.min.js';
    s.onload = render;
    document.head.appendChild(s);
})();
</script>
