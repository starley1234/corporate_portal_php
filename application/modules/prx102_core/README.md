# Базовый модуль для информационной системы

Базовый модуль для информационной системы (prx100_core), предоставляющий основные контроллеры, утилиты и общую структуру для других модулей.

## Содержание

- [Controller_Prx102_Base](#controller_prx102_core)
- [Controller_Prx102_Auth](#controller_prx100_auth)
- [Controller_Base](#controller_base)

---

## Controller_Prx102_Base

Базовый контроллер для всех контроллеров приложения. Он инициализирует основной шаблон, загружает общие данные и управляет мета-тегами.

### Основные возможности:

- **Шаблонизация**: Использует `template_tailwindcss` (или `template_bootstrap_5_3`) в качестве основного шаблона.
- **Мета-теги**: Управление `title`, `description`, `keywords` страницы.
- **CSS/JS**: Добавление стилей и скриптов (внешних и инлайн).
- **Авторизация**: Проверяет авторизацию пользователя, загружает объект `Model_User` и его роли.
- **Глобальные переменные**: Привязывает `current_user`, `current_user_roles`, `site_name`, `site_logo` и `table_view` к глобальным переменным View.
- **Меню**: Метод `set_menu()` для генерации и установки меню в шаблон с поддержкой разрешений (ACL).
- **Проверка прав**: Методы `has_role()`, `has_any_role()` для проверки наличия ролей у текущего пользователя.
- **Проверка владения**: Метод `is_owner()` для определения, является ли текущий пользователь владельцем объекта.
- **Кэширование классов**: Метод `action_update_declared_classes_cache()` для обновления кэша объявленных классов (только для разработки).
- **Единая проверка прав (`can`)**: Метод `can($ability, $object = NULL)` для централизованной проверки разрешений, учитывающий роль администратора, владение объектом и системный ACL.

### Использование:

Все контроллеры должны наследовать `Controller_Prx102_Base` (или `Controller_Base` для PRX-специфичных контроллеров).

```php
class Controller_MyModule_Example extends Controller_Prx102_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Моя страница');
        $this->add_style('assets/css/my_style.css');
        $this->add_script('assets/js/my_script.js');
        
        $this->set_menu([
            [
                'title' => 'Главная',
                'href'  => '/',
                'icon'  => 'fas fa-home',
                'acl'   => 'all'
            ],
            [
                'title' => 'Админка',
                'href'  => '/admin',
                'icon'  => 'fas fa-cog',
                'acl'   => 'admin.access' // Проверка через ACL
            ]
        ]);
    }

    public function action_index()
    {
        if (!$this->can('my_module.view')) {
            throw new HTTP_Exception_403('Доступ запрещен');
        }
        $this->template->content = View::factory('my_module/index');
    }
}
```

---

## Controller_Prx102_Auth

Контроллер, отвечающий за аутентификацию и регистрацию пользователей в системе.

### Основные возможности:

- **Вход (`action_login`)**:
    - Обработка POST-запросов для входа.
    - Проверка учетных данных и статуса подтверждения email.
    - Перенаправление на предыдущую страницу после успешного входа.
    - Интеграция с модулем `yandexauth` для входа через Яндекс.
- **Выход (`action_logout`)**:
    - Завершение сессии пользователя и перенаправление на главную страницу.
- **Регистрация (`action_register`)**:
    - Обработка POST-запросов для регистрации нового пользователя.
    - Валидация данных (username, email, password, terms).
    - Создание пользователя, присвоение роли 'login'.
    - Генерация токена подтверждения email и отправка письма.
    - Перенаправление на страницу успешной регистрации.
- **Успешная регистрация (`action_success_registration`)**:
    - Отображение страницы с сообщением об успешной регистрации и инструкциями по подтверждению email.
- **Подтверждение email (`action_confirm_email`)**:
    - Проверка токена подтверждения.
    - Активация учетной записи пользователя.
    - Автоматический вход пользователя после подтверждения.
- **Восстановление пароля (`action_forgot_password`)**:
    - Обработка запроса на сброс пароля по email.
    - Генерация токена сброса и отправка письма со ссылкой.
- **Сброс пароля (`action_reset_password`)**:
    - Проверка токена сброса и его срока действия.
    - Установка нового пароля для пользователя.
- **Профиль пользователя (`action_profile`)**:
    - Отображение страницы профиля для авторизованного пользователя.
- **Управление пользователями (`action_index`)**:
    - Доступно только для пользователей с ролью 'admin'.
    - Отображает список всех пользователей.

### Использование:

Контроллер предоставляет стандартные маршруты для аутентификации.

- `/auth/login` - Страница входа
- `/auth/logout` - Выход
- `/auth/register` - Страница регистрации
- `/auth/success_registration` - Страница успешной регистрации
- `/auth/confirm_email/<token>` - Подтверждение email
- `/auth/forgot_password` - Страница восстановления пароля
- `/auth/reset_password/<token>` - Сброс пароля
- `/auth/profile` - Профиль пользователя
- `/auth/index` - Список пользователей (для админов)

---

## Controller_Base

Расширенный базовый контроллер для PRX-системы, наследующий `Controller_Prx102_Base` и добавляющий специфичные для PRX функциональности и рекомендации.

### Принципы: "Просто, Единообразно, Хорошо"

1.  **Доступ и Разрешения (ACL)**:
    -   Использует `$this->can('ability_name', $model)` для проверки разрешений.
    -   Глобальные переменные `current_user` и `current_user_roles` доступны в View.
    -   `$this->_current_user` содержит объект `Model_User`.

2.  **Связи через События (Event Dispatcher)**:
    -   Модули общаются через шину событий: `Event::publish('prx[ID].object.action', $data, $initiator_id)`
        (только ID и скаляры) и подписываются в `init.php` через `Event::listen()`.
    -   Это позволяет модулям Audit, Notify и Storage работать автоматически.

3.  **Хранилище (Storage)**:
    -   Загрузка файла: `$file_id = Storage::save($_FILES['doc'], 'prx[ID]_module_name', $actor->id())`.
    -   Строка как файл: `$file_id = Storage::put($text, 'result.txt', 'prx[ID]_module_name')`.
    -   Содержимое и ссылка: `Storage::bytes($file_id)`, `Storage::url($file_id, $actor->id())`.
    -   `Service_Prx212_Storage` снаружи не вызывается — только фасад (`docs/FOUNDATION.md` §5).

4.  **Уведомления (Notify)**:
    -   Отправка уведомлений: `Notify::send($user, 'template_code', ['var' => 'val'])` (почта/TG/push).

5.  **ИИ (LLM)**:
    -   Интеграция с LLM: `Llm_Factory::get()->chat([...])`.

6.  **Интерфейс и Меню**:
    -   `$this->set_title('Заголовок')`: Установка заголовка страницы.
    -   `$this->set_menu([...])`: Формирование навигации. **Важно**: Доступ к пункту меню определяется через ключ `'acl'`.

### Пример стандартного действия (Action):

Демонстрирует рекомендуемый подход к написанию действий контроллера с использованием ACL, ORM, событий и JSON-ответов.

```php
public function action_update() {
   $id = $this->request->param('id');
   $model = ORM::factory('Prx301_Task', $id);      // Используем стандартный ORM
   
   if ( ! $this->can('task.edit', $model)) {       // Проверяем ПРАВО, а не роль
       throw new HTTP_Exception_403('Access Denied');
   }

   $model->values($this->request->post())->save();
   Event::publish('prx100.task.updated', ['task_id' => (int) $model->id], $this->_current_user ? (int) $this->_current_user->id : NULL);

   $this->_json_response(['status' => 'ok']);      // Отвечаем JSON'ом (для AJAX)
}
```

### Дополнительные методы:

-   `_json_response($data, $code = 200)`: Удобный метод для отправки JSON-ответов, отключает автоматический рендеринг шаблона.

### Использование:

Все PRX-специфичные контроллеры должны наследовать `Controller_Base` для использования общих принципов и функциональностей.

```php
class Controller_Prx301_Tasks extends Controller_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Управление задачами');
        $this->set_menu([
            [
                'title' => 'Мои задачи',
                'href'  => '/prx100_tasks',
                'icon'  => 'fas fa-tasks',
                'acl'   => 'task.view'
            ],
            [
                'title' => 'Создать задачу',
                'href'  => '/prx100_tasks/create',
                'icon'  => 'fas fa-plus',
                'acl'   => 'task.create'
            ]
        ]);
    }

    public function action_index()
    {
        if (!$this->can('task.view')) {
            throw new HTTP_Exception_403('Доступ запрещен');
        }
        $tasks = ORM::factory('Prx301_Task')->find_all();
        $this->template->content = View::factory('prx100/tasks/list')->set('tasks', $tasks);
    }
}