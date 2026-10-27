<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Аккаунт и безопасность (prl599): 2FA, сессии, журнал входов.
 */

$dt = function ($v) {
    return HTML::chars(Date::formatted_time((string) $v, 'd.m.Y H:i'));
};

$device = function ($ua) {
    $ua = (string) $ua;

    if ($ua === '')
    {
        return 'Неизвестное устройство';
    }

    $os  = stripos($ua, 'windows') !== FALSE ? 'Windows' : ((stripos($ua, 'android') !== FALSE) ? 'Android' : ((stripos($ua, 'iphone') !== FALSE || stripos($ua, 'ipad') !== FALSE) ? 'iOS' : ((stripos($ua, 'mac os') !== FALSE || stripos($ua, 'macintosh') !== FALSE) ? 'macOS' : ((stripos($ua, 'linux') !== FALSE) ? 'Linux' : ''))));
    $br  = stripos($ua, 'edg') !== FALSE ? 'Edge' : ((stripos($ua, 'opr') !== FALSE || stripos($ua, 'opera') !== FALSE) ? 'Opera' : ((stripos($ua, 'chrome') !== FALSE) ? 'Chrome' : ((stripos($ua, 'firefox') !== FALSE) ? 'Firefox' : ((stripos($ua, 'safari') !== FALSE) ? 'Safari' : ''))));

    return trim($os.($br !== '' ? ' · '.$br : ''));
};
?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Аккаунт и безопасность</span>
    </nav>

    <nav class="prl-seg mt-3" aria-label="Разделы аккаунта">
        <a href="/auth/profile" class="prl-seg-item flex-1 text-center">Профиль и данные</a>
        <span class="prl-seg-item is-active flex-1 text-center" aria-current="page">Безопасность</span>
    </nav>

    <h1 class="prl-h1 mt-2"><i class="fas fa-shield-halved mr-2 text-emerald-500"></i>Аккаунт и безопасность</h1>
    <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Двухфакторный вход (2FA / TOTP — одноразовый код из приложения-аутентификатора), активные сессии на устройствах и журнал входов.</p>

    <?php if ((string) $flash !== ''): ?>
    <div class="prl-flash prl-flash-info mt-3" role="status">
        <i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
    </div>
    <?php endif; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Контент (2/3) ============ -->
        <div class="min-w-0 space-y-4 md:col-span-2">

            <!-- 2FA -->
            <div class="prl-card">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                    <h2 class="prl-h3"><i class="fas fa-key mr-1.5 text-emerald-500"></i>Двухфакторный вход (2FA / TOTP)</h2>
                    <span class="prl-badge <?php echo $totp_enabled ? 'prl-badge-ok' : 'prl-badge-wait'; ?> uppercase"><span class="prl-dot"></span><?php echo $totp_enabled ? 'включена' : 'выключена'; ?></span>
                </div>

                <?php if ($totp_enabled): ?>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">При каждом входе сайт спросит одноразовый код из вашего приложения-аутентификатора.</p>
                <form method="post" action="/account/security" class="mt-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="disable_totp">
                    <button data-prl-arm="Выключить 2FA?" type="submit" class="prl-btn-danger"><i class="fas fa-toggle-off mr-1.5"></i>Выключить</button>
                </form>
                <?php elseif ( ! $totp_setup): ?>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Второй фактор защищает аккаунт, даже если пароль утечёт. Понадобится любое приложение-аутентификатор: Яндекс Ключ, Google Authenticator, 1Password и т.п.</p>
                <form method="post" action="/account/security" class="mt-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="begin_totp">
                    <button type="submit" class="prl-btn-cta"><i class="fas fa-qrcode mr-1.5"></i>Начать настройку</button>
                </form>
                <?php else: ?>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    <li>Откройте приложение-аутентификатор и добавьте аккаунт по QR или секретному ключу.</li>
                    <li>Введите текущий 6-значный код из приложения.</li>
                </ol>
                <div class="mt-3 flex flex-wrap items-center gap-4">
                    <!-- QR рисуется В БРАУЗЕРЕ локальной библиотекой. Раньше здесь
                         стоял <img> со стороннего сервиса генерации QR: секрет
                         второго фактора уходил в адресе запроса на чужой сервер
                         и оседал в его логах (исправлено 28.09.2026). -->
                    <div id="prl599-totp-qr" data-qr-uri="<?php echo HTML::chars($setup_uri); ?>"
                         role="img" aria-label="QR-код для приложения-аутентификатора"
                         class="h-40 w-40 shrink-0 rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-700 dark:bg-white"></div>
                    <div class="min-w-0 text-xs text-gray-500 dark:text-gray-400">
                        <p class="font-semibold text-gray-700 dark:text-gray-200">Если QR не сканируется — ключ вручную:</p>
                        <p class="mt-1 break-all rounded bg-gray-50 px-2 py-1 font-mono dark:bg-gray-900"><?php echo HTML::chars($setup_secret); ?></p>
                    </div>
                </div>
                <form method="post" action="/account/security" class="mt-4 flex flex-wrap items-center gap-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="enable_totp">
                    <label for="prl599-code" class="prl-label">Код из приложения</label>
                    <input type="text" id="prl599-code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required placeholder="000000"
                           class="prl-input w-36 text-center font-mono tracking-[0.3em]">
                    <button type="submit" class="prl-btn-cta">Включить 2FA</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Сессии -->
            <div class="prl-card">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                    <h2 class="prl-h3"><i class="fas fa-laptop mr-1.5 text-emerald-500"></i>Активные сессии</h2>
                    <form method="post" action="/account/security">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <input type="hidden" name="act" value="logout_everywhere">
                        <button data-prl-arm="Завершить на всех устройствах?" type="submit" class="prl-btn-danger"><i class="fas fa-right-from-bracket mr-1.5"></i>Выйти везде</button>
                    </form>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($sessions as $s): $cur = (string) $s['session_id'] === (string) $current_sid; ?>
                    <li class="flex flex-wrap items-center gap-2 py-3">
                        <i class="fas <?php echo $cur ? 'fa-circle-check text-emerald-500' : 'fa-circle text-gray-300 dark:text-gray-600'; ?> text-xs"></i>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo $device($s['ua']); ?><?php echo $cur ? ' <span class="prl-badge prl-badge-ok ml-1"><span class="prl-dot"></span>это устройство</span>' : ''; ?></p>
                            <p class="prl-hint"><?php echo HTML::chars((string) $s['ip']); ?> · вход <?php echo $dt($s['created_at']); ?> · активность <?php echo $dt($s['last_seen']); ?></p>
                        </div>
                        <?php if ( ! $cur): ?>
                        <form method="post" action="/account/security">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="act" value="end_session">
                            <input type="hidden" name="sid" value="<?php echo HTML::chars($s['session_id']); ?>">
                            <button type="submit" class="prl-btn-secondary prl-btn-sm">Завершить</button>
                        </form>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($sessions)): ?>
                    <li class="py-3 text-sm text-gray-500 dark:text-gray-400">Активных сессий нет — войдите заново.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Журнал -->
            <div class="prl-card">
                <h2 class="prl-h3 border-b border-gray-100 pb-3 dark:border-gray-700"><i class="fas fa-clock-rotate-left mr-1.5 text-emerald-500"></i>Журнал входов</h2>
                <?php if (empty($logins)): ?>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пока пусто.</p>
                <?php else: ?>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($logins as $l): ?>
                    <li class="flex flex-wrap items-center gap-2 py-2.5 text-xs sm:text-sm">
                        <span class="prl-count font-mono"><?php echo HTML::chars((string) $l['via'] === '2fa' ? '2FA' : 'пароль'); ?></span>
                        <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200"><?php echo $device($l['ua']); ?></span>
                        <span class="text-gray-400"><?php echo HTML::chars((string) $l['ip']); ?></span>
                        <span class="font-mono text-xs text-gray-400"><?php echo $dt($l['created_at']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="prl-hint mt-3">Хранится 90 дней, старые записи удаляются автоматически.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ Компаньон (1/3) ============ -->
        <div class="hidden space-y-5 md:block">
            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-circle-question text-emerald-500"></i>
                    <h3 class="text-sm font-bold">Как работает 2FA</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Код TOTP живёт 30 секунд и работает офлайн — приложение не нужно в сети.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Код принимается с допуском ±30 с — если код «на грани», введите следующий.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Не потеряйте доступ к приложению: выключите 2FA заранее, если меняете телефон.</span></li>
                </ul>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">«Выйти везде»</h3>
                </div>
                <p class="mt-2.5 text-xs text-gray-600 dark:text-gray-300">Завершает все сессии на всех устройствах, включая текущую. Пригодится, если утерян телефон или вы входили с чужого компьютера.</p>
            </div>
        </div>
    </div>
</div>

<script src="/assets/vendor/qrcode/qrcode.js"></script>
<?php echo View::factory('599/script'); ?>
