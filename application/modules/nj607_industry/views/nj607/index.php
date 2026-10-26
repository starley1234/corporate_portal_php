<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj607_industry: /industry — авто-карта отрасли (5 кредитов) и заказ
 * ручной разведки (бесплатная заявка). Ниже — мои отчёты.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$inp  = 'n2j-input mt-1 w-full';
?>
<div class="n2j-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="grid gap-6 md:grid-cols-2 md:items-start">
        <!-- Авто-карта -->
        <div class="<?php echo $card; ?>">
            <h1 class="n2j-h1"><i class="fas fa-map mr-1.5 text-blue-500"></i>Карта отрасли · авто</h1>
            <p class="n2j-hint">Воркер обходит открытые источники и собирает отчёт: игроки и их сайты, что и как производят, сегменты, цены (где видны), каналы, возможности входа.</p>
            <?php if ( ! $logged): ?>
            <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50/60 p-3.5 dark:border-amber-800 dark:bg-amber-950/20">
                <p class="text-sm font-bold text-gray-900 dark:text-white">Что вы получите после <a href="/auth/login?return_url=industry" class="text-blue-600 underline dark:text-blue-400">входа</a>:</p>
                <ul class="mt-2 space-y-1.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i>Карта отрасли: кто производит, что и по каким ценам</li>
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i>Каналы продаж и возможности входа для вашей ниши</li>
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i>Готовый отчёт через 20–40 минут — можно заказывать по конкретной отрасли</li>
                </ul>
            </div>
            <?php else: ?>
            <form method="post" action="/industry" class="mt-3 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="self">
                <input type="text" name="industry" required maxlength="300" placeholder="Отрасль или продукт: производители насосов"
                       autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                       value="<?php echo HTML::chars((string) $prefill['industry']); ?>"
                       data-nj607-industry class="<?php echo $inp; ?>">
                <input type="text" name="region" maxlength="120" placeholder="Регион (необязательно)"
                       autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                       value="<?php echo HTML::chars((string) $prefill['region']); ?>"
                       data-nj607-region class="<?php echo $inp; ?>">
                <button type="submit" class="n2j-btn-process w-full">
                    Собрать карту<?php if (Service_Nj601_Billing::enabled()): ?><span class="ml-1 rounded bg-blue-500/40 px-1.5 py-0.5 text-xs">5 кредитов</span><?php endif; ?>
                </button>
                <p class="n2j-hint">Отчёт готовится 3–10 минут, появится в списке ниже.</p>

                <?php
                // Подсказки — из нашей базы (что уже спрашивали), а не из
                // автозаполнения браузера: владелец просил его не использовать.
                $hints = [];

                foreach ((array) ($popular['industry'] ?? []) as $h)
                {
                    if (trim((string) $h['v']) !== '')
                    {
                        $hints[] = ['i', (string) $h['v']];
                    }
                }

                foreach ((array) ($popular['region'] ?? []) as $h)
                {
                    if (trim((string) $h['v']) !== '')
                    {
                        $hints[] = ['r', (string) $h['v']];
                    }
                }
                ?>
                <?php if ( ! empty($hints)): ?>
                <div class="mt-3 border-t border-gray-100 pt-2.5 dark:border-gray-700/60">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Часто спрашивают</p>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <?php foreach ($hints as $h): ?>
                        <button type="button" data-nj607-fill="<?php echo $h[0]; ?>" data-value="<?php echo HTML::chars($h[1]); ?>"
                                class="n2j-btn-secondary n2j-btn-sm">
                            <i class="fas <?php echo $h[0] === 'r' ? 'fa-location-dot' : 'fa-industry'; ?> text-[10px] text-gray-400"></i><?php echo HTML::chars($h[1]); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>

        <!-- Ручная разведка -->
        <div class="<?php echo $card; ?> border-amber-300 dark:border-amber-700">
            <h2 class="n2j-h3"><i class="fas fa-user-tie mr-1.5 text-amber-500"></i>Разведка под запрос · вручную</h2>
            <p class="n2j-hint">Глубокая выверенная разведка: 30–50 производителей с контактами и реквизитами, как и что производят, 20 готовых черновиков писем. Срок 1–3 дня, обсуждается индивидуально.</p>
            <form method="post" action="/industry" class="mt-3 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="order">
                <input type="text" name="industry" required maxlength="300" placeholder="Задача: поставщики ёмкостного оборудования для пищепрома"
                       autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                       value="<?php echo HTML::chars((string) $prefill['industry']); ?>"
                       class="<?php echo $inp; ?>">
                <input type="text" name="region" maxlength="120" placeholder="Регион (необязательно)"
                       autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                       value="<?php echo HTML::chars((string) $prefill['region']); ?>"
                       class="<?php echo $inp; ?>">
                <input type="text" name="contact" required maxlength="300" placeholder="Контакт: email или телефон"
                       autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                       class="<?php echo $inp; ?>">
                <button type="submit" class="n2j-btn-cta w-full">
                    Оставить заявку — бесплатно
                </button>
            </form>
        </div>
    </div>

    <?php if ( ! empty($reports)): ?>
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3">Мои отчёты и заявки</h2>
        <ul class="mt-2 divide-y divide-gray-50 dark:divide-gray-700/40">
            <?php foreach ($reports as $rep): ?>
            <li class="flex flex-wrap items-center gap-2 py-2 text-sm">
                <i class="fas <?php echo $rep['kind'] === 'order' ? 'fa-user-tie text-amber-500' : 'fa-map text-blue-500'; ?> w-4"></i>
                <?php if ($rep['kind'] === 'self'): ?>
                <a href="/industry/<?php echo HTML::chars($rep['code']); ?>" class="min-w-0 flex-1 truncate font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $rep['industry']); ?></a>
                <?php else: ?>
                <span class="min-w-0 flex-1 truncate font-semibold text-gray-800 dark:text-gray-200">[заявка] <?php echo HTML::chars((string) $rep['industry']); ?></span>
                <?php endif; ?>
                <span class="n2j-badge <?php echo $rep['status'] === 'done' ? 'n2j-badge-ok' : ((string) $rep['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-wait'); ?> uppercase"><?php echo HTML::chars(Service_Nj607_Industry::status_label((string) $rep['status'])); ?></span>
                <span class="text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $rep['created_at'], 'd.m H:i')); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

<script>
/* nj607/index: подсказка из нашей базы подставляет значение в поле формы
   («Часто спрашивают»). Автозаполнение браузера для этих полей отключено. */
(function () {
    document.querySelectorAll('[data-nj607-fill]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var kind = btn.getAttribute('data-nj607-fill');
            var target = document.querySelector(kind === 'r' ? '[data-nj607-region]' : '[data-nj607-industry]');
            if (!target) return;
            target.value = btn.getAttribute('data-value') || '';
            target.focus();
        });
    });
})();
</script>
