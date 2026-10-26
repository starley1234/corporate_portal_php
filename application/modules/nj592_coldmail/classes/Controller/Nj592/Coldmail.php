<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Холодное письмо» (nj592): /coldmail — создание, /coldmail/<code> — варианты.
 */
class Controller_Nj592_Coldmail extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Холодное письмо — персонализированный первый абзац от ИИ');
        $this->set_description('Укажите компанию и что вы предлагаете — ИИ изучит профиль компании и свежие новости и напишет ультра-персонализированный первый абзац для холодного B2B-письма. Несколько вариантов на выбор.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        $this->set_module_nav([]); // чипы раздела даёт header_nav (дубли убраны)

        $this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
        $this->response->headers('Pragma', 'no-cache');
    }

    /** /coldmail: GET — форма + мои письма; POST — создание. */
    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        // готовые профили nj591 — чтобы сослаться на них в подсказке формы
        $profiles = [];

        if ($this->_user_id !== NULL)
        {
            foreach (Service_Nj591_Company::list_user($this->_user_id, 20) as $pr)
            {
                if ((string) $pr['status'] === 'done')
                {
                    $profiles[] = ['code' => (string) $pr['code'],
                                   'label' => (($pr['company'] !== '' ? $pr['company'] : $pr['query']).' ('.$pr['code'].')')];
                }
            }
        }

        $this->template->content = View::factory('592/index', [
            'my'       => $this->_user_id ? Service_Nj592_Coldmail::list_user($this->_user_id) : [],
            'profiles' => $profiles,
            'limits'   => [
                'max_variants'     => 5,
                'default_variants' => 3,
            ],
            'logged'   => $this->_user_id !== NULL,
            'csrf'     => Security::token(),
            'flash'    => (string) Arr::get($_GET, 'flash', ''),
        ]);
    }

    protected function _create()
    {
        if ($this->_user_id === NULL)
        {
            $this->redirect('auth/login?return_url=coldmail');

            return;
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('coldmail?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        $res = Service_Nj592_Coldmail::create(
            $this->_user_id,
            (string) Arr::get($_POST, 'company', ''),
            (string) Arr::get($_POST, 'profile_code', ''),
            (string) Arr::get($_POST, 'product', ''),
            (string) Arr::get($_POST, 'audience', ''),
            (string) Arr::get($_POST, 'tone', 'business'),
            (int) Arr::get($_POST, 'variants', 3)
        );

        if (isset($res['error']))
        {
            $this->redirect('coldmail?flash='.urlencode((string) $res['error']));

            return;
        }

        $this->redirect('coldmail/'.$res['code']);
    }

    /** POST /coldmail/<code>/delete — удалить СВОЙ прогон полностью. */
    public function action_delete()
    {
        $code = (string) $this->request->param('code');
        $l    = Service_Nj592_Coldmail::by_code($code);
        $is_owner = ($l AND $this->_user_id !== NULL AND (int) $l['user_id'] === $this->_user_id);

        if ( ! $is_owner AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Прогон удаляет владелец');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('coldmail?flash='.urlencode('Сессия устарела — прогон не удалён'));

            return;
        }

        Service_Nj592_Coldmail::hard_delete((int) $l['id']);
        $this->redirect('coldmail?flash='.urlencode('Прогон '.$code.' удалён полностью'));
    }

    /** GET /coldmail/<code> — варианты первого абзаца. */
    /** POST /coldmail/stop/<code> — прервать генерацию письма («Остановить»). */
    public function action_stop()
    {
        $code = (string) $this->request->param('code');
        $l    = Service_Nj592_Coldmail::by_code($code);

        if ($l === NULL)
        {
            throw HTTP_Exception::factory(404, 'Прогон не найден');
        }

        $is_owner = ($this->_user_id !== NULL AND (int) $l['user_id'] === $this->_user_id);

        if ( ! $is_owner AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Прогон останавливает владелец');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('coldmail/'.$code.'?flash='.urlencode('Сессия устарела — генерация не остановлена'));

            return;
        }

        if ( ! empty($l['job_id']) AND class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                // force: гасим и исполняющуюся задачу (воркер свой результат уже
                // не применит — статус в очереди не processing)
                Service_Nj120_JobQueue::instance()->cancel((int) $l['job_id'], TRUE);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj592: остановка генерации: :e', [':e' => $e->getMessage()]);
            }
        }

        Service_Nj592_Coldmail::fail((int) $l['id'], 'Сборка остановлена вручную — запустите заново');
        $this->redirect('coldmail/'.$code.'?flash='.urlencode('Сборка остановлена'));

        return;
    }

    public function action_view()
    {
        $code = (string) $this->request->param('code');
        $l    = Service_Nj592_Coldmail::by_code($code);

        if ($l === NULL)
        {
            throw HTTP_Exception::factory(404, 'Прогон не найден');
        }

        $l = Service_Nj592_Coldmail::reconcile($l);

        $this->template->content = View::factory('592/view', [
            'l'        => $l,
            'result'   => Service_Nj592_Coldmail::result_of($l),
            'sources'  => Service_Nj592_Coldmail::sources_of($l),
            'is_owner' => ($this->_user_id !== NULL AND (int) $l['user_id'] === $this->_user_id),
            'csrf'     => Security::token(),
            'flash'    => (string) Arr::get($_GET, 'flash', ''),
        ]);
    }
}
