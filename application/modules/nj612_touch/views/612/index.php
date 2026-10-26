<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /touches — мои серии касаний + форма создания.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$status_ru = [
    'active'   => 'идёт', 'paused' => 'пауза', 'done' => 'завершена',
    'stopped'  => 'остановлена', 'answered' => 'ответил ✓',
];
$status_cl = [
    'active'   => 'bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300',
    'paused'   => 'bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-300',
    'done'     => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-300',
    'stopped'  => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300',
    'answered' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <h1 class="n2j-h1"><i class="fas fa-hand-point-right mr-2 text-blue-500"></i>Серии касаний</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Первое письмо и вежливые напоминания по компании. Черновики пишет ИИ, отправляете кнопкой, а если контакт ответил (видно по входящим) — серия останавливается сама.</p>
    </div>

    <div class="gap-5 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- Создание серии (2/3) -->
        <div class="space-y-4 md:col-span-2">
            <div class="<?php echo $card; ?>">
                <?php if ( ! $logged): ?>
                <?php echo Gate::wall('touches', [
                    'icon'   => 'fas fa-hand-point-right',
                    'title'  => 'Серии касаний — по бесплатному аккаунту',
                    'text'   => 'Черновики писем пишет ИИ, а напоминания уходят по расписанию. Аккаунт нужен, чтобы серии были вашими: ответ клиента автоматически останавливает цепочку, а переписка видна с любого устройства.',
                    'points' => [
                        'Серия строится сама: письмо и вежливые напоминания по шагам',
                        'Черновики готовит ИИ — вы правите и отправляете кнопкой',
                        'Клиент ответил — серия останавливается автоматически',
                        'История шагов и отправок в аккаунте, а не в одном браузере',
                    ],
                    'stats'  => ['Beta, бесплатно', 'шаг напоминания — 4 дня'],
                    'cta'    => 'Войти и начать серию',
                    'note'   => 'Перед серией полезно собрать профиль компании: письмо станет точнее, а факты — из источников.',
                ]); ?>
                <?php else: ?>
                <h2 class="n2j-h3"><i class="fas fa-plus mr-1.5 text-blue-500"></i>Новая серия</h2>
                <form method="post" action="/touches" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="create">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="n2j-label">Компания <span class="text-rose-500">*</span></label>
                            <input type="text" name="company" required maxlength="300" placeholder="ООО «Пример-Маш»"
                                   value="<?php echo HTML::chars((string) Arr::get($_GET, 'company', '')); ?>"
                                   class="n2j-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="n2j-label">Email контакта</label>
                            <input type="email" name="email" maxlength="200" placeholder="ivan@prim-mash.ru"
                                   value="<?php echo HTML::chars((string) Arr::get($_GET, 'email', '')); ?>"
                                   class="n2j-input mt-1 w-full">
                        </div>
                    </div>
                    <div>
                        <label class="n2j-label">Имя контакта <span class="font-normal text-xs text-gray-400">(для приветствия)</span></label>
                        <input type="text" name="contact_name" maxlength="200" placeholder="Иван"
                               class="n2j-input mt-1 w-full">
                    </div>
                    <div>
                        <label class="n2j-label">Что вы предлагаете <span class="text-rose-500">*</span></label>
                        <textarea name="offer" required rows="3" maxlength="2000" placeholder="Например: обслуживаем станки с ЧПУ по договору — выезд в течение суток"
                                  class="n2j-textarea min-h-[44px] mt-1 w-full"></textarea>
                    </div>
                    <div class="flex flex-wrap gap-4">
                        <div>
                            <label class="n2j-label">Тон</label>
                            <select name="tone" class="n2j-input n2j-select mt-1 w-auto">
                                <option value="business">деловой</option>
                                <option value="friendly">дружелюбный</option>
                                <option value="bold">смелый</option>
                            </select>
                        </div>
                        <div>
                            <label class="n2j-label">Шагов</label>
                            <select name="steps" class="min-h-[44px] mt-1 block w-24 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                <?php for ($i = 1; $i <= (int) $limits['max_steps']; $i++): ?>
                                <option value="<?php echo $i; ?>"<?php echo $i === 3 ? ' selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="n2j-btn-cta w-full">
                        <i class="fas fa-pen-nib mr-1"></i>Создать серию — первый черновик сразу
                    </button>
                    <p class="text-sm text-gray-400">Пауза между шагами — 4 дня. Черновик каждого шага можно переписать или отправить вручную; адресат ответит — серия остановится.</p>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Мои серии (1/3) -->
        <div class="space-y-4" id="my">
            <?php // Пустой список своих серий гостю не показываем: «Пока ни одной серии»
                  // — это утверждение о его аккаунте, а аккаунта у гостя ещё нет. ?>
            <?php if ($logged): ?>
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-list mr-1.5 text-gray-400"></i>Мои серии</p>
                <?php if (empty($sequences)): ?>
                <p class="mt-2 border border-dashed border-gray-300 p-4 text-center text-sm text-gray-400 dark:border-gray-600">Пока ни одной серии.</p>
                <?php else: ?>
                <ul class="mt-2 space-y-1.5">
                    <?php foreach ($sequences as $q): $q = (array) $q; ?>
                    <li class="rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <a href="/touches/<?php echo HTML::chars($q['code']); ?>" class="min-w-0 flex-1 truncate text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($q['company']); ?></a>
                            <span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase <?php echo $status_cl[$q['status']] ?? $status_cl['stopped']; ?>"><?php echo $status_ru[$q['status']] ?? HTML::chars($q['status']); ?></span>
                        </div>
                        <p class="n2j-hint mt-0.5">шаг <?php echo (int) $q['current_step']; ?>/<?php echo (int) $q['steps_total']; ?><?php echo (int) $q['ready'] > 0 ? ' · <span class="font-semibold text-blue-500">черновик готов</span>' : ''; ?></p>
                        <form method="post" action="/touches" class="mt-1">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="act" value="delete">
                            <input type="hidden" name="code" value="<?php echo HTML::chars($q['code']); ?>">
                            <button data-n2j-confirm="Удалить серию «<?php echo HTML::chars($q['company']); ?>» вместе с письмами?" data-n2j-title="Удалить серию?" type="submit" class="text-xs font-semibold text-rose-400 hover:text-rose-500"><i class="fas fa-trash-can mr-1"></i>удалить</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Как это работает</p>
                <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li>ИИ пишет письмо под компанию и ваш оффер — с крючком, не шаблон.</li>
                    <li>Вы правите и отправляете (или включается автоотправка у админа).</li>
                    <li>Через 4 дня — вежливое напоминание с учётом прошлого письма.</li>
                    <li>Контакт ответил — серия остановлена, дальше вы общаетесь сами.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

