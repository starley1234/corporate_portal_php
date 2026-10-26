<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <label for="nj630-in" class="n2j-label">Подсеть (CIDR)</label>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <input id="nj630-in" type="text" value="<?php echo HTML::chars($value); ?>" placeholder="192.168.1.0/24"
                   class="n2j-input min-w-0 flex-1 font-mono">
            <button type="button" id="nj630-go" class="n2j-btn-process">
                <i class="fas fa-calculator"></i> Посчитать
            </button>
        </div>
        <div class="mt-3 flex flex-wrap gap-1.5">
            <?php foreach (['192.168.1.0/24', '10.0.0.0/16', '172.16.0.0/12', '8.8.8.8/32', '0.0.0.0/0'] as $s): ?>
                <button type="button" class="n2j-chip nj630-example" data-value="<?php echo HTML::chars($s); ?>"><?php echo HTML::chars($s); ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-diagram-project mr-1.5 text-gray-400"></i>Результат</p>
        <div id="nj630-out" class="mt-3 grid grid-cols-1 gap-1.5 font-mono text-sm sm:grid-cols-2">
            <?php if ($report): foreach ([
                'Адрес сети' => $report['network'],
                'Broadcast'  => $report['broadcast'],
                'Маска'      => $report['mask'].' /'.$report['prefix'],
                'Wildcard'   => $report['wildcard'],
                'Первый хост' => $report['first_host'],
                'Последний хост' => $report['last_host'],
                'Класс'      => $report['class'],
                'Хостов'     => number_format($report['hosts'], 0, ',', ' '),
            ] as $label => $val): ?>
                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-950">
                    <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($label); ?></span>
                    <span class="font-bold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($val); ?></span>
                </div>
            <?php endforeach; else: ?>
                <p class="col-span-full text-rose-600">Не удалось разобрать подсеть. Формат: a.b.c.d/n.</p>
            <?php endif; ?>
        </div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li><span class="font-mono">/24</span> — 256 адресов, <span class="font-mono">/16</span> — 65 536.</li>
            <li>Число хостов = всего адресов минус 2 (сеть и broadcast).</li>
            <li><span class="font-mono">Wildcard</span> — обратная маска для ACL-списков Cisco.</li>
            <li><span class="font-mono">10.</span>, <span class="font-mono">192.168.</span>, <span class="font-mono">172.16–31.</span> — приватные.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что такое CIDR-запись?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Это адрес с длиной префикса: 192.168.1.0/24 означает «первые 24 бита —
                    сеть, остальные 8 — адреса внутри неё».
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Зачем знать broadcast?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Broadcast — адрес «всем сразу» в подсети; его нельзя назначить устройству.
                    Вместе с адресом сети он вычитается из числа доступных хостов.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Есть ли JSON API?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: GET /nj630/api/range?value=10.0.0.0/8 вернёт весь отчёт по подсети.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('630/script'); ?>
