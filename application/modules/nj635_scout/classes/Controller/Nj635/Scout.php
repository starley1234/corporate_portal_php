<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Разведка контрагента» (nj635_scout): /scout — создание маршрута,
 * /scout/<code> — карта шагов (Карточка → Наблюдение → Профиль → Досье → Письмо).
 */
class Controller_Nj635_Scout extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Разведка контрагента — справочник, сайт, риски, досье, первое письмо');
        $this->set_description('Один вход — полный контур по контрагенту: карточка ЕГРЮЛ/ЕГРИП, профиль сайта, наблюдение за рисками, AI-досье и готовое первое письмо.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        $this->set_module_nav([]);

        $this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
        $this->response->headers('Pragma', 'no-cache');
    }

    /** GET /scout — форма + мои разведки. POST — старт маршрута. */
    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        $this->template->content = View::factory('635/index', [
            'my'     => $this->_user_id ? Service_Nj635_Scout::list_user($this->_user_id) : [],
            'logged' => $this->_user_id !== NULL,
            'csrf'   => Security::token(),
            'flash'  => (string) Arr::get($_GET, 'flash', ''),
            'steps'  => Service_Nj635_Scout::steps(),
        ]);
    }

    /** GET /scout/<code> — карта шагов. */
    public function action_view()
    {
        $code = (string) $this->request->param('code');
        $proj = $this->_user_id !== NULL ? Service_Nj635_Scout::by_code($code) : NULL;

        // аноним — сразу на вход; чужую разведку не показываем никому
        if ($this->_user_id === NULL)
        {
            $this->redirect('auth/login?return_url=scout/'.rawurlencode($code));

            return;
        }

        if ( ! $proj OR (int) $proj['user_id'] !== (int) $this->_user_id)
        {
            $this->redirect('scout?flash='.urlencode('Разведка не найдена или вам не принадлежит'));

            return;
        }

        // POST «написать первое письмо»
        if ($this->request->method() === Request::POST
            AND (string) $this->request->post('act') === 'letter')
        {
            $this->_start_letter($code, (int) $proj['user_id']);

            return;
        }

        $steps = Service_Nj635_Scout::status_of((int) $proj['id'], (int) $proj['user_id']);

        $this->template->content = View::factory('635/view', [
            'proj'  => $proj,
            'steps' => $steps,
            'csrf'  => Security::token(),
            'flash' => (string) Arr::get($_GET, 'flash', ''),
        ]);
    }

    protected function _create()
    {
        if ($this->_user_id === NULL)
        {
            $this->redirect('auth/login?return_url=scout');

            return;
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('scout?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        $res = Service_Nj635_Scout::create($this->_user_id, (string) Arr::get($_POST, 'query', ''));

        if (isset($res['error']))
        {
            $this->redirect('scout?flash='.urlencode((string) $res['error']));

            return;
        }

        $this->redirect('scout/'.$res['code']);
    }

    protected function _start_letter($code, $owner_id)
    {
        if ($this->_user_id === NULL OR (int) $this->_user_id !== (int) $owner_id)
        {
            $this->redirect('auth/login?return_url=scout/'.rawurlencode($code));

            return;
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('scout/'.$code.'?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        $res = Service_Nj635_Scout::start_letter($this->_user_id, $code, [
            'product'  => (string) Arr::get($_POST, 'product', ''),
            'audience' => (string) Arr::get($_POST, 'audience', ''),
            'tone'     => (string) Arr::get($_POST, 'tone', 'business'),
        ]);

        if (isset($res['error']))
        {
            $this->redirect('scout/'.$code.'?flash='.urlencode((string) $res['error']));

            return;
        }

        $this->redirect('coldmail/'.$res['code']);
    }
}
