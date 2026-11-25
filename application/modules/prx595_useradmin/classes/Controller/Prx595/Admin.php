<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление пользователями и ролями (prx595): /prx595/admin.
 *
 * Только роль admin. Пользователи: список/поиск, карточка (email, username,
 * пароль, роли), блокировка = снятие роли login (схему ядра не меняем).
 * Активность: последние задачи очереди prx_120_jobs — у каждой есть
 * visitor_id ('u:<id>' у зарегистрированных, 'g:<ubid>' у гостей).
 * Опасные действия — в 2 клика (armed), window.confirm не используется.
 */
class Controller_Prx595_Admin extends Controller_Prx102_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Пользователи и роли · управление');

        // внутренних чипов нет в каталоге — это единственное меню раздела
        $this->set_module_nav([
            ['title' => 'Пользователи', 'href' => '/prx595/admin',           'icon' => 'fas fa-users'],
            ['title' => 'Роли',         'href' => '/prx595/admin/roles',     'icon' => 'fas fa-user-tag'],
            ['title' => 'Активность',   'href' => '/prx595/admin/activity',  'icon' => 'fas fa-chart-line'],
        ]);

    }

    /** GET /prx595/admin — список пользователей (+поиск ?q=, ?page=). */
    public function action_index()
    {
        $q   = trim((string) $this->request->query('q'));
        $per = 30;
        $page = max(1, (int) $this->request->query('page'));
        $params = [];
        $where = '';
        if ($q !== '')
        {
            $where = 'WHERE u.email ILIKE :like OR u.username ILIKE :like';
            $params[':like'] = '%'.$q.'%';
        }

        try
        {
            $total_row = Prx_Db::one('SELECT count(*) AS c FROM users u '.$where, $params);
            $total = (int) ($total_row['c'] ?? 0);

            $rows = Prx_Db::select(
                'SELECT u.id, u.email, u.username, u.logins, u.last_login,
                        COALESCE(array_agg(r.name ORDER BY r.name) FILTER (WHERE r.name IS NOT NULL), \'{}\') AS roles
                 FROM users u
                 LEFT JOIN roles_users ru ON ru.user_id = u.id
                 LEFT JOIN roles r ON r.id = ru.role_id
                 '.$where.'
                 GROUP BY u.id ORDER BY u.id DESC
                 LIMIT '.$per.' OFFSET '.(($page - 1) * $per), $params);
        }
        catch (Throwable $e)
        {
            $total = 0;
            $rows  = [];
            Kohana::$log->add(Log::WARNING, 'prx595: users list: :e', [':e' => $e->getMessage()]);
        }

        $this->template->content = View::factory('prx595/admin', [
            'rows'   => $rows,
            'total'  => $total,
            'q'      => $q,
            'page'   => $page,
            'per'    => $per,
            'me_id'  => (int) $this->_current_user->id,
            'csrf'   => Security::token(),
            'flash'  => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
        ]);
    }

    /** POST /prx595/admin/create — создать пользователя (email, username, пароль, роль admin?). */
    public function action_create()
    {
        if ($this->request->method() !== Request::POST)
        {
            $this->redirect('/prx595/admin');
        }

        if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('/prx595/admin?flash='.urlencode('Сессия устарела — повторите'));
        }

        $email    = trim((string) Arr::get($_POST, 'email', ''));
        $username = trim((string) Arr::get($_POST, 'username', ''));
        $password = (string) Arr::get($_POST, 'password', '');
        $as_admin = ! empty($_POST['as_admin']);

        $fail = NULL;

        if ( ! filter_var($email, FILTER_VALIDATE_EMAIL))
        {
            $fail = 'Email выглядит некорректно';
        }
        elseif (mb_strlen($username) < 2)
        {
            $fail = 'Имя пользователя — минимум 2 символа';
        }
        elseif (mb_strlen($password) < 6)
        {
            $fail = 'Пароль — минимум 6 символов';
        }

        if ($fail === NULL)
        {
            try
            {
                $user = ORM::factory('User');
                $user->email    = $email;
                $user->username = $username;
                $user->password = $password; // ORM Auth сам хэширует при записи
                $user->create();

                Prx_Db::execute(
                    'INSERT INTO roles_users (user_id, role_id)
                     SELECT '.(int) $user->id.', id FROM roles WHERE name = \'login\'');

                if ($as_admin)
                {
                    Prx_Db::execute(
                        'INSERT INTO roles_users (user_id, role_id)
                         SELECT '.(int) $user->id.', id FROM roles WHERE name = \'admin\'');
                }

                $this->redirect('/prx595/admin?flash='.urlencode('Пользователь создан: '.$username.' (id '.$user->id.')'));
            }
            catch (ORM_Validation_Exception $e)
            {
                $fail = implode('; ', Arr::flatten($e->errors('models', 'ru')));
            }
            catch (Throwable $e)
            {
                $fail = 'Не удалось создать: '.mb_substr($e->getMessage(), 0, 200)
                    .' @ '.basename($e->getFile()).':'.$e->getLine();
            }
        }

        $this->redirect('/prx595/admin?flash='.urlencode($fail ?: 'Неизвестная ошибка'));
    }

    /** GET /prx595/admin/user/<id> — карточка пользователя. */
    public function action_user()
    {
        $user = $this->_orm_user((int) $this->request->param('id'));

        if ($user === NULL)
        {
            throw HTTP_Exception::factory(404, 'Пользователь не найден');
        }

        $roles = Prx_Db::select('SELECT * FROM roles ORDER BY id');
        $mine  = [];

        foreach (Prx_Db::select(
            'SELECT role_id FROM roles_users WHERE user_id = '.(int) $user->id) as $r)
        {
            $mine[(int) $r['role_id']] = TRUE;
        }

        $this->set_title('Пользователь '.$user->username.' · управление');

        $this->template->content = View::factory('prx595/user', [
            'u'      => $user,
            'roles'  => $roles,
            'mine'   => $mine,
            'me_id'  => (int) $this->_current_user->id,
            'csrf'   => Security::token(),
            'flash'  => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
        ]);
    }

    /** POST /prx595/admin/user/<id> — сохранить карточку (данные, пароль, роли). */
    public function action_save()
    {
        $id = (int) $this->request->param('id');

        if ($this->request->method() !== Request::POST
            OR ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('/prx595/admin/user/'.$id.'?flash='.urlencode('Сессия устарела'));
        }

        $user = $this->_orm_user($id);

        if ($user === NULL)
        {
            throw HTTP_Exception::factory(404, 'Пользователь не найден');
        }

        $email    = trim((string) Arr::get($_POST, 'email', ''));
        $username = trim((string) Arr::get($_POST, 'username', ''));
        $password = (string) Arr::get($_POST, 'password', '');
        $picked   = (array) Arr::get($_POST, 'roles', []);
        $msg      = 'Сохранено';
        $is_self  = ($id === (int) $this->_current_user->id);

        try
        {
            if ( ! filter_var($email, FILTER_VALIDATE_EMAIL) OR mb_strlen($username) < 2)
            {
                throw new Exception('Email или имя некорректны');
            }

            if ($password !== '' AND mb_strlen($password) < 6)
            {
                throw new Exception('Пароль — минимум 6 символов (или оставьте поле пустым)');
            }

            if ($is_self AND ! in_array('admin', $picked, TRUE))
            {
                throw new Exception('Себе нельзя снимать роль admin — иначе потеряете доступ');
            }

            $user->email    = $email;
            $user->username = $username;

            if ($password !== '')
            {
                $user->password = $password; // хэширует фильтр ORM Auth
            }

            $user->update();

            // роли: перезаписываем набор целиком
            Prx_Db::execute('DELETE FROM roles_users WHERE user_id = '.$id);

            foreach ($picked as $role_name)
            {
                Prx_Db::execute(
                    'INSERT INTO roles_users (user_id, role_id)
                     SELECT '.$id.', id FROM roles WHERE name = :role',
                    [':role' => (string) $role_name]);
            }

            if ($is_self AND ! Prx_Db::one(
                'SELECT 1 AS x FROM roles_users ru JOIN roles r ON r.id = ru.role_id
                 WHERE ru.user_id = '.$id.' AND r.name = \'admin\''))
            {
                throw new Exception('Роль admin потеряна — возвращаю');
            }
        }
        catch (ORM_Validation_Exception $e)
        {
            $msg = 'Не сохранено: '.implode('; ', Arr::flatten($e->errors('models', 'ru')));
        }
        catch (Throwable $e)
        {
            $msg = 'Не сохранено: '.mb_substr($e->getMessage(), 0, 200).' @ '.basename($e->getFile()).':'.$e->getLine();
        }

        $this->redirect('/prx595/admin/user/'.$id.'?flash='.urlencode($msg));
    }

    /** GET /prx595/admin/roles — роли и их состав; POST — создать роль. */
    public function action_roles()
    {
        if ($this->request->method() === Request::POST
            AND Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            $name = strtolower(trim((string) Arr::get($_POST, 'name', '')));
            $desc = trim((string) Arr::get($_POST, 'description', ''));

            if ( ! preg_match('/^[a-z0-9_]{2,32}$/', $name))
            {
                $this->redirect('/prx595/admin/roles?flash='.urlencode('Имя роли: латиница/цифры/_, 2–32 знака'));
            }

            try
            {
                Prx_Db::execute(
                    'INSERT INTO roles (name, description) VALUES (:name, :desc)',
                    [':name' => $name, ':desc' => mb_substr($desc, 0, 200)]);
                $this->redirect('/prx595/admin/roles?flash='.urlencode('Роль добавлена: '.$name));
            }
            catch (Throwable $e)
            {
                $this->redirect('/prx595/admin/roles?flash='.urlencode('Не удалось добавить (возможно, уже есть): '.mb_substr($e->getMessage(), 0, 120)));
            }
        }

        $roles = Prx_Db::select(
            'SELECT r.*, count(ru.user_id) AS users
             FROM roles r LEFT JOIN roles_users ru ON ru.role_id = r.id
             GROUP BY r.id ORDER BY r.id');

        $this->template->content = View::factory('prx595/roles', [
            'roles' => $roles,
            'csrf'  => Security::token(),
            'flash' => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
        ]);
    }

    /** POST /prx595/admin/role_delete/<id> — удалить неиспользуемую роль (2 клика). */
    public function action_role_delete()
    {
        $id = (int) $this->request->param('id');

        if ($this->request->method() !== Request::POST
            OR ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('/prx595/admin/roles?flash='.urlencode('Сессия устарела'));
        }

        try
        {
            $used_row = Prx_Db::one('SELECT count(*) AS c FROM roles_users WHERE role_id = '.$id);
            $used = (int) ($used_row['c'] ?? 0);
            $name_row = Prx_Db::one('SELECT name FROM roles WHERE id = '.$id);
            $core = in_array((string) ($name_row['name'] ?? ''), ['login', 'admin'], TRUE);

            if ($core)
            {
                $this->redirect('/prx595/admin/roles?flash='.urlencode('Системные роли login/admin удалить нельзя'));
            }

            if ($used)
            {
                $this->redirect('/prx595/admin/roles?flash='.urlencode('У роли есть пользователи ('.$used.') — сначала снимите её'));
            }

            Prx_Db::execute('DELETE FROM roles WHERE id = '.$id);
            $this->redirect('/prx595/admin/roles?flash='.urlencode('Роль удалена'));
        }
        catch (Throwable $e)
        {
            $this->redirect('/prx595/admin/roles?flash='.urlencode('Ошибка: '.mb_substr($e->getMessage(), 0, 160)));
        }
    }

    /** GET /prx595/admin/activity — последняя активность системы (u:/g:ubid). */
    public function action_activity()
    {
        try
        {
            $activity = Service_Prx120_JobQueue::instance()->admin_activity();
            $kpi = $activity['kpi'];
            $channels = $activity['channels'];
            $jobs = $activity['jobs'];

            $new_users = Prx_Db::select(
                'SELECT id, email, username, created_at FROM users
                 ORDER BY id DESC LIMIT 8');
        }
        catch (Throwable $e)
        {
            $kpi = []; $channels = []; $jobs = []; $new_users = [];
        }

        $this->template->content = View::factory('prx595/activity', [
            'kpi'       => $kpi ? (array) $kpi : [],
            'channels'  => $channels,
            'jobs'      => $jobs,
            'new_users' => $new_users,
        ]);
    }

    protected function _orm_user($id)
    {
        try
        {
            $user = ORM::factory('User', $id);

            return $user->loaded() ? $user : NULL;
        }
        catch (Throwable $e)
        {
            return NULL;
        }
    }
}
