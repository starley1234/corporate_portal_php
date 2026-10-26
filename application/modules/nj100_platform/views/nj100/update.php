<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /nj100/update — пинок обновления сайта (bin/deploy.sh --migrate).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="mb-3 rounded-xl border <?php echo $kicked ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/40' : 'border-rose-200 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/40'; ?> px-3 py-2 text-xs <?php echo $kicked ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-300'; ?>">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <div class="gap-4 space-y-4 md:grid md:grid-cols-2 md:items-start md:space-y-0">

        <div class="space-y-4">
            <?php if ($diag !== NULL): $pending = ! empty($diag['pending']); ?>
            <div class="rounded-xl border <?php echo ($pending OR $diag['ahead'] > 0) ? 'border-amber-300/80 bg-amber-50/90 dark:border-amber-800/80 dark:bg-amber-950/40' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'; ?> p-4 shadow-xs">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-code-branch mr-1.5 <?php echo ($pending OR $diag['ahead'] > 0) ? 'text-amber-500' : 'text-blue-500'; ?>"></i>Куда смотрит деплой</p>
                <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300">
                    Ветка сервера: <span class="font-mono font-bold"><?php echo HTML::chars($diag['branch']); ?></span>
                    (на сервере <span class="font-mono"><?php echo HTML::chars($diag['local']); ?></span>)
                    · в GitHub <span class="font-mono"><?php echo HTML::chars(($diag['upstream'] ?? '') !== '' ? $diag['upstream'] : '?'); ?></span>
                    <?php if ((string) $deploy_branch !== ''): ?>
                    · деплой следит за <span class="font-mono"><?php echo HTML::chars($deploy_branch); ?></span>
                    <?php else: ?>
                    · origin/main: <span class="font-mono"><?php echo HTML::chars($diag['main'] !== '' ? $diag['main'] : '?'); ?></span>
                    <?php endif; ?>
                </p>
                <?php if ($pending): ?>
                <p class="mt-1.5 text-xs font-semibold text-amber-800 dark:text-amber-200">
                    <i class="fas fa-triangle-exclamation mr-1"></i>На сервере старее: <span class="font-mono"><?php echo HTML::chars($diag['local']); ?></span> → <span class="font-mono"><?php echo HTML::chars($diag['upstream']); ?></span>.
                    Нажмите «Обновить сайт сейчас». Если в обновлении есть python-воркеры (workers/) — перезапустите их под root:
                    <span class="font-mono">systemctl restart n2j-worker</span>.
                </p>
                <?php elseif ($diag['ahead'] > 0): ?>
                <p class="mt-1.5 text-xs font-semibold text-amber-800 dark:text-amber-200">
                    <i class="fas fa-triangle-exclamation mr-1"></i>Текущая ветка впереди main на <?php echo (int) $diag['ahead']; ?> коммитов —
                    деплой с DEPLOY_BRANCH=main обновлять НЕЧЕМУ. Смержите PR (arena → main) на GitHub
                    или задайте <span class="font-mono">DEPLOY_BRANCH=<?php echo HTML::chars($diag['branch']); ?></span> в /etc/default/n2j-deploy.
                </p>
                <?php else: ?>
                <p class="n2j-hint mt-1.5"><i class="fas fa-circle-check mr-1 text-emerald-500"></i>Сайт на последнем коммите своей ветки — обновлять нечего.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-arrows-rotate mr-1.5 text-blue-500"></i>Обновить сайт сейчас</p>
                <p class="n2j-hint">Запускает <span class="font-mono">bin/deploy.sh --migrate</span> в фоне: git pull → composer → идемпотентные миграции БД (<span class="font-mono">db:migrate</span>) → перезапуск воркера → reload php-fpm. Повторный пинок, пока обновление идёт, безопасен (flock). Крон каждую минуту больше не нужен — таймер systemd можно отключить.</p>

                <form method="post" action="/nj100/update" class="mt-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit" class="n2j-btn-process w-full">
                        <i class="fas fa-bolt mr-1"></i>Пнуть обновление (pull + миграции)
                    </button>
                </form>
                <p class="n2j-hint mt-2" id="n100-hint">Обычно занимает 10–60 секунд. Журнал пинка — справа.</p>
            </div>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-robot mr-1.5 text-gray-400"></i>Пинок для агента (без логина)</p>
                <?php if ($kick_on): ?>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">GET-ссылка с токеном <span class="font-mono">SITE_UPDATE_TOKEN</span> из <span class="font-mono">.env</span>:</p>
                <p class="mt-1 break-all rounded-xl bg-gray-50 p-2 font-mono text-xs text-gray-600 dark:bg-gray-900 dark:text-gray-300">/update/kick/&lt;SITE_UPDATE_TOKEN&gt;</p>
                <p class="n2j-hint">Токен не показывается нигде в интерфейсе; сменили в .env — ссылка сразу новая.</p>
                <?php else: ?>
                <p class="n2j-hint">Не настроено: добавьте в <span class="font-mono">.env</span> строку <span class="font-mono">SITE_UPDATE_TOKEN=длинный-случайный-токен</span> — появится GET-ссылка <span class="font-mono">/update/kick/&lt;токен&gt;</span> для агента и скриптов.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="n2j-card">
            <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-terminal mr-1.5 text-gray-400"></i>Журнал пинка <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars($log_path); ?></span></p>
            <?php if (empty($log)): ?>
            <p class="n2j-hint mt-2">Пока пусто — пинок ещё не запускался.</p>
            <?php else: ?>
            <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 dark:bg-gray-900 dark:text-gray-300"><?php echo HTML::chars(implode("\n", $log)); ?></pre>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($kicked): ?>
<script>
setTimeout(function () { window.location.reload(); }, 25000);
</script>
<?php endif; ?>
