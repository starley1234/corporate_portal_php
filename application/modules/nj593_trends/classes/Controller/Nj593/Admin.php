<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Тренды GitHub» · управление (nj593): все обзоры, удаление, автосоздание.
 * Доступ — только роль admin. Плотность таблиц по эталону: строка 40px
 * (py-2.5), кегль text-sm, удаление в два шага.
 */
class Controller_Nj593_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj593.admin'))
        {
            throw HTTP_Exception::factory(403, 'Раздел только для администратора');
        }

        $this->set_title('Тренды GitHub · управление');

        // Чипы раздела — в верхнем header шаблона. Владелец (26.09): «в чипах
        // оставь Тренды GitHub, /nj593/admin — остальное только дублирует».
        // Тот же набор, что на странице модуля: активный пункт помечает Base.
        $this->set_module_nav([
            ['title' => 'Тренды GitHub', 'href' => '/trends',      'icon' => 'fas fa-fire'],
            ['title' => 'Управление',       'href' => '/nj593/admin', 'icon' => 'fas fa-lock'],
        ]);
    }

    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            $action = (string) Arr::get($_POST, 'do', '');

            if ($action === 'delete')
            {
                if (Security::check(Arr::get($_POST, 'csrf', '')))
                {
                    Service_Nj593_Trends::hard_delete((int) Arr::get($_POST, 'id', 0));
                }

                $this->redirect('nj593/admin?flash='.urlencode('Обзор удалён'));

                return;
            }

            if ($action === 'create')
            {
                $admin_id = (int) $this->_current_user->id;
                $res = Service_Nj593_Trends::create(
                    $admin_id,
                    trim((string) Arr::get($_POST, 'date', '')),
                    (int) Arr::get($_POST, 'period', 1),
                    (int) Arr::get($_POST, 'count', 8),
                    (string) Arr::get($_POST, 'language', ''),
                    (int) Arr::get($_POST, 'min_stars', 50),
                    (string) Arr::get($_POST, 'focus', '')
                );
                $msg = isset($res['error']) ? (string) $res['error'] : 'Обзор '.$res['code'].' создан';
                $this->redirect('nj593/admin?flash='.urlencode($msg));

                return;
            }
        }

        // Поиск и постраничный вывод (владелец: «/nj593/admin тоже приведи к
        // эталону, добавь пагинацию и поиск»).
        $q     = trim((string) Arr::get($_GET, 'q', ''));
        // Строк на страницу — выбор пользователя (10/20/50/100), ?per=
        $per   = (int) Arr::get($_GET, 'per', 50);
        $per   = in_array($per, [10, 20, 50, 100], TRUE) ? $per : 50;
        $total = Service_Nj593_Trends::count_admin($q);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($pages, max(1, (int) Arr::get($_GET, 'page', 1)));

        // Незавершённые строки сверяем с очередью и на этой странице: управление не
        // должен видеть вечное «в работе» (реконсиляция гасит молчащего воркера).
        $rows = Service_Nj593_Trends::reconcile_list(
            Service_Nj593_Trends::search_admin($q, $page, $per), $per
        );

        $this->template->content = View::factory('593/admin', [
            'stats' => Service_Nj593_Trends::stats_admin(),
            'rows'  => $rows,
            'csrf'  => Security::token(),
            'flash' => (string) Arr::get($_GET, 'flash', ''),
            'q'     => $q,
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
            'per'   => $per,
            'per_options' => [10, 20, 50, 100],
        ]);
    }
}
