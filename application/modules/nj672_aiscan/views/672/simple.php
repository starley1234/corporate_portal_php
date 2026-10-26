<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Простой режим /aiscan/simple — «просто проверь вот эту модель».
 *
 * Полная форма /aiscan показывает все ручки аудита и потому перегружена.
 * Здесь одна цель и одна кнопка; умолчания фиксированы честно и написаны
 * на странице: все наборы атак, быстрый стресс-профиль, судья-модель
 * включён. POST уходит в тот же /aiscan/run (поле ui=simple возвращает
 * ошибки сюда), отчёт — общий с полным режимом.
 *
 * @var array  $profiles пресеты цели: ключ => [title, note]
 * @var array  $suites   наборы проверок (нужны скрытые значения и счётчик)
 * @var array  $history  последние прогоны (5)
 * @var string $flash    сообщение после POST
 * @var string $csrf     токен формы
 */

$tests_total = 0;
foreach ($suites as $suite)
{
    $tests_total += (int) ($suite['tests'] ?? 0);
}

$statuses = [
    'queued'    => ['В очереди', 'n2j-badge-run'],
    'running'   => ['Проверяю', 'n2j-badge-run'],
    'done'      => ['Готово', 'n2j-badge-ok'],
    'error'     => ['Ошибка', 'n2j-badge-err'],
    'cancelled' => ['Отменён', 'n2j-badge-wait'],
];
$tone2badge = ['rose' => 'n2j-badge-err', 'amber' => 'n2j-badge-run', 'emerald' => 'n2j-badge-ok', 'blue' => 'n2j-badge-info', 'gray' => 'n2j-badge-wait'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php echo View::factory('partials/page_header', [
    'crumbs' => [['Сервисы', '/services'], ['Сканер безопасности', '/aiscan'], ['Простая проверка']],
    'title'  => 'Простая проверка модели',
    'pill'   => ['Простой режим', 'fa-bolt'],
    'actions' => [
        ['label' => 'Расширенный режим', 'href' => '/aiscan', 'icon' => 'fa-sliders', 'role' => 'secondary'],
        ['label' => 'Справочник', 'href' => '/aiscan/guide', 'icon' => 'fa-book-open', 'role' => 'secondary'],
    ],
    'lead'   => 'Выберите, что проверить, и нажмите одну кнопку. Сканер проведёт все проверки атак и быстрый стресс-тест, а отчёт начнётся с вердикта простыми словами.',
]); ?>

<?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-err mt-4 flex items-center gap-2.5" role="alert">
        <i class="fas fa-triangle-exclamation shrink-0"></i>
        <p class="text-sm"><?php echo HTML::chars($flash); ?></p>
    </div>
<?php endif; ?>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <section class="n2j-card md:col-span-2" aria-labelledby="nj672s-title">
        <h2 class="n2j-h2" id="nj672s-title">Что проверяем</h2>

        <form method="post" action="/aiscan/run" class="mt-4 space-y-5" data-nj-submit-once autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <input type="hidden" name="ui" value="simple">
            <input type="radio" class="hidden" name="mode" value="profile" id="nj672s-mode-profile" checked>
            <input type="radio" class="hidden" name="mode" value="custom" id="nj672s-mode-custom">
<?php foreach ($suites as $key => $suite): ?>
            <input type="hidden" name="suites[]" value="<?php echo HTML::chars($key); ?>">
<?php endforeach; ?>
            <input type="hidden" name="stress" value="quick">
            <input type="hidden" name="judge" value="1">

            <div class="space-y-2" role="radiogroup" aria-label="Цель проверки">
<?php foreach ($profiles as $key => $profile): ?>
                <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-gray-200 p-3 hover:border-blue-400 dark:border-gray-700 dark:hover:border-blue-500">
                    <input type="radio" name="profile" value="<?php echo HTML::chars($key); ?>" class="mt-0.5" data-nj672s-target<?php echo $key === 'sandbox_vulnerable' ? ' checked' : ''; ?>>
                    <span>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-50"><?php echo HTML::chars((string) ($profile['title'] ?? $key)); ?></span>
                        <span class="n2j-hint block"><?php echo HTML::chars((string) ($profile['note'] ?? '')); ?></span>
                    </span>
                </label>
<?php endforeach; ?>
                <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-gray-200 p-3 hover:border-blue-400 dark:border-gray-700 dark:hover:border-blue-500">
                    <input type="radio" name="profile" value="__custom" class="mt-0.5" data-nj672s-target>
                    <span>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-50">Мой эндпоинт</span>
                        <span class="n2j-hint block">любой OpenAI-совместимый API: OpenAI, vLLM, LM Studio, локальная модель за туннелем</span>
                    </span>
                </label>
            </div>

            <div id="nj672s-custom-box" class="space-y-4 hidden">
                <div class="space-y-1.5">
                    <label class="n2j-label" for="nj672s-url">Адрес эндпоинта</label>
                    <input class="n2j-input" id="nj672s-url" name="base_url" type="url" maxlength="300"
                           placeholder="https://api.example.com/v1" autocomplete="off"
                           data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label class="n2j-label" for="nj672s-model">Модель</label>
                        <input class="n2j-input" id="nj672s-model" name="model" type="text" maxlength="160"
                               placeholder="gpt-4o-mini, llama-3.1-8b, …" autocomplete="off"
                               data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                    </div>
                    <div class="space-y-1.5">
                        <label class="n2j-label" for="nj672s-key">Ключ API <span class="font-normal text-gray-500 dark:text-gray-400">(если нужен)</span></label>
                        <input class="n2j-input" id="nj672s-key" name="api_key" type="password" maxlength="300"
                               autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                        <p class="n2j-hint">Ключ не сохраняется нигде: браузеру запоминать запрещено, на сервере живёт только в задаче и памяти воркера.</p>
                    </div>
                </div>
            </div>

            <p class="n2j-hint">
                Что будет: все <?php echo $tests_total; ?> проверки атак (инъекции, утечки, джейлбрейк, захват вывода)
                и быстрая нагрузка — 1 и 10 параллельных потоков плюс длинный контекст.
                Выбрать наборы, нагрузку посильнее или свои атаки — <a class="n2j-link" href="/aiscan">расширенный режим</a>.
                Что именно проверяется и как должно быть — <a class="n2j-link" href="/aiscan/guide">справочник</a>.
            </p>

            <div id="nj672s-attest-box" class="n2j-flash n2j-flash-warn">
                <label class="flex cursor-pointer items-start gap-2.5 text-sm font-medium">
                    <input type="checkbox" id="nj672s-attest" name="attest" value="1" class="mt-0.5" disabled>
                    <span>Подтверждаю, что у меня есть право тестировать выбранный эндпоинт: сканер будет атаковать его и нагружать.</span>
                </label>
                <p class="n2j-hint mt-1.5 pl-6" id="nj672s-attest-hint"></p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" id="nj672s-submit" class="n2j-btn-process">
                    <i class="fas fa-shield-halved"></i><span>Проверить</span>
                </button>
                <span class="n2j-hint" id="nj672s-submit-hint"></span>
            </div>
        </form>
    </section>

    <aside class="space-y-4">
        <section class="n2j-card" aria-labelledby="nj672s-how-title">
            <h2 class="n2j-h3" id="nj672s-how-title">Как это работает</h2>
            <ol class="mt-3 space-y-2.5 text-sm text-gray-700 dark:text-gray-200">
                <li><strong>1 · Атакуем.</strong> Модели уходят вредные на вид, но безопасные по сути промпты: успех атаки измеряется секретным маркером, а не настоящим вредом.</li>
                <li><strong>2 · Нагружаем.</strong> Короткий стресс: параллельные запросы и длинный контекст со спрятанной фразой.</li>
                <li><strong>3 · Объясняем.</strong> Отчёт начинается с вердикта словами, дальше — главные проблемы и доказательства, в конце — как чинить.</li>
            </ol>
            <p class="mt-3"><a class="n2j-link text-sm" href="/aiscan/guide">Подробно: что проверяем и как должно быть →</a></p>
        </section>

        <section class="n2j-card" aria-labelledby="nj672s-history-title">
            <h2 class="n2j-h3" id="nj672s-history-title">Последние проверки</h2>
<?php if ($history): ?>
            <ul class="mt-3 space-y-2.5">
<?php foreach ($history as $run): ?>
<?php
    $brief = Service_Nj672_Scan::brief($run);
    [$status_label, $status_class] = $statuses[(string) ($run['status'] ?? '')] ?? ['—', 'n2j-badge-wait'];
?>
                <li class="text-sm">
                    <a class="n2j-link font-medium" href="/aiscan/result/<?php echo (int) $run['id']; ?>">Проверка №<?php echo (int) $run['id']; ?></a>
                    <span class="ml-1.5"><span class="n2j-badge <?php echo $status_class; ?>"><span class="n2j-dot"></span><?php echo HTML::chars($status_label); ?></span></span>
<?php if (($brief['risk']['label'] ?? '') !== ''): ?>
                    <span class="ml-1"><span class="n2j-badge <?php echo $tone2badge[$brief['risk']['tone'] ?? 'gray'] ?? 'n2j-badge-wait'; ?>"><span class="n2j-dot"></span><?php echo HTML::chars($brief['risk']['label']); ?></span></span>
<?php endif; ?>
                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) ($run['title'] ?? '')); ?></span>
                </li>
<?php endforeach; ?>
            </ul>
            <p class="mt-3"><a class="n2j-link text-sm" href="/aiscan">Вся история и настройки →</a></p>
<?php else: ?>
            <p class="n2j-hint mt-2">Проверок ещё не было. Начните с «Песочница: уязвимая модель» — увидите, как выглядит плохой отчёт.</p>
<?php endif; ?>
        </section>
    </aside>
</div>

</div>

<?php echo View::factory('672/simple_script'); ?>
