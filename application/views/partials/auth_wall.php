<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Плашка «Сервис для зарегистрированных» — ОБЩАЯ для всех страниц платформы.
 *
 * Гость видит её вместо рабочей формы: что даёт сервис, почему нужен аккаунт и
 * одна кнопка входа. Так устроены /receipt, /meal, /plant (решение владельца
 * 06.10.2026), и по этому же образцу оформляются все «закрытые» страницы —
 * чтобы у сервиса для зарегистрированных был один узнаваемый вид, а не
 * строчка «войдите» в углу карточки.
 *
 * Кнопка одна и главная (дизайн v6: главное действие — .prx-btn-cta, emerald;
 * на экране ОДНА ключевая кнопка): вход и регистрация живут на одной странице
 * /auth/login (там же ссылка на /auth/register), поэтому вторая кнопка не нужна.
 * Возврат после входа — `?return_url=`, его читает Controller_Auth.
 *
 * Параметры (все необязательные, кроме осмысленных title/text):
 *   return_url — локальный путь (со строкой запроса), куда вернуть после входа
 *                («radar?title=окна», «veclab», «docs»);
 *   icon       — класс Font Awesome («fas fa-satellite-dish»);
 *   title      — заголовок плашки;
 *   text       — одна фраза, зачем нужен аккаунт;
 *   points     — массив строк «что вы получите после входа» (галочки);
 *   cta        — надпись на кнопке;
 *   note       — мелкая сноска под кнопкой;
 *   stats      — массив подписей-фактов под текстом («каждые 30 минут», «Beta»);
 *   compact    — узкий вариант (внутренности карточки, меньше воздуха);
 *   tone       — 'blue' (умолчание) | 'amber' (внимание, а не приглашение).
 *
 * Полос прогресса и «заглушек» внутри нет: только текст, галочки и кнопка.
 */

$aw_return  = (string) ($return_url ?? '');
$aw_icon    = (string) ($icon ?? 'fas fa-user-lock');
$aw_title   = (string) ($title ?? 'Раздел для зарегистрированных');
$aw_text    = (string) ($text ?? 'Сервис тратит время модели и очередь задач, поэтому работает по бесплатному аккаунту — так доступ достаётся людям, а не ботам.');
$aw_points  = array_values(array_filter(array_map('strval', (array) ($points ?? []))));
$aw_stats   = array_values(array_filter(array_map('strval', (array) ($stats ?? []))));
$aw_cta     = (string) ($cta ?? 'Войти или зарегистрироваться');
$aw_note    = (string) ($note ?? 'Аккаунт бесплатный: вход и регистрация — на одном экране. Результаты сохраняются и открываются с любого устройства.');
$aw_compact = ! empty($compact);
$aw_tone    = (string) ($tone ?? 'blue') === 'amber' ? 'amber' : 'blue';

$aw_login = '/auth/login'.($aw_return !== '' ? '?return_url='.rawurlencode($aw_return) : '');

$aw_wrap = $aw_tone === 'amber'
    ? 'border-amber-300 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/30'
    : 'border-blue-200 bg-blue-50/70 dark:border-blue-900/70 dark:bg-blue-950/30';

$aw_badge = $aw_tone === 'amber' ? 'prx-badge-run' : 'prx-badge-info';

$aw_pad = $aw_compact ? 'p-4' : 'p-4 sm:p-5';
?>

<section class="rounded-xl border <?php echo $aw_wrap; ?> <?php echo $aw_pad; ?> shadow-xs"
         data-auth-wall="<?php echo HTML::chars($aw_return !== '' ? $aw_return : 'page'); ?>">

    <span class="prx-badge uppercase <?php echo $aw_badge; ?>"><span class="prx-dot"></span>Только для зарегистрированных</span>

    <div class="mt-3 flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/80 text-blue-600 shadow-xs dark:bg-gray-900/60 dark:text-blue-300">
            <i class="<?php echo HTML::chars($aw_icon); ?>" aria-hidden="true"></i>
        </span>
        <div class="min-w-0">
            <h2 class="prx-h3"><?php echo HTML::chars($aw_title); ?></h2>
            <?php if ($aw_text !== ''): ?>
            <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($aw_text); ?></p>
            <?php endif; ?>

            <?php if ($aw_stats): ?>
            <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <?php foreach ($aw_stats as $aw_i => $aw_stat): ?>
                <?php if ($aw_i > 0): ?><span class="text-gray-300 dark:text-gray-600" aria-hidden="true">·</span><?php endif; ?>
                <span><?php echo HTML::chars($aw_stat); ?></span>
                <?php endforeach; ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($aw_points): ?>
    <ul class="mt-3 space-y-1.5 text-sm leading-relaxed text-gray-700 dark:text-gray-200">
        <?php foreach ($aw_points as $aw_point): ?>
        <li class="flex gap-2">
            <i class="fas fa-check mt-1 text-[11px] text-emerald-500" aria-hidden="true"></i>
            <span><?php echo HTML::chars($aw_point); ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
        <a href="<?php echo HTML::chars($aw_login); ?>"
           class="prx-btn-cta w-full sm:w-auto">
            <i class="fas fa-right-to-bracket" aria-hidden="true"></i><span><?php echo HTML::chars($aw_cta); ?></span>
        </a>
        <span class="text-center text-xs text-gray-500 sm:text-left dark:text-gray-400">Регистрация — там же, занимает минуту</span>
    </div>

    <?php if ($aw_note !== ''): ?>
    <p class="mt-2.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars($aw_note); ?></p>
    <?php endif; ?>
</section>
