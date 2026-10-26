<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /digest — подписка + мои дайджесты.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$days = [0 => 'воскресенье', 1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота'];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-ok mb-3 block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mb-4 max-w-3xl">
        <h1 class="n2j-h1"><i class="fas fa-mug-hot mr-2 text-blue-500"></i>Дайджест руководителю</h1>
        <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">Раз в неделю — короткое человеческое резюме: сколько находок принёс радар, сколько новых компаний и писем, что творится во входящих. ИИ пишет текст по вашим цифрам; приходит на страницу и на почту.</p>
    </div>

    <?php /* Живой статус-ряд (общий механизм, эталон /trends): пока воркер
     * собирает недельный дайджест, это видно; после — плашка итога. */ ?>
    <div data-nj613-live-host>
        <?php if ( ! empty($live)): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/digest/api/status',
            'title'   => 'Собираю дайджест недели',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) ($live['stages'] ?? []),
            'note'    => 'Готовый дайджест появится в списке — страница обновится сама.',
        ]); ?>
        <?php elseif ( ! empty($last)): ?>
        <?php echo View::factory('partials/last_status', [
            'status'      => (string) $last['status'],
            'finished_at' => (string) $last['finished_at'],
            'error'       => (string) $last['error'],
            'found'       => NULL,
            'steps'       => (array) ($last['stages'] ?? []),
            'elapsed'     => (int) $last['elapsed_sec'],
        ]); ?>
        <?php endif; ?>
    </div>

    <div class="gap-5 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- Подписка (1/3) -->
        <div class="space-y-4">
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-calendar-check mr-1.5 text-gray-400"></i>Расписание</p>
                <form method="post" action="/digest" class="mt-2 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                        <input type="checkbox" name="enabled" value="1" <?php echo ! empty($sub['enabled']) ? 'checked' : ''; ?> class="h-4 w-4 rounded accent-blue-600">
                        Присылать дайджест
                    </label>
                    <div>
                        <label class="n2j-label">День недели</label>
                        <select name="weekday" class="n2j-input n2j-select mt-1 w-full">
                            <?php foreach ($days as $k => $v): ?>
                            <option value="<?php echo $k; ?>"<?php echo (int) $sub['weekday'] === $k ? ' selected' : ''; ?>><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="n2j-hint">Дайджест собирается раз в 7 дней; день выбирается здесь.</p>
                    </div>
                    <button type="submit" class="n2j-btn-cta w-full">Сохранить</button>
                    <?php if ( ! $has_email): ?>
                    <p class="rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">В профиле нет email — сводка будет только здесь, без письма.</p>
                    <?php endif; ?>
                    <?php if ( ! empty($sub['last_sent'])): ?>
                    <p class="n2j-hint">Последний: <?php echo HTML::chars(Date::formatted_time((string) $sub['last_sent'], 'd.m.y H:i')); ?></p>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Мои дайджесты (2/3) -->
        <div class="space-y-4 md:col-span-2">
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-newspaper mr-1.5 text-gray-400"></i>Мои дайджесты</p>
                <?php if (empty($digests)): ?>
                <p class="mt-2 border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600">Пока пусто — первый придёт через неделю после включения. Недели без событий не присылаются.</p>
                <?php else: ?>
                <ul class="mt-2 space-y-2">
                    <?php foreach ($digests as $d): $d = (array) $d; $st = (string) $d['status']; ?>
                    <li class="rounded-xl border border-gray-100 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-bold text-gray-900 dark:text-white">Неделя <?php echo HTML::chars(Date::formatted_time((string) $d['date_from'], 'd.m')); ?> — <?php echo HTML::chars(Date::formatted_time((string) $d['date_to'], 'd.m')); ?></span>
                            <span class="n2j-badge <?php echo $st === 'ready' ? 'n2j-badge-ok' : ($st === 'sent' ? 'n2j-badge-info' : 'n2j-badge-wait'); ?> uppercase">
                                <?php echo $st === 'ready' ? 'готов' : ($st === 'sent' ? 'отправлен' : 'собирается…'); ?>
                            </span>
                        </div>
                        <?php if ((string) $d['summary'] !== ''): ?>
                        <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $d['summary']); ?></p>
                        <?php endif; ?>
                        <?php $stats = json_decode((string) ($d['stats'] ?? ''), TRUE); ?>
                        <?php if (is_array($stats) AND $stats): ?>
                        <p class="n2j-hint mt-2 flex flex-wrap gap-2">
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700">находок: <?php echo (int) ($stats['radar_finds'] ?? 0); ?></span>
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700">лидов: <?php echo (int) ($stats['leads'] ?? 0); ?></span>
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700">входящих: <?php echo (int) ($stats['inbox_new'] ?? 0); ?></span>
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700">писем: <?php echo (int) ($stats['touches_sent'] ?? 0); ?></span>
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700">статей: <?php echo (int) ($stats['articles'] ?? 0); ?></span>
                        </p>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>
