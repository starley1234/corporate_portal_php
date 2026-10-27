<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prl100/update — пинок обновления сайта (bin/deploy.sh --migrate).
 */
?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="prl-flash <?php echo $kicked ? 'prl-flash-ok' : 'prl-flash-err'; ?> mb-5" role="<?php echo $kicked ? 'status' : 'alert'; ?>">
        <i class="fas <?php echo $kicked ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
        <span><?php echo HTML::chars($flash); ?></span>
    </div>
    <?php endif; ?>

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Платформа'], ['Обновление сайта']],
        'title'  => 'Обновление сайта',
        'pill'   => ['prl100 · деплой', 'fa-arrows-rotate'],
        'lead'   => 'Диагностика ветки деплоя, запуск обновления и журнал последнего пинка.',
    ]); ?>

    <div class="mt-6 gap-4 space-y-4 md:grid md:grid-cols-2 md:items-start md:space-y-0">

        <div class="space-y-4">
            <?php if ($diag !== NULL): $pending = ! empty($diag['pending']); ?>
            <section class="<?php echo ($pending OR $diag['ahead'] > 0) ? 'prl-flash prl-flash-warn block' : 'prl-card'; ?>">
                <h2 class="prl-h3"><i class="fas fa-code-branch mr-1.5 <?php echo ($pending OR $diag['ahead'] > 0) ? 'text-amber-600 dark:text-amber-400' : 'text-blue-500'; ?>"></i>Куда смотрит деплой</h2>
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
                    <span class="font-mono">systemctl restart prl-worker</span>.
                </p>
                <?php elseif ($diag['ahead'] > 0): ?>
                <p class="mt-1.5 text-xs font-semibold text-amber-800 dark:text-amber-200">
                    <i class="fas fa-triangle-exclamation mr-1"></i>Текущая ветка впереди main на <?php echo (int) $diag['ahead']; ?> коммитов —
                    деплой с DEPLOY_BRANCH=main обновлять НЕЧЕМУ. Смержите PR (arena → main) на GitHub
                    или задайте <span class="font-mono">DEPLOY_BRANCH=<?php echo HTML::chars($diag['branch']); ?></span> в /etc/default/prl-deploy.
                </p>
                <?php else: ?>
                <p class="prl-hint mt-1.5"><i class="fas fa-circle-check mr-1 text-emerald-500"></i>Сайт на последнем коммите своей ветки — обновлять нечего.</p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <div class="prl-card">
                <h2 class="prl-h3"><i class="fas fa-arrows-rotate mr-1.5 text-blue-500"></i>Обновить сайт сейчас</h2>
                <p class="prl-hint">Запускает <span class="font-mono">bin/deploy.sh --migrate</span> в фоне: git pull → composer → идемпотентные миграции БД (<span class="font-mono">db:migrate</span>) → перезапуск воркера → reload php-fpm. Повторный пинок, пока обновление идёт, безопасен (flock). Крон каждую минуту больше не нужен — таймер systemd можно отключить.</p>

                <form method="post" action="/prl100/update" class="mt-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit" class="prl-btn-process w-full">
                        <i class="fas fa-bolt mr-1"></i>Пнуть обновление (pull + миграции)
                    </button>
                </form>
                <p class="prl-hint mt-2" id="n100-hint">Обычно занимает 10–60 секунд. Журнал пинка — справа.</p>
            </div>

            <div class="prl-card">
                <h2 class="prl-h3"><i class="fas fa-robot mr-1.5 text-blue-500"></i>Пинок для агента (без логина)</h2>
                <?php if ($kick_on): ?>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">GET-ссылка с токеном <span class="font-mono">SITE_UPDATE_TOKEN</span> из <span class="font-mono">.env</span>:</p>
                <p class="mt-1 break-all rounded-xl bg-gray-50 p-2 font-mono text-xs text-gray-600 dark:bg-gray-900 dark:text-gray-300">/update/kick/&lt;SITE_UPDATE_TOKEN&gt;</p>
                <p class="prl-hint">Токен не показывается нигде в интерфейсе; сменили в .env — ссылка сразу новая.</p>
                <?php else: ?>
                <p class="prl-hint">Не настроено: добавьте в <span class="font-mono">.env</span> строку <span class="font-mono">SITE_UPDATE_TOKEN=длинный-случайный-токен</span> — появится GET-ссылка <span class="font-mono">/update/kick/&lt;токен&gt;</span> для агента и скриптов.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="prl-card">
            <h2 class="prl-h3"><i class="fas fa-terminal mr-1.5 text-blue-500"></i>Журнал пинка <span class="font-mono text-xs font-normal text-gray-400 dark:text-gray-500"><?php echo HTML::chars($log_path); ?></span></h2>
            <?php if (empty($log)): ?>
            <p class="prl-hint mt-2">Пока пусто — пинок ещё не запускался.</p>
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
