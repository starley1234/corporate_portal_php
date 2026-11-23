<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карточка события: что, кто, когда, payload и кто подписан на это имя.
 *
 * Переменные: $event (Service_Prx208_Journal::detail).
 */
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Администрирование', '/admin'], ['События', '/prx208_events'], [$event['event_name']]],
        'title'   => 'Событие',
        'pill'    => ['prx208 · шина', 'fa-bolt'],
        'lead'    => 'Строка журнала prx_208_events. Редактирования нет: журнал пишет код, старое убирает чистка.',
        'actions' => [['label' => 'К журналу', 'href' => '/prx208_events', 'icon' => 'fa-arrow-left', 'role' => 'secondary']],
    ]); ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <section class="prx-card md:col-span-2">
            <h2 class="prx-h3"><span class="prx-badge prx-badge-info"><span class="prx-dot"></span><?php echo HTML::chars($event['prefix']); ?></span> <code class="font-mono text-base"><?php echo HTML::chars($event['action']); ?></code></h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Когда</dt><dd class="mt-1 font-mono text-gray-800 dark:text-gray-100"><?php echo HTML::chars($event['when']); ?></dd></div>
                <div><dt class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Инициатор</dt><dd class="mt-1 font-mono text-gray-800 dark:text-gray-100"><?php echo $event['initiator_id'] === NULL ? 'система' : 'user #'.(int) $event['initiator_id']; ?></dd></div>
                <div><dt class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Модуль</dt><dd class="mt-1 text-gray-800 dark:text-gray-100"><?php echo HTML::chars($event['module_title'] !== '' ? $event['module_title'] : 'не найден в манифестах'); ?></dd></div>
                <div><dt class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">ID записи</dt><dd class="mt-1 font-mono text-gray-800 dark:text-gray-100">#<?php echo (int) $event['id']; ?></dd></div>
            </dl>
            <h3 class="prx-h3 mt-6">Payload</h3>
            <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-900 p-4 text-xs leading-5 text-gray-100"><?php echo HTML::chars($event['payload_text']); ?></pre>
        </section>

        <aside class="space-y-4">
            <section class="prx-card" aria-labelledby="prx208-detail-subs">
                <h2 id="prx208-detail-subs" class="prx-h3">Подписки на это имя <span class="prx-count ml-1"><?php echo count($event['subscribers']); ?></span></h2>
                <?php if (empty($event['subscribers'])): ?>
                    <p class="prx-hint mt-2">Явных подписок из БД нет. Слушатели в коде (Event::listen в init.php модулей) здесь не видны — смотрите init.php издателя.</p>
                <?php else: ?>
                    <ul class="mt-3 space-y-2">
                        <?php foreach ($event['subscribers'] as $s): ?>
                        <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ($s['is_active']): ?><span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>активна</span><?php else: ?><span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>выключена</span><?php endif; ?>
                                <?php if ($s['is_notification']): ?><span class="prx-badge prx-badge-info"><span class="prx-dot"></span>уведомление</span><?php endif; ?>
                                <span class="text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $s['module_id'] !== '' ? (string) $s['module_id'] : 'модуль не указан'); ?></span>
                            </div>
                            <?php if ((string) $s['callback_method'] !== ''): ?><code class="mt-1 block break-all font-mono text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $s['callback_method']); ?></code><?php endif; ?>
                            <?php if ((string) $s['description'] !== ''): ?><p class="prx-hint mt-1"><?php echo HTML::chars((string) $s['description']); ?></p><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a href="/prx208_events/list_subscribers?search=<?php echo rawurlencode($event['event_name']); ?>" class="prx-link mt-3 inline-block text-sm">Все подписки на это имя →</a>
            </section>
            <section class="prx-card">
                <h2 class="prx-h3">Дальше событие идёт</h2>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <li><i class="fas fa-code mr-1.5 w-4 text-gray-400" aria-hidden="true"></i>слушатели в коде — сразу, в том же запросе;</li>
                    <li><i class="fas fa-bell mr-1.5 w-4 text-gray-400" aria-hidden="true"></i><a class="prx-link" href="/prx597/admin">уведомления prx597</a> — если модуль зовёт Notify::emit() сам или включён мост;</li>
                    <li><i class="fas fa-broom mr-1.5 w-4 text-gray-400" aria-hidden="true"></i>через 90 дней строку убирает <a class="prx-link" href="/prx100/cleanup">чистка</a>.</li>
                </ul>
            </section>
        </aside>
    </div>
</div>
