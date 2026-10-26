<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php if (count($user_links) > 0): ?>
            <?php foreach ($user_links as $link): ?>
                <div class="n2j-card p-0 group relative hover:border-blue-300 dark:hover:border-blue-700 transition-colors duration-200 flex flex-col h-full">
                    <button data-n2j-arm="Удалить?" type="button" 
                            class="n2j-btn-icon-danger absolute top-2 right-2 opacity-0 [&.is-armed]:opacity-100 delete-link-btn h-8 w-8 group-hover:opacity-100" 
                            aria-label="Удалить" 
                            data-link-id="<?php echo $link->id; ?>">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                    
                    <div class="p-5 flex flex-col h-full">
                        <div class="flex items-start mb-3">
                            <div class="flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center mr-3" style="background-color: <?php echo HTML::chars($link->color); ?>20;">
                                <i class="<?php echo HTML::chars($link->icon_class); ?> text-xl" style="color: <?php echo HTML::chars($link->color); ?>;"></i>
                            </div>
                            <h5 class="text-lg font-bold leading-tight pt-1">
                                <a href="<?php echo HTML::chars($link->url); ?>" class="text-gray-900 dark:text-white hover:underline decoration-2 underline-offset-4" style="text-decoration-color: <?php echo HTML::chars($link->color); ?>;">
                                    <?php echo HTML::chars($link->title); ?>
                                </a>
                            </h5>
                        </div>

                        <?php if (!empty($link->description)): ?>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 flex-grow line-clamp-3">
                                <?php echo HTML::chars($link->description); ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($link->group_name)): ?>
                            <div class="mt-auto pt-3 flex justify-end">
                                <span class="n2j-badge n2j-badge-wait">
                                    <i class="fas fa-folder mr-1.5 opacity-50"></i>
                                    <?php echo HTML::chars($link->group_name); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="mt-8 flex justify-center">
        <a href="/erp118_links/manage" class="text-xs text-gray-400 dark:text-gray-500 hover:text-blue-500 dark:hover:text-blue-400 transition-colors flex items-center gap-1.5 opacity-60 hover:opacity-100">
            <i class="fas fa-cog"></i>
            Управление ссылками
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Удаление — вторым нажатием (data-n2j-arm, app.js): первое взводит кнопку
    // и сюда не доходит. Итог — тостом N2J, без alert.
    document.querySelectorAll('.delete-link-btn').forEach(button => {
        button.addEventListener('click', function() {
            const linkId = this.dataset.linkId;
            const cardContainer = this.closest('.group');

            fetch('/erp118_links/delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `id=${linkId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    cardContainer.style.opacity = '0';
                    cardContainer.style.transform = 'scale(0.95)';
                    setTimeout(() => cardContainer.remove(), 200);
                    N2J.toast('Ссылка удалена', 0, 'ok');
                } else {
                    N2J.toast('Не удалось удалить ссылку: ' + (data.message || 'ошибка сервера'), 0, 'err');
                }
            })
            .catch(() => N2J.toast('Не удалось удалить ссылку: сеть не ответила', 0, 'err'));
        });
    });
});
</script>