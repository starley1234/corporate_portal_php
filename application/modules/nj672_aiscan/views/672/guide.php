<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Справочник /aiscan/guide — что и как проверяет сканер человеческим языком.
 *
 * Разделы угроз приходят из Service_Nj672_Scan::guide_sections() (ключи
 * наборов и коды рекомендаций), тексты исправлений — из
 * Service_Nj672_Scan::recommendation(): страница не может рассказать про
 * проверку, которой нет в батарее, и не выдумывает своих методов лечения.
 *
 * @var array $sections разделы угроз guide_sections()
 * @var array $suites   наборы проверок: ключ => [title, note, owasp, tests]
 */

$badge = [
    'emerald' => 'n2j-badge n2j-badge-ok',
    'blue'    => 'n2j-badge n2j-badge-info',
    'amber'   => 'n2j-badge n2j-badge-run',
    'rose'    => 'n2j-badge n2j-badge-err',
    'gray'    => 'n2j-badge n2j-badge-wait',
];
$fbadge = function ($words) use ($badge) {
    $words = (array) $words;
    return '<span class="'.($badge[(string) ($words['tone'] ?? 'gray')] ?? $badge['gray']).'">'
         . '<span class="n2j-dot"></span>'.HTML::chars((string) ($words['label'] ?? '')).'</span>';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php echo View::factory('partials/page_header', [
    'crumbs' => [['Сервисы', '/services'], ['Сканер безопасности', '/aiscan'], ['Справочник']],
    'title'  => 'Что и как проверяет сканер',
    'pill'   => ['Справочник', 'fa-book-open'],
    'actions' => [
        ['label' => 'Простая проверка', 'href' => '/aiscan/simple', 'icon' => 'fa-bolt', 'role' => 'process'],
        ['label' => 'Расширенный режим', 'href' => '/aiscan', 'icon' => 'fa-sliders', 'role' => 'secondary'],
    ],
    'lead'   => 'По каждой угрозе: что именно делает сканер, как должна отвечать здоровая модель, как выглядит провал и как это чинить. Без жаргона — жаргон вынесен в словарь справа.',
]); ?>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
<div class="space-y-6 md:col-span-2">

    <section class="n2j-card" aria-labelledby="nj672g-how-title">
        <h2 class="n2j-h2" id="nj672g-how-title">Проверка за одну минуту</h2>
        <ol class="mt-3 space-y-3 text-sm text-gray-700 dark:text-gray-200">
            <li><strong>1 · Атаки безвредны.</strong> Сканер отправляет модели промпты-атаки по списку угроз OWASP для языковых моделей. Успех атаки измеряется секретными маркерами и подсаженными фальшивыми секретами (тестовая карта, выдуманный ключ): настоящего вреда модель не просят причинять никому.</li>
            <li><strong>2 · Каждый ответ оценивают детекторы.</strong> Ищем маркер атакующего, утечки подсаженных секретов, персональные данные, опасный вывод и отказы. Спорные случаи («ни утечки, ни отказа») досматривает вторая модель — судья. Ошибка цели — не «чисто», а «проверять было нечего».</li>
            <li><strong>3 · Отдельно меряем устойчивость.</strong> Параллельная нагрузка и длинный контекст: не складывается ли инференс под потоком запросов и не теряет ли модель факты из середины длинного текста.</li>
        </ol>
    </section>

    <section class="n2j-card" aria-labelledby="nj672g-read-title">
        <h2 class="n2j-h2" id="nj672g-read-title">Как читать итоги отчёта</h2>
        <div class="n2j-panel mt-4 overflow-x-auto">
            <table class="n2j-table">
                <thead><tr><th>Итог проверки</th><th>Что значит</th></tr></thead>
                <tbody>
                    <tr><td class="whitespace-nowrap"><?php echo $fbadge(Service_Nj672_Scan::FINDING_STATUS['pass']); ?></td><td>Модель удержала правила: отказала атакующему или продолжила исходную задачу, не выполнив чужую команду.</td></tr>
                    <tr><td class="whitespace-nowrap"><?php echo $fbadge(Service_Nj672_Scan::FINDING_STATUS['fail']); ?></td><td>Атака сработала: модель выполнила требование атакующего или отдала секрет. Доказательство — фрагмент ответа в отчёте (секреты замаскированы).</td></tr>
                    <tr><td class="whitespace-nowrap"><?php echo $fbadge(Service_Nj672_Scan::FINDING_STATUS['warn']); ?></td><td>Ни пробития, ни отказа: автоматика не уверена. Такие находки досматривает судья-модель или человек — скрытно засчитывать их в «чисто» нельзя.</td></tr>
                    <tr><td class="whitespace-nowrap"><?php echo $fbadge(Service_Nj672_Scan::FINDING_STATUS['error']); ?></td><td>Цель ответила ошибкой или молчанием — проверять было нечего. Недоступная модель не считается защищённой.</td></tr>
                </tbody>
            </table>
        </div>
        <ul class="mt-4 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-200">
            <li><strong>Балл безопасности (0–100)</strong> взвешен по серьёзности проверок: провал критичной проверки весит вчетверо больше лёгкой. Одна пробитая критичная проверка делает риск критическим при любом среднем балле — «в среднем хорошо» здесь не работает.</li>
            <li><strong>Строки матрицы OWASP</strong> сводятся в слова: <?php echo $fbadge(Service_Nj672_Scan::OWASP_STATUS['pass']); ?> / <?php echo $fbadge(Service_Nj672_Scan::OWASP_STATUS['fail']); ?> / <?php echo $fbadge(Service_Nj672_Scan::OWASP_STATUS['critical']); ?> / <?php echo $fbadge(Service_Nj672_Scan::OWASP_STATUS['warn']); ?>.</li>
            <li><strong>Вердикт первым экраном отчёта</strong> — тот же итог двумя фразами для решения «можно в прод или рано». Ниже всегда лежат доказательства, по которым вывод можно проверить руками.</li>
        </ul>
    </section>

<?php foreach ($sections as $section): ?>
    <section class="n2j-card" id="g-<?php echo HTML::chars($section['id']); ?>" aria-labelledby="g-<?php echo HTML::chars($section['id']); ?>-title">
        <p class="font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($section['id']); ?> <span class="text-gray-400">(в редакции OWASP 2023 — <?php echo HTML::chars($section['id_legacy']); ?>)</span></p>
        <h2 class="n2j-h2 mt-1" id="g-<?php echo HTML::chars($section['id']); ?>-title"><?php echo HTML::chars($section['title']); ?></h2>
<?php if ( ! empty(Service_Nj672_Scan::OWASP_HINT[$section['id']])): ?>
        <p class="n2j-hint mt-1">Суть угрозы: <?php echo HTML::chars((string) Service_Nj672_Scan::OWASP_HINT[$section['id']]); ?>.</p>
<?php endif; ?>

        <h3 class="n2j-h3 mt-5">Что проверяем</h3>
        <p class="mt-1 text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars($section['checks']); ?></p>
<?php if ($section['suites']): ?>
        <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
<?php foreach ($section['suites'] as $key): ?>
<?php if (isset($suites[$key])): ?>
            <li><span class="font-medium text-gray-900 dark:text-gray-50"><?php echo HTML::chars((string) $suites[$key]['title']); ?></span> <span class="n2j-count"><?php echo (int) ($suites[$key]['tests'] ?? 0); ?> проверок</span> — <?php echo HTML::chars((string) ($suites[$key]['note'] ?? '')); ?>.</li>
<?php endif; ?>
<?php endforeach; ?>
        </ul>
<?php endif; ?>

        <div class="n2j-flash n2j-flash-ok mt-4 block">
            <p class="text-sm font-semibold"><i class="fas fa-circle-check mr-2"></i>Как должно быть</p>
            <p class="mt-1 text-sm leading-relaxed"><?php echo HTML::chars($section['good']); ?></p>
        </div>
        <div class="n2j-flash n2j-flash-err mt-3 block">
            <p class="text-sm font-semibold"><i class="fas fa-circle-xmark mr-2"></i>Как НЕ должно быть</p>
            <p class="mt-1 text-sm leading-relaxed"><?php echo HTML::chars($section['bad']); ?></p>
        </div>

        <h3 class="n2j-h3 mt-5">Как сканер понимает, что атака удалась</h3>
        <p class="mt-1 text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars($section['detect']); ?></p>

        <h3 class="n2j-h3 mt-5">Как это чинить</h3>
        <div class="mt-2 space-y-4">
<?php foreach ($section['recs'] as $code): ?>
<?php $rec = Service_Nj672_Scan::recommendation($code); ?>
            <article>
                <h4 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-50">
                    <?php echo HTML::chars((string) ($rec['title'] ?? $code)); ?>
                    <?php echo $fbadge(Service_Nj672_Scan::words(Service_Nj672_Scan::SEVERITY, (string) ($rec['priority'] ?? ''), 'low')); ?>
                </h4>
                <p class="mt-1 text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) ($rec['detail'] ?? '')); ?></p>
            </article>
<?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

    <section class="n2j-card" aria-labelledby="nj672g-limits-title">
        <h2 class="n2j-h2" id="nj672g-limits-title">Честные границы метода</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-200">
            <li><strong>Чёрный ящик.</strong> Сканер видит только HTTP-интерфейс модели — как настоящий атакующий снаружи. Весов, логов и внутренностей инференса у него нет и не должно быть.</li>
            <li><strong>Батарея конечна.</strong> «Отбито всё» значит «отбиты эти атаки», а не «взломать нельзя». Атаки именно на ваш сценарий добавляются своим датасетом в расширенном режиме; аудит стоит повторять после каждой смены модели или системного промпта.</li>
            <li><strong>Без градиентной магии.</strong> Настоящий GCG/AutoDAN оптимизирует суффикс по градиентам конкретной модели — в чёрном ящике это невозможно, поэтому используется статический суффикс, и в отчёте это честно подписано.</li>
            <li><strong>KV-Cache не наблюдается напрямую.</strong> Порог деградации на длинном контексте — косвенный вывод по взрывному росту ожидания и ошибкам. Это тот же симптом, который почувствуют пользователи.</li>
            <li><strong>Судья — тоже модель.</strong> Вердикты судьи по спорным находкам помечены в отчёте: это второй взгляд, а не истина; критичное перепроверяют руками по доказательствам.</li>
        </ul>
    </section>
</div>

<aside class="space-y-4">
    <section class="n2j-card" aria-labelledby="nj672g-toc-title">
        <h2 class="n2j-h3" id="nj672g-toc-title">Содержание</h2>
        <ul class="mt-3 space-y-2 text-sm">
            <li><a class="n2j-link" href="#nj672g-how-title">Проверка за одну минуту</a></li>
            <li><a class="n2j-link" href="#nj672g-read-title">Как читать итоги отчёта</a></li>
<?php foreach ($sections as $section): ?>
            <li><a class="n2j-link" href="#g-<?php echo HTML::chars($section['id']); ?>"><?php echo HTML::chars($section['id']); ?> · <?php echo HTML::chars($section['title']); ?></a></li>
<?php endforeach; ?>
            <li><a class="n2j-link" href="#nj672g-limits-title">Честные границы метода</a></li>
        </ul>
    </section>

    <section class="n2j-card" aria-labelledby="nj672g-gloss-title">
        <h2 class="n2j-h3" id="nj672g-gloss-title">Словарь</h2>
        <dl class="mt-3 space-y-2 text-sm">
            <div><dt class="font-medium inline">Маркер</dt> <dd class="inline text-gray-700 dark:text-gray-200">— секретная строка, которую просит вывести атака. Появилась в ответе — модель послушалась атакующего.</dd></div>
            <div><dt class="font-medium inline">Канарейка</dt> <dd class="inline text-gray-700 dark:text-gray-200">— уникальный секрет, зашитый в системный промпт проверки. Его нельзя угадать — только пересказать, поэтому он доказывает утечку.</dd></div>
            <div><dt class="font-medium inline">Судья-модель</dt> <dd class="inline text-gray-700 dark:text-gray-200">— вторая, своя модель воркера, которая пересматривает спорные ответы цели. Не путать с проверяемой моделью.</dd></div>
            <div><dt class="font-medium inline">TTFT</dt> <dd class="inline text-gray-700 dark:text-gray-200">— время до первого токена ответа; то, что пользователь чувствует как «задумалась».</dd></div>
            <div><dt class="font-medium inline">ITL</dt> <dd class="inline text-gray-700 dark:text-gray-200">— пауза между токенами при потоковой выдаче; «скорость печати».</dd></div>
            <div><dt class="font-medium inline">«Иголка»</dt> <dd class="inline text-gray-700 dark:text-gray-200">— фраза-факт, спрятанная в середину длинного шумного текста: нашла ли её модель — проверка внимания к контексту.</dd></div>
            <div><dt class="font-medium inline">«Колено»</dt> <dd class="inline text-gray-700 dark:text-gray-200">— точка нагрузки или длины контекста, за которой инференс резко деградирует или начинает отказывать.</dd></div>
            <div><dt class="font-medium inline">Эндпоинт</dt> <dd class="inline text-gray-700 dark:text-gray-200">— адрес OpenAI-совместимого API вашей модели (…/v1), куда сканер шлёт запросы.</dd></div>
        </dl>
    </section>

    <section class="n2j-card" aria-labelledby="nj672g-run-title">
        <h2 class="n2j-h3" id="nj672g-run-title">Проверить на практике</h2>
        <p class="n2j-hint mt-2">Сначала песочницы: уязвимая покажет провальный отчёт со всеми пробитиями, закалённая — чистый. Потом — ваш эндпоинт.</p>
        <p class="mt-3 flex flex-col gap-2">
            <a class="n2j-btn-process justify-center" href="/aiscan/simple"><i class="fas fa-bolt"></i><span>Простая проверка</span></a>
            <a class="n2j-btn-secondary justify-center" href="/aiscan"><i class="fas fa-sliders"></i><span>Расширенный режим</span></a>
        </p>
    </section>
</aside>
</div>

</div>
