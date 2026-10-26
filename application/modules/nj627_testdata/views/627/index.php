<?php defined('SYSPATH') or die('No direct script access.');

$types = [
    'fio'     => ['ФИО', 'фамилия имя отчество — под тесты форм и документов'],
    'phone'   => ['Телефон', '+7 9XX … — российский мобильный формат'],
    'email'   => ['E-mail', 'адрес в зарезервированном домене example.test'],
    'company' => ['Компания', 'название + ИНН, КПП, ОГРН и СНИЛС с корректными контрольными цифрами'],
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Что генерировать</span>
        <fieldset class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($types as $code => $meta): ?>
                <label class="flex cursor-pointer select-none items-center">
                    <input type="radio" name="nj627-type" value="<?php echo HTML::chars($code); ?>"<?php echo $type === $code ? ' checked' : ''; ?> class="peer sr-only">
                    <span class="flex w-full items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium text-gray-600 transition hover:border-gray-400 hover:text-gray-800 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-500 dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-900/30 dark:peer-checked:text-blue-300">
                        <span><?php echo HTML::chars($meta[0]); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <p id="nj627-sub" class="n2j-hint mt-2"><?php echo HTML::chars($types[$type][1]); ?></p>

        <div class="mt-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="nj627-count" class="n2j-label">Количество</label>
                <input id="nj627-count" type="number" min="1" max="100" value="5"
                       class="n2j-input mt-1 w-24 font-mono">
            </div>
            <button type="button" id="nj627-gen" class="n2j-btn-process ml-auto">
                <i class="fas fa-wand-magic-sparkles"></i> Сгенерировать
            </button>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-list-ul mr-1.5 text-gray-400"></i>Результат</p>
            <button type="button" id="nj627-copy" class="n2j-btn-secondary n2j-btn-sm" disabled>
                <i class="fas fa-copy mr-1"></i>Копировать все
            </button>
        </div>
        <ul id="nj627-list" class="mt-3 space-y-1.5 font-mono text-sm text-gray-800 dark:text-gray-100"></ul>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>Данные вымышленные, но <b>валидны по алгоритмам</b> контрольных цифр — под QA.</li>
            <li>ИНН 10 знаков — юрлицо, 12 — предприниматель/физлицо.</li>
            <li>Домен <span class="font-mono">example.test</span> зарезервирован — письма никому не уйдут.</li>
            <li>Генерация криптографически случайная, пачки до 100 штук.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    ИНН настоящие?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет: это вымышленные номера, которые лишь проходят проверку контрольной
                    цифры. Для регистрации в реальных сервисах они не предназначены.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Зачем это нужно?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Чтобы тестировать формы, интеграции и валидаторы на данных, которые
                    выглядят как настоящие, но никому не принадлежат.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Можно ли получить JSON?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: GET /nj627/api/generate?t=company&amp;n=3 — массив сущностей.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('627/script'); ?>
