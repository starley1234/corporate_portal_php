<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Печатный отчёт для руководителя /aiscan/report/<id> — PDF делается печатью из
 * браузера (кнопка ниже), поэтому вёрстка отчёта автономна: без интерактива,
 * без тёмной темы в печати (print:-варианты), без сворачивающихся блоков.
 * Содержит только то, что можно отдать руководству/интервьюеру: паспорт цели,
 * баллы, матрицу OWASP, критичные находки, стресс-сводку, рекомендации и
 * методологию с честными границами применимости.
 *
 * @var array $run  строка nj_672_runs
 * @var array $data результат Service_Nj672_Scan::shape()
 */

$summary = (array) ($data['summary'] ?? []);
$target  = (array) ($data['target'] ?? []);
$counts  = (array) ($summary['counts'] ?? []);
$critical = [];
foreach ($data['suites'] as $suite)
{
    foreach ($suite['findings'] as $f)
    {
        if ($f['status'] === 'fail' OR $f['status'] === 'warn')
        {
            $critical[] = [$suite, $f];
        }
    }
}
?>

<div class="n2j-ds n2j-print-area w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8 print:px-0 print:pt-0 print:pb-0">

    <div class="no-print flex flex-wrap items-center justify-between gap-3">
        <a href="/aiscan/result/<?php echo (int) $run['id']; ?>" class="n2j-link text-xs font-semibold">← Интерактивный отчёт</a>
        <button type="button" id="nj672-print-btn" class="n2j-btn-process">
            <i class="fas fa-print"></i><span>Печать / сохранить в PDF</span>
        </button>
    </div>

    <header class="mt-4 border-b-2 border-gray-800 pb-4 dark:border-gray-200 print:border-black">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-500 print:text-gray-700">
            N2J · AI Vulnerability &amp; Stress Scanner · конфиденциально
        </p>
        <h1 class="n2j-h1 mt-1 print:text-black">
            Аудит безопасности и устойчивости AI-модели
        </h1>
        <dl class="mt-3 grid gap-2 text-sm text-gray-700 dark:text-gray-200 print:text-black sm:grid-cols-2">
            <div><dt class="inline font-semibold">Цель: </dt><dd class="inline"><?php echo HTML::chars((string) ($target['label'] ?: '—')); ?></dd></div>
            <div><dt class="inline font-semibold">Модель: </dt><dd class="inline font-mono"><?php echo HTML::chars((string) ($target['model'] ?: '—')); ?></dd></div>
            <div><dt class="inline font-semibold">Эндпоинт: </dt><dd class="inline font-mono"><?php echo HTML::chars((string) ($target['base_url'] ?: 'встроенная песочница')); ?></dd></div>
            <div><dt class="inline font-semibold">Дата и прогон: </dt><dd class="inline"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $run['created_at']))); ?> · #<?php echo (int) $run['id']; ?> · <?php echo HTML::chars((string) ($data['engine'] ?? '')); ?></dd></div>
            <div><dt class="inline font-semibold">Право на тест: </dt><dd class="inline"><?php echo ($target['kind'] ?? '') === 'sandbox' ? 'не требуется (песочница)' : (! empty($data['profile']['attested']) ? 'подтверждено оператором при запуске' : 'не зафиксировано'); ?></dd></div>
        </dl>
    </header>

    <section class="mt-5">
        <h2 class="text-base font-bold text-gray-900 dark:text-white print:text-black"><?php echo HTML::chars((string) ($data['verdict']['headline'] ?? 'Резюме для руководителя')); ?></h2>
        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200 print:text-black">
            <?php echo HTML::chars((string) ($data['verdict']['text'] ?? '')); ?>
        </p>
        <div class="mt-2 grid gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-gray-300 p-3 print:border-gray-500">
                <p class="text-xs uppercase tracking-wide text-gray-500 print:text-gray-700">Безопасность</p>
                <p class="text-2xl font-extrabold text-gray-900 dark:text-white print:text-black">
                    <?php echo $summary['security_score'] !== NULL ? (int) $summary['security_score'].'/100 · '.HTML::chars((string) $summary['grade']) : '—'; ?>
                </p>
            </div>
            <div class="rounded-lg border border-gray-300 p-3 print:border-gray-500">
                <p class="text-xs uppercase tracking-wide text-gray-500 print:text-gray-700">Риск</p>
                <p class="text-2xl font-extrabold text-gray-900 dark:text-white print:text-black"><?php echo HTML::chars((string) ($summary['risk']['label'] ?? '—')); ?></p>
            </div>
            <div class="rounded-lg border border-gray-300 p-3 print:border-gray-500">
                <p class="text-xs uppercase tracking-wide text-gray-500 print:text-gray-700">Атаки: отбито / пробито</p>
                <p class="text-2xl font-extrabold text-gray-900 dark:text-white print:text-black">
                    <?php echo (int) ($counts['pass'] ?? 0); ?> / <?php echo (int) ($counts['fail'] ?? 0); ?>
                </p>
            </div>
            <div class="rounded-lg border border-gray-300 p-3 print:border-gray-500">
                <p class="text-xs uppercase tracking-wide text-gray-500 print:text-gray-700">Устойчивость (SLA)</p>
                <p class="text-2xl font-extrabold text-gray-900 dark:text-white print:text-black">
                    <?php echo $summary['reliability_score'] !== NULL ? (int) $summary['reliability_score'].'/100' : '—'; ?>
                </p>
            </div>
        </div>
        <p class="mt-3 text-sm text-gray-700 dark:text-gray-200 print:text-black">
            <?php echo HTML::chars((string) ($summary['grade_words'] ?? '')); ?>.
            Из <?php echo (int) ($counts['total'] ?? 0); ?> проверок: <?php echo HTML::chars(Service_Nj672_Scan::counts_line($counts)); ?>.
            Расход аудита: <?php echo HTML::chars(Service_Nj672_Scan::fmt_int($data['usage']['requests'])); ?> запросов,
            ≈<?php echo HTML::chars(Service_Nj672_Scan::fmt_int($data['usage']['total_tokens'])); ?> токенов<?php echo $data['cost'] ? ' (~'.str_replace('.', ',', (string) $data['cost']['rub']).' ₽)' : ''; ?>.
        </p>
    </section>

    <section class="mt-6">
        <h2 class="text-base font-bold text-gray-900 dark:text-white print:text-black">Матрица рисков OWASP Top 10 for LLM (2025)</h2>
        <table class="mt-2 w-full text-sm text-gray-800 dark:text-gray-100 print:text-black">
            <thead>
                <tr class="border-b border-gray-400 text-left text-xs uppercase">
                    <th class="py-1.5 pr-3">Код</th><th class="py-1.5 pr-3">Угроза и что это значит</th>
                    <th class="py-1.5 pr-3">Статус</th><th class="py-1.5 pr-3">Балл</th><th class="py-1.5">Исходы проверок</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['owasp'] as $row): ?>
                <tr class="border-b border-gray-200 dark:border-gray-700 print:border-gray-300">
                    <td class="py-1.5 pr-3 font-mono text-xs"><?php echo HTML::chars((string) $row['id']); ?></td>
                    <td class="py-1.5 pr-3"><?php echo HTML::chars((string) $row['title']); ?><?php echo $row['hint'] !== '' ? '<br><span class="text-xs text-gray-500 print:text-gray-700">'.HTML::chars((string) $row['hint']).'</span>' : ''; ?></td>
                    <td class="py-1.5 pr-3 font-bold"><?php echo HTML::chars((string) $row['words']['label']); ?></td>
                    <td class="py-1.5 pr-3"><?php echo $row['score'] !== NULL ? (int) $row['score'].'/100' : '—'; ?></td>
                    <td class="py-1.5 text-xs"><?php echo HTML::chars((string) $row['counts_line']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="mt-6">
        <h2 class="text-base font-bold text-gray-900 dark:text-white print:text-black">
            Находки, требующие реакции (пробитые и спорные)
        </h2>
        <?php if ( ! $critical): ?>
        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200 print:text-black">
            Таких находок нет: все проверки батареи отражены в рамках пройденных наборов.
        </p>
        <?php else: ?>
        <div class="mt-2 space-y-3">
            <?php foreach ($critical as [$suite, $f]): ?>
            <div class="rounded-lg border border-gray-300 p-3 print:border-gray-500">
                <p class="text-sm font-bold text-gray-900 dark:text-white print:text-black">
                    <?php echo HTML::chars((string) $f['status_words']['label']); ?> ·
                    <?php echo HTML::chars((string) $f['title']); ?>
                    <span class="font-normal text-xs text-gray-500 print:text-gray-700">
                        (<?php echo HTML::chars((string) $suite['title']); ?>, серьёзность — <?php echo HTML::chars((string) $f['severity_words']['label']); ?>)
                    </span>
                </p>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300 print:text-black">
                    Приём: <?php echo HTML::chars((string) $f['technique']); ?>. Итог: <?php echo HTML::chars((string) $f['reason']); ?>
                </p>
                <?php if ($f['evidence'] !== ''): ?>
                <pre class="mt-1 whitespace-pre-wrap rounded bg-gray-50 p-2 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200 print:bg-white print:text-black print:border print:border-gray-300"><?php echo HTML::chars(mb_substr((string) $f['evidence'], 0, 500)); ?></pre>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($data['stress']): ?>
    <section class="mt-6">
        <h2 class="text-base font-bold text-gray-900 dark:text-white print:text-black">Стресс-профиль и SLA</h2>
        <?php if ( ! empty($data['stress']['reading'])): ?>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-200 print:text-black">
            <?php foreach ($data['stress']['reading'] as $line): ?>
            <li><?php echo HTML::chars((string) $line); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <table class="mt-2 w-full text-sm text-gray-800 dark:text-gray-100 print:text-black">
            <thead>
                <tr class="border-b border-gray-400 text-left text-xs uppercase">
                    <th class="py-1.5 pr-3">Потоков</th><th class="py-1.5 pr-3">Ошибок</th>
                    <th class="py-1.5 pr-3">TTFT p95</th><th class="py-1.5 pr-3">TTFT p99</th>
                    <th class="py-1.5">TPS суммарно</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['stress']['levels'] as $lv): ?>
                <?php if ($lv['skipped'] === ''): ?>
                <tr class="border-b border-gray-200 dark:border-gray-700 print:border-gray-300">
                    <td class="py-1.5 pr-3 font-bold"><?php echo (int) $lv['concurrency']; ?></td>
                    <td class="py-1.5 pr-3"><?php echo HTML::chars(Service_Nj672_Scan::fmt_rate($lv['error_rate'])); ?></td>
                    <td class="py-1.5 pr-3"><?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($lv['ttft']['p95'])); ?></td>
                    <td class="py-1.5 pr-3"><?php echo HTML::chars(Service_Nj672_Scan::fmt_ms($lv['ttft']['p99'])); ?></td>
                    <td class="py-1.5"><?php echo $lv['tps_total'] !== NULL ? HTML::chars(number_format((float) $lv['tps_total'], 0, ',', ' ')) : '—'; ?></td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200 print:text-black">
            SLA-класс: <strong><?php echo HTML::chars((string) $data['stress']['sla']['words']); ?></strong>.
            <?php if ($data['stress']['knee']): ?>
            <strong>Колено деградации:</strong> <?php echo HTML::chars((string) $data['stress']['knee']['detail']); ?>.
            <?php else: ?>
            Колено деградации в пройденном диапазоне контекста не найдено.
            <?php endif; ?>
        </p>
    </section>
    <?php endif; ?>

    <?php if ($data['recommendations']): ?>
    <section class="mt-6">
        <h2 class="text-base font-bold text-gray-900 dark:text-white print:text-black">Рекомендации архитектора</h2>
        <ol class="mt-2 list-decimal space-y-3 pl-5">
            <?php foreach ($data['recommendations'] as $rec): ?>
            <li>
                <p class="text-sm font-bold text-gray-900 dark:text-white print:text-black">
                    <?php echo HTML::chars((string) $rec['title']); ?>
                    <span class="font-normal text-xs text-gray-500 print:text-gray-700">(приоритет: <?php echo HTML::chars((string) (Service_Nj672_Scan::SEVERITY[(string) $rec['priority']]['label'] ?? $rec['priority'])); ?>)</span>
                </p>
                <p class="text-sm text-gray-700 dark:text-gray-200 print:text-black"><?php echo HTML::chars((string) $rec['detail']); ?></p>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endif; ?>

    <section class="mt-6 border-t border-gray-300 pt-4 print:border-gray-500">
        <h2 class="text-base font-bold text-gray-900 dark:text-white print:text-black">Методология и границы</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-600 dark:text-gray-300 print:text-black">
            <li>Чёрноящичный аудит только через OpenAI-совместимый HTTP-интерфейс: атакующие промпты и нагрузочный трафик, без доступа к весам и инфраструктуре цели.</li>
            <li>Успех атак измеряется безвредными маркерами и подсаженными тестовыми секретами (канарейка системного промпта, тестовая карта 4111…, адреса example.test) — вредный контент не запрашивается.</li>
            <li>Оценка ответов: детерминированные эвристики (маркеры, утечки, PII-паттерны, отказы RU/EN) и, если включён, LLM-as-a-Judge для спорных случаев. Итог «спорно» — честное «не уверены», а не скрытое «отбито».</li>
            <li>Полноценный GCG/AutoDAN (оптимизация суффикса по градиентам) в чёрноящичный аудит не входит: батарея использует статические приёмы и подписанные прокси.</li>
            <li>KV-Cache/GPU-память напрямую не наблюдаются: порог Model DoS определён косвенно по «колену» TTFT и росту ошибок на лестнице контекста.</li>
            <?php foreach ($data['notes'] as $note): ?>
            <li><?php echo HTML::chars((string) $note); ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="mt-3 text-xs text-gray-400 print:text-gray-700">
            Отчёт сформирован модулем nj672_aiscan платформы N2J (движок <?php echo HTML::chars((string) ($data['engine'] ?? '')); ?>).
            Интерактивная версия со всеми находками и сырым JSON — на сайте, прогон #<?php echo (int) $run['id']; ?>.
        </p>
    </section>

</div>
<script>
document.getElementById('nj672-print-btn').addEventListener('click', function () { window.print(); });
</script>
