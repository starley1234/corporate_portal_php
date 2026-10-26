<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj609_watchdog: /watchdog — добавить контрагента, лента событий.
 */
class Controller_Nj609_Watchdog extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Сторож контрагентов — суды, банкротства, ЕГРЮЛ');
        $this->set_description('Добавьте клиентов и поставщиков под наблюдение: раз в неделю проверяем открытые источники и приносим только реальные сигналы риска.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // «Управление» модуля — чипом в шапке (module_nav), а не в рабочей области
        $this->set_module_nav([
            ['title' => 'Управление', 'href' => '/watchdog/admin', 'icon' => 'fas fa-lock', 'acl' => 'nj609.admin'],
        ]);

    }

    public function action_index()
    {
        $flash = '';

        // «Проверить сейчас» — задача сразу в очередь, не ждём пиналку
        if ($this->request->method() === Request::POST AND (string) $this->request->post('act') === 'run_now')
        {
            $res = Security::check((string) $this->request->post('csrf'))
                ? Service_Nj609_Watchdog::run_now((string) $this->request->post('code'), $this->_user_id,
                    (bool) $this->_current_user AND $this->can('nj609.admin'))
                : ['error' => 'Сессия устарела — попробуйте ещё раз'];

            if ($this->request->post('ajax') === '1'
                OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest')
            {
                $this->auto_render = FALSE;
                $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                    ->body(json_encode([
                        'ok'    => ! isset($res['error']),
                        'flash' => isset($res['error']) ? $res['error']
                            : 'Проверка запущена — события появятся в ленте через несколько минут',
                    ], JSON_UNESCAPED_UNICODE));

                return;
            }

            $flash = isset($res['error']) ? $res['error'] : 'Проверка запущена — события появятся в ленте через несколько минут';
        }

        // «Остановить» — живой ряд нельзя оставлять без выхода: воркер бывает
        // недоступен, и без кнопки пользователь ждёт таймаут очереди.
        if ($this->request->method() === Request::POST AND (string) $this->request->post('act') === 'stop_run')
        {
            $res = Security::check((string) $this->request->post('csrf'))
                ? Service_Nj609_Watchdog::cancel_run((string) $this->request->post('code'), $this->_user_id,
                    (bool) $this->_current_user AND $this->can('nj609.admin'))
                : ['error' => 'Сессия устарела — попробуйте ещё раз'];

            if ($this->request->post('ajax') === '1'
                OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest')
            {
                $this->auto_render = FALSE;
                $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                    ->body(json_encode([
                        'ok'    => ! isset($res['error']),
                        'flash' => isset($res['error']) ? $res['error'] : 'Проверка остановлена',
                    ], JSON_UNESCAPED_UNICODE));

                return;
            }

            HTTP::redirect('/watchdog', 303);
        }

        // отметить событие прочитанным: и старая ссылка ?seen=N, и AJAX-кнопка
        if ((string) $this->request->query('seen') !== '')
        {
            Service_Nj609_Watchdog::event_seen((int) $this->request->query('seen'), $this->_user_id);
        }

        if ($this->request->method() === Request::POST AND (string) $this->request->post('act') === 'seen')
        {
            // NB: не "$ok = check() AND ..." — приоритет AND ниже присваивания
            $ok = (Security::check((string) $this->request->post('csrf'))
                AND Service_Nj609_Watchdog::event_seen((int) $this->request->post('id'), $this->_user_id));

            if ($this->request->post('ajax') === '1'
                OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest')
            {
                $this->auto_render = FALSE;
                $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                    ->body(json_encode(['ok' => (bool) $ok,
                        'flash' => $ok ? 'Отмечено прочитанным' : 'Не получилось'], JSON_UNESCAPED_UNICODE));

                return;
            }

            HTTP::redirect('/watchdog', 303);
        }

        if ($this->request->method() === Request::POST)
        {
            if ((string) $this->request->post('act') === 'delete')
            {
                Service_Nj609_Watchdog::delete_subject((string) $this->request->post('code'), $this->_user_id);
                $flash = 'Контрагент снят с наблюдения';
            }
            elseif (Security::check((string) $this->request->post('csrf')))
            {
                $res = Service_Nj609_Watchdog::add_subject($this->_user_id,
                    (string) $this->request->post('company'),
                    (string) $this->request->post('inn'),
                    (string) $this->request->post('note'));

                $flash = isset($res['error']) ? $res['error'] : 'Добавлен — первая проверка в ближайший час';
            }
            else
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
        }

        $subjects = $this->_user_id ? Service_Nj609_Watchdog::subjects_of($this->_user_id) : [];
        // Скобки обязательны: AND слабее присваивания — без них администратором
        // считался любой вошедший пользователь.
        $is_admin = ($this->_user_id !== NULL AND ($this->can('nj609.admin') OR Auth::instance()->logged_in('admin')));

        // Живой статус-ряд и плашка итога — по каждому контрагенту (эталон
        // /trends): работающую проверку видно сразу, а после завершения в списке
        // остаётся «Готово · 10 мин назад · событий: 2», а не пустое место.
        $live = [];
        $last = [];

        foreach ($subjects as $s)
        {
            $code = (string) $s['code'];
            $st   = Service_Nj609_Watchdog::live_for($code, $this->_user_id, $is_admin);

            if ($st AND in_array((string) $st['status'], ['queued', 'running'], TRUE))
            {
                $live[$code] = $st;

                continue;
            }

            $done = Service_Nj609_Watchdog::last_for($code, $this->_user_id, $is_admin);

            if ($done)
            {
                $last[$code] = $done + ['found' => Service_Nj609_Watchdog::found_since((int) $s['id'], $done['started_at'])];
            }
        }

        $this->template->content = View::factory('609/index', [
            'subjects' => $subjects,
            'feed'     => $this->_user_id ? Service_Nj609_Watchdog::feed($this->_user_id) : [],
            'live'     => $live,
            'last'     => $last,
            'flash'    => $flash,
            'csrf'     => Security::token(),
            'logged'   => $this->_user_id !== NULL,
            'is_admin' => $is_admin,
        ]);
    }
}
