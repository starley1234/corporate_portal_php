<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /architecture/guide — «Как устроена платформа»: философия, архитектура, имена.
 * Одна страница для программиста; подробности — по ссылкам на документы репозитория.
 *
 * Переменные: $built_at (когда собрана схема /architecture).
 */
$philosophy = [
    ['fa-cubes', 'Модуль — владелец', 'Схема, миграции, логика, вьюхи и канал воркера живут в <code>application/modules/prxNNN_slug/</code>. Чужие таблицы не читают: только публичный Service, событие, HMVC-виджет или очередь.', 'AGENTS.md §2, docs/MODULE_GUIDE.md'],
    ['fa-lock', 'Запрещено по умолчанию', '«Кто действует» — <code>$this->actor()</code>, «можно ли запись» — политика модуля (<code>Prx_Policy</code>). POST без CSRF не проходит глобально (<code>Prx_PostGuard</code>). Скрытая кнопка — не защита.', 'docs/FOUNDATION.md'],
    ['fa-palette', 'Одна дизайн-система', 'Только v6: page-kit из партиалов, классы <code>.prx-*</code>, поведение <code>PRX.*</code>. Без CDN, без своих <code>&lt;style&gt;</code>; тёмная тема и мобильные — сразу, не потом.', 'docs/DESIGN-SYSTEM.md'],
    ['fa-glasses', 'Читаемо человеку и агенту', 'Лаконично, без лишних сущностей. Манифест — контракт модуля, памятка AGENTS.md — его состояние. Что признано лишним — не возвращать (110 модулей, витрина дизайна, archive/, перенос закладок).', 'docs/ARCHITECTURE-REVIEW.md'],
];

$request_steps = [
    ['fa-display', 'Браузер', 'HTML из вьюх, <code>/assets/*</code> локально. Формы несут <code>csrf</code>, fetch — <code>X-CSRF-Token</code>.'],
    ['fa-route', 'Маршрут', '<code>Route::set(\'prxNNN_slug…\')</code> в <code>init.php</code> модуля. Реестр сверяет <code>route_registry.py</code>.'],
    ['fa-hand-pointer', 'Контроллер', 'Тонкий: <code>$this->actor()->require(\'prxNNN.view\')</code> → вызов Service → View или JSON. Без SQL и бизнес-логики.'],
    ['fa-gears', 'Сервис + политика', '<code>Service_PrxNNN_*</code> — логика; доступ к записи — <code>Policy_PrxNNN_*</code>; SQL — только через <code>Prx_Db</code>.'],
    ['fa-database', 'Данные', 'Своя таблица <code>prx_<id>_*</code>, миграция в <code>migrations_pg/</code>. Схему не меняет никто, кроме модуля.'],
];

$branches = [
    ['fa-hourglass-half', 'Долго?', 'Очередь <code>prx120_jobs</code> → Python-воркер берёт канал <code>handlers/&lt;модуль&gt;/</code> → отдаёт результат в PHP. Токен воркера — не CSRF.'],
    ['fa-bolt', 'Что-то произошло?', '<code>Event::publish(\'prxNNN.entity.action\', [...], $initiator_id)</code> → журнал и слушатели prx208 → при необходимости уведомления prx597 (колокольчик, почта, мессенджер). Payload — только ID и скаляры.'],
];

$names = [
    ['Модуль', '<code>prxNNN_slug</code>', '<code>prx399_docmerge</code> — папка и slug манифеста; ID не переиспользуются'],
    ['Контроллер · маршрут', '<code>Controller_PrxNNN_Name</code> · <code>prxNNN_…</code>', '<code>Controller_Prx399_Api</code>, <code>/prx399/api/&lt;action&gt;</code>'],
    ['Сервис', '<code>Service_PrxNNN_Name</code>', '<code>Service_Prx399_Merge</code> — статические методы, принимают <code>Prx_Actor</code>'],
    ['Политика', '<code>Policy_PrxNNN_Name</code> в <code>classes/Policy/PrxNNN/</code>', '<code>Policy_Prx397_Extraction extends Prx_Policy</code>'],
    ['Модель · таблица', '<code>Model_PrxNNN_Name</code> · <code>prx_&lt;id&gt;_&lt;slug&gt;</code>', '<code>prx_399_merges</code> — схема только через миграции модуля'],
    ['Право (ACL)', '<code>prxNNN.ability</code>', '<code>prx399.view</code>, <code>prx399.manage</code> — в <code>config/acl.php</code>'],
    ['Переменные окружения', '<code>PRXNNN_&lt;SLUG&gt;_*</code>', '<code>PRX399_DOCMERGE_MAX_CHARS</code> — и в <code>.env</code>, и в <code>workers/.env.example</code>'],
    ['Событие', '<code>prxNNN.entity.action</code>', '<code>prx397.invoice.submitted</code> — имя это контракт для слушателей'],
    ['Канал воркера', '<code>&lt;domain&gt;.&lt;action&gt;</code> в <code>handlers/&lt;модуль&gt;/</code>', '<code>doc.merge.delta</code> → <code>handlers/prx399_docmerge/</code>'],
    ['Интерфейс', '<code>.prx-*</code>, <code>PRX.*</code>, <code>data-prx-*</code>', '<code>.prx-card</code>, <code>PRX.toast()</code>, <code>data-prx-arm</code>'],
    ['Тесты', '<code>tests/Unit/XTest.php</code> · <code>tests/Js/x.test.mjs</code>', 'Python-воркер: <code>tests/handlers/prx399_docmerge/</code>'],
];

$tree = <<<'HTML'
application/modules/prx312_claims/
├── config/prx_module.php         манифест: id, slug, права, href, каналы, cleanup, health
├── init.php                      маршруты prx312_*; Event::listen() — если модуль слушает
├── classes/
│   ├── Controller/Prx312/        тонкие контроллеры: actor → require → Service → View
│   ├── Service/Prx312/           логика; SQL через Prx_Db; события через Event::publish()
│   ├── Policy/Prx312/            доступ к записи (extends Prx_Policy)
│   └── Model/Prx312/             ORM, если он действительно нужен
├── migrations_pg/                идемпотентные миграции prx_312_*
├── views/prx312/                 v6: page_header, filters, prx-table, empty_state
└── AGENTS.md                     состояние, долги, подводные камни (индекс разделов)
workers/prx_workers/handlers/prx312_claims/   канал воркера, если объявлен в манифесте
HTML;

$checks = [
    ['fa-list-check', '<code>make check</code>', 'манифесты, ACL, маршруты, ссылки, CSRF на клиенте, storage-тесты'],
    ['fa-flask', '<code>npm test</code> и <code>bash .tools/php_check_all.sh</code>', 'JS-контракты UI; синтаксис PHP, Tailwind-классы, дизайн-аудит, бюджеты памяток'],
    ['fa-palette', '<code>npm run build:css</code>', 'если добавили Tailwind-классы; собранный app.css коммитится'],
    ['fa-file-pen', '<code>context_pack.py index &lt;AGENTS.md&gt; --write</code>', 'после правки памятки: индекс разделов не должен разойтись'],
];

$next = [
    ['Образец для новых модулей', '<a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/prx399_docmerge">prx399_docmerge</a> — эталон: миграция, манифест, слои Service/Policy, канал воркера, 22 теста, AGENTS.md-учебник. Новые модули сверять с ним, а не с гипотетическими примерами.'],
    ['PHP-стенд', 'Прогнать то, что в песочнице без БД не запускается: phpunit, HTTP smoke, <code>make new-module</code> вживую.'],
    ['Цикл входящих документов', 'prx390–398 уже в проде: довести связку «роутер prx398 → извлечение prx395/397 → слияние prx399» и убрать диагностические леса.'],
    ['Аудит ассетов и чистка', 'Мёртвые JS/CSS, SQL в манифестах (<code>count_sql</code>), god-сервисы prx120/prx597 — только когда в них всё равно придётся зайти.'],
    ['Воркеры', 'Разбиение <code>base.py</code>/<code>queue.py</code> на жизненный цикл и транспорты — отдельным шагом, с сохранением контрактных тестов.'],
    ['Понятность для агента', 'Реестры в манифестах (поиск, worker, cleanup, health) — расширять секциями, а не новыми глобальными списками; AGENTS.md каждого модуля — живой, не формальный.'],
];
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Платформа', '/'], ['Архитектура', '/architecture'], ['Памятка программиста']],
        'title'   => 'Как устроена платформа',
        'pill'    => ['памятка · одна страница', 'fa-compass'],
        'lead'    => 'Философия, архитектура и правила именования — коротко, для программиста. Подробности и история решений — по ссылкам на документы репозитория.',
        'actions' => [
            ['label' => 'Схемы и решения', 'href' => '/architecture', 'icon' => 'fa-sitemap', 'role' => 'secondary'],
            ['label' => 'Администрирование', 'href' => '/admin', 'icon' => 'fa-gear', 'role' => 'secondary'],
        ],
    ]); ?>

    <section class="mt-6" aria-labelledby="guide-philosophy">
        <h2 id="guide-philosophy" class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">1. Философия — четыре правила</h2>
        <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <?php foreach ($philosophy as $i => $card): ?>
            <div class="prx-card">
                <p class="flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white"><span class="rounded-lg bg-gray-100 p-2 text-blue-600 dark:bg-gray-700 dark:text-blue-300"><i class="fas <?php echo HTML::chars($card[0]); ?>" aria-hidden="true"></i></span><span class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Правило <?php echo (int) $i + 1; ?></span></p>
                <h3 class="prx-h3 mt-3"><?php echo HTML::chars($card[1]); ?></h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300"><?php echo $card[2]; ?></p>
                <p class="prx-hint mt-3"><i class="fas fa-book-open mr-1" aria-hidden="true"></i><?php echo HTML::chars($card[3]); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mt-8" aria-labelledby="guide-request">
        <h2 id="guide-request" class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">2. Как идёт запрос</h2>
        <ol class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <?php foreach ($request_steps as $i => $step): ?>
            <li class="relative rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas <?php echo HTML::chars($step[0]); ?> mr-1.5 text-blue-600 dark:text-blue-400" aria-hidden="true"></i><?php echo (int) $i + 1; ?>. <?php echo HTML::chars($step[1]); ?></p>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300"><?php echo $step[2]; ?></p>
                <?php if ($i < count($request_steps) - 1): ?><i class="fas fa-arrow-right absolute -right-3 top-1/2 hidden -translate-y-1/2 text-gray-300 dark:text-gray-600 xl:block" aria-hidden="true"></i><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
        <div class="mt-3 grid gap-3 md:grid-cols-2">
            <?php foreach ($branches as $branch): ?>
            <div class="prx-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas <?php echo HTML::chars($branch[0]); ?> mr-2 text-amber-600 dark:text-amber-400" aria-hidden="true"></i><?php echo HTML::chars($branch[1]); ?></p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300"><?php echo $branch[2]; ?></p>
                <p class="prx-hint mt-2"><i class="fas fa-eye mr-1" aria-hidden="true"></i>Посмотреть вживую: <a class="prx-link" href="/prx120_jobs">очередь</a> · <a class="prx-link" href="/prx208_events">события</a> · <a class="prx-link" href="/prx597/admin">уведомления</a> · <a class="prx-link" href="/prx100/logs">логи</a></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="mt-8 gap-6 space-y-6 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <section class="prx-card lg:col-span-2" aria-labelledby="guide-anatomy">
            <h2 id="guide-anatomy" class="prx-h3">3. Анатомия модуля</h2>
            <p class="prx-hint mt-1">Создаёт <code>make new-module id=NNN slug=… name="…" entity=…</code>. Всё, что модуль знает о своих данных, — внутри; наружу — манифест и публичный Service. Живой образец всех слоёв — <a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/prx399_docmerge">prx399_docmerge</a>: его <code>AGENTS.md</code> — учебник по каждому слою, от миграции до тестов воркера.</p>
            <pre class="mt-3 overflow-x-auto whitespace-pre rounded-lg bg-gray-900 p-4 text-xs leading-5 text-gray-100"><?php echo HTML::chars($tree); ?></pre>
        </section>

        <section class="prx-card" aria-labelledby="guide-check">
            <h2 id="guide-check" class="prx-h3">5. Перед коммитом</h2>
            <ul class="mt-3 space-y-3">
                <?php foreach ($checks as $check): ?>
                <li class="flex gap-3"><i class="fas <?php echo HTML::chars($check[0]); ?> mt-0.5 w-4 shrink-0 text-gray-400" aria-hidden="true"></i><div><p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo $check[1]; ?></p><p class="prx-hint mt-0.5"><?php echo HTML::chars($check[2]); ?></p></div></li>
                <?php endforeach; ?>
            </ul>
            <p class="prx-hint mt-4">Коммит и push — в текущую рабочую ветку после проверок. Секреты и runtime-файлы не публикуются.</p>
        </section>
    </div>

    <section class="prx-card mt-6" aria-labelledby="guide-names">
        <h2 id="guide-names" class="prx-h3">4. Правила именования</h2>
        <p class="prx-hint mt-1">Один префикс на всё — модуль находится поиском по <code>prxNNN</code>. ID 100–299 и 560/595/597/599 заняты ядром; бизнес-план — 300–424.</p>
        <div class="mt-3 overflow-x-auto">
            <table class="prx-table min-w-[760px]">
                <thead><tr><th>Что</th><th>Шаблон</th><th>Пример</th></tr></thead>
                <tbody>
                <?php foreach ($names as $n): ?>
                <tr><td class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($n[0]); ?></td><td><?php echo $n[1]; ?></td><td class="text-gray-600 dark:text-gray-300"><?php echo $n[2]; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="prx-card mt-6" aria-labelledby="guide-next">
        <h2 id="guide-next" class="prx-h3">6. Что дальше</h2>
        <p class="prx-hint mt-1">Фундамент заложен; порядок — от «проверить» к «строить». Полный список с аргументами — <code>docs/ARCHITECTURE-REVIEW.md</code>, §5.</p>
        <ol class="mt-3 grid gap-3 md:grid-cols-2">
            <?php foreach ($next as $i => $item): ?>
            <li class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo (int) $i + 1; ?>. <?php echo HTML::chars($item[0]); ?></p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300"><?php echo $item[1]; ?></p>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <p class="prx-hint mt-6">Схема на /architecture собрана <?php echo $built_at ? date('d.m.Y H:i', (int) $built_at) : 'ещё не собрана (make architecture)'; ?>. Источник истины для этой страницы — AGENTS.md, docs/FOUNDATION.md, docs/MODULE_GUIDE.md, docs/DESIGN-SYSTEM.md; здесь — только карта.</p>
</div>
