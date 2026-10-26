<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Профиль компании» (nj591): /company — создание, /company/<code> — результат,
 * /company/<code>/json — выгрузка JSON.
 */
class Controller_Nj591_Company extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Профиль компании — сфера, размер, контакты, стек, ЛПР');
        $this->set_description('Укажите домен (или название) компании — сервис обойдёт Главную, О нас и Контакты и соберёт структурированный JSON: сфера деятельности, примерный размер, контакты, стек технологий, лица, принимающие решения.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        $this->set_module_nav([]); // чипы раздела даёт header_nav (дубли убраны)

        $this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
        $this->response->headers('Pragma', 'no-cache');
    }

    /** /company: GET — форма + мои профили; POST — создание. */
    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        $this->template->content = View::factory('591/index', [
            'my'     => $this->_user_id ? Service_Nj591_Company::list_user($this->_user_id) : [],
            'logged' => $this->_user_id !== NULL,
            'csrf'   => Security::token(),
            'flash'  => (string) Arr::get($_GET, 'flash', ''),
        ]);
    }

    protected function _create()
    {
        if ($this->_user_id === NULL)
        {
            $this->redirect('auth/login?return_url=company');

            return;
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('company?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        $res = Service_Nj591_Company::create($this->_user_id, (string) Arr::get($_POST, 'query', ''));

        if (isset($res['error']))
        {
            $this->redirect('company?flash='.urlencode((string) $res['error']));

            return;
        }

        $this->redirect('company/'.$res['code']);
    }

    /** GET /company/<code> — профиль: статус, результат JSON, источники. */
    public function action_view()
    {
        $code = (string) $this->request->param('code');
        $p    = Service_Nj591_Company::by_code($code);

        if ($p === NULL)
        {
            throw HTTP_Exception::factory(404, 'Профиль не найден');
        }

        // Прерывание сбора: кнопка «Остановить» в статус-ряде (POST act=stop).
        // Гасим задачу и закрываем профиль понятной причиной — иначе он висел
        // бы в «Выполняется» до таймаута задачи.
        if ($this->request->method() === HTTP_Request::POST
            AND (string) $this->request->post('act') === 'stop')
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $this->redirect('company/'.$code.'?flash='.urlencode('Сессия устарела — сбор не остановлен'));

                return;
            }

            if ( ! empty($p['job_id']) AND class_exists('Service_Nj120_JobQueue'))
            {
                try
                {
                    // force: гасим и ИСПОЛНЯЮЩУЮСЯ задачу (воркер свой результат
                    // уже не применит — статус в очереди не processing)
                    Service_Nj120_JobQueue::instance()->cancel((int) $p['job_id'], TRUE);
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'nj591: остановка сбора: :e', [':e' => $e->getMessage()]);
                }
            }

            Service_Nj591_Company::fail((int) $p['id'], 'Сбор остановлен вручную — запустите заново');
            $this->redirect('company/'.$code.'?flash='.urlencode('Сбор остановлен'));

            return;
        }

        $p = Service_Nj591_Company::reconcile($p);

        $this->template->content = View::factory('591/view', [
            'p'        => $p,
            'result'   => Service_Nj591_Company::result_of($p),
            'sources'  => Service_Nj591_Company::sources_of($p),
            'is_owner' => ($this->_user_id !== NULL AND (int) $p['user_id'] === $this->_user_id),
            'csrf'     => Security::token(),
            'flash'    => (string) Arr::get($_GET, 'flash', ''),
            // Связь с модулем вакансий (nj642): сколько вакансий этой компании
            // мы уже видели на её сайте. Нет модуля/таблиц — чипа просто нет.
            'vacancies' => (class_exists('Service_Nj642_Vacancies'))
                ? array_merge(
                    ['key' => Service_Nj642_Vacancies::key_for_profile($p)],
                    Service_Nj642_Vacancies::count_for_company(Service_Nj642_Vacancies::key_for_profile($p)))
                : NULL,
        ]);
    }

    /** POST /company/<code>/delete — удалить СВОЙ профиль полностью. */
    public function action_delete()
    {
        $code = (string) $this->request->param('code');
        $p    = Service_Nj591_Company::by_code($code);
        $is_owner = ($p AND $this->_user_id !== NULL AND (int) $p['user_id'] === $this->_user_id);

        if ( ! $is_owner AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Профиль удаляет владелец');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('company?flash='.urlencode('Сессия устарела — профиль не удалён'));

            return;
        }

        Service_Nj591_Company::hard_delete((int) $p['id']);
        $this->redirect('company?flash='.urlencode('Профиль '.$code.' удалён полностью'));
    }

    /** GET /company/<code>/json — выгрузка результата как JSON-файл. */
    public function action_json()
    {
        $code = (string) $this->request->param('code');
        $p    = Service_Nj591_Company::by_code($code);

        if ($p === NULL)
        {
            throw HTTP_Exception::factory(404, 'Профиль не найден');
        }

        $this->auto_render = FALSE;

        if ((string) $p['status'] !== 'done' OR $p['result'] === NULL)
        {
            $this->response->status(409);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode(['error' => 'not_ready', 'status' => (string) $p['status']], JSON_UNESCAPED_UNICODE));

            return;
        }

        $result = Service_Nj591_Company::result_of($p);
        $result['_meta'] = [
            'code'    => (string) $p['code'],
            'query'   => (string) $p['query'],
            'domain'  => (string) $p['domain'],
            'company' => (string) $p['company'],
            'sources' => Service_Nj591_Company::sources_of($p),
            'made_at' => (string) $p['finished_at'],
        ];

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="company-'.$code.'.json"');
        $this->response->body(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
