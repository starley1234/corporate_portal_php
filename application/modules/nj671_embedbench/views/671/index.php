<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /embedbench — запуск сравнения двух путей эмбеддингов.
 *
 * SSR отдаёт всё, что известно без модели: что сравнивается, какой материал
 * есть, какие прогоны уже были. Сама работа уходит в очередь, поэтому страница
 * не ждёт модель и не показывает полос прогресса (правило платформы): ход
 * работы виден в общем статус-ряде на странице результата.
 *
 * @var array  $corpora наборы материала: ключ → [title, note]
 * @var array  $limits  пределы модуля (per_hour, timeout_sec, history)
 * @var array  $history последние прогоны (result уже массивом)
 * @var string $flash   сообщение после POST (ошибка запуска)
 * @var string $csrf    токен формы
 * @var array  $site    что видит САЙТ: endpoint/model/dimensions и есть ли MM
 */

$statuses = [
    'queued'    => ['В очереди', 'n2j-badge-warn'],
    'running'   => ['Считается', 'n2j-badge-warn'],
    'done'      => ['Готово', 'n2j-badge-ok'],
    'error'     => ['Ошибка', 'n2j-badge-err'],
    'cancelled' => ['Отменён', 'n2j-badge'],
];

$per_hour = (int) ($limits['per_hour'] ?? 6);
$timeout  = (int) ($limits['timeout_sec'] ?? 600);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <h1 class="n2j-h1">Стенд эмбеддингов</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
        Один и тот же материал — два пути получить вектор. Стенд гоняет оба, меряет
        качество поиска, цену входа, повторяемость и связь «картинка ↔ слова» и
        говорит, какой путь брать для какой задачи. Считает воркер: он один видит
        оба сервера (<code class="rounded bg-gray-100 px-1 text-xs dark:bg-gray-800">workers/.env</code>).
    </p>

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-err mt-4 block">
        <p class="font-semibold">Прогон не запустился</p>
        <p class="mt-1"><?php echo HTML::chars($flash); ?></p>
    </div>
    <?php endif; ?>

    <div class="mt-5 grid gap-4 lg:grid-cols-2">
        <?php
        $cards = [
            'text' => [
                'title' => 'Текстовый путь',
                'env'   => 'EMBEDDING_API_URL',
                'model' => (string) ($site['model'] ?? ''),
                'url'   => (string) ($site['api_url'] ?? ''),
                'dims'  => (int) ($site['dimensions'] ?? 0),
                'text'  => 'OpenAI-совместимый /v1/embeddings. Пачка текстов одним запросом, '
                          .'лёгкая модель, работает и локально. Картинку не видит вовсе.',
                'icon'  => 'fas fa-font',
                'tone'  => 'sky',
            ],
            'mm'   => [
                'title' => 'Мультимодальный путь',
                'env'   => 'EMBEDDING_MM_API_URL',
                'model' => '',
                'url'   => '',
                'dims'  => 0,
                'text'  => 'Свой сервис на Qwen3-VL-Embedding: текст и картинка в одном '
                          .'пространстве. Каждый вход — отдельный запрос (пачка меняет вектор).',
                'icon'  => 'fas fa-images',
                'tone'  => 'violet',
            ],
        ];

        $tones = [
            'sky'    => 'border-sky-200 bg-sky-50/60 dark:border-sky-900/60 dark:bg-sky-950/30',
            'violet' => 'border-violet-200 bg-violet-50/60 dark:border-violet-900/60 dark:bg-violet-950/30',
        ];
        ?>

        <?php foreach ($cards as $key => $card): ?>
        <section class="rounded-xl border p-4 <?php echo $tones[$card['tone']]; ?>">
            <div class="flex items-center gap-2">
                <i class="fas <?php echo $card['icon']; ?> text-sm text-gray-500 dark:text-gray-300"></i>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($card['title']); ?></h2>
            </div>
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($card['text']); ?></p>
            <dl class="mt-3 space-y-1 text-xs">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="uppercase tracking-wide text-gray-400">Адрес</dt>
                    <dd class="font-mono text-[11px] text-gray-700 dark:text-gray-200"><?php echo HTML::chars($card['env']); ?></dd>
                </div>
                <?php if ($key === 'text' AND $card['model'] !== ''): ?>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="uppercase tracking-wide text-gray-400">Модель сайта</dt>
                    <dd class="text-right text-gray-700 dark:text-gray-200"><?php echo HTML::chars($card['model']); ?></dd>
                </div>
                <?php endif; ?>
                <?php if ($key === 'text' AND $card['dims'] > 0): ?>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="uppercase tracking-wide text-gray-400">Размерность сайта</dt>
                    <dd class="text-gray-700 dark:text-gray-200"><?php echo (int) $card['dims']; ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </section>
        <?php endforeach; ?>
    </div>

    <p class="n2j-hint mt-3">
        На сайте видно только его собственный конфиг. Меряет воркер — адреса и ключи
        он берёт из <code class="rounded bg-gray-100 px-1 text-xs dark:bg-gray-800">workers/.env</code>,
        и они могут отличаться от сайтовых. Что реально ответило — написано в отчёте.
    </p>

    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Запустить сравнение</h2>

        <?php if ( ! $corpora): ?>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            В конфиге модуля нет ни одного набора материала — проверьте
            <code class="rounded bg-gray-100 px-1 text-xs dark:bg-gray-800">config/nj671_embedbench.php</code>.
        </p>
        <?php else: ?>
        <form method="post" action="/embedbench/run" class="mt-3 space-y-3" data-nj-submit-once>
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

            <div>
                <label for="nj671-corpus" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Материал
                </label>
                <select id="nj671-corpus" name="corpus" class="n2j-input mt-1 w-full max-w-xl">
                    <?php foreach ($corpora as $key => $corpus): ?>
                    <option value="<?php echo HTML::chars($key); ?>">
                        <?php echo HTML::chars((string) ($corpus['title'] ?? $key)); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php foreach ($corpora as $key => $corpus): ?>
                <?php if (trim((string) ($corpus['note'] ?? '')) !== ''): ?>
                <p class="n2j-hint mt-1"><?php echo HTML::chars((string) $corpus['note']); ?></p>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="quick" value="1" class="mt-0.5">
                <span>
                    Быстрый режим — 6 описаний и 3 запроса вместо 14 и 10.
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        Для проверки настройки: отвечает за секунды, но цифры качества грубее.
                    </span>
                </span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="n2j-btn-process">
                    <i class="fas fa-play mr-1.5"></i>Сравнить пути
                </button>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Не больше <?php echo $per_hour; ?> прогонов в час; задача живёт до <?php echo $timeout; ?> с.
                </span>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Прошлые прогоны</h2>

        <?php if ( ! $history): ?>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Прогонов ещё не было. Первый покажет, настроены ли оба пути вообще:
            чаще всего один из адресов пустой.
        </p>
        <?php else: ?>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700">
                        <th class="py-2 pr-3 font-semibold">Когда</th>
                        <th class="py-2 pr-3 font-semibold">Материал</th>
                        <th class="py-2 pr-3 font-semibold">Что показал</th>
                        <th class="py-2 pr-3 font-semibold">Состояние</th>
                        <th class="py-2 font-semibold"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row):
                        $status = (string) ($row['status'] ?? 'queued');
                        list($status_text, $status_class) = $statuses[$status] ?? [$status, 'n2j-badge'];
                        $brief  = Service_Nj671_Bench::brief($row);
                    ?>
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-700">
                        <td class="py-2 pr-3 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                            <?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $row['created_at']))); ?>
                        </td>
                        <td class="py-2 pr-3 text-gray-800 dark:text-gray-100">
                            <?php echo HTML::chars(trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : 'Прогон №'.(int) $row['id']); ?>
                        </td>
                        <td class="py-2 pr-3 text-xs text-gray-600 dark:text-gray-300">
                            <?php echo HTML::chars($brief !== '' ? $brief : '—'); ?>
                        </td>
                        <td class="py-2 pr-3">
                            <span class="<?php echo $status_class; ?>"><?php echo HTML::chars($status_text); ?></span>
                        </td>
                        <td class="py-2 text-right">
                            <a href="/embedbench/result/<?php echo (int) $row['id']; ?>"
                               class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Отчёт</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

</div>

<aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Как читать цифры</h2>
        <dl class="mt-2 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <div>
                <dt class="font-semibold text-gray-800 dark:text-gray-100">Recall@1 и Recall@3</dt>
                <dd>Доля запросов, где верное описание стоит первым (или в первой тройке). Главная цифра: именно её видит человек в поиске.</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-800 dark:text-gray-100">Своё − чужое</dt>
                <dd>Насколько косинус к своему описанию выше косинуса к чужому. Мало — модель «всё считает похожим».</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-800 dark:text-gray-100">Повторяемость</dt>
                <dd>Косинус одного и того же текста, посчитанного дважды. Ниже 0,999 — вектор дрожит, дедупликация будет ошибаться.</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-800 dark:text-gray-100">Мс на вход</dt>
                <dd>Цена одного входа с учётом способа батчить: текстовый путь шлёт пачку, мультимодальный — по одному.</dd>
            </div>
        </dl>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Чего стенд не делает</h2>
        <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <li>· Не проверяет вашу настоящую базу: 14 описаний — дымовая проба, а не бенчмарк.</li>
            <li>· Не сравнивает косинусы МЕЖДУ путями: у моделей разные пространства, такая цифра ничего не значит.</li>
            <li>· Не решает за вас: он показывает цифры и правило выбора, а модель может быть заменена в любой момент.</li>
        </ul>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Из командной строки</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            То же сравнение без сайта и очереди — быстрее и с машинным отчётом:
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-3 text-[11px] leading-relaxed text-gray-100">cd workers
python3 nj671_bench.py --quick
python3 nj671_bench.py --json /tmp/bench.json</pre>
    </section>
</aside>
</div>
</div>
