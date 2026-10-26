<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj635: «Проверка компании» /check — единый интерфейс по одной строке ввода.
 *
 * Одна страница делает всю работу:
 *   1. карточка сразу (реквизиты, вакансии, сигналы) — без ожидания и без ИИ;
 *   2. кнопка «Собрать полный отчёт» (POST act=run) запускает остальное у
 *      владельцев данных (профиль сайта nj591, AI-досье nj598, наблюдение
 *      nj609) и приводит на эту же страницу с живым отчётом;
 *   3. готовые блоки показываются на месте, страница опрашивает
 *      /check/api/status и подменяет только изменившиеся шаги.
 *
 * Гостю доступны карточка и AI-досье (nj598 умеет анонимов по куке); профиль
 * сайта и наблюдение — после входа: их данные привязаны к аккаунту.
 */
class Controller_Nj635_Check extends Controller_Erp100_Base {

    protected $_user_id = NULL;
    protected $_uid     = '';
    protected $_ubid    = NULL;

    public function before()
    {
        parent::before();

        // Опрос состояния отчёта идёт каждые 4 секунды — меню и мета ему не нужны:
        // сборка меню трогает каталог модулей, и делать это на каждый опрос дорого.
        $is_api = ($this->request->action() === 'api_status');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
        $this->_uid     = Visitor::uid();
        $this->_ubid    = Visitor::ubid();

        if ($is_api)
        {
            return;
        }

        $this->set_title('Проверка компании и работодателя — реквизиты, вакансии, досье');
        $this->set_description('Введите название, ИНН/ОГРН, сайт или ссылку на вакансию — получите карточку сразу, а по кнопке — полный отчёт: профиль сайта, AI-досье и наблюдение за рисками на одной странице.');

        $this->set_module_nav([]);
    }

    /** GET /check — карточка и отчёт; POST — старт полного отчёта. */
    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            $this->_start();

            return;
        }

        $q   = trim((string) $this->request->query('q'));
        $ref = Service_Nj635_Report::ref((array) $_GET);

        // после входа с /auth/login?return_url=check?run=1… — запускаем сразу
        if ((string) $this->request->query('run') === '1' AND $this->_user_id !== NULL
            AND $q !== '' AND ! Service_Nj635_Report::has_ref($ref))
        {
            $res = Service_Nj635_Report::start($this->_user_id, $this->_uid, $q);

            if (isset($res['error']))
            {
                $this->redirect('/check?q='.rawurlencode($q).'&flash='.urlencode((string) $res['error']));

                return;
            }

            $this->redirect('/check?'.Service_Nj635_Report::query($res), 303);

            return;
        }

        $card = ($q !== '') ? Service_Nj635_Card::build($q, ['online' => TRUE]) : NULL;

        $this->template->content = View::factory('635/check', [
            'q'         => $q,
            'card'      => $card,
            'ref'       => $ref,
            'report'    => Service_Nj635_Report::has_ref($ref)
                ? $this->_report($ref)
                : NULL,
            'csrf'      => Security::token(),
            'logged'    => $this->_user_id !== NULL,
            'flash'     => trim((string) $this->request->query('flash')),
        ]);
    }

    /** GET /check/api/status — состояние шагов отчёта (JSON + готовые блоки). */
    public function action_api_status()
    {
        $ref = Service_Nj635_Report::ref((array) $_GET);

        // JSON отдаём как есть: шаблон страницы тут не нужен
        $this->auto_render = FALSE;

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers('Cache-Control', 'private, no-store');

        if ( ! Service_Nj635_Report::has_ref($ref))
        {
            $this->response->body(json_encode(['ok' => FALSE, 'error' => 'Нет ссылок отчёта'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        try
        {
            $state = Service_Nj635_Report::steps_with_html($ref, $this->_ctx());
            $state['ok'] = TRUE;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj635: статус отчёта: :e', [':e' => $e->getMessage()]);

            $this->response->status(500);
            $this->response->body(json_encode(['ok' => FALSE, 'error' => 'Не удалось получить состояние отчёта'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        $this->response->body(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    // -------------------------------------------------------------------------

    /** Сборка отчёта для страницы: блоки + ссылки для опроса. */
    protected function _report(array $ref)
    {
        $state  = Service_Nj635_Report::state($ref, $this->_ctx());
        $blocks = [];

        foreach ($state['steps'] as $key => $step)
        {
            $blocks[] = [
                'key'    => $key,
                'status' => (string) $step['status'],
                'html'   => Service_Nj635_Report::block($key, $step, $ref),
            ];
        }

        $query = trim((string) ($ref['query'] !== '' ? $ref['query'] : ($state['query'] ?? '')));

        return [
            'blocks'  => $blocks,
            'letters' => TRUE,   // подсказка про письмо: собирается отдельно
            'ref_q'   => Service_Nj635_Report::query($ref),
            'query'   => $query,
            'ref'     => $ref,
            'done'    => (bool) $state['done'],
        ];
    }

    /** Контекст доступа: кто смотрит страницу. */
    protected function _ctx()
    {
        return [
            'user_id' => $this->_user_id,
            'uid'     => (string) $this->_uid,
            'ubid'    => $this->_ubid,
        ];
    }

    /**
     * POST /check — старт. `act=run` (или старый `act=dossier`) — полный отчёт;
     * `act=card` — просто карточка. Действия через PRG: работа запускается ровно
     * один раз, а не при каждом обновлении страницы.
     */
    protected function _start()
    {
        $q   = trim((string) $this->request->post('q'));
        $act = (string) $this->request->post('act');

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            $this->redirect('/check?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        if ($q === '')
        {
            $this->redirect('/check?flash='.urlencode('Укажите компанию, ИНН/ОГРН, сайт или ссылку на вакансию'));

            return;
        }

        if ($act === 'card')
        {
            $this->redirect('/check?q='.rawurlencode($q));

            return;
        }

        $res = Service_Nj635_Report::start($this->_user_id, $this->_uid, $q);

        if (isset($res['error']))
        {
            $this->redirect('/check?q='.rawurlencode($q).'&flash='.urlencode((string) $res['error']));

            return;
        }

        $flash = ! empty($res['exists'])
            ? 'По этой компании отчёт уже собран — открыли его. Нужен свежий — запустите снова.'
            : '';

        $this->redirect('/check?'.Service_Nj635_Report::query($res)
            .($flash !== '' ? '&flash='.urlencode($flash) : ''), 303);
    }
}
