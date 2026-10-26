<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj612_touch: /touches — мои серии касаний + создание; /touches/<code> —
 * таймлайн шагов, отправка, управление. Владелец видит только своё.
 */
class Controller_Nj612_Touch extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Серии касаний — вежливые follow-up письма по вашим контактам');
        $this->set_description('Первое письмо и до четырёх вежливых напоминаний по компании: черновики пишет ИИ, отправка — по вашей кнопке, ответ контакта автоматически останавливает серию.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // «Управление» модуля — чипом в шапке
        $this->set_module_nav([
            ['title' => 'Управление', 'href' => '/nj612/admin', 'icon' => 'fas fa-lock', 'acl' => 'nj612.admin'],
        ]);
    }

    public function action_index()
    {
        $flash = '';
        // Скобки обязательны: OR слабее присваивания — без них AJAX-запрос с
        // заголовком X-Requested-With не распознавался (страница вместо JSON).
        $ajax  = ($this->request->post('ajax') === '1'
            OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest');

        if ($this->request->method() === Request::POST)
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ($this->_user_id === NULL)
            {
                $flash = 'Войдите, чтобы работать с сериями';
            }
            else
            {
                $act  = (string) $this->request->post('act');
                $code = (string) $this->request->post('code');

                switch ($act)
                {
                    case 'create':
                        $res = Service_Nj612_Touch::create($this->_user_id, (array) $this->request->post());

                        if (isset($res['ok']))
                        {
                            if ($ajax)
                            {
                                $this->_json(['ok' => TRUE, 'flash' => 'Серия создана — черновик первого письма уже пишется', 'code' => $res['code']]);
                                return;
                            }

                            $this->redirect('/touches/'.$res['code']);
                            return;
                        }

                        $flash = (string) $res['error'];
                        break;

                    case 'delete':
                        Service_Nj612_Touch::delete($this->_user_id, $code);
                        $flash = 'Серия удалена';
                        break;

                    default:
                        $flash = 'Неизвестное действие';
                }
            }
        }

        if ($ajax AND $flash !== '')
        {
            $this->_json(['ok' => FALSE, 'flash' => $flash]);
            return;
        }

        $logged = $this->_user_id !== NULL;

        $this->template->content = View::factory('612/index', [
            'flash'     => $flash,
            'csrf'      => Security::token(),
            'logged'    => $logged,
            'sequences' => $logged ? Service_Nj612_Touch::sequences_of($this->_user_id) : [],
            'limits'    => ['max_steps' => Service_Nj612_Touch::MAX_STEPS],
        ]);
    }

    public function action_view()
    {
        $code = (string) $this->request->param('code');
        $flash = '';

        if ($this->_user_id === NULL)
        {
            throw HTTP_Exception::factory(403, 'Войдите, чтобы видеть серию');
        }

        if ($this->request->method() === Request::POST AND Security::check((string) $this->request->post('csrf')))
        {
            $act = (string) $this->request->post('act');

            if ($act === 'send')
            {
                $res = Service_Nj612_Touch::send_step($this->_user_id, $code);
                $flash = isset($res['error']) ? $res['error']
                    : (( ! empty($res['done'])) ? 'Серия завершена — все шаги отправлены' : 'Письмо отправлено — следующий черновик готовится');
            }
            elseif ($act === 'redraft')
            {
                $s = Service_Nj612_Touch::by_code($this->_user_id, $code);
                $seq = $s ? (int) $s['current_step'] + 1 : 0;

                if ($s AND $seq >= 1 AND $seq <= (int) $s['steps_total'])
                {
                    Service_Nj612_Touch::enqueue_draft($code, (int) $s['id'], $seq);
                    $flash = 'Заказал новый черновик шага '.$seq.' — появится через минуту-две';
                }
                else
                {
                    $flash = 'Переписывать нечего — шаги уже отправлены';
                }
            }
            elseif ($act === 'stop_draft')
            {
                $res = Service_Nj612_Touch::cancel_run($this->_user_id, $code);
                $flash = isset($res['error']) ? $res['error'] : 'Задачу остановил — черновик можно заказать заново';
            }
            elseif ($act === 'contact')
            {
                $res = Service_Nj612_Touch::update_contact($this->_user_id, $code,
                    (string) $this->request->post('email'), (string) $this->request->post('contact_name'));
                $flash = isset($res['error']) ? $res['error'] : 'Контакт обновлён';
            }
            elseif (in_array($act, ['pause', 'resume', 'stop', 'answered'], TRUE))
            {
                $res = Service_Nj612_Touch::set_status($this->_user_id, $code, $act);
                $flash = isset($res['error']) ? $res['error'] : 'Готово';
            }
        }
        elseif ($this->request->method() === Request::POST)
        {
            $flash = 'Сессия устарела — попробуйте ещё раз';
        }

        $s = Service_Nj612_Touch::by_code($this->_user_id, $code);

        if ($s === NULL)
        {
            throw HTTP_Exception::factory(404, 'Серия не найдена среди ваших');
        }

        // Живой статус-ряд (эталон /trends): видно, что воркер пишет черновик,
        // а после завершения в серии остаётся плашка итога, а не тишина.
        $live = Service_Nj612_Touch::live_for($this->_user_id, $code);
        $last = NULL;

        if ($live AND ! in_array((string) $live['status'], ['queued', 'running'], TRUE))
        {
            $last = Service_Nj612_Touch::last_for($this->_user_id, $code);
            $live = NULL;
        }

        $this->template->content = View::factory('612/view', [
            's'     => $s,
            'live'  => $live,
            'last'  => $last,
            'flash' => $flash,
            'csrf'  => Security::token(),
            'autosend' => Service_Nj612_Touch::autosend(),
        ]);
    }

    /** AJAX-ответ. */
    protected function _json(array $data)
    {
        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

}
