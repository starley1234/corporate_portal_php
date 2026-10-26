<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj642_vacancies: /vacancies — мои мониторы и лента вакансий;
 * /vacancies/<code> — ОДИН монитор: живой ряд сбора, история, вакансии.
 *
 * Владелец (26.09.2026): «по вакансиям от работодателя можно сделать отдельный
 * функционал — указать город, направление и мониторить изменение (появление
 * интересных, с уведомлением письмом) вакансий на сайтах компаний».
 *
 * Все действия страницы — POST → Redirect → GET: перезагрузка ничего не
 * повторяет, а результат показывается через сессию (не через `?flash=` в адресе).
 */
class Controller_Nj642_Vacancies extends Controller_Erp100_Base {

    /** @var int|NULL */
    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Вакансии компаний — что открывают и закрывают работодатели');
        $this->set_description('Мониторы вакансий: город, направление и компании. Мы следим за страницами «Карьера» и «Вакансии» на сайтах работодателей, показываем историю появления и исчезновения вакансий и присылаем письмо о новых подходящих.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
    }

    /** /vacancies — форма монитора, мои мониторы и лента вакансий. */
    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            $this->_post('vacancies');

            return;
        }

        $f = [
            'status'    => $this->_status_filter(),
            'watch'     => trim((string) $this->request->query('watch')),
            'company'   => trim((string) $this->request->query('company')),
            'city'      => trim((string) $this->request->query('city')),
            'direction' => trim((string) $this->request->query('direction')),
            'q'         => trim((string) $this->request->query('q')),
        ];

        $per   = $this->_per();
        $page  = max(1, (int) $this->request->query('page'));
        $total = 0;
        $items = [];
        $counts = ['active' => 0, 'new' => 0, 'saved' => 0, 'gone' => 0];
        $watches = [];
        $live    = [];
        $last    = [];

        if ($this->_user_id)
        {
            $this->_sweep_queue();

            $watches = Service_Nj642_Vacancies::watches_of($this->_user_id);
            $is_admin = (bool) $this->can('nj642.admin');

            foreach ($watches as $w)
            {
                $code = (string) $w['code'];
                $st   = Service_Nj642_Vacancies::live_for($code, $this->_user_id, $is_admin);

                if ($st AND in_array($st['status'], ['queued', 'running'], TRUE))
                {
                    $live[$code] = $st;

                    continue;
                }

                $done = Service_Nj642_Vacancies::last_for($code, $this->_user_id, $is_admin);

                if ($done AND in_array($done['status'], ['done', 'failed', 'cancelled'], TRUE))
                {
                    $last[$code] = $done;
                }
            }

            $total   = Service_Nj642_Vacancies::feed_count($this->_user_id, $f);
            $pages   = max(1, (int) ceil($total / $per));
            $page    = min($page, $pages);
            $items   = Service_Nj642_Vacancies::feed($this->_user_id, $f, $per, ($page - 1) * $per);
            $counts  = Service_Nj642_Vacancies::feed_counts($this->_user_id);
        }

        $this->template->content = View::factory('642/index', [
            'items'    => $items,
            'watches'  => $watches,
            'live'     => $live,
            'last'     => $last,
            'hints'    => $this->_user_id ? Service_Nj642_Vacancies::company_hints($this->_user_id) : [],
            'counts'   => $counts,
            'f'        => $f + ['page' => $page, 'per' => $per, 'total' => $total,
                                'pages' => max(1, (int) ceil($total / max(1, $per)))],
            'flash'    => $this->_flash(),
            'csrf'     => Security::token(),
            'signed'   => $this->_user_id !== NULL,
        ]);
    }

    /** /vacancies/<code> — один монитор вакансий. */
    public function action_watch()
    {
        $code = (string) $this->request->param('code');

        if ($this->request->method() === Request::POST)
        {
            $this->_post('vacancies/'.urlencode($code));

            return;
        }

        if ($this->_user_id === NULL)
        {
            throw HTTP_Exception::factory(403, 'Войдите, чтобы открыть монитор вакансий');
        }

        $is_admin = (bool) $this->can('nj642.admin');
        $w        = Service_Nj642_Vacancies::by_code($code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $this->_user_id))
        {
            throw HTTP_Exception::factory(404, 'Монитор не найден');
        }

        $this->_sweep_queue();

        $live = Service_Nj642_Vacancies::live_for($code, $this->_user_id, $is_admin);
        $last = Service_Nj642_Vacancies::last_for($code, $this->_user_id, $is_admin);

        if ( ! $live OR ! in_array($live['status'], ['queued', 'running'], TRUE))
        {
            $live = NULL;
        }

        if ( ! $last OR ! in_array($last['status'], ['done', 'failed', 'cancelled'], TRUE))
        {
            $last = NULL;
        }

        $f = [
            'status'    => $this->_status_filter(),
            'watch'     => (string) $w['code'],
            'company'   => '',
            'city'      => '',
            'direction' => '',
            'q'         => trim((string) $this->request->query('q')),
        ];

        $per   = $this->_per();
        $page  = max(1, (int) $this->request->query('page'));
        $total = Service_Nj642_Vacancies::feed_count((int) $w['user_id'], $f);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($page, $pages);

        $this->set_title(($w['title'] !== '' ? $w['title'] : 'Монитор вакансий').' — вакансии компаний');
        $this->set_description('Вакансии, снятые с сайтов выбранных компаний: что открылось, что закрылось, история по каждому изменению.');

        $this->template->content = View::factory('642/watch', [
            'w'      => $w,
            'items'  => Service_Nj642_Vacancies::feed((int) $w['user_id'], $f, $per, ($page - 1) * $per),
            'stats'  => Service_Nj642_Vacancies::stats_of((int) $w['id']),
            'company_list' => Service_Nj642_Vacancies::companies_of($w),
            'feed'   => $f + ['page' => $page, 'per' => $per, 'total' => $total, 'pages' => $pages],
            'live'   => $live,
            'last'   => $last,
            'flash'  => $this->_flash(),
            'csrf'   => Security::token(),
        ]);
    }

    /**
     * Обработка POST. Заканчивается JSON (AJAX-«Собрать») либо редиректом на
     * чистый GET.
     */
    protected function _post($back = 'vacancies')
    {
        $act      = (string) $this->request->post('act');
        $code     = (string) $this->request->post('code');
        // Скобки обязательны: AND слабее присваивания — без них любой вошедший
        // пользователь получал права администратора модуля.
        $is_admin = ((bool) $this->_current_user AND $this->can('nj642.admin'));
        $csrf_ok  = Security::check((string) $this->request->post('csrf'));

        if ($act === 'run_now' AND $csrf_ok AND $this->_ajax())
        {
            $res = Service_Nj642_Vacancies::run_now($code, $this->_user_id, $is_admin);

            $this->auto_render = FALSE;
            $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                ->body(json_encode([
                    'ok'    => ! isset($res['error']),
                    'flash' => isset($res['error']) ? (string) $res['error']
                        : 'Сбор запущен — вакансии появятся на странице',
                ], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ( ! $csrf_ok)
        {
            $msg = 'Сессия устарела — попробуйте ещё раз';
        }
        elseif ($act === 'run_now')
        {
            $res = Service_Nj642_Vacancies::run_now($code, $this->_user_id, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error'] : 'Сбор запущен';
        }
        elseif ($act === 'stop_run')
        {
            $res = Service_Nj642_Vacancies::cancel_run($code, $this->_user_id, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error']
                : 'Сбор остановлен — найденное сохранено, можно запустить заново';
        }
        elseif ($act === 'toggle')
        {
            $enabled = (string) $this->request->post('enabled') !== '0';
            $res = Service_Nj642_Vacancies::set_enabled($code, $this->_user_id, $enabled, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error']
                : ($enabled ? 'Монитор включён — сбор пойдёт по расписанию'
                            : 'Монитор на паузе — новые вакансии не собираются');
        }
        elseif ($act === 'delete')
        {
            $res = Service_Nj642_Vacancies::remove_watch($code, $this->_user_id, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error'] : 'Монитор удалён';
            $back = 'vacancies';
        }
        elseif ($act === 'match')
        {
            $res = Service_Nj642_Vacancies::set_match_status($this->_user_id,
                (int) $this->request->post('match_id'), (string) $this->request->post('status'));
            $msg = isset($res['error']) ? (string) $res['error'] : 'Пометка сохранена';
        }
        else
        {
            $res = Service_Nj642_Vacancies::create_watch($this->_user_id, [
                'title'     => (string) $this->request->post('title'),
                'city'      => (string) $this->request->post('city'),
                'direction' => (string) $this->request->post('direction'),
                'keywords'  => (string) $this->request->post('keywords'),
                'companies' => $this->_companies_from_post(),
                'notify'    => (string) $this->request->post('notify') !== '0',
            ]);

            if (isset($res['error']))
            {
                $msg = (string) $res['error'];
            }
            else
            {
                $this->redirect('vacancies/'.urlencode((string) $res['code']), 303);

                return;
            }
        }

        Session::instance()->set('nj642_flash', $msg);
        $this->redirect($back, 303);
    }

    /** Компании из формы: строки «Название|домен» (домен можно не указывать). */
    protected function _companies_from_post()
    {
        $raw = (array) $this->request->post('companies');
        $out = [];

        foreach ($raw as $line)
        {
            $line = trim((string) $line);

            if ($line === '')
            {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 2));

            $out[] = [
                'name'   => (string) ($parts[0] ?? ''),
                'domain' => (string) ($parts[1] ?? ''),
            ];
        }

        return $out;
    }

    /** Фильтр статуса ленты: active|new|saved|gone (по умолчанию — активные). */
    protected function _status_filter()
    {
        $s = (string) $this->request->query('status');

        return in_array($s, ['active', 'new', 'saved', 'gone'], TRUE) ? $s : 'active';
    }

    /** «Строк:» 20|50|100 — значение помнит сам браузер (n2j:per:/vacancies). */
    protected function _per()
    {
        $per = (int) $this->request->query('per');

        return in_array($per, [20, 50, 100], TRUE) ? $per : 20;
    }

    /** Текст результата: сессия (один раз) + совместимость с `?flash=`. */
    protected function _flash()
    {
        $session = trim((string) Session::instance()->get_once('nj642_flash', ''));

        return $session !== '' ? $session : trim((string) $this->request->query('flash'));
    }

    /** Самолечение очереди при заходе на страницу (как в мониторе nj120). */
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
            Kohana::$log->add(Log::WARNING, 'nj642: свипер очереди со страницы: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Запрос из AJAX? */
    protected function _ajax()
    {
        return (string) $this->request->post('ajax') === '1'
            OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest';
    }
}
