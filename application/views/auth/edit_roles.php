<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /* Роли пользователя — каркас эталона v6: шапка, карточка, CTA + вторичная кнопка */ ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Пользователи', URL::site('auth/view/'.$user->id)], ['Роли']],
        'title'  => 'Роли: '.$user->username,
    ]); ?>

    <div class="mt-6 max-w-2xl space-y-4">
        <?php if (isset($errors) && !empty($errors)): ?>
        <div class="prx-flash prx-flash-err block" role="alert">
            <strong class="font-bold">Ошибка</strong>
            <ul class="mt-2 list-inside list-disc">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form action="" method="post" class="prx-card space-y-6">
            <fieldset>
                <legend class="prx-label">Выберите роли</legend>
                <div class="mt-3 space-y-2">
                    <?php foreach ($all_roles as $role): ?>
                    <label for="role_<?php echo htmlspecialchars($role->id); ?>" class="flex cursor-pointer items-center gap-2 text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="roles[]" value="<?php echo htmlspecialchars($role->name); ?>" id="role_<?php echo htmlspecialchars($role->id); ?>"
                            class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"
                            <?php if (in_array($role->name, $user_roles)): ?>checked<?php endif; ?>>
                        <?php echo htmlspecialchars($role->name); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" name="submit" class="prx-btn-cta">
                    <i class="fas fa-check"></i><span>Обновить роли</span>
                </button>
                <a href="<?php echo htmlspecialchars(URL::site('auth/view/' . $user->id)); ?>" class="prx-btn-secondary">Отмена</a>
            </div>
        </form>
    </div>
</div>
