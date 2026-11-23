<?php if (isset($current_user) AND ($current_user)): ?>
<!-- Кнопка "Сохранить ссылку" (закладка) в стиле Tailwind CSS.
     На телефоне (до sm) её нет: строка шапки умещает только поиск, уведомления,
     переключатель темы и аккаунт — с закладкой вся шапка уезжала за правый край. -->
<button id="toggle-save-link-btn" class="n2j-btn-icon hidden sm:inline-flex" title="Сохранить/Удалить текущую страницу">
    <i class="fa-regular fa-bookmark text-xl" id="bookmark-icon"></i>
</button>

<script>
    $(document).ready(function() {
        var currentUrl = window.location.href;
        var currentTitle = document.title;
        var currentDescription = $('meta[name="description"]').attr('content');
        var bookmarkIcon = $('#bookmark-icon');
        var toggleSaveLinkBtn = $('#toggle-save-link-btn');

        // Генерируем URL через роуты Kohana
        var checkStatusUrl = '<?php echo Route::url('default', array('controller' => 'erp118_links', 'action' => 'check_link_status')); ?>';
        var toggleSaveUrl = '<?php echo Route::url('default', array('controller' => 'erp118_links', 'action' => 'toggle_save')); ?>';

        // Функция для проверки статуса закладки
        function checkBookmarkStatus() {
            $.ajax({
                url: checkStatusUrl,
                method: 'GET',
                data: { url: currentUrl },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        if (response.is_saved) {
                            bookmarkIcon.removeClass('fa-regular').addClass('fa-solid text-blue-600 dark:text-blue-400');
                            toggleSaveLinkBtn.attr('title', 'Удалить текущую страницу из закладок');
                        } else {
                            bookmarkIcon.removeClass('fa-solid text-blue-600 dark:text-blue-400').addClass('fa-regular');
                            toggleSaveLinkBtn.attr('title', 'Сохранить текущую страницу в закладки');
                        }
                    }
                }
            });
        }

        checkBookmarkStatus();

        // Обработчик кнопки
        toggleSaveLinkBtn.on('click', function() {
            $.ajax({
                url: toggleSaveUrl,
                method: 'POST',
                data: {
                    url: currentUrl,
                    title: currentTitle,
                    description: currentDescription
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        if (response.action === 'saved') {
                            bookmarkIcon.removeClass('fa-regular').addClass('fa-solid text-blue-600 dark:text-blue-400');
                            toggleSaveLinkBtn.attr('title', 'Удалить текущую страницу из закладок');
                        } else if (response.action === 'deleted') {
                            bookmarkIcon.removeClass('fa-solid text-blue-600 dark:text-blue-400').addClass('fa-regular');
                            toggleSaveLinkBtn.attr('title', 'Сохранить текущую страницу в закладки');
                        }
                    }
                }
            });
        });
    });
</script>
<?php endif; ?>