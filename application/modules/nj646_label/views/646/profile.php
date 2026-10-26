<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Мой профиль (nj646_label) — /label/profile.
 *
 * Профиль задаёт персональные предупреждения в разборах и хранится под
 * анонимной меткой браузера. Медицински чувствительные данные удаляются
 * после года без обращений; пользователь также может стереть их вручную.
 * Макет страницы следует /pages/required_design_sample и использует полную
 * ширину рабочей области, как /label.
 */
$conditions = [
    'allergy'      => 'Аллергии',
    'pregnancy'    => 'Беременность / кормление',
    'diabetes'     => 'Диабет',
    'hypertension' => 'Гипертония',
    'kids'         => 'Покупаю ребёнку',
    'asthma'       => 'Астма',
    'pku'          => 'Фенилкетонурия',
    'kidney'       => 'Болезни почек',
    'acne'         => 'Склонность к акне',
];
$saved = array_map('strval', (array) ($profile['conditions'] ?? []));
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <header>
        <div class="flex flex-wrap items-center gap-2.5">
            <h1 class="n2j-h1">Мой профиль</h1>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
                <i class="fas fa-user-gear text-[11px]" aria-hidden="true"></i>Настройки
            </span>
        </div>
        <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">
            Отметьте важные ограничения — сервис добавит персональные предупреждения в разборы состава.
            Настройки относятся только к этому браузеру и не заменяют консультацию специалиста.
        </p>
    </header>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <div class="min-w-0 md:col-span-2">
            <section class="n2j-card">
                <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                    <h2 class="n2j-h3">Что учитывать в разборах</h2>
                    <p class="n2j-hint mt-0.5">Выбранные пункты повлияют на предупреждения, но не меняют оценку состава.</p>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label for="nj646-skin" class="block text-xs font-semibold text-gray-800 sm:text-sm dark:text-gray-200">
                        Тип кожи
                        <select id="nj646-skin" class="n2j-input n2j-select mt-1.5 w-full font-normal">
                            <?php foreach ([
                                'normal' => 'Обычная', 'dry' => 'Сухая', 'oily' => 'Жирная',
                                'combination' => 'Комбинированная', 'sensitive' => 'Чувствительная',
                            ] as $key => $label): ?>
                                <option value="<?php echo HTML::chars($key); ?>" <?php echo (string) ($profile['skin'] ?? '') === $key ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label for="nj646-diet" class="block text-xs font-semibold text-gray-800 sm:text-sm dark:text-gray-200">
                        Питание
                        <select id="nj646-diet" class="n2j-input n2j-select mt-1.5 w-full font-normal">
                            <?php foreach ([
                                'none' => 'Без ограничений', 'vegetarian' => 'Вегетарианство', 'vegan' => 'Веганство',
                            ] as $key => $label): ?>
                                <option value="<?php echo HTML::chars($key); ?>" <?php echo (string) ($profile['diet'] ?? 'none') === $key ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <fieldset class="mt-5">
                    <legend class="text-xs font-semibold text-gray-800 sm:text-sm dark:text-gray-200">Ограничения и особенности</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3" id="nj646-conditions">
                        <?php foreach ($conditions as $code => $label): ?>
                            <label class="flex min-h-[44px] items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs text-gray-700 transition hover:border-blue-300 sm:text-sm dark:border-gray-700 dark:text-gray-200 dark:hover:border-blue-700">
                                <input type="checkbox" data-condition="<?php echo HTML::chars($code); ?>" class="h-4 w-4 shrink-0 accent-blue-600" <?php echo in_array($code, $saved, TRUE) ? 'checked' : ''; ?>>
                                <?php echo HTML::chars($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <label for="nj646-allergies" class="mt-5 block text-xs font-semibold text-gray-800 sm:text-sm dark:text-gray-200">
                    Аллергия на конкретное вещество
                    <input id="nj646-allergies" type="text" value="<?php echo HTML::chars($profile['allergies'] ?? ''); ?>" placeholder="например: лимонен" autocomplete="off"
                           class="n2j-input mt-1.5 w-full font-normal">
                    <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">Если вещество встретится в составе, разбор предупредит об этом отдельно.</span>
                </label>

                <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-700/60">
                    <button type="button" id="nj646-profile-save"
                            class="n2j-btn-cta">
                        <i class="fas fa-floppy-disk" aria-hidden="true"></i>Сохранить профиль
                    </button>
                    <span id="nj646-profile-out" role="status" aria-live="polite" class="text-xs text-gray-500 dark:text-gray-400"></span>
                </div>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="n2j-card">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500" aria-hidden="true"></i>
                    <h2 class="n2j-h3">Как используется профиль</h2>
                </div>
                <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><span class="font-bold text-blue-500">•</span><span>Профиль добавляет предупреждения об ингредиентах с учётом выбранных ограничений.</span></li>
                    <li class="flex gap-2"><span class="font-bold text-blue-500">•</span><span>Оценка состава считается по ингредиентам, а не по данным профиля.</span></li>
                    <li class="flex gap-2"><span class="font-bold text-blue-500">•</span><span>Совет справочный и не заменяет врача или медицинскую рекомендацию.</span></li>
                </ul>
            </section>

            <section class="rounded-xl border border-blue-100 bg-blue-50/70 p-4 shadow-xs sm:p-5 dark:border-blue-900/50 dark:bg-blue-950/30">
                <h2 class="n2j-h3"><i class="fas fa-shield-heart mr-1 text-blue-500" aria-hidden="true"></i>Конфиденциальность</h2>
                <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Профиль хранится на сервере под анонимной меткой браузера и не передаётся третьим лицам. После года без обращений он удаляется автоматически.</p>
            </section>
        </aside>
    </div>

    <section class="mt-5 rounded-xl border border-rose-200 bg-rose-50/70 p-4 shadow-xs sm:p-5 dark:border-rose-900/60 dark:bg-rose-950/20">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-3xl">
                <h2 class="text-sm font-bold text-rose-900 dark:text-rose-200"><i class="fas fa-eraser mr-1" aria-hidden="true"></i>Удалить мои данные</h2>
                <p class="mt-1.5 text-xs leading-relaxed text-rose-900/80 dark:text-rose-200/80">
                    Стираются профиль (аллергии, питание, кожа), все разборы этого браузера и записи о них.
                    Общая база веществ и товары остаются — они не привязаны к вам. Действие необратимо.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" id="nj646-forget" data-n2j-confirm="Будут стёрты ваш профиль и все разборы этого браузера. Общая база веществ и товары останутся." data-n2j-title="Удалить мои данные?" data-csrf="<?php echo HTML::chars((string) ($csrf ?? '')); ?>"
                        class="n2j-btn-danger">
                    <i class="fas fa-trash-can" aria-hidden="true"></i><span data-forget-label>Удалить мои данные</span>
                </button>
                <span id="nj646-forget-out" role="status" aria-live="polite" class="text-xs text-rose-800 dark:text-rose-200"></span>
            </div>
        </div>
    </section>
</div>

<?php echo View::factory('646/js/core'); ?>
<?php echo View::factory('646/js/profile'); ?>
