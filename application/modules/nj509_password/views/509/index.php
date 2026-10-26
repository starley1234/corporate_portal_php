<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Генератор паролей» (nj509_password) — страница.
 *
 * SSR: стартовый пароль уже сгенерирован на сервере ($generated).
 * Дальше пересчёт на клиенте (views/509/script.php, crypto.getRandomValues).
 * Элементы UI: слайдер длины, тумблеры наборов, entropy-метр, копирование,
 * пачка из 5 вариантов, FAQ. Пароли нигде не сохраняются.
 */
$strength = Service_Nj509_Password::strength($generated['entropy']);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="gap-4 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">
<div class="space-y-4 md:col-span-2">

    <!-- ============ Пароль ============ -->
    <div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-5 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20">
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Ваш пароль</p>
            <span id="pw-entropy" class="rounded-md bg-white/70 px-2 py-0.5 text-xs font-bold text-gray-500 dark:bg-gray-800/70 dark:text-gray-400"
                  title="Энтропия, бит"><?php echo (float) $generated['entropy']; ?> бит</span>
        </div>

        <p id="pw-out" class="mt-2 break-all font-mono text-xl font-bold leading-relaxed text-gray-900 selection:bg-blue-200 dark:text-white dark:selection:bg-blue-900"
           data-nosnippet><?php echo HTML::chars($generated['items'][0]); ?></p>

        <!-- Стойкость: цветной бейдж вместо полосы-метра (полосы убраны везде) -->
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Стойкость</span>
            <span id="pw-strength" class="rounded-md px-2 py-0.5 text-xs font-bold <?php echo $strength[1]; ?>"><?php echo $strength[0]; ?></span>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button" id="pw-regen"
                    class="n2j-btn-process">
                <i class="fas fa-rotate"></i> Ещё пароль
            </button>
            <button type="button" id="pw-copy"
                    class="n2j-btn-secondary">
                <i class="fas fa-copy"></i> Копировать
            </button>
            <button type="button" id="pw-bulk"
                    class="n2j-btn-secondary">
                <i class="fas fa-list"></i> 5 вариантов
            </button>
        </div>

        <!-- Пачка вариантов -->
        <div id="pw-bulk-wrap" class="mt-3 hidden space-y-1.5">
            <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="n2j-card p-0 flex items-center gap-2 px-3 py-1.5">
                    <code class="min-w-0 flex-1 truncate font-mono text-sm text-gray-700 dark:text-gray-200" data-pw-slot="<?php echo $i; ?>"></code>
                    <button type="button" data-pw-copy-slot="<?php echo $i; ?>" class="n2j-btn-icon shrink-0 h-8 w-8"
                            title="Скопировать"><i class="fas fa-copy text-xs"></i></button>
                </div>
            <?php endfor; ?>
        </div>
    </div>



    <!-- ============ Настройки ============ -->
    <div class="n2j-card">
        <div class="flex items-baseline justify-between">
            <label for="pw-length" class="n2j-label">Длина пароля</label>
            <span id="pw-length-val" class="rounded-xl bg-blue-50 px-2.5 py-0.5 text-lg font-extrabold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"><?php echo (int) $opts['length']; ?></span>
        </div>
        <input id="pw-length" type="range" min="<?php echo $min_len; ?>" max="<?php echo $max_len; ?>" step="1" value="<?php echo (int) $opts['length']; ?>"
               class="mt-2 h-2 w-full cursor-pointer">

        <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
            <?php foreach ($sets_info as $set): ?>
                <label class="n2j-chip cursor-pointer<?php echo $opts[$set['key']] ? ' is-on' : ''; ?>"
                       data-set-label="<?php echo $set['key']; ?>">
                    <input type="checkbox" data-set="<?php echo $set['key']; ?>"
                           class="min-h-[44px] h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900"
                           <?php if ($opts[$set['key']]) echo 'checked'; ?>>
                    <?php echo HTML::chars($set['label']); ?>
                </label>
            <?php endforeach; ?>
            <label class="n2j-chip cursor-pointer<?php echo $opts['exclude_ambiguous'] ? ' is-on' : ''; ?>"
                   data-set-label="exclude_ambiguous">
                <input type="checkbox" id="pw-exclude-ambiguous"
                       class="min-h-[44px] h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900"
                       <?php if ($opts['exclude_ambiguous']) echo 'checked'; ?>>
                Без похожих
            </label>
        </div>
        <p class="n2j-hint mt-2">«Без похожих» убирает 0/O, 1/l/I, | и кавычки — для паролей, которые читают вслух или вводят с бумаги.</p>
    </div>

</div>

<div class="hidden space-y-4 md:block">
    <!-- ============ FAQ ============ -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Какой длины должен быть пароль?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Для большинства сервисов — 16+ случайных символов (60+ бит энтропии). Для шифровальных
                    дисков, ключей и мастер-паролей менеджеров — 24+ (80+ бит). Длина важнее набора символов:
                    20-символьный из букв и цифр сильнее 8-символьного со всем зоопарком.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Где хранятся сгенерированные пароли?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нигде. Ни база, ни логи, ни localStorage — пароль живёт только на экране, пока вы не
                    скопировали его и не закрыли вкладку. Поэтому после перезагрузки страницы пароль будет другим.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что значит «бит энтропии»?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Мера непредсказуемости: длина × log₂(размер алфавита). 40 бит — перебирается экспертной
                    атакой, 60 — недостижимо для онлайн-перебора, 80+ — бессмысленно даже для ботнетов.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Насколько случаен генератор?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    В браузере используется crypto.getRandomValues, на сервере — random_int; оба —
                    криптостойкие источники, без модульного смещения. Символы перемешиваются Фишером—Йетсом,
                    в пароле гарантирован хотя бы один символ из каждого включённого набора.
                </p>
            </details>
        </div>
    </div>
</div>
</div>
</div>

<?php // Инлайн-скрипт — в конце вьюхи (0 лишних HTTP-запросов)
echo View::factory('509/script');
