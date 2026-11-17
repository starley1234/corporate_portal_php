<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx599: второй шаг входа — одноразовый код TOTP из приложения-аутентификатора.
 * Пейлоад сессии живёт 10 минут, максимум 5 попыток (см. Controller_Auth::action_2fa).
 */
?>

<div class="prx-ds w-full px-3.5 pt-6 pb-16 sm:px-6 lg:px-8">

    <div class="prx-card">

        <div class="flex items-center gap-3 border-b border-gray-100 pb-4 dark:border-gray-700/60">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300"><i class="fas fa-shield-halved text-lg"></i></span>
            <div>
                <h1 class="prx-h1">Двухфакторный вход</h1>
                <p class="prx-hint mt-0.5">Пароль принят. Введите 6-значный код из приложения-аутентификатора — код обновляется каждые 30 секунд.</p>
            </div>
        </div>

        <?php if ( ! empty($errors)): ?>
        <div class="prx-flash prx-flash-err mt-4 flex items-center gap-3 text-xs font-semibold">
            <i class="fas fa-triangle-exclamation shrink-0"></i>
            <span><?php echo HTML::chars(implode(' ', (array) $errors)); ?></span>
        </div>
        <?php endif; ?>

        <form method="post" action="/auth/2fa" class="mt-5 max-w-sm">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

            <label for="prx599-code" class="prx-label">Одноразовый код</label>
            <input type="text" id="prx599-code" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" autofocus
                   class="prx-input mt-1.5 w-full text-center font-mono tracking-[0.4em]">

            <button type="submit" class="prx-btn-cta mt-4 w-full">
                <i class="fas fa-unlock mr-2"></i>Подтвердить и войти
            </button>

            <p class="prx-hint mt-3">
                Не подходит код? Проверьте время на устройстве — он живёт одну «тридцатку».
                <a href="/auth/login" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Начать вход заново</a>.
            </p>
        </form>
    </div>
</div>
