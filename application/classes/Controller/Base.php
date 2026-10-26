<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Controller_Base — Базовый контроллер ERP-системы.
 * 
 * ПРИНЦИП: "Просто, Единообразно, Хорошо"
 * 
 * 1. ДОСТУП И РАЗРЕШЕНИЯ (ACL):
 *    - $this->can('ability_name', $model): Проверка разрешения (например, 'invoice.edit').
 *    - Глобальные переменные в View: $current_user, $current_user_roles.
 *    - В контроллерах: $this->_current_user (объект Model_User).
 *     
 * ПАМЯТКА ПО ПРОВЕРКЕ ПРАВ В VIEWS (ШАБЛОНАХ):
 * 
 * 1. ACL + Владение: <?php if ($this->can('client.edit', $client)): ?> ... <?php endif; ?>
 * 2. Роли (массив):  <?php if (in_array('admin', $current_user_roles)): ?> ... <?php endif; ?>
 * 3. Роли (метод):   <?php if ($this->has_any_role(['admin', 'manager'])): ?> ... <?php endif; ?>
 * 4. Владелец:       <?php if ($this->is_owner($item)): ?> ... <?php endif; ?>
 * 5. Авторизация:    <?php if ($current_user): ?> Привет, <?=$current_user->username?> <?php endif; ?>
 *
 *  
 * 2. ДОСТУПНЫЕ ДАННЫЕ ПРОФИЛЯ (через $current_user = $this->_current_user):
 *    - Основное: $current_user->email, $current_user->username
 *    - Профиль: $current_user->profile->full_name, position, first_name, last_name, phone_number, city и т.д.
 *    - Настройки: $current_user->setting->settings_json (автоматически декодируется в массив)
 *    - $user = ORM::factory('User', 2); $fullName = $user->profile->full_name; position;
 *    - $profiles = Model_User::get_all_names(); // @return array ['user_id', 'full_name', 'position'] Массив данных профилей, индексированный по user_id
 * 
 * 3. СВЯЗИ ЧЕРЕЗ СОБЫТИЯ (Event Dispatcher):
 *    - Модули общаются через шину: Event::fire('erp[ID].object.action', $data).
 *    - Это позволяет модулям Audit, Notify и Storage работать автоматически.
 *    - Пример подписки в контроллерах или init-файлах (в одну строку): `Event::listen('erp219.item.created', function($payload) {`
 * 
 * 4. ХРАНИЛИЩЕ (Storage):
 *    - Загрузка файлов: $file_id = Storage::upload($_FILES['doc'], 'erp[ID]_module_name').
 *    - Получение ссылки: Storage::url($file_id).
 * 
 * 5. УВЕДОМЛЕНИЯ (Notify):
 *    - Notify::send($user, 'template_code', ['var' => 'val']): Отправка почты/TG/push.
 *  
 * 6. УПРАВЛЕНИЕ ШАБЛОНОМ ИЗ КОНТРОЛЛЕРА:
 *    - $this->set_title('Заголовок') - установить title.
 *    - $this->set_description('Описание') - установить description.
 *    - $this->template->content = View::factory('path/to/view') - основной контент.
 *    - $this->add_style('css/path.css'), $this->add_script('js/path.js') - подключение ресурсов.
 * 
 * 7. ИИ (LLM):
 *
 * // Самый простой вариант (модель по умолчанию)
 * $text = Llm::ask("Напиши текст для рассылки"); echo $text;
 *
 * // Мгновенная смена модели через ваш синтаксис
 * $answer = Llm::model('openrouter::google/gemini-2.5-flash-lite')->ask("Проанализируй код"); echo $answer;
 *
 * // Прямой вопрос с системным промптом (без массивов)
 * $pro = Llm::system("Ты юрист")->ask("Проверь договор на риски");
 *
 * // Передаем путь, URL или base64 и вопрос
 * $desc = Llm::model('openrouter::google/gemini-2.5-flash-lite')->vision('path/to/invoice.jpg', 'Сумма счета?');
 *
 * // Анализ нескольких картинок
 * $diff = Llm::vision(['img1.png', 'img2.png'], 'В чем разница между товарами?');
 *
 * // LLM сама понимает, что нужен JSON, благодаря методу
 * $data = Llm::model('local::qwen3-coder-30b-a3b-instruct')->extract($text, ['amount', 'vendor', 'date']);
 * echo $data['amount']; // Работаем сразу с массивом
 *
 * // Вызов именованного шаблона с передачей переменных (анализ данных)
 * $review = Llm::prompt('invoice_check', ['invoice' => $data])->ask(); echo $review;
 *
 * // Базовый метод: Всегда возвращает полный ответ API (массив)
 * $response = Llm::chat($messages);
 * echo "Ответ LLM: " . $response['choices'][0]['message']['content'] . PHP_EOL;
 *  
 * Llm::get_embedding("Это текст для превращения в вектор");
 * 
 * 8. МЕНЮ:
 *    - $this->set_title('Заголовок'): Установить заголовок страницы.
 *    - $this->set_menu([...]): Формирование навигации. 
 *      ВАЖНО: Доступ к пункту меню определяется через ключ 'acl'.
 *
 * 9. Открытие доступа в конкретных контроллерах

#### Вариант А: Открыть весь контроллер (например, статьи)
В файле `/application/modules/517_articles/classes/Controller/Lib517/Articles.php`:

```php
class Controller_Lib517_Articles extends Controller_Lib517_Main {  
    protected $auth_required = FALSE; // Весь контроллер доступен всем
}
```

#### Вариант Б: Открыть только конкретные методы (рекомендуется)
Если вы хотите, чтобы список статей и просмотр были открыты, а создание/редактирование по-прежнему требовало пароль:

```php
class Controller_Lib517_Articles extends Controller_Lib517_Main {
    
    protected $auth_required = TRUE; // Защита включена
    protected $public_actions = ['index', 'view', 'category', 'all', 'sitemap']; // Но эти методы открыты

    public function before() {
        parent::before();
        // ...
    }
}
```
 
 
 */

abstract class Controller_Base extends Controller_Erp100_Base {

    /**
     * @var bool Требуется ли авторизация для доступа к контроллеру по умолчанию
     */
    protected $auth_required = TRUE;

    /**
     * @var array Список action, доступных без авторизации
     */
    protected $public_actions = [];

    /**
     * Выполняется перед вызовом action.
     */
    public function before()
    {
        parent::before();

        // Проверяем, нужно ли ограничивать доступ
        if ($this->auth_required) {
            $action = $this->request->action();
            
            // Если экшен не в белом списке и пользователь не авторизован
            if (!in_array($action, $this->public_actions) && !$this->_current_user) {
                // Перенаправляем на логин или выдаем 403
                throw new HTTP_Exception_403('Access Denied. Please login.');
            }
        }
        
        /**
         * ПРИМЕР ВЫЗОВА МЕНЮ С ПРОВЕРКОЙ РАЗРЕШЕНИЙ:
         * 
         * 'acl' => 'all' (или пустая строка) — пункт видят все авторизованные.
         * 'acl' => 'task.view' — увидят те, кому разрешено 'task.view'.
         * 
         * $this->set_menu([
         *     [
         *         'title'      => 'Задачи',
         *         'href'       => '/erp100_tasks',
         *         'icon'       => 'fas fa-tasks',
         *         'acl' => 'task.view',
         *         'children'   => []
         *     ],
         *     [
         *         'title'      => 'Настройки системы',
         *         'href'       => '/erp100_settings',
         *         'icon'       => 'fas fa-cog',
         *         'acl' => 'settings.manage',
         *         'children'   => []
         *     ]
         * ]);
         */
    }

    /**
     * Быстрый ответ JSON для контроллеров (AJAX/API)
     */
    public function _json_response($data, $code = 200)
    {
        
        $this->auto_render = FALSE;
        $this->response
            ->status($code)
            ->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

}