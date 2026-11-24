<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API центра уведомлений (prx597).
 *
 * Пользовательские действия (нужна сессия и CSRF в теле):
 *  * GET  /prx597/api/unread  — {unread, items:[…]} — колокольчик в шапке;
 *    (с ?visible=1 он же отмечает присутствие человека на сайте: см.
 *     Notify::touch_presence — «своё событие» не будит того, кто на сайте);
 *  * POST /prx597/api/read    — отметить прочитанными {id:[..]} или {all:1};
 *  * POST /prx597/api/archive — в архив;
 *  * POST /prx597/api/clear   — удалить прочитанные;
 *  * POST /prx597/api/prefs   — сохранить настройки (каналы, тихие часы, дайджест);
 *  * POST /prx597/api/sub     — клетка матрицы «событие × канал»;
 *  * POST /prx597/api/bind    — выдать код привязки Telegram/MAX ({channel});
 *  * POST /prx597/api/unbind  — отвязать канал;
 *  * GET  /prx597/api/events  — какие события доступны пользователю (матрица).
 *
 * Служебное (без сессии, по токену воркера):
 *  * POST /prx597/api/outbox  — один проход центра: очередь, сводки, опрос ботов.
 *
 * ВАЖНО (06.10.2026): before() отвечает 401/403 и ставит $_halt, но Kohana
 * выполняет действие ВСЁ РАВНО — каждое пользовательское действие обязано
 * начинаться с `if ($this->_halt) return;`. Проверяет tests/Contracts/prx597_api.php.
 */
class Controller_Prx597_Api extends Controller {

    /** Прервано в before() (401/403): действие не должно выполняться. */
    protected $_halt = FALSE;

    public function before()
    {
        parent::before();

        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');

        // Служебный outbox вызывается python-воркером из prx603 без пользовательской сессии.
        if ($this->request->action() === 'outbox')
        {
            // Сохраняем прежний PHP ?: приоритет заголовка: пробел не даёт
            // fallback на query, а строка "0" считается отсутствующей.
            $header = $this->request->headers('x-worker-token');
            $authorized = FALSE;
            if ($header AND trim((string) $header) === '')
            {
                $authorized = FALSE;
            }
            elseif ($header === '0')
            {
                $this->request->headers('x-worker-token', '');
                try { $authorized = Prx_Gate::worker_request($this->request, NULL, TRUE); }
                finally { $this->request->headers('x-worker-token', '0'); }
            }
            else
            {
                $authorized = Prx_Gate::worker_request($this->request, NULL, TRUE);
            }

            if ( ! $authorized)
            {
                $this->response->status(403);
                $this->response->body(json_encode(['error' => 'Токен воркера не совпал'], JSON_UNESCAPED_UNICODE));
                $this->_halt = TRUE;
                return;
            }

            Service_Prx597_Notify::ensure_tables();
            return;
        }

        // prx599: убитая kick-меткой сессия — выходим, чтобы колокольчик дал 401
        $user597 = Auth::instance()->get_user();

        if ($user597 AND class_exists('Service_Prx599_Account'))
        {
            try
            {
                if ( ! Service_Prx599_Account::session_valid((int) $user597->id))
                {
                    Auth::instance()->logout(TRUE);
                    $user597 = NULL;
                }
            }
            catch (Throwable $e) { /* учёт не блокирует poll */ }
        }

        if ( ! $user597)
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'Только для зарегистрированных'], JSON_UNESCAPED_UNICODE));
            $this->_halt = TRUE;

            return;
        }

        Service_Prx597_Notify::ensure_tables();
    }

    /** GET /prx597/api/unread. */
    public function action_unread()
    {
        // 06.10.2026: у анонима/протухшей сессии before() отвечает 401 и
        // ставит _halt — но Kohana выполняет действие ВСЁ РАВНО, и на
        // get_user()->id=null ловили EMERGENCY «read property on null».
        if ($this->_halt) return;

        $uid = (int) Auth::instance()->get_user()->id;

        // Присутствие: колокольчик и так спрашивает сервер раз в 45 секунд,
        // поэтому отдельного «пульса» нет — вкладка добавляет ?visible=1,
        // когда она открыта на виду (свёрнутая или чужая вкладка молчит).
        // Нужно, чтобы своим событием не будить того, кто смотрит результат.
        if ((string) $this->request->query('visible') === '1')
        {
            Service_Prx597_Notify::touch_presence($uid);
        }

        $items = [];

        foreach (Service_Prx597_Notify::latest($uid, 8) as $n)
        {
            $items[] = [
                'id'     => (int) $n['id'],
                'kind'   => (string) $n['kind'],
                'title'  => (string) $n['title'],
                'body'   => (string) $n['body'],
                'href'   => (string) $n['href'],
                'unread' => $n['read_at'] === NULL,
                'ago'    => self::_ago((string) $n['created_at']),
            ];
        }

        $this->response->body(json_encode([
            'unread' => Service_Prx597_Notify::unread_count($uid),
            'items'  => $items,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * POST /prx597/api/outbox — один проход центра уведомлений.
     *
     * Зовёт «Пиналка» (prx603) по расписанию или python-воркер с токеном
     * (X-Worker-Token). Что делает за проход:
     *   * догоняет завершённые задачи очереди (sweep_jobs) — уведомления о
     *     готовых отчётах и ошибках;
     *   * разбирает очередь доставки: почта, Telegram, MAX, в том числе
     *     отложенное (тихие часы, лимиты);
     *   * рассылает сводки-дайджесты;
     *   * при режиме «опрос» забирает обновления ботов (привязка аккаунтов).
     */
    public function action_outbox()
    {
        if ($this->_halt) return;

        $jobs  = Service_Prx597_Notify::sweep_jobs(100);
        $run   = Service_Prx597_Engine::run(50);
        $digest = Service_Prx597_Engine::flush_digests(50);

        // Старые письма, поставленные в очередь до появления движка.
        $legacy = Service_Prx597_Notify::send_digests(50, 30);

        $poll = [];

        foreach (['telegram', 'max'] as $bot)
        {
            if (Service_Prx597_Bind::mode($bot) === 'poll')
            {
                $poll[$bot] = Service_Prx597_Bind::poll($bot, 20);
            }
        }

        $this->response->body(json_encode([
            'ok'     => TRUE,
            'jobs'   => (int) $jobs,
            'run'    => $run,
            'digest' => $digest,
            'legacy' => ['sent' => (int) Arr::get($legacy, 'sent', 0), 'failed' => (int) Arr::get($legacy, 'failed', 0)],
            'poll'   => $poll,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** POST /prx597/api/read. */
    public function action_read()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST
            OR ! Security::check((string) $this->request->post('csrf')))
        {
            $this->response->status(403);
            $this->response->body(json_encode(['error' => 'Сессия устарела'], JSON_UNESCAPED_UNICODE));

            return;
        }

        $uid = (int) Auth::instance()->get_user()->id;

        if ($this->request->post('all'))
        {
            Service_Prx597_Notify::mark_read($uid);
        }
        else
        {
            $ids = (array) json_decode((string) $this->request->post('id'), TRUE);
            Service_Prx597_Notify::mark_read($uid, $ids);
        }

        $this->response->body(json_encode([
            'ok'     => TRUE,
            'unread' => Service_Prx597_Notify::unread_count($uid),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** POST /prx597/api/prefs — настройки доставки пользователя. */
    public function action_prefs()
    {
        if ($this->_halt) return;

        if ( ! $this->_csrf_ok()) return;

        $uid = (int) Auth::instance()->get_user()->id;
        $p   = $this->request->post();

        $fields = Service_Prx597_Notify::user_pref_fields($p);

        try
        {
            $saved = Service_Prx597_Notify::save_prefs($uid, $fields);
        }
        catch (Throwable $e)
        {
            $this->response->status(500);
            $this->response->body(json_encode(['error' => 'Настройки не сохранились: база не подтвердила запись'], JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->response->body(json_encode(['ok' => TRUE, 'prefs' => self::_public_prefs($saved)], JSON_UNESCAPED_UNICODE));
    }

    /** POST /prx597/api/sub — клетка матрицы «событие × канал». */
    public function action_sub()
    {
        if ($this->_halt) return;

        if ( ! $this->_csrf_ok()) return;

        $uid     = (int) Auth::instance()->get_user()->id;
        $alias   = (string) $this->request->post('event');
        $channel = (string) $this->request->post('channel');
        $enabled = (bool) $this->request->post('enabled');

        $event = Service_Prx597_Registry::event($alias);

        if ($event === NULL)
        {
            $this->response->status(404);
            $this->response->body(json_encode(['error' => 'Событие не найдено'], JSON_UNESCAPED_UNICODE));

            return;
        }

        // Обязательное событие выключить нельзя: ни пользователю, ни админу.
        if ( ! empty($event['mandatory']))
        {
            $this->response->status(403);
            $this->response->body(json_encode(['error' => 'Это событие обязательное — его нельзя отключить'], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ( ! isset(Service_Prx597_Base::user_channels()[$channel])
            OR ! in_array($channel, $event['channels'], TRUE)
            OR ! Service_Prx597_Registry::user_allowed($event, Service_Prx597_Registry::roles_of($uid), $uid))
        {
            $this->response->status(403);
            $this->response->body(json_encode(['error' => 'Событие или канал недоступны'], JSON_UNESCAPED_UNICODE));
            return;
        }

        Service_Prx597_Registry::save_sub($uid, $event['alias'], $channel, $enabled);

        $this->response->body(json_encode(['ok' => TRUE, 'event' => $event['alias'], 'channel' => $channel, 'enabled' => $enabled], JSON_UNESCAPED_UNICODE));
    }

    /** POST /prx597/api/bind — выдать код привязки мессенджера. */
    public function action_bind()
    {
        if ($this->_halt) return;

        if ( ! $this->_csrf_ok()) return;

        $uid     = (int) Auth::instance()->get_user()->id;
        $channel = (string) $this->request->post('channel');

        // Привязать можно только мессенджер: у почты и колокольчика привязки
        // нет по смыслу (адрес берётся из профиля, лента — из сессии).
        if ( ! in_array($channel, Service_Prx597_Registry::binding_channels(), TRUE))
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'Этот канал не привязывается'], JSON_UNESCAPED_UNICODE));

            return;
        }

        $res = Service_Prx597_Bind::start($uid, $channel);

        if (isset($res['error']))
        {
            $this->response->status(400);
            $this->response->body(json_encode($res, JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->response->body(json_encode(array_merge(['ok' => TRUE], $res), JSON_UNESCAPED_UNICODE));
    }

    /** POST /prx597/api/unbind — отвязать канал. */
    public function action_unbind()
    {
        if ($this->_halt) return;

        if ( ! $this->_csrf_ok()) return;

        $uid     = (int) Auth::instance()->get_user()->id;
        $channel = (string) $this->request->post('channel');

        if ( ! in_array($channel, Service_Prx597_Registry::binding_channels(), TRUE))
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'Неизвестный канал'], JSON_UNESCAPED_UNICODE));

            return;
        }

        Service_Prx597_Bind::unbind($uid, $channel);

        $this->response->body(json_encode(['ok' => TRUE, 'channel' => $channel], JSON_UNESCAPED_UNICODE));
    }

    /** GET /prx597/api/events — матрица «событие × канал» для настроек. */
    public function action_events()
    {
        if ($this->_halt) return;

        $uid     = (int) Auth::instance()->get_user()->id;
        $prefs   = Service_Prx597_Notify::prefs($uid);
        $roles   = Service_Prx597_Registry::roles_of($uid);
        $subs    = Service_Prx597_Registry::subs($uid);
        $binding = [];
        $channels = Service_Prx597_Base::user_channels();

        foreach ($channels as $key => $info)
        {
            $driver = Service_Prx597_Base::channel_driver($key);

            $binding[$key] = class_exists($driver)
                ? (string) call_user_func([$driver, 'destination'], $uid)
                : '';
        }

        $events = [];

        foreach (Service_Prx597_Registry::events(TRUE) as $alias => $event)
        {
            if ( ! Service_Prx597_Registry::user_allowed($event, $roles, $uid))
            {
                continue;   // не его план — не показываем и не предлагаем
            }

            $row = [
                'alias'       => $alias,
                'title'       => $event['title'],
                'description' => $event['description'],
                'category'    => $event['category'],
                'icon'        => $event['icon'],
                'mandatory'   => $event['mandatory'],
                'channels'    => array_values(array_intersect($event['channels'], array_keys($channels))),
                'enabled'     => [],
            ];

            foreach ($row['channels'] as $channel)
            {
                $row['enabled'][$channel] = Service_Prx597_Registry::sub_enabled($event, $channel, $prefs, $subs);
            }

            $events[] = $row;
        }

        $this->response->body(json_encode([
            'events'    => $events,
            'channels'  => array_map(function ($info) {
                return ['key' => $info['key'], 'label' => $info['label'], 'icon' => $info['icon'], 'bind' => ! empty($info['bind'])];
            }, array_values($channels)),
            'binding'   => $binding,
            'prefs'     => self::_public_prefs($prefs),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** POST /prx597/api/archive — убрать прочитанное из ленты, но не удалять. */
    public function action_archive()
    {
        if ($this->_halt) return;

        if ( ! $this->_csrf_ok()) return;

        $uid = (int) Auth::instance()->get_user()->id;
        $ids = (array) json_decode((string) $this->request->post('id'), TRUE);

        $n = Service_Prx597_Notify::archive($uid, $ids);

        $this->response->body(json_encode(['ok' => TRUE, 'archived' => $n], JSON_UNESCAPED_UNICODE));
    }

    /** POST /prx597/api/clear — удалить прочитанные. */
    public function action_clear()
    {
        if ($this->_halt) return;

        if ( ! $this->_csrf_ok()) return;

        $uid = (int) Auth::instance()->get_user()->id;
        $n   = Service_Prx597_Notify::clear_read($uid);

        $this->response->body(json_encode(['ok' => TRUE, 'deleted' => $n], JSON_UNESCAPED_UNICODE));
    }

    /** Настройки для выдачи: наружу отдаём только то, что нужно интерфейсу. */
    protected static function _public_prefs(array $prefs)
    {
        $out = [
            'digest_mode' => (string) Arr::get($prefs, 'digest_mode', 'off'),
            'quiet_from' => (int) Arr::get($prefs, 'quiet_from', -1),
            'quiet_to' => (int) Arr::get($prefs, 'quiet_to', -1),
            'tz' => (string) Arr::get($prefs, 'tz', 'Europe/Moscow'),
        ];
        foreach (Service_Prx597_Base::user_channels() as $key => $info)
        {
            // Внешние каналы выключены по умолчанию, колокольчик включён
            // (владелец, 16.10.2026) — и в ответе API ровно то же, иначе
            // интерфейс рисовал бы не то, что решает доставка.
            $out[$key.'_enabled'] = (bool) Arr::get($prefs, $key.'_enabled', $key === 'in_app');
        }
        return $out;
    }

    /** CSRF из тела запроса. */
    protected function _csrf_ok()
    {
        if ($this->request->method() !== Request::POST
            OR ! Security::check((string) $this->request->post('csrf')))
        {
            $this->response->status(403);
            $this->response->body(json_encode(['error' => 'Сессия устарела'], JSON_UNESCAPED_UNICODE));

            return FALSE;
        }

        return TRUE;
    }

    /** «5 мин назад» — без тяжёлых библиотек. */
    protected static function _ago($ts)
    {
        $diff = max(0, time() - (int) strtotime($ts));

        if ($diff < 60) return 'только что';
        if ($diff < 3600) return floor($diff / 60).' мин назад';
        if ($diff < 86400) return floor($diff / 3600).' ч назад';

        return floor($diff / 86400).' дн назад';
    }
}
