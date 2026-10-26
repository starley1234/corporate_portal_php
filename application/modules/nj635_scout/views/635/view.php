<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /scout/<code> — карта шагов разведки контрагента.
 */

$badge = function ($status) {
    switch ($status) {
        case 'done':    return ['text' => 'готово',      'cls' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40'];
        case 'running': return ['text' => 'работает',    'cls' => 'bg-blue-50 text-blue-600 dark:bg-blue-950/50'];
        case 'error':   return ['text' => 'ошибка',      'cls' => 'bg-rose-50 text-rose-500 dark:bg-rose-950/40'];
        case 'action':  return ['text' => 'нужно действие', 'cls' => 'bg-amber-50 text-amber-600 dark:bg-amber-950/40'];
        case 'missing': return ['text' => 'не найдено',  'cls' => 'bg-gray-50 text-gray-500 dark:bg-gray-700/50 dark:text-gray-300'];
        default:        return ['text' => 'ожидает',     'cls' => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'];
    }
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <h1 class="n2j-h1"><i class="fas fa-user-secret mr-2 text-blue-500"></i><?php echo HTML::chars(($proj['company'] !== '' ? $proj['company'] : $proj['query'])); ?></h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Маршрут <span class="font-mono"><?php echo HTML::chars($proj['code']); ?></span>
            · создан <?php echo HTML::chars((string) $proj['created_at']); ?>
            <?php if ($proj['domain'] !== ''): ?>· <span class="font-mono"><?php echo HTML::chars($proj['domain']); ?></span><?php endif; ?>
            <?php if ($proj['inn'] !== ''): ?>· ИНН <span class="font-mono"><?php echo HTML::chars($proj['inn']); ?></span><?php endif; ?>
        </p>
        <!-- Всё то же самое, но одним экраном: блоки появляются сами -->
        <a href="/check?<?php echo HTML::chars(Service_Nj635_Report::query([
                'project' => (string) $proj['code'],
                'dossier' => '',
                'profile' => '',
                'watch'   => '',
                'query'   => (string) ($proj['company'] !== '' ? $proj['company'] : $proj['query']),
            ])); ?>" class="n2j-btn-secondary n2j-btn-sm mt-2.5">
            <i class="fas fa-clipboard-list mr-1.5 text-blue-500"></i>Открыть единым отчётом
        </a>
    </div>

    <div class="gap-4 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Шаги маршрута ============ -->
        <div class="space-y-3 md:col-span-2">
            <?php foreach ($steps as $key => $s): $b = $badge($s['status']); ?>
            <div class="n2j-card">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="shrink-0 text-blue-500"><i class="<?php echo HTML::chars($s['icon']); ?>"></i></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($s['title']); ?></p>
                        <p class="n2j-hint"><?php echo HTML::chars($s['desc']); ?></p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-bold <?php echo $b['cls']; ?>"><?php echo $b['text']; ?></span>
                </div>

                <?php if ($s['code'] !== NULL): ?>
                <p class="n2j-hint mt-2">Артефакт: <span class="font-mono"><?php echo HTML::chars($s['code']); ?></span></p>
                <?php endif; ?>

                <?php if ($s['status'] === 'action'): ?>
                <form method="post" action="/scout/<?php echo HTML::chars($proj['code']); ?>" class="mt-3 rounded-lg border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-800 dark:bg-amber-950/20">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="letter">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">Что вы предлагаете? Это даст письму факты профиля.</p>
                    <textarea name="product" rows="2" required maxlength="2000" placeholder="Металлоконструкции под заказ с доставкой по РФ…"
                              class="n2j-textarea mt-2 w-full"></textarea>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <input type="text" name="audience" maxlength="300" placeholder="Кому: снабженец, директор (необязательно)"
                               class="n2j-input w-auto">
                        <select name="tone" class="n2j-input n2j-select w-auto">
                            <option value="business">Деловой тон</option>
                            <option value="friendly">Дружелюбный</option>
                            <option value="bold">Смелый</option>
                        </select>
                    </div>
                    <button type="submit" class="n2j-btn-process mt-2">
                        <i class="fas fa-envelope mr-1"></i>Написать первое письмо
                    </button>
                </form>
                <?php elseif ($s['url'] !== NULL): ?>
                <a href="<?php echo HTML::chars($s['url']); ?>" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">
                    Открыть <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ============ Помощь / источник ============ -->
        <div class="space-y-4">
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-question mr-1.5 text-gray-400"></i>Как читать эту страницу</p>
                <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <li><span class="font-bold text-emerald-600">готово</span> — артефакт собран, откройте его.</li>
                    <li><span class="font-bold text-blue-600">работает</span> — воркер ещё читает источники (1–3 мин).</li>
                    <li><span class="font-bold text-amber-600">нужно действие</span> — от вас нужен ввод (оффер письма).</li>
                    <li><span class="font-bold text-gray-500">ожидает</span> — шаг появится после предыдущего (или ещё не запущен).</li>
                    <li><span class="font-bold text-gray-500">не найдено</span> — по вводу ничего нет (напр. организации в справочнике).</li>
                    <li><span class="font-bold text-rose-500">ошибка</span> — что-то пошло не так, откройте артефакт.</li>
                </ul>
            </div>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-shield mr-1.5 text-gray-400"></i>Честность данных</p>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">Разведка не выдумывает факты: каждый шаг — отдельный сервис со своими источниками и статусом. Если данные не найдены — шаг честно покажет «готово без данных» или «ошибку».</p>
            </div>
        </div>
    </div>

</div>
