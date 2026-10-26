<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj606_leads: /leads — создание потока, лента компаний, действия над лидом
 * (взять/не нужен, собрать профиль, письмо). API воркера — Nj606_Api.
 *
 * Действия страницы — строго POST → Redirect → GET (см. _post()): ответ на POST
 * никогда не остаётся «страницей формы», поэтому перезагрузка (F5) не повторяет
 * действие — не создаёт второй поток и не списывает второй кредит.
 */
class Controller_Nj606_Leads extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Поток заказчиков — компании под ваш оффер');
        $this->set_description('Опишите, какие компании вам нужны (что производят, регион) — сервис найдёт производителей в открытых источниках и подготовит зацепки для первого письма.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // «Управление» модуля — чипом в шапке (module_nav), а не в рабочей области
        $this->set_module_nav([
            ['title' => 'Управление', 'href' => '/leads/admin', 'icon' => 'fas fa-lock', 'acl' => 'nj606.admin'],
        ]);

    }

    public function action_index()
    {
        // Любой POST — отдельным обработчиком: JSON (AJAX-кнопки) либо редирект
        // на чистый GET. Страница на POST не рендерится.
        if ($this->request->method() === Request::POST)
        {
            $this->_post();

            return;
        }

        $flash = trim((string) $this->request->query('flash'));

        // статус лида: и старые ссылки ?item=N&st=..., и AJAX-кнопки
        if ((string) $this->request->query('item') !== '' AND (string) $this->request->query('st') !== '')
        {
            Service_Nj606_Leads::lead_status((int) $this->request->query('item'), $this->_user_id,
                (string) $this->request->query('st'));
        }

        // Живой статус-ряд (эталон /trends/<code>): у каждого потока, чья задача
        // в очереди/исполняется — спиннер + этап воркера + секундомер,
        // страница обновится сама, когда сбор завершится.
        $live = [];
        $last = [];

        if ($this->_user_id)
        {
            // Самолечение очереди при каждом заходе (то же, что делает монитор):
            //   * дожим «доставлено, но не закрыто» → «Готово»;
            //   * зависшие processing разбираются (возврат в очередь / отказ по
            //     молчанию) — иначе задача висела «Выполняется» навсегда и её
            //     нельзя было ни дождаться, ни остановить.
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

            $is_admin = (bool) $this->can('nj606.admin');

            foreach (Service_Nj606_Leads::flows_of($this->_user_id) as $f)
            {
                $st = Service_Nj606_Leads::live_for((string) $f['code'], $this->_user_id, $is_admin);

                if ($st AND in_array($st['status'], ['queued', 'running'], TRUE))
                {
                    $live[(string) $f['code']] = $st;

                    // живой ряд закрывает собой итоговую плашку — не дублируем
                    continue;
                }

                // Постоянный итог последнего сбора (компаний найдено, время) —
                // иначе после доставки страница молчит и «Готово» не видно.
                $ls = Service_Nj606_Leads::last_for((string) $f['code'], $this->_user_id, $is_admin);

                if ($ls AND in_array($ls['status'], ['done', 'failed', 'cancelled'], TRUE))
                {
                    $last[(string) $f['code']] = $ls;
                }
            }
        }

        $this->template->content = View::factory('606/index', [
            'live'   => $live,
            'last'   => $last,
            'flows'  => $this->_user_id ? Service_Nj606_Leads::flows_of($this->_user_id) : [],
            'feed'   => $this->_user_id ? Service_Nj606_Leads::feed($this->_user_id) : [],
            'flash'  => $flash,
            'csrf'   => Security::token(),
            'logged' => $this->_user_id !== NULL,
            'is_admin' => $this->_user_id !== NULL AND ($this->can('nj606.admin') OR Auth::instance()->logged_in('admin')),
        ]);
    }

    /**
     * Действия POST-ом: живой сбор, отметка лида, удаление потока, остановка,
     * профиль компании, создание потока. Завершается JSON-ответом (AJAX) либо
     * редиректом с текстом в `?flash=`.
     */
    protected function _post()
    {
        $act      = (string) $this->request->post('act');
        // Скобки обязательны: AND/OR слабее присваивания. Без них любой вошедший
        // считался администратором, а AJAX-запросы с заголовком X-Requested-With
        // не распознавались (страница вместо JSON).
        $is_admin = ((bool) $this->_current_user AND $this->can('nj606.admin'));
        $csrf_ok  = Security::check((string) $this->request->post('csrf'));
        $ajax     = ((string) $this->request->post('ajax') === '1'
            OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest');

        // AJAX-кнопки страницы: отвечаем JSON, страницу не трогаем
        if ($ajax AND $act === 'run_now')
        {
            $res = $csrf_ok
                ? Service_Nj606_Leads::run_now((string) $this->request->post('code'), $this->_user_id, $is_admin)
                : ['error' => 'Сессия устарела — попробуйте ещё раз'];

            $this->_json([
                'ok'    => ! isset($res['error']),
                'flash' => isset($res['error']) ? $res['error']
                    : 'Сбор запущен — компании появятся в ленте через несколько минут',
            ]);

            return;
        }

        if ($ajax AND $act === 'lead_status')
        {
            $ok = Service_Nj606_Leads::lead_status((int) $this->request->post('id'), $this->_user_id,
                (string) $this->request->post('st'));

            $this->_json(['ok' => (bool) $ok,
                'flash' => $ok ? 'Отметка сохранена' : 'Не получилось']);

            return;
        }

        if ( ! $csrf_ok)
        {
            $msg = 'Сессия устарела — попробуйте ещё раз';
        }
        elseif ($act === 'run_now')
        {
            $res = Service_Nj606_Leads::run_now((string) $this->request->post('code'), $this->_user_id, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error']
                : 'Сбор запущен — компании появятся в ленте через несколько минут';
        }
        elseif ($act === 'lead_status')
        {
            $ok  = Service_Nj606_Leads::lead_status((int) $this->request->post('id'), $this->_user_id,
                (string) $this->request->post('st'));
            $msg = $ok ? 'Отметка сохранена' : 'Не получилось';
        }
        elseif ($act === 'delete')
        {
            Service_Nj606_Leads::delete_flow((string) $this->request->post('code'), $this->_user_id);
            $msg = 'Поток удалён';
        }
        elseif ($act === 'stop_run')
        {
            // остановка идущего/залипшего сбора со страницы (без админки)
            $res = Service_Nj606_Leads::cancel_run((string) $this->request->post('code'), $this->_user_id, $is_admin);
            $msg = isset($res['error']) ? (string) $res['error']
                : 'Сбор остановлен — можно запустить заново кнопкой «Собрать»';
        }
        elseif ($act === 'to_profile')
        {
            $res = Service_Nj606_Leads::lead_to_profile((int) $this->request->post('id'), $this->_user_id);

            if (isset($res['code']))
            {
                HTTP::redirect('/company/'.$res['code'], 302);
            }

            $msg = isset($res['error']) ? (string) $res['error']
                : 'Профиль собирается — воркер прочитает сайт компании';
        }
        else
        {
            $res = Service_Nj606_Leads::create_flow($this->_user_id,
                (string) $this->request->post('title'),
                (string) $this->request->post('industry'),
                (string) $this->request->post('region'));

            $msg = isset($res['error']) ? (string) $res['error']
                : ( ! empty($res['existing'])
                    ? 'Такой поток уже есть — запустил сбор заново'
                    : 'Поток создан — первый сбор уже идёт');
        }

        HTTP::redirect('/leads?flash='.urlencode($msg), 303);
    }

    /** JSON-ответ AJAX-кнопкам (страница не перерисовывается). */
    protected function _json(array $data)
    {
        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
