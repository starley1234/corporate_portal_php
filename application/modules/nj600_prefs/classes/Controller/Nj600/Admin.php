<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj600: управление «Аккумулятором предпочтений». Доступ — право nj600.admin.
 *
 *  /nj600/admin                   — KPI, популярность модулей, профили, очистка/сжатие;
 *  /nj600/admin/owner/<key>       — карточка владельца (u:<id> / g:<ubid>): предпочтения,
 *                                   последние действия, рекомендации с причинами, адресные правила;
 *  /nj600/admin/reco              — рекомендации под надзором: правила, CTR, журнал показов;
 *  POST rule / rule_toggle / rule_delete / owner_action — правки правил (CSRF, two-click в вёрстке).
 */
class Controller_Nj600_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj600.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_module_nav([
            ['title' => 'Профили',      'href' => '/nj600/admin',      'icon' => 'fas fa-brain'],
            ['title' => 'Рекомендации', 'href' => '/nj600/admin/reco', 'icon' => 'fas fa-wand-magic-sparkles'],
        ]);

        Service_Nj600_Prefs::ensure_tables();
    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $act = (string) $this->request->post('act');

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ($act === 'prune')
            {
                try
                {
                    $n = Service_Nj600_Prefs::prune();
                    $flash = 'Сырьё очищено, строк удалено: '.$n;
                }
                catch (Throwable $e)
                {
                    $flash = 'Не получилось: '.$e->getMessage();
                }
            }
            elseif ($act === 'compact')
            {
                try
                {
                    $res = Service_Nj600_Prefs::queue_compact();
                    $flash = isset($res['error']) ? $res['error']
                        : 'Сжатие поставлено в очередь (задача #'.(int) $res['job'].'): воркер сначала склеит профили скриптами, потом LLM напишет резюме. Статус — /nj120/monitor.';
                }
                catch (Throwable $e)
                {
                    $flash = 'Не получилось: '.$e->getMessage();
                }
            }
            else
            {
                $flash = 'Неизвестное действие';
            }

            HTTP::redirect('/nj600/admin?flash='.urlencode($flash), 303);
        }

        $this->set_title('Аккумулятор предпочтений · управление');

        // Живой статус-ряд сжатия (эталон /trends): раньше после кнопки была
        // только строчка-флеш «статус — /nj120/monitor», то есть работа воркера
        // с этой страницы не видна вовсе.
        $live = Service_Nj600_Prefs::live_now();
        $last = NULL;

        if ($live AND ! in_array((string) $live['status'], ['queued', 'running'], TRUE))
        {
            $last = Service_Nj600_Prefs::last_run();
            $live = NULL;
        }

        $this->template->content = View::factory('600/admin', [
            'live'     => $live,
            'last'     => $last,
            'stats'    => Service_Nj600_Prefs::admin_stats(),
            'profiles' => Service_Nj600_Prefs::admin_profiles(20),
            'mods'     => Service_Nj600_Prefs::module_stats(),
            'flash'    => isset($_GET['flash']) ? (string) $_GET['flash'] : $flash,
            'csrf'     => Security::token(),
        ]);
    }

    // ------------------------------------------------------ карточка владельца

    /** GET /nj600/admin/owner/<key> — предпочтения и последние действия одного владельца. */
    public function action_owner()
    {
        $key = $this->_owner_key();

        $user = NULL;
        $user_id = 0;

        if (preg_match('/^u:(\d+)$/', $key, $m))
        {
            $user_id = (int) $m[1];

            try
            {
                $u = ORM::factory('User', $user_id);

                if ($u->loaded())
                {
                    $user = [
                        'id'         => (int) $u->id,
                        'username'   => (string) $u->username,
                        'email'      => (string) $u->email,
                        'logins'     => (int) $u->logins,
                        'last_login' => (string) $u->last_login,
                    ];
                }
            }
            catch (Throwable $e)
            {
                $user = NULL;
            }
        }

        $profile = Service_Nj600_Prefs::profile_full($key);
        $events  = Service_Nj600_Prefs::owner_events($key, 60);
        $days    = Service_Nj600_Prefs::owner_days($key, 14);
        $catalog = Service_Nj600_Reco::catalog($key);
        $reco    = [];
        $reco_log = [];
        $rules   = [];

        try
        {
            $reco     = Service_Nj600_Reco::for_owner($key, 8, FALSE);
            $reco_log = Service_Nj600_Reco::owner_log($key, 30);

            foreach (Service_Nj600_Reco::rules() as $r)
            {
                if ((string) $r['when_owner'] === $key)
                {
                    $rules[] = $r;
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj600: owner reco: :e', [':e' => $e->getMessage()]);
        }

        $jobs = [];

        if (class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $jobs = Service_Nj120_JobQueue::instance()->visitor_jobs($key, 25);
            }
            catch (Throwable $e)
            {
                $jobs = [];
            }
        }

        $logins = [];

        if ($user_id > 0 AND class_exists('Service_Nj599_Account'))
        {
            try
            {
                $logins = Service_Nj599_Account::logins($user_id, 10);
            }
            catch (Throwable $e)
            {
                $logins = [];
            }
        }

        // центр контроля: объекты по модулям, токены, сбои — читает
        // Service_Nj600_Control через контракты других модулей
        $objects  = Service_Nj600_Control::objects($key);
        $tokens   = Service_Nj600_Control::tokens($key, 30);
        $failures = Service_Nj600_Control::failures($key, 15);
        $links    = Service_Nj600_Control::links($key);
        $budget   = [];

        if (class_exists('Service_Nj120_Budget'))
        {
            try
            {
                $budget = Service_Nj120_Budget::state($key);
            }
            catch (Throwable $e)
            {
                $budget = [];
            }
        }

        $this->set_title(($user ? $user['username'] : $key).' · центр контроля');

        $this->template->content = View::factory('600/owner', [
            'key'      => $key,
            'user'     => $user,
            'profile'  => $profile,
            'events'   => $events,
            'days'     => $days,
            'catalog'  => $catalog,
            'reco'     => $reco,
            'reco_log' => $reco_log,
            'rules'    => $rules,
            'jobs'     => $jobs,
            'logins'   => $logins,
            'objects'  => $objects,
            'tokens'   => $tokens,
            'failures' => $failures,
            'links'    => $links,
            'budget'   => $budget,
            'flash'    => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
            'csrf'     => Security::token(),
        ]);
    }

    /**
     * POST /nj600/admin/owner_action/<key> — адресные действия администратора:
     * act=pin (закрепить рекомендацию), block (не советовать этому владельцу),
     * notify (отправить уведомлением nj597 — только u:<id>).
     */
    public function action_owner_action()
    {
        $key  = $this->_owner_key();
        $back = '/nj600/admin/owner/'.rawurlencode($key);

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            HTTP::redirect($back.'?flash='.urlencode('Сессия устарела — повторите'), 303);
        }

        $act  = (string) $this->request->post('act');
        $href = Service_Nj600_Reco::clean_href((string) $this->request->post('href'));
        $msg  = 'Неизвестное действие';

        try
        {
            if ($href === '')
            {
                throw new Exception('Адрес — внутренний путь вида /vat');
            }

            if ($act === 'pin' OR $act === 'block')
            {
                $res = Service_Nj600_Reco::save_rule([
                    'kind'       => $act === 'pin' ? 'boost' : 'block',
                    'href'       => $href,
                    'when_owner' => $key,
                    'weight'     => 90,
                    'title'      => (string) $this->request->post('title'),
                    'note'       => (string) $this->request->post('note'),
                ], (int) $this->_current_user->id);

                $msg = isset($res['error']) ? $res['error']
                    : ($act === 'pin' ? 'Закреплено для владельца: '.$href : 'Больше не советуем владельцу: '.$href);
            }
            elseif ($act === 'notify')
            {
                if ( ! preg_match('/^u:(\d+)$/', $key, $m))
                {
                    throw new Exception('Уведомление можно отправить только зарегистрированному (u:<id>)');
                }

                if ( ! class_exists('Service_Nj597_Notify'))
                {
                    throw new Exception('Модуль уведомлений (nj597) не подключён');
                }

                $title = trim((string) $this->request->post('title'));
                $note  = trim((string) $this->request->post('note'));
                $title = $title !== '' ? $title : 'Вам может пригодиться';
                $body  = ($note !== '' ? $note."\n\n" : '').'Открыть: '.$href;

                $res = Service_Nj597_Notify::system_message((string) $m[1], $title, $body, (int) $this->_current_user->id);

                if (is_array($res) AND ! empty($res['error']))
                {
                    throw new Exception((string) $res['error']);
                }

                Service_Nj600_Reco::log_shown($key, ['href' => $href, 'source' => 'manual', 'rule_id' => NULL,
                    'reason' => 'отправлено администратором уведомлением']);

                $msg = 'Уведомление отправлено: '.$href;
            }
        }
        catch (Throwable $e)
        {
            $msg = 'Не получилось: '.mb_substr($e->getMessage(), 0, 200);
        }

        HTTP::redirect($back.'?flash='.urlencode($msg), 303);
    }

    // ------------------------------------------------------------ надзор

    /** GET /nj600/admin/reco — правила, CTR, журнал показов. */
    public function action_reco()
    {
        $this->set_title('Рекомендации · надзор');

        $this->template->content = View::factory('600/reco', [
            'stats'   => Service_Nj600_Reco::stats(30),
            'rules'   => Service_Nj600_Reco::rules(),
            'catalog' => Service_Nj600_Reco::catalog('u:0'),
            'flash'   => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
            'csrf'    => Security::token(),
        ]);
    }

    /** POST /nj600/admin/rule — создать правило. */
    public function action_rule()
    {
        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            HTTP::redirect('/nj600/admin/reco?flash='.urlencode('Сессия устарела — повторите'), 303);
        }

        $res = Service_Nj600_Reco::save_rule((array) $this->request->post(), (int) $this->_current_user->id);
        $msg = isset($res['error']) ? $res['error']
            : ( ! empty($res['dup']) ? 'Такое правило уже было — включено и обновлено (#'.(int) $res['id'].')' : 'Правило добавлено (#'.(int) $res['id'].')');

        HTTP::redirect('/nj600/admin/reco?flash='.urlencode($msg), 303);
    }

    /** POST /nj600/admin/rule_toggle/<id> — включить/выключить. */
    public function action_rule_toggle()
    {
        $this->_rule_post(function ($id) {
            return Service_Nj600_Reco::toggle_rule($id) ? 'Правило #'.$id.' переключено' : 'Правило не найдено';
        });
    }

    /** POST /nj600/admin/rule_delete/<id> — удалить (two-click в вёрстке). */
    public function action_rule_delete()
    {
        $this->_rule_post(function ($id) {
            return Service_Nj600_Reco::delete_rule($id) ? 'Правило #'.$id.' удалено' : 'Правило не найдено';
        });
    }

    protected function _rule_post($fn)
    {
        $id   = (int) $this->request->param('id');
        $back = (string) $this->request->post('back');
        $back = ($back !== '' AND $back[0] === '/' AND strpos($back, '//') !== 0) ? $back : '/nj600/admin/reco';

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            HTTP::redirect($back.'?flash='.urlencode('Сессия устарела — повторите'), 303);
        }

        try
        {
            $msg = (string) $fn($id);
        }
        catch (Throwable $e)
        {
            $msg = 'Не получилось: '.mb_substr($e->getMessage(), 0, 160);
        }

        HTTP::redirect($back.'?flash='.urlencode($msg), 303);
    }

    /** Ключ владельца из маршрута; чужой формат — 404. */
    protected function _owner_key()
    {
        $key = rawurldecode((string) $this->request->param('id'));

        if ( ! preg_match('/^(u:\d+|g:[a-z0-9]{6,40})$/i', $key))
        {
            throw HTTP_Exception::factory(404, 'Владелец ожидается вида u:<id> или g:<ubid>');
        }

        return $key;
    }
}
