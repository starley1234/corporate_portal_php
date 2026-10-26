<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1">
                <label for="nj629-in" class="n2j-label">Цвет</label>
                <input id="nj629-in" type="text" value="<?php echo HTML::chars($value); ?>" placeholder="#3B82F6 или 59,130,246"
                       class="n2j-input mt-1 w-full font-mono">
            </div>
            <div class="pt-5"><input type="color" id="nj629-picker" value="#3B82F6" class="h-11 w-14 cursor-pointer rounded-lg border border-gray-300 bg-white p-1 dark:border-gray-600 dark:bg-gray-900"></div>
        </div>
        <div class="mt-3 flex flex-wrap gap-1.5">
            <?php foreach ([['#3B82F6', 'синий'], ['#22c55e', 'зелёный'], ['#ef4444', 'красный'], ['#f59e0b', 'янтарный'], ['#111827', 'графит']] as $c): ?>
                <button type="button" class="n2j-chip nj629-example" data-value="<?php echo HTML::chars($c[0]); ?>">
                    <span class="inline-block h-3 w-3 rounded-full" style="background:<?php echo HTML::chars($c[0]); ?>"></span><?php echo HTML::chars($c[1]); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <div class="flex items-center gap-4">
            <div id="nj629-swatch" class="h-16 w-16 shrink-0 rounded-xl border border-gray-200 dark:border-gray-700" style="background:<?php echo $report ? HTML::chars($report['hex']) : '#3B82F6'; ?>"></div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-swatchbook mr-1.5 text-gray-400"></i>Значения</p>
                <div id="nj629-values" class="mt-1 space-y-1 font-mono text-sm">
                    <?php if ($report): ?>
                        <p><span class="text-gray-400">HEX</span> <b class="text-gray-900 dark:text-white"><?php echo HTML::chars($report['hex']); ?></b></p>
                        <p><span class="text-gray-400">RGB</span> <b class="text-gray-900 dark:text-white"><?php echo implode(', ', $report['rgb']); ?></b></p>
                        <p><span class="text-gray-400">HSL</span> <b class="text-gray-900 dark:text-white"><?php echo HTML::chars($report['hsl_s']); ?></b></p>
                    <?php else: ?>
                        <p class="text-gray-400">Введите цвет — увидите HEX, RGB и HSL.</p>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" id="nj629-copy" class="n2j-btn-secondary n2j-btn-sm shrink-0" disabled>
                <i class="fas fa-copy mr-1"></i>Копировать
            </button>
        </div>

        <div id="nj629-shades" class="mt-4 grid grid-cols-5 gap-2">
            <?php if ($report): foreach ($report['shades'] as $i => $s): ?>
                <div class="text-center"><div class="h-10 rounded-lg" style="background:<?php echo HTML::chars($s['hex']); ?>"></div><span class="mt-1 block text-xs text-gray-400"><?php echo ($s['pct'] > 0 ? '+' : '').$s['pct']; ?>%</span></div>
            <?php endforeach; else: ?>
                <div class="col-span-5 text-xs text-gray-400">Здесь появятся светлее/темнее оттенки.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>Принимаются <span class="font-mono">#RGB</span>, <span class="font-mono">#RRGGBB</span>, <span class="font-mono">rgb()</span> и «59,130,246».</li>
            <li>HEX для CSS, RGB — для расчётов, HSL — чтобы менять светлость/тон.</li>
            <li>Контраст текста можно оценить по яркости (luma): &gt;128 — тёмный текст.</li>
            <li>Комплементарный цвет — поворот оттенка на 180°: рассыпается по палитре.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Чем HSL лучше RGB?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    HSL описывает цвет так, как его видит человек: тон, насыщенность,
                    светлота. Легче подобрать «тот же цвет, но темнее»: меняете только L.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что такое комплементарный цвет?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Цвет напротив на круге оттенков. Пара с исходным даёт яркий контраст —
                    для кнопок и акцентов на странице.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Можно ли вызвать из кода?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: GET /nj629/api/convert?value=%233b82f6 — JSON с hex, rgb, hsl и оттенками.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('629/script'); ?>
