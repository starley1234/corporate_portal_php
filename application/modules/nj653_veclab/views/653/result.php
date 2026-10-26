<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Отдельная страница результата поиска по смыслу (модуль nj653_veclab).
 *
 * Поле ввода здесь не монтируется: мобильная клавиатура закрывается при
 * переходе, а запрос, живой статус и находки собраны на одной странице.
 * Рабочая область выровнена слева и занимает ширину страницы, как /veclab
 * и /veclab/admin.
 *
 * @var array $run     строка прогона
 * @var array $query   сохранённый запрос (текст и миниатюра)
 * @var array $result  Service_Nj653_Veclab::shape()
 * @var array $live    контракт общего статус-ряда
 * @var string $hint   подсказка дожима
 * @var bool $running  выполняется ли задача
 */
$state       = (string) ($run['status'] ?? 'error');
$query_text  = trim((string) ($query['content'] ?? ''));
$query_image = Service_Nj653_Veclab::safe_preview((string) ($query['preview'] ?? ''));
$hits        = (array) ($result['hits'] ?? []);
$tone_class  = [
    'emerald' => 'text-emerald-700 dark:text-emerald-300',
    'sky'     => 'text-blue-700 dark:text-blue-300',
    'amber'   => 'text-amber-700 dark:text-amber-300',
    'rose'    => 'text-rose-700 dark:text-rose-300',
    'gray'    => 'text-gray-600 dark:text-gray-300',
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <a href="/veclab#search" class="inline-flex min-h-[40px] items-center gap-2 text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">
        <i class="fas fa-arrow-left" aria-hidden="true"></i>Новый поиск
    </a>

    <header class="mt-3">
        <h1 class="n2j-h1">Результаты поиска по смыслу</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Запрос остаётся на этой странице, пока модель ищет ближайшие элементы вашей коллекции.</p>
    </header>

    <section class="n2j-card mt-5">
        <div class="flex flex-wrap items-start gap-4">
            <?php if ($query_image !== ''): ?>
            <img src="<?php echo HTML::chars($query_image); ?>" alt="Картинка запроса" class="h-20 w-20 shrink-0 rounded-lg border border-gray-200 bg-gray-50 object-cover dark:border-gray-700 dark:bg-gray-900">
            <?php else: ?>
            <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-500 dark:bg-blue-950/40 dark:text-blue-300" aria-hidden="true"><i class="fas fa-magnifying-glass text-xl"></i></span>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Ваш запрос</p>
                <?php if ($query_text !== ''): ?>
                <p class="mt-1 break-words text-base font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($query_text); ?></p>
                <?php elseif ($query_image !== ''): ?>
                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">Поиск по картинке</p>
                <?php else: ?>
                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">Запрос сохранён</p>
                <?php endif; ?>
                <p class="n2j-hint">
                    <?php echo HTML::chars(Service_Nj653_Veclab::kind_label((string) ($query['kind'] ?? 'text'))); ?>
                    · <?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) ($run['created_at'] ?? 'now')))); ?>
                    <?php if ((int) ($result['elapsed_ms'] ?? 0) > 0): ?>
                    · модель ответила за <?php echo (int) round((int) $result['elapsed_ms'] / 1000); ?> с
                    <?php endif; ?>
                </p>
            </div>
            <span class="n2j-badge <?php echo $state === 'done' ? 'n2j-badge-ok' : ($running ? 'n2j-badge-info' : 'n2j-badge-err'); ?>">
                <?php echo $state === 'done' ? 'Завершено' : ($running ? 'Ищем…' : ($state === 'cancelled' ? 'Остановлено' : 'Ошибка')); ?>
            </span>
        </div>

        <?php if ($running): ?>
            <?php echo View::factory('partials/live_status', [
                'url'     => '/veclab/api/status?id='.(int) $run['id'],
                'title'   => 'Ищу по смыслу',
                'status'  => (string) $live['status'],
                'stage'   => (string) $live['stage'],
                'elapsed' => (int) $live['elapsed_sec'],
                'silent'  => $live['silent_sec'],
                'steps'   => (array) $live['stages'],
                'note'    => $hint !== '' ? $hint : 'Страница обновится сама, когда появятся результаты.',
            ]); ?>
            <?php echo View::factory('partials/live_status_js'); ?>
        <?php elseif ($state !== 'done'): ?>
            <div class="n2j-flash n2j-flash-err mt-4 block">
                <?php echo HTML::chars((string) ($run['error'] ?? '') !== '' ? $run['error'] : 'Не получилось выполнить поиск. Попробуйте ещё раз.'); ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($state === 'done'): ?>
    <section class="n2j-card mt-5">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="n2j-h3">Что нашлось</h2>
                <p class="n2j-hint mt-0.5">Сходство показано числом и словами — каждый результат из вашей коллекции.</p>
            </div>
            <span class="n2j-badge n2j-badge-wait"><?php echo count($hits); ?> <?php echo count($hits) === 1 ? 'находка' : 'находок'; ?></span>
        </div>

        <?php if ($hits): ?>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($hits as $hit): ?>
            <?php $score = (array) ($hit['score'] ?? []); $tone = (string) ($score['tone'] ?? 'gray'); ?>
            <li class="flex min-w-0 gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <?php if ((string) ($hit['preview'] ?? '') !== ''): ?>
                <img src="<?php echo HTML::chars($hit['preview']); ?>" alt="<?php echo HTML::chars($hit['label']); ?>" loading="lazy" class="h-16 w-16 shrink-0 rounded-lg border border-gray-200 bg-gray-50 object-cover dark:border-gray-700 dark:bg-gray-900">
                <?php else: ?>
                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-400 dark:bg-gray-900"><i class="fas <?php echo HTML::chars(Service_Nj653_Veclab::kind_icon($hit['kind'] ?? 'text')); ?>"></i></span>
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                    <p class="break-words text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($hit['label']); ?></p>
                    <p class="n2j-hint mt-0.5"><?php echo HTML::chars(Service_Nj653_Veclab::kind_label($hit['kind'])); ?></p>
                    <?php if ((string) ($hit['content'] ?? '') !== ''): ?>
                    <p class="mt-1 line-clamp-2 break-words text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($hit['content']); ?></p>
                    <?php endif; ?>
                    <p class="mt-2 text-xs font-semibold <?php echo $tone_class[$tone] ?? $tone_class['gray']; ?>">
                        <?php echo HTML::chars(number_format((float) ($score['value'] ?? 0), 4, ',', ' ')); ?> · <?php echo HTML::chars((string) ($score['title'] ?? 'близость')); ?>
                    </p>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="mt-4 rounded-lg bg-gray-50 px-3 py-4 text-sm text-gray-600 dark:bg-gray-900 dark:text-gray-300">Похожих элементов пока нет. Добавьте картинки или описания в коллекцию и повторите поиск.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
