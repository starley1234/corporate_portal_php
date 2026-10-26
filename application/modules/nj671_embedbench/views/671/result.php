<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /embedbench/result/<id> — отчёт сравнения двух путей.
 *
 * Здесь ничего не считается: `Service_Nj671_Bench::shape()` уже привёл ответ
 * воркера к структуре, а коды решения перевёл в слова. Вьюха только рисует.
 *
 * Полос прогресса нет (правило платформы): пока идёт прогон, работает общий
 * статус-ряд, а после завершения страница перезагружается сама.
 *
 * @var array $run  строка nj_671_runs
 * @var array $data результат shape(): sides, metrics, decision, notes, rows
 * @var array $live контракт статус-ряда (Vision::run_live)
 * @var string $hint подсказка «задача не двигается» (Vision::run_watch)
 */

$status = (string) ($run['status'] ?? 'queued');
$tone   = [
    'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-100',
    'sky'     => 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/30 dark:text-sky-100',
    'violet'  => 'border-violet-200 bg-violet-50 text-violet-900 dark:border-violet-900/60 dark:bg-violet-950/30 dark:text-violet-100',
    'amber'   => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100',
    'rose'    => 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-100',
    'gray'    => 'border-gray-200 bg-gray-50 text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100',
];

$badge = [
    'emerald' => 'n2j-badge n2j-badge-ok',
    'sky'     => 'n2j-badge',
    'violet'  => 'n2j-badge',
    'amber'   => 'n2j-badge n2j-badge-warn',
    'rose'    => 'n2j-badge n2j-badge-err',
    'gray'    => 'n2j-badge',
];

$corpus    = (array) ($data['corpus'] ?? []);
$decision  = (array) ($data['decision'] ?? []);
$sides     = (array) ($data['sides'] ?? []);
$text      = (array) ($sides['text'] ?? []);
$mm        = (array) ($sides['mm'] ?? []);
$running   = in_array($status, ['queued', 'running'], TRUE);
$done      = ($status === 'done');
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <div class="flex flex-wrap items-center gap-2 text-xs">
        <a href="/embedbench" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">← Стенд эмбеддингов</a>
        <span class="text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $run['created_at']))); ?></span>
        <span class="text-gray-400">·</span>
        <span class="text-gray-500 dark:text-gray-400">
            <?php echo HTML::chars((string) ($corpus['title'] ?? $run['title'])); ?>
            <?php if ( ! empty($corpus['docs'])): ?>
            (<?php echo (int) $corpus['docs']; ?> описаний, <?php echo (int) $corpus['queries']; ?> запросов<?php echo ! empty($corpus['quick']) ? ', быстрый режим' : ''; ?>)
            <?php endif; ?>
        </span>
    </div>

    <h1 class="n2j-h1 mt-2"><?php echo HTML::chars($data['title'] !== '' ? $data['title'] : 'Сравнение путей эмбеддингов'); ?></h1>

    <?php if ($running): ?>

        <?php echo View::factory('partials/live_status', [
            'url'     => '/embedbench/api/status?id='.(int) $run['id'],
            'title'   => 'Считаю два пути на одном материале',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) $live['stages'],
            'note'    => $hint !== '' ? $hint : 'Сначала идут документы, потом запросы, повтор и картинки. Страница обновится сама.',
        ]); ?>
        <?php echo View::factory('partials/live_status_js'); ?>

        <p class="n2j-hint mt-3">
            Первый прогон после перезапуска сервера может быть долгим: модель
            загружается в память. Это не зависание — статус-ряд показывает этап.
        </p>

    <?php elseif ($status === 'error'): ?>

        <div class="n2j-flash n2j-flash-err mt-4 block">
            <p class="font-semibold">Сравнение не удалось</p>
            <p class="mt-1"><?php echo HTML::chars(trim((string) $run['error']) !== '' ? (string) $run['error'] : 'Причина не записана.'); ?></p>
            <p class="mt-2 text-xs">
                Чаще всего виноват один из адресов в <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">workers/.env</code>:
                пустой <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">EMBEDDING_MM_API_URL</code> или неверный токен.
                Проверить из командной строки: <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">python3 workers/nj671_bench.py --quick</code>.
            </p>
            <p class="mt-2"><a href="/embedbench" class="font-semibold underline">Запустить ещё раз</a></p>
        </div>

    <?php elseif ($status === 'cancelled'): ?>

        <div class="n2j-flash mt-4 block">
            <p class="font-semibold">Прогон отменён</p>
            <p class="mt-1">Задачу сняли до завершения — сравнения нет.</p>
        </div>

    <?php elseif ($done): ?>

        <?php if (($data['state'] ?? '') === 'summary' && ! $data['ok']): ?>

        <?php
        // Сайт получил не отчёт, а короткую сводку из очереди. Это НЕ «оба пути
        // молчат»: работа выполнена, но метрик в сводке нет. Владелец
        // 22.10.2026 увидел здесь «Ни один путь не ответил — причина не
        // записана» при полностью настроенном стенде, и был прав, что не
        // поверил странице.
        $sum       = (array) ($data['summary'] ?? []);
        $sum_ok    = (int) ($sum['text_ok'] === TRUE) + (int) ($sum['mm_ok'] === TRUE);
        $sum_label = Service_Nj671_Bench::winner_text((string) ($sum['winner'] ?? ''));
        ?>
        <div class="mt-4 rounded-xl border p-4 <?php echo $tone['amber']; ?>">
            <p class="text-sm font-bold">Прогон выполнен, но на сайт дошла только сводка</p>
            <p class="mt-1 text-sm">
                Очередь вернула итог без метрик: известно, что ответили
                <strong><?php echo $sum_ok; ?> из 2</strong> путей<?php echo $sum['winner'] !== '' ? ' · вывод: '.HTML::chars($sum_label) : ''; ?>.
                Таблицы цифр в этой записи нет — её недописала доставка отчёта.
            </p>
            <?php if ($sum['agreement'] !== NULL): ?>
            <p class="mt-1 text-xs opacity-80">Согласие пространств: <?php echo HTML::chars(Service_Nj671_Bench::display('cos', $sum['agreement'])); ?></p>
            <?php endif; ?>
            <ul class="mt-2 space-y-1 text-xs">
                <li>текстовый: <?php echo $sum['text_ok'] === TRUE ? 'ответил' : 'не ответил'; ?></li>
                <li>мультимодальный: <?php echo $sum['mm_ok'] === TRUE ? 'ответил' : 'не ответил'; ?></li>
            </ul>
            <p class="mt-2 text-xs">
                Полные цифры даёт CLI на машине воркера — там же, где лежат адреса:
                <code class="rounded bg-white/60 px-1 dark:bg-gray-800">python3 workers/nj671_bench.py --quick</code>.
                Или запустите сравнение ещё раз: <a href="/embedbench" class="font-semibold underline">/embedbench</a>.
            </p>
        </div>

        <?php elseif (($data['state'] ?? '') === 'empty'): ?>

        <div class="mt-4 rounded-xl border p-4 <?php echo $tone['amber']; ?>">
            <p class="text-sm font-bold">Отчёт пуст</p>
            <p class="mt-1 text-sm">
                Прогон закрыт, но результата в нём нет: ни метрик, ни сводки.
                Так бывает, если отчёт не дошёл до сайта и задачу закрыли вручную.
                Проверить по-настоящему:
                <code class="rounded bg-white/60 px-1 dark:bg-gray-800">python3 workers/nj671_bench.py --quick</code>
                на машине воркера.
            </p>
        </div>

        <?php elseif ( ! $data['ok']): ?>
        <div class="mt-4 rounded-xl border p-4 <?php echo $tone['rose']; ?>">
            <p class="text-sm font-bold">Ни один путь не ответил</p>
            <p class="mt-1 text-sm">
                Оба пути пробовали и оба отказали — каждый по своей причине. Адреса
                и токены лежат в <code class="rounded bg-white/60 px-1 dark:bg-gray-800">workers/.env</code>
                на машине воркера, а не на сайте.
            </p>
            <ul class="mt-2 space-y-1 text-xs">
                <li>текстовый (<?php echo HTML::chars($text['env']); ?>): <?php echo HTML::chars($text['error'] !== '' ? $text['error'] : '—'); ?></li>
                <li>мультимодальный (<?php echo HTML::chars($mm['env']); ?>): <?php echo HTML::chars($mm['error'] !== '' ? $mm['error'] : '—'); ?></li>
            </ul>
            <?php
            // Замечания пути — самое ценное при отказе: там список моделей
            // сервера, факт отправки токена и «сервер не принял пачку».
            // Показываем их рядом с ошибкой, а не только в паспорте ниже.
            $failed_notes = [];

            foreach (['text' => $text, 'mm' => $mm] as $side_key => $side_row)
            {
                if ( ! empty($side_row['ok']) OR empty($side_row['notes']))
                {
                    continue;
                }

                foreach ((array) $side_row['notes'] as $line)
                {
                    $failed_notes[] = $side_row['label'].': '.$line;
                }
            }
            ?>
            <?php if ($failed_notes): ?>
            <ul class="mt-2 space-y-1 text-[11px] opacity-80">
                <?php foreach ($failed_notes as $line): ?>
                <li>· <?php echo HTML::chars((string) $line); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <p class="mt-2 text-xs">
                Проверить из командной строки:
                <code class="rounded bg-white/60 px-1 dark:bg-gray-800">python3 workers/nj671_bench.py --quick</code>
                — он печатает ту же причину, но с текстом исключения.
            </p>
        </div>
        <?php else: ?>

        <div class="mt-4 rounded-xl border p-4 <?php echo $tone[$decision['tone']] ?? $tone['gray']; ?>">
            <div class="flex flex-wrap items-center gap-2">
                <span class="<?php echo $badge[$decision['tone']] ?? $badge['gray']; ?>"><?php echo HTML::chars($decision['label']); ?></span>
                <?php if ($data['elapsed_ms'] > 0): ?>
                <span class="text-xs opacity-70">вся работа: <?php echo round($data['elapsed_ms'] / 1000, 1); ?> с</span>
                <?php endif; ?>
            </div>
            <?php if ($decision['summary'] !== ''): ?>
            <p class="mt-2 text-sm font-semibold"><?php echo HTML::chars($decision['summary']); ?></p>
            <?php endif; ?>
            <p class="mt-1 text-xs opacity-80">
                Сравнение: текстовый путь <?php echo HTML::chars(Service_Nj671_Bench::display('pct', $text['recall1'], $text['ok'])); ?>
                recall@1 · мультимодальный <?php echo HTML::chars(Service_Nj671_Bench::display('pct', $mm['recall1'], $mm['ok'])); ?>.
            </p>
        </div>

        <?php if ($decision['tasks']): ?>
        <section class="n2j-card mt-5">
            <h2 class="n2j-h3">Что брать для какой задачи</h2>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700">
                            <th class="py-2 pr-3 font-semibold">Задача</th>
                            <th class="py-2 pr-3 font-semibold">Выбор</th>
                            <th class="py-2 font-semibold">Почему</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($decision['tasks'] as $task): ?>
                        <tr class="border-b border-gray-100 align-top last:border-0 dark:border-gray-700">
                            <td class="py-2 pr-3">
                                <span class="font-semibold text-gray-900 dark:text-gray-100"><?php echo HTML::chars($task['title']); ?></span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($task['note']); ?></span>
                            </td>
                            <td class="py-2 pr-3">
                                <span class="<?php echo $badge[$task['tone']] ?? $badge['gray']; ?>"><?php echo HTML::chars($task['label']); ?></span>
                            </td>
                            <td class="py-2 text-xs text-gray-600 dark:text-gray-300">
                                <?php if ($task['task'] === 'text_index'): ?>
                                    <?php
                                    $delta = (float) ($task['delta'] ?? 0);
                                    $speed = (float) ($task['speed'] ?? 0);
                                    ?>
                                    <?php if ($task['pick'] === 'text'): ?>
                                        Качество не хуже, а вход дешевле<?php echo $speed > 0 ? ' в '.round($speed, 1).'×' : ''; ?>.
                                        Тратить 2B-модель на каждый текст незачем.
                                    <?php else: ?>
                                        Мультимодальный точнее на <?php echo Service_Nj671_Bench::pct(abs($delta)); ?> recall@1 —
                                        это больше порога шума.
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo HTML::chars($task['note']); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($decision['reasons']): ?>
        <section class="n2j-card mt-5">
            <h2 class="n2j-h3">Из чего сложился вывод</h2>
            <ul class="mt-2 space-y-2">
                <?php foreach ($decision['reasons'] as $reason): ?>
                <li class="flex gap-3 text-sm">
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full
                        <?php
                        // Точка — только три цвета из собранного CSS: «плохо»,
                        // «внимание», «просто к сведению». Оттенков sky/violet
                        // в сборке нет, и выдуманный класс молча не красился бы.
                        echo ['rose' => 'bg-rose-500', 'amber' => 'bg-amber-500',
                              'emerald' => 'bg-emerald-500'][$reason['tone']] ?? 'bg-gray-400';
                        ?>"></span>
                    <span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100"><?php echo HTML::chars($reason['title']); ?></span>
                        <?php if ($reason['note'] !== ''): ?>
                        <span class="block text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($reason['note']); ?></span>
                        <?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="n2j-card mt-5">
            <h2 class="n2j-h3">Цифры рядом</h2>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700">
                            <th class="py-2 pr-3 font-semibold">Метрика</th>
                            <th class="py-2 pr-3 text-right font-semibold">Текстовый</th>
                            <th class="py-2 pr-3 text-right font-semibold">Мультимодальный</th>
                            <th class="py-2 font-semibold">Что это</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['metrics'] as $metric): ?>
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-700">
                            <td class="py-2 pr-3 whitespace-nowrap text-gray-800 dark:text-gray-100"><?php echo HTML::chars($metric['name']); ?></td>
                            <td class="py-2 pr-3 text-right tabular-nums <?php echo $metric['best'] === 'text' ? 'font-bold text-emerald-700 dark:text-emerald-400' : 'text-gray-700 dark:text-gray-200'; ?>">
                                <?php echo HTML::chars((string) $metric['text']); ?>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums <?php echo $metric['best'] === 'mm' ? 'font-bold text-emerald-700 dark:text-emerald-400' : 'text-gray-700 dark:text-gray-200'; ?>">
                                <?php echo HTML::chars((string) $metric['mm']); ?>
                            </td>
                            <td class="py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($metric['hint']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-2 pr-3 whitespace-nowrap text-gray-800 dark:text-gray-100">Картинка ↔ слова</td>
                            <td class="py-2 pr-3 text-right text-xs text-gray-500 dark:text-gray-400">не умеет</td>
                            <td class="py-2 pr-3 text-right">
                                <?php
                                $images = (array) ($mm['images'] ?? []);
                                $img_grade = (array) ($mm['grades']['images'] ?? ['text' => '—', 'tone' => 'gray']);
                                ?>
                                <span class="<?php echo $badge[$img_grade['tone']] ?? $badge['gray']; ?>">
                                    <?php echo HTML::chars($img_grade['text']); ?>
                                </span>
                                <?php if ($images['checked'] AND $images['margin'] !== NULL): ?>
                                <span class="block text-[11px] text-gray-400">отрыв <?php echo Service_Nj671_Bench::show($images['margin'], 2); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 text-xs text-gray-500 dark:text-gray-400">своя подпись ближе к картинке, чем чужая</td>
                        </tr>
                        <?php if ($data['agreement'] !== NULL): ?>
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-2 pr-3 whitespace-nowrap text-gray-800 dark:text-gray-100">Согласие структуры</td>
                            <td class="py-2 pr-3 text-right tabular-nums text-gray-700 dark:text-gray-200" colspan="2">
                                r = <?php echo Service_Nj671_Bench::show($data['agreement'], 3); ?>
                            </td>
                            <td class="py-2 text-xs text-gray-500 dark:text-gray-400">не качество: насколько похоже упорядочивают материал</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($data['rows']): ?>
        <section class="n2j-card mt-5">
            <h2 class="n2j-h3">Где пути разошлись</h2>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700">
                            <th class="py-2 pr-3 font-semibold">Запрос</th>
                            <th class="py-2 pr-3 font-semibold">Текстовый</th>
                            <th class="py-2 font-semibold">Мультимодальный</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['rows'] as $row): ?>
                        <tr class="border-b border-gray-100 align-top last:border-0 dark:border-gray-700">
                            <td class="py-2 pr-3 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($row['query']); ?></td>
                            <td class="py-2 pr-3 text-xs">
                                <span class="<?php echo ! empty($row['text_ok']) ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'; ?>">
                                    <i class="fas <?php echo ! empty($row['text_ok']) ? 'fa-check' : 'fa-xmark'; ?> mr-1"></i>
                                    <?php echo ! empty($row['text_ok']) ? 'верно' : 'мимо'; ?>
                                </span>
                                <span class="block text-[11px] text-gray-400">
                                    место <?php echo (int) $row['text_rank']; ?>
                                    <?php if ($row['text_cos'] !== NULL): ?>· косинус <?php echo Service_Nj671_Bench::show($row['text_cos'], 3); ?><?php endif; ?>
                                </span>
                            </td>
                            <td class="py-2 text-xs">
                                <span class="<?php echo ! empty($row['mm_ok']) ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'; ?>">
                                    <i class="fas <?php echo ! empty($row['mm_ok']) ? 'fa-check' : 'fa-xmark'; ?> mr-1"></i>
                                    <?php echo ! empty($row['mm_ok']) ? 'верно' : 'мимо'; ?>
                                </span>
                                <span class="block text-[11px] text-gray-400">
                                    место <?php echo (int) $row['mm_rank']; ?>
                                    <?php if ($row['mm_cos'] !== NULL): ?>· косинус <?php echo Service_Nj671_Bench::show($row['mm_cos'], 3); ?><?php endif; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="n2j-hint mt-2">Показаны первые шесть запросов набора — по ним видно, где модель путает соседние услуги.</p>
        </section>
        <?php endif; ?>

        <?php if ($data['notes']): ?>
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <?php foreach ($data['notes'] as $note): ?>
            <section class="rounded-xl border p-4 <?php echo $tone[$note['tone']] ?? $tone['gray']; ?>">
                <p class="text-sm font-semibold"><?php echo HTML::chars($note['title']); ?></p>
                <p class="mt-1 text-xs opacity-90"><?php echo HTML::chars($note['text']); ?></p>
            </section>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <section class="n2j-card mt-5">
            <h2 class="n2j-h3">Паспорт путей</h2>
            <div class="mt-2 grid gap-4 sm:grid-cols-2">
                <?php foreach (['text' => $text, 'mm' => $mm] as $key => $side): ?>
                <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        <i class="fas <?php echo HTML::chars((string) $side['icon']); ?> text-gray-400"></i>
                        <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo HTML::chars((string) $side['label']); ?></p>
                        <span class="<?php echo ! empty($side['ok']) ? 'n2j-badge n2j-badge-ok' : 'n2j-badge n2j-badge-err'; ?>">
                            <?php echo ! empty($side['ok']) ? 'работает' : 'нет'; ?>
                        </span>
                    </div>
                    <dl class="mt-2 space-y-1">
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-400">Адрес</dt>
                            <dd class="text-right font-mono text-[11px] text-gray-700 dark:text-gray-200"><?php echo HTML::chars($side['base_url'] !== '' ? (string) $side['base_url'] : (string) $side['env']); ?></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-400">Модель</dt>
                            <dd class="text-right text-gray-700 dark:text-gray-200"><?php echo HTML::chars($side['model'] !== '' ? (string) $side['model'] : '—'); ?></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-400">Размерность</dt>
                            <dd class="text-gray-700 dark:text-gray-200"><?php echo $side['dims'] > 0 ? (int) $side['dims'] : '—'; ?></dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-400">Всего времени</dt>
                            <dd class="text-gray-700 dark:text-gray-200"><?php echo $side['total_ms'] > 0 ? round($side['total_ms'] / 1000, 1).' с' : '—'; ?></dd>
                        </div>
                    </dl>
                    <?php if ( ! empty($side['notes'])): ?>
                    <ul class="mt-2 space-y-1 text-[11px] text-gray-500 dark:text-gray-400">
                        <?php foreach ((array) $side['notes'] as $line): ?>
                        <li>· <?php echo HTML::chars((string) $line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if ( ! empty($side['ok']) AND $side['recall1'] !== NULL): ?>
                    <p class="mt-2">
                        <span class="<?php echo $badge[$side['grades']['recall1']['tone']] ?? $badge['gray']; ?>">
                            поиск: <?php echo HTML::chars($side['grades']['recall1']['text']); ?>
                        </span>
                        <span class="<?php echo $badge[$side['grades']['repeat']['tone']] ?? $badge['gray']; ?>">
                            вектор: <?php echo HTML::chars($side['grades']['repeat']['text']); ?>
                        </span>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <details class="mt-5 rounded-xl border border-gray-200 p-4 text-xs dark:border-gray-700">
            <summary class="cursor-pointer font-semibold text-gray-700 dark:text-gray-200">Сырой ответ воркера (jsonb)</summary>
            <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-gray-900 p-3 text-[11px] leading-relaxed text-gray-100"><?php
                echo HTML::chars((string) json_encode((array) $run['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            ?></pre>
        </details>

        <?php endif; ?>

    <?php endif; ?>

</div>

<aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Кто что использует</h2>
        <ul class="mt-2 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>
                <a href="/veclab" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Лаборатория векторов</a>
                — мультимодальный путь: текст и картинка в одном пространстве.
            </li>
            <li>
                <a href="/similar" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Похожие картинки</a>
                — тоже мультимодальный: без него поиск картинкой невозможен.
            </li>
            <li>
                Семантический индекс (nj115) и поиск по статьям — текстовый путь:
                материала много, картинок нет.
            </li>
        </ul>
        <p class="n2j-hint mt-2">
            Смена модели — это смена пространства: старые векторы придётся пересчитать,
            а ширина колонки vector(N) задаётся миграцией один раз.
        </p>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Повторить в консоли</h2>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-3 text-[11px] leading-relaxed text-gray-100">cd workers
python3 nj671_bench.py --quick
python3 nj671_bench.py
python3 nj671_bench.py --json /tmp/bench.json</pre>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Тот же код, что в канале <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">nj671.bench</code>:
            консоль видит те же адреса и считает те же цифры.
        </p>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Проверить пути</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Диагностика мультимодального сервиса отдельно — доктор лаборатории векторов:
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-3 text-[11px] leading-relaxed text-gray-100">cd workers
python3 nj653_doctor.py</pre>
    </section>
</aside>
</div>
</div>
