<?php defined('SYSPATH') or die('No direct script access.');

/** @var string $csrf токен формы для явного запроса к ФНС */
$input = 'n2j-input mt-1.5 w-full text-xl font-semibold tracking-wide';
$label = 'n2j-label';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <nav aria-label="Хлебные крошки" class="mb-3 text-xs text-gray-500 dark:text-gray-400"><a href="/services" class="hover:text-blue-600">Сервисы</a><span class="mx-1.5">/</span><span>Проверка ИНН</span></nav>
    <header class="mb-5">
        <h1 class="n2j-h1">Проверить ИНН: контрольные цифры и сведения ФНС</h1>
        <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">Сначала проверьте номер прямо в браузере. Если это ИНН организации, отдельным действием можно запросить её открытую карточку из ФНС.</p>
    </header>

    <div class="n2j-card">
        <form id="inn-form" autocomplete="off" novalidate>
            <label for="inn-number" class="<?php echo $label; ?>">ИНН — 10 или 12 цифр</label>
            <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
                <input id="inn-number" type="text" inputmode="numeric" maxlength="32" spellcheck="false"
                       aria-describedby="inn-privacy-note" placeholder="Например, 7707083893"
                       class="<?php echo $input; ?> sm:flex-1">
                <button type="submit" class="n2j-btn-process">
                    <i class="fas fa-shield-alt"></i> Проверить номер
                </button>
            </div>
            <p class="n2j-hint mt-2">Можно вставить номер с пробелами или дефисами. Проверка контрольных цифр работает прямо в браузере.</p>
        </form>
    </div>

    <div id="inn-result" class="mt-4" aria-live="polite" aria-atomic="true">
        <div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
            Введите номер: сервис проверит длину и контрольные цифры, не отправляя их на сервер.
        </div>
    </div>

    <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-sm text-blue-950 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-100" id="inn-privacy-note">
        <p class="font-semibold"><i class="fas fa-user-shield mr-1.5"></i>Номер не попадает в адрес страницы</p>
        <p class="mt-1">Проверка цифр выполняется локально. Только для 10-значного ИНН организации можно отдельно нажать кнопку запроса в ФНС: номер уйдёт в открытый реестр, а найденная карточка сохранится в общем справочнике N2J. 12-значный ИНН физлица/ИП остаётся только в браузере.</p>
    </div>

</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200"><i class="fas fa-circle-check mr-1.5 text-emerald-500"></i>Что именно проверяется</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li><b>10 цифр</b> — ИНН юридического лица.</li>
            <li><b>12 цифр</b> — ИНН физического лица, в том числе ИП.</li>
            <li>Контрольные цифры помогают обнаружить опечатку, но сами по себе не подтверждают, что номер присвоен или что организация действует.</li>
            <li>Актуальные сведения показываются только после отдельного запроса открытого реестра ФНС.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200"><i class="fas fa-building mr-1.5 text-blue-500"></i>Что дальше</p>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">Если нашли организацию, откройте карточку в справочнике или перейдите к проверке контрагента и наблюдению за изменениями.</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="/orgs" class="n2j-chip">Справочник организаций</a>
            <a href="/check" class="n2j-chip">Проверка контрагента</a>
        </div>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200"><i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>Что означает успешная проверка?</summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">Она подтверждает формат и контрольные цифры номера. Факт регистрации и текущий статус можно подтвердить только по открытым сведениям ФНС.</p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200"><i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>Почему для 12 цифр нет поиска в ФНС?</summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">12-значный ИНН относится к физическому лицу или ИП. Чтобы не передавать такой номер без необходимости, он проверяется только в браузере. Онлайн-поиск доступен для 10-значного ИНН организации.</p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200"><i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>Сохраняются ли введённые цифры?</summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">Локальная проверка ничего не отправляет и не сохраняет. При отдельном запросе для организации сведения берутся из открытого реестра ФНС и добавляются в общий справочник организаций N2J.</p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Что означает успешная проверка ИНН?", "acceptedAnswer": {"@type": "Answer", "text": "Проверка подтверждает формат и контрольные цифры номера. Факт регистрации и текущий статус можно подтвердить только по открытым сведениям ФНС."}},
            {"@type": "Question", "name": "Почему для 12-значного ИНН нет поиска в ФНС?", "acceptedAnswer": {"@type": "Answer", "text": "12-значный ИНН относится к физическому лицу или ИП. Чтобы не передавать такой номер без необходимости, он проверяется только в браузере. Онлайн-поиск доступен для 10-значного ИНН организации."}},
            {"@type": "Question", "name": "Сохраняется ли введённый ИНН?", "acceptedAnswer": {"@type": "Answer", "text": "Локальная проверка ничего не отправляет и не сохраняет. При отдельном запросе для организации сведения берутся из открытого реестра ФНС и добавляются в общий справочник организаций N2J."}}
          ]
        }
        </script>
    </div>

    <p class="n2j-hint px-1">Справочный инструмент, а не подтверждение юридического статуса или благонадёжности. Перед сделкой сверяйте данные в официальном реестре и проверяйте дату сведений.</p>
</aside>
</div>
</div>

<?php echo View::factory('517/script', ['csrf' => $csrf]);
