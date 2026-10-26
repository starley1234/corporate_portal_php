<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /touches/<code> — таймлайн серии: шаги-письма, отправка, управление.
 */

$st_ru = ['drafting' => 'черновик пишется', 'ready' => 'готов к отправке', 'sent' => 'отправлено', 'skipped' => 'пропущено'];
$st_cl = [
    'drafting' => 'bg-blue-50 text-blue-600 animate-pulse dark:bg-blue-950/60 dark:text-blue-300',
    'ready'    => 'bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-300',
    'sent'     => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-300',
    'skipped'  => 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-400',
];
$status_ru = [
    'active' => 'идёт', 'paused' => 'пауза', 'done' => 'завершена',
    'stopped' => 'остановлена', 'answered' => 'контакт ответил',
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div data-nj612-live-host>
        <?php if ( ! empty($live)): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/touches/api/status/'.urlencode((string) $s['code']),
            'title'   => 'Пишу черновик шага',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) ($live['stages'] ?? []),
            'note'    => 'Черновик появится в таймлайне — страница обновится сама.',
            'stop'    => '<form method="post" class="shrink-0">'
                .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                .'<input type="hidden" name="act" value="stop_draft">'
                .'<button type="submit" class="rounded-lg border border-gray-200 px-2 py-1 text-sm font-semibold text-gray-500 transition hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/60"><i class="fas fa-circle-stop"></i> Остановить</button>'
                .'</form>',
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

    <div class="flex flex-wrap items-center gap-2">
        <a href="/touches" class="text-sm text-gray-400 hover:text-blue-500"><i class="fas fa-arrow-left mr-1"></i>к сериям</a>
        <h1 class="n2j-h1"><?php echo HTML::chars($s['company']); ?></h1>
        <span class="n2j-badge <?php echo $s['status'] === 'answered' ? 'n2j-badge-ok' : ($s['status'] === 'active' ? 'n2j-badge-info' : 'n2j-badge-wait'); ?> uppercase">
            <?php echo $status_ru[$s['status']] ?? HTML::chars($s['status']); ?>
        </span>
        <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars($s['code']); ?></span>
    </div>

    <div class="mt-4 grid gap-5 lg:grid-cols-3 lg:items-start">

        <!-- Таймлайн писем (2/3) -->
        <div class="space-y-4 lg:col-span-2">
            <?php foreach ($s['steps'] as $st): $st = (array) $st; ?>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs transition dark:border-gray-700 dark:bg-gray-800
                <?php echo $st['status'] === 'ready' ? 'border-amber-300 dark:border-amber-800' : ''; ?>">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="n2j-badge n2j-badge-wait uppercase">
                        <i class="fas fa-envelope-open-text"></i>Шаг <?php echo (int) $st['seq']; ?> из <?php echo (int) $s['steps_total']; ?>
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase <?php echo $st_cl[$st['status']] ?? $st_cl['skipped']; ?>"><?php echo $st_ru[$st['status']] ?? HTML::chars($st['status']); ?></span>
                    <?php if ($st['sent_at']): ?><span class="text-xs text-gray-400">отправлено <?php echo HTML::chars(Date::formatted_time((string) $st['sent_at'], 'd.m.y H:i')); ?></span><?php endif; ?>
                    <?php if ($st['status'] === 'ready'): ?>
                    <form method="post" action="/touches/<?php echo HTML::chars($s['code']); ?>" class="ml-auto">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="send">
                        <button type="submit" class="n2j-btn-cta">
                            <i class="fas fa-paper-plane"></i>Отправить
                        </button>
                    </form>
                    <?php elseif ($st['status'] === 'drafting'): ?>
                    <span class="ml-auto text-xs text-gray-400"><i class="fas fa-spinner fa-spin mr-1"></i>ИИ пишет…</span>
                    <?php endif; ?>
                </div>

                <?php if ((string) $st['subject'] !== ''): ?>
                <p class="mt-3 text-base font-bold text-gray-900 dark:text-white"><i class="fas fa-tag mr-1.5 text-gray-400"></i><?php echo HTML::chars($st['subject']); ?></p>
                <?php endif; ?>
                <?php if ((string) $st['body'] !== ''): ?>
                <p class="mt-2 whitespace-pre-line text-base leading-relaxed text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $st['body']); ?></p>
                <?php endif; ?>
                <?php if ((string) $st['error'] !== ''): ?>
                <p class="mt-2 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-600 dark:bg-rose-950/40 dark:text-rose-300"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo HTML::chars((string) $st['error']); ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Управление (1/3) -->
        <div class="space-y-4">
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-address-book mr-1.5 text-gray-400"></i>Контакт</p>
                <form method="post" action="/touches/<?php echo HTML::chars($s['code']); ?>" class="mt-2 space-y-2">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="contact">
                    <input type="text" name="contact_name" maxlength="200" placeholder="Имя" value="<?php echo HTML::chars((string) $s['contact_name']); ?>"
                           class="n2j-input w-full">
                    <input type="email" name="email" maxlength="200" placeholder="email контакта" value="<?php echo HTML::chars((string) $s['contact_email']); ?>"
                           class="n2j-input w-full">
                    <button type="submit" class="n2j-btn-secondary n2j-btn-sm w-full">Сохранить</button>
                </form>
                <p class="n2j-hint mt-2">Ответ отслеживается по этому адресу во входящих N2J. Без адреса отправка недоступна — серию можно вести как черновики.</p>
            </div>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-sliders mr-1.5 text-gray-400"></i>Управление</p>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <form method="post" action="/touches/<?php echo HTML::chars($s['code']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="redraft">
                        <button type="submit" class="n2j-btn-secondary w-full"><i class="fas fa-rotate mr-1"></i>Черновик заново</button>
                    </form>
                    <?php if ($s['status'] === 'active'): ?>
                    <form method="post" action="/touches/<?php echo HTML::chars($s['code']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="pause">
                        <button type="submit" class="n2j-btn-secondary w-full"><i class="fas fa-pause mr-1"></i>Пауза</button>
                    </form>
                    <?php elseif ($s['status'] === 'paused'): ?>
                    <form method="post" action="/touches/<?php echo HTML::chars($s['code']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="resume">
                        <button type="submit" class="n2j-btn-secondary w-full"><i class="fas fa-play mr-1"></i>Продолжить</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($s['status'] !== 'answered'): ?>
                    <form method="post" action="/touches/<?php echo HTML::chars($s['code']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="answered">
                        <button type="submit" class="n2j-btn-secondary w-full"><i class="fas fa-reply mr-1"></i>Он ответил</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="/touches/<?php echo HTML::chars($s['code']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="stop">
                        <button data-n2j-confirm="Письма этой серии больше не будут отправляться." data-n2j-title="Остановить серию навсегда?" data-n2j-ok="Остановить" type="submit" class="n2j-btn-danger w-full"><i class="fas fa-stop mr-1"></i>Стоп</button>
                    </form>
                </div>
                <?php if ($autosend): ?>
                <p class="mt-2 rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">Автоотправка включена администратором: готовые шаги уходят сами по расписанию.</p>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-bullseye mr-1.5 text-gray-400"></i>Оффер серии</p>
                <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $s['offer']); ?></p>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('partials/live_status_js'); ?>

