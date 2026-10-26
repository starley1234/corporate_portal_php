<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «AI Досье на работодателя» (nj598): /dossier — создание и мои досье,
 * /dossier/<code> — результат разведки.
 */
class Controller_Nj598_Dossier extends Controller_Erp100_Base {

    protected $_user_id = NULL;
    protected $_uid = '';
    protected $_ubid = NULL;

    public function before()
    {
        parent::before();

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
        $this->_uid = Visitor::uid();
        // ubid связывает «создал гостем, потом залогинился в этом браузере»
        $this->_ubid = Visitor::ubid();

        // чипы раздела собирает nj110 из манифеста

        Service_Nj598_Dossier::ensure_tables();
    }

    /** GET /dossier — форма + мои досье; POST — создать. */
    public function action_index()
    {
        // Замок: досье собирает агент (5–8 поисков и чтение источников), это
        // время модели — сервис работает только по бесплатному аккаунту
        // (владелец, 08.10.2026). Страница остаётся, но гость видит на ней
        // плашку входа; готовое досье по коду открывается как раньше.
        if ( ! Gate::logged_in())
        {
            if ($this->request->method() === Request::POST)
            {
                $this->redirect('auth/login?return_url=dossier');

                return;
            }

            $this->set_title('AI Досье на работодателя — разведка компании перед откликом');
            $this->set_description('Отзывы сотрудников, реальный стек по статьям инженеров, новости о судах и сокращениях: красные флаги, плюсы, шпаргалка к собеседованию и вероятные вопросы скрининга.');

            $this->template->content = View::factory('598/index', [
                'logged'  => FALSE,
                'my'      => [],
                'flash'   => '',
                'prefill' => '',
                'csrf'    => Security::token(),
            ]);

            return;
        }

        if ($this->request->method() === Request::POST)
        {
            if ($this->_check_csrf() !== TRUE)
            {
                HTTP::redirect('/dossier?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));
            }

            $res = Service_Nj598_Dossier::create($this->_user_id, $this->_uid,
                (string) Arr::get($_POST, 'company', ''), isset($_POST['force']));

            if (isset($res['error']))
            {
                HTTP::redirect('/dossier?flash='.urlencode($res['error']));
            }

            // по этой организации досье уже есть — ведём к последнему
            if (isset($res['exists']))
            {
                $when = (int) $res['age_days'] > 0
                    ? 'создано '.(int) $res['age_days'].' дн. назад'
                    : 'создано только что';

                HTTP::redirect('/dossier/'.$res['exists'].'?flash='
                    .urlencode('По этой компании досье уже есть ('.$when.') — открыли его. Нужно свежее: «Повторить разведку» или «Создать новое».'), 303);
            }

            HTTP::redirect('/dossier/'.$res['code'], 303);
        }

        $this->set_title('AI Досье на работодателя — разведка компании перед откликом');
        $this->set_description('Отзывы сотрудников, реальный стек по статьям инженеров, новости о судах и сокращениях: красные флаги, плюсы, шпаргалка к собеседованию и вероятные вопросы скрининга.');

        $this->template->content = View::factory('598/index', [
            'logged' => TRUE,
            'my'    => Service_Nj598_Dossier::list_for($this->_user_id, $this->_uid, 20, $this->_ubid),
            'flash' => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
            'prefill' => isset($_GET['company']) ? (string) $_GET['company'] : '',
            'csrf'  => Security::token(),
        ]);
    }

    /** GET /dossier/<code> — результат. */
    public function action_view()
    {
        $r = Service_Nj598_Dossier::by_code((string) $this->request->param('code'));

        if ($r === NULL)
        {
            throw HTTP_Exception::factory(404, 'Досье не найдено');
        }

        $r = Service_Nj598_Dossier::reconcile($r);

        $mine = (($this->_user_id AND (int) $r['user_id'] === $this->_user_id)
            OR ((string) $r['visitor_id'] === $this->_uid AND $this->_uid !== '')
            OR ($this->_ubid !== NULL AND $this->_ubid !== ''
                AND (string) $r['visitor_id'] === 'g:'.$this->_ubid));

        $this->set_title('Досье: '.$r['company'].' — AI разведка работодателя');

        $this->template->content = View::factory('598/view', [
            'r'       => $r,
            'result'  => Service_Nj598_Dossier::result_of($r),
            'mine'    => $mine,
            'csrf'    => Security::token(),
        ]);
    }

    /** POST /dossier/retry/<code> — повторить разведку своего досье (2 клика). */
    public function action_retry()
    {
        if ($this->_check_csrf() !== TRUE)
        {
            HTTP::redirect('/dossier?flash='.urlencode('Сессия устарела'));
        }

        $code = (string) $this->request->param('code');
        $r = Service_Nj598_Dossier::by_code($code);
        $mine = ($r !== NULL AND (($this->_user_id AND (int) $r['user_id'] === $this->_user_id)
            OR (string) $r['visitor_id'] === $this->_uid));

        if ( ! $mine)
        {
            HTTP::redirect('/dossier?flash='.urlencode('Это досье создано другим посетителем'));
        }

        $res = Service_Nj598_Dossier::retry($code);
        HTTP::redirect('/dossier/'.$code.(isset($res['error']) ? '?flash='.urlencode((string) $res['error']) : ''));
    }

    /** POST /dossier/stop/<code> — прервать идущую разведку (кнопка «Остановить»). */
    public function action_stop()
    {
        if ($this->_check_csrf() !== TRUE)
        {
            HTTP::redirect('/dossier?flash='.urlencode('Сессия устарела — разведка не остановлена'));
        }

        $code = (string) $this->request->param('code');
        $r = Service_Nj598_Dossier::by_code($code);
        $mine = ($r !== NULL AND (($this->_user_id AND (int) $r['user_id'] === $this->_user_id)
            OR (string) $r['visitor_id'] === $this->_uid));

        if ( ! $mine)
        {
            HTTP::redirect('/dossier?flash='.urlencode('Это досье создано другим посетителем'));
        }

        if ( ! empty($r['job_id']) AND class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                // force: гасим и исполняющуюся задачу (воркер свой результат
                // уже не применит — статус в очереди не processing)
                Service_Nj120_JobQueue::instance()->cancel((int) $r['job_id'], TRUE);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj598: остановка разведки: :e', [':e' => $e->getMessage()]);
            }
        }

        Service_Nj598_Dossier::stop((int) $r['id']);
        HTTP::redirect('/dossier/'.$code.'?flash='.urlencode('Разведка остановлена — можно запустить заново'));
    }

    /** POST /dossier/delete/<code> — удалить своё (2 клика). */
    public function action_delete()
    {
        if ($this->_check_csrf() !== TRUE)
        {
            HTTP::redirect('/dossier?flash='.urlencode('Сессия устарела'));
        }

        $res = Service_Nj598_Dossier::delete_own((string) $this->request->param('code'), $this->_user_id, $this->_uid, $this->_ubid);
        HTTP::redirect('/dossier?flash='.urlencode(isset($res['ok']) ? 'Досье удалено' : (string) $res['error']));
    }

    protected function _check_csrf()
    {
        return ($this->request->method() === Request::POST
            AND Security::check((string) Arr::get($_POST, 'csrf', ''))) ? TRUE : 'no csrf';
    }
}
