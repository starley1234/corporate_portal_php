<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj605_radar: /radar — мои радары + лента находок; /radar/<code> — ОДИН радар
 * с его находками.
 *
 * Действия страницы — строго POST → Redirect → GET: и «Создать», и «Пауза», и
 * «Удалить», и «Остановить» заканчиваются переводом на чистый GET. Это не
 * стилистика: без редиректа ответ на POST оставался «страницей формы», и
 * перезагрузка (F5) повторяла отправку — каждый refresh создавал новый радар и
 * новый сбор, «по кругу, пока не остановишь» (владелец, 26.09.2026).
 *
 * Тексты результатов теперь идут через сессию (`get_once`), а не через
 * `?flash=…` в адресе: ссылка остаётся короткой, и сообщение нельзя увидеть
 * заново простым F5 — владелец: «адрес в строке не лаконичный» и «на новой
 * странице „сбор уже идёт“, хотя он завершён».
 */
class Controller_Nj605_Radar extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Радар заказов — находки спроса по вашим запросам');
        $this->set_description('Задайте, что ищете (продукт, работы, тендер), и получайте находки из открытых источников с оценкой релевантности: закупки, запросы поставщиков, объявления.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // «Управление» модуля — чипом в шапке (module_nav), а не в рабочей области
        $this->set_module_nav([
            ['title' => 'Управление', 'href' => '/radar/admin', 'icon' => 'fas fa-lock', 'acl' => 'nj605.admin'],
        ]);
    }

    /** /radar — форма, мои радары и общая лента находок. */
    public function action_index()
    {
        // Любой POST обрабатывается отдельно и НЕ рендерит страницу: только
        // JSON (AJAX-«Собрать») или редирект. Перезагрузка безопасна.
        if ($this->request->method() === Request::POST)
        {
            $this->_post('radar');

            return;
        }

        // статус-переходы находок («взял в работу» / «не интересно») —
        // действия идемпотентные, повторное применение ничего не меняет
        $this->_apply_item_status();

        $live    = [];
        $last    = [];
        $watches = $this->_user_id ? Service_Nj605_Radar::watches_of($this->_user_id) : [];

        if ($this->_user_id)
        {
            $this->_sweep_queue();

            $is_admin = (bool) $this->can('nj605.admin');

            foreach ($watches as $w)
            {
                $code = (string) $w['code'];
                $st   = Service_Nj605_Radar::live_for($code, $this->_user_id, $is_admin);

                if ($st AND in_array($st['status'], ['queued', 'running'], TRUE))
                {
                    $live[$code] = $st;

                    // живой ряд закрывает собой итоговую плашку — не дублируем
                    continue;
                }

                // Постоянный итог последнего сбора: «Готово 3 мин назад, находок 5»
                // (или «Ошибка: …»). Без него после завершения страница молчала.
                $ls = Service_Nj605_Radar::last_for($code, $this->_user_id, $is_admin);

                if ($ls AND in_array($ls['status'], ['done', 'failed', 'cancelled'], TRUE))
                {
                    $last[$code] = $ls;
                }
            }
        }

        $this->template->content = View::factory('605/index', [
            'live'    => $live,
            'last'    => $last,
            'watches' => $watches,
            'feed'    => $this->_user_id ? Service_Nj605_Radar::feed($this->_user_id) : [],
            'watch_count' => $this->_user_id ? Service_Nj605_Radar::count_of($this->_user_id) : 0,
            'max_watches' => (int) Kohana::$config->load('nj605_radar.max_watches') ?: 10,
            'flash'   => $this->_flash(),
            'csrf'    => Security::token(),
            'logged'  => $this->_user_id !== NULL,
            'is_admin' => $this->_user_id !== NULL AND ($this->can('nj605.admin') OR Auth::instance()->logged_in('admin')),
        ]);
    }

    /**
     * /radar/<code> — страница одного запроса: живой статус сбора, итог
     * последнего прогона и находки ИМЕННО этого радара.
     *
     * Создание радара редиректит сюда же: адрес чистый (`/radar/rad1a2b3c4`),
     * а «сбор идёт» показывается фактически статус-рядом, а не текстом в URL.
     */
    public function action_watch()
    {
        $code = (string) $this->request->param('code');

        if ($this->request->method() === Request::POST)
        {
            $this->_post('radar/'.urlencode($code));

            return;
        }

        // Скобки обязательны: AND слабее присваивания. Без них любой вошедший
        // пользователь считался администратором радара.
        $is_admin = ((bool) $this->_user_id AND $this->can('nj605.admin'));

        if ($this->_user_id === NULL OR Service_Nj605_Radar::by_code_for($code, $this->_user_id, $is_admin) === NULL)
        {
            // Чужой радар не подтверждаем существованием: и «нет», и «не ваш» — 404
            throw HTTP_Exception::factory(404, 'Радар не найден');
        }

        $this->_apply_item_status();
        $this->_sweep_queue();

        $w     = Service_Nj605_Radar::by_code($code);
        $live  = Service_Nj605_Radar::live_for($code, $this->_user_id, $is_admin);
        $last  = Service_Nj605_Radar::last_for($code, $this->_user_id, $is_admin);

        if ( ! $live OR ! in_array($live['status'], ['queued', 'running'], TRUE))
        {
            $live = NULL;
        }

        if ( ! $last OR ! in_array($last['status'], ['done', 'failed', 'cancelled'], TRUE))
        {
            $last = NULL;
        }

        $this->set_title(($w['title'] !== '' ? $w['title'] : $w['query']).' — радар заказов');
        $this->set_description('Находки по запросу «'.mb_substr((string) $w['query'], 0, 120).'»: закупки, запросы поставщиков и объявления с оценкой релевантности.');

        $this->template->content = View::factory('605/watch', [
            'w'       => $w,
            'items'   => Service_Nj605_Radar::items_of((int) $w['id'], (int) $w['user_id']),
            'stats'   => Service_Nj605_Radar::stats_of((int) $w['id']),
            'live'    => $live,
            'last'    => $last,
            'flash'   => $this->_flash(),
            'csrf'    => Security::token(),
            'is_admin' => $is_admin,
        ]);
    }

    /**
     * Обработка POST со страницы. Всегда заканчивается либо JSON-ответом
     * (AJAX-кнопка «Собрать»), либо редиректом на чистый GET.
     *
     * @param string $back куда возвращать пользователя после действия
     */
    protected function _post($back = 'radar')
    {
        $act      = (string) $this->request->post('act');
        $code     = (string) $this->request->post('code');
        // Скобки обязательны (см. выше): без них «админ» — просто «вошёл».
        $is_admin = ((bool) $this->_current_user AND $this->can('nj605.admin'));
        $csrf_ok  = Security::check((string) $this->request->post('csrf'));

        // «Собрать сейчас» из AJAX: страница сама монтирует ряд «идёт сбор»,
        // перезагружать её не нужно — отвечаем JSON.
        if ($act === 'run_now' AND $csrf_ok AND $this->_ajax())
        {
            $res = Service_Nj605_Radar::run_now($code, $this->_user_id, $is_admin);

            $this->auto_render = FALSE;
            $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                ->body(json_encode([
                    'ok'    => ! isset($res['error']),
                    'flash' => isset($res['error']) ? $res['error']
                        : 'Сбор запущен — находки появятся на странице радара',
                ], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ( ! $csrf_ok)
        {
            $msg = 'Сессия устарела — попробуйте ещё раз';
        }
        elseif ($act === 'run_now')
        {
            $res = Service_Nj605_Radar::run_now($code, $this->_user_id, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error'] : 'Сбор запущен';
        }
        elseif ($act === 'delete')
        {
            $msg = Service_Nj605_Radar::delete_watch($code, $this->_user_id)
                ? 'Радар удалён' : 'Радар не найден';

            // удалять можно только с общего списка — страницы радара уже нет
            $back = 'radar';
        }
        elseif ($act === 'stop_run')
        {
            // остановка идущего/залипшего сбора: у пользователя должна быть
            // возможность прекратить процесс со страницы, не дожидаясь
            // таймаута очереди и не бегая в админку
            $res = Service_Nj605_Radar::cancel_run($code, $this->_user_id, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error']
                : 'Сбор остановлен — можно запустить заново кнопкой «Собрать»';
        }
        elseif ($act === 'toggle')
        {
            $enabled = (string) $this->request->post('enabled') !== '0';
            $res = Service_Nj605_Radar::set_enabled($code, $this->_user_id, $enabled, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error']
                : ($enabled ? 'Радар снова включён — сбор возобновится по расписанию'
                            : 'Радар на паузе — новые находки не собираются');
        }
        else
        {
            $res = Service_Nj605_Radar::create_watch($this->_user_id,
                (string) $this->request->post('title'),
                (string) $this->request->post('query'),
                (string) $this->request->post('region'));

            if (isset($res['error']))
            {
                $msg = (string) $res['error'];
            }
            else
            {
                // Создан (или уже был) — сразу на страницу радара. Сообщение
                // оставляем только для «уже есть»: остальное видно по ряду сбора.
                if ( ! empty($res['existing']))
                {
                    Session::instance()->set('nj605_flash', 'Такой радар уже есть — запустил сбор заново');
                }

                $this->redirect('radar/'.urlencode((string) $res['code']), 303);

                return;
            }
        }

        Session::instance()->set('nj605_flash', $msg);

        // 303 See Other: POST → Redirect → GET (перезагрузка ничего не повторяет)
        $this->redirect($back, 303);
    }

    /** Текст результата для страницы: сессия (один раз) + старые ссылки `?flash=`. */
    protected function _flash()
    {
        $session = trim((string) Session::instance()->get_once('nj605_flash', ''));

        return $session !== '' ? $session : trim((string) $this->request->query('flash'));
    }

    /** «Взял в работу» / «не интересно» — идемпотентные статусы находок. */
    protected function _apply_item_status()
    {
        if ((string) $this->request->query('item') === '' OR (string) $this->request->query('st') === '')
        {
            return;
        }

        Service_Nj605_Radar::item_status((int) $this->request->query('item'), $this->_user_id,
            (string) $this->request->query('st'));
    }

    /**
     * Самолечение очереди при заходе на страницу (то же, что делает монитор):
     *   * дожим «доставлено, но не закрыто» → «Готово»;
     *   * зависшие processing разбираются (возврат в очередь / отказ по
     *     молчанию) — иначе задача висела «Выполняется» навсегда и её нельзя
     *     было ни дождаться, ни остановить.
     */
    protected function _sweep_queue()
    {
        try
        {
            if (class_exists('Service_Nj120_JobQueue'))
            {
                Service_Nj120_JobQueue::instance()->requeue_stale();
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj120: свипер очереди со страницы: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Запрос из AJAX («Собрать» с кнопки) или обычная отправка формы? */
    protected function _ajax()
    {
        return (string) $this->request->post('ajax') === '1'
            OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest';
    }
}
