<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj602 модерация: /nj602/admin — список статей, «Собрать статью сейчас»,
 * публикация/отклонение/удаление (two-click).
 */
class Controller_Nj602_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj602.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Контент-конвейер · модерация');

        Service_Nj602_Content::ensure_tables();
    }

    public function action_index()
    {
        $flash = '';

        // зачистка зависших «сборок» — при каждом открытии админки (см. sweep_stale)
        Service_Nj602_Content::sweep_stale();

        // AJAX-обновление строк таблицы и живого журнала (без перезагрузки)
        if ($this->request->method() === Request::GET AND (string) $this->request->query('rows') === '1')
        {
            // Обновление строк идёт В ТЕХ ЖЕ фильтрах, что открыты у модератора:
            // поиск, статус и страница передаются тем же адресом (?q=&status=&page=).
            $f = $this->_filters();

            $this->auto_render = FALSE;
            $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                ->body(json_encode([
                    'html'   => View::factory('602/admin_rows', [
                        'items' => Service_Nj602_Content::admin_list($f['per'], ($f['page'] - 1) * $f['per'], $f['q'], $f['status']),
                        'csrf'  => Security::token(),
                    ])->render(),
                    'pager'  => View::factory('602/admin_pager', ['f' => $f])->render(),
                    'total'  => $f['total'],
                    'trace'  => View::factory('602/trace', [
                        'trace'  => self::_trace_payload((string) $this->request->query('code')),
                        'csrf'   => Security::token(),
                    ])->render(),
                    'live'   => self::_live_html((string) $this->request->query('code'), Security::token()),
                    'counts' => Service_Nj602_Content::counts(),
                ], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($this->request->method() === Request::POST)
        {
            $act  = (string) $this->request->post('act');
            $id   = (int) $this->request->post('id');

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ($act === 'create')
            {
                $res = Service_Nj602_Content::create_draft((string) $this->request->post('topic'), 'admin');
                $flash = isset($res['error']) ? $res['error']
                    : 'Черновик '.$res['code'].' создан (тема: «'.$res['topic'].'») — воркер собирает статью, ряд ниже.';

                // Редирект сразу на эту статью: живой статус-ряд (эталон
                // «Собираем обзор») виден без лишних кликов.
                if ( ! isset($res['error']) AND ! empty($res['code']))
                {
                    $created_code = (string) $res['code'];
                }
            }
            elseif ($act === 'stop_job')
            {
                $res = Service_Nj602_Content::stop_job((string) $this->request->post('code'));
                $flash = isset($res['error']) ? $res['error'] : 'Сбор остановлен — статью можно запустить заново';
                $stop_code = (string) $this->request->post('code');
            }
            elseif ($act === 'settings')
            {
                $topics = (array) $this->request->post('topics');
                $custom = preg_split('~\r?\n~', (string) $this->request->post('custom')) ?: [];
                $s = Service_Nj602_Content::save_settings(
                    $topics, $custom, (string) $this->request->post('enabled') === '1');

                $flash = 'Настройки сохранены: конвейер '.($s['enabled'] ? 'работает' : 'ВЫКЛЮЧЕН')
                    .' · активных тем из каталога: '.count($s['topics']).' · своих: '.count($s['custom']);
            }
            elseif ($act === 'publish' AND $id > 0)
            {
                Service_Nj602_Content::publish($id);
                $flash = 'Статья опубликована';
            }
            elseif ($act === 'reject' AND $id > 0)
            {
                Service_Nj602_Content::reject($id);
                $flash = 'Статья отклонена';
            }
            elseif ($act === 'retry' AND $id > 0)
            {
                // nj602_admin: недоделанная (error/зависшая) статья — заново в сбор
                $res = Service_Nj602_Content::retry($id);
                $flash = isset($res['error']) ? $res['error'] : 'Сбор запущен заново — статус обновится здесь же';
            }
            elseif ($act === 'publish_bulk')
            {
                $res   = Service_Nj602_Content::publish_many((array) $this->request->post('ids'));
                $flash = isset($res['error']) ? $res['error'] : 'Опубликовано статей: '.$res['count'];
            }
            elseif ($act === 'reject_bulk')
            {
                $res   = Service_Nj602_Content::reject_many((array) $this->request->post('ids'));
                $flash = isset($res['error']) ? $res['error'] : 'Отклонено статей: '.$res['count'];
            }
            elseif ($act === 'repair_mermaids')
            {
                // Пакетная починка схем (нормализация без ИИ): «Починить схемы» в
                // блоке «Модерация» или «Починить у выбранных» в массовых действиях.
                $res   = Service_Nj602_Content::repair_mermaids((array) $this->request->post('ids'));
                $flash = isset($res['error']) ? $res['error'] : (string) ($res['flash'] ?? 'Схемы чинятся');
            }
            elseif ($act === 'save_manual' AND $id > 0)
            {
                $res = Service_Nj602_Content::save_manual($id, [
                    'title'    => (string) $this->request->post('title'),
                    'summary'  => (string) $this->request->post('summary'),
                    'body_md'  => (string) $this->request->post('body_md'),
                    'mermaid'  => (string) $this->request->post('mermaid'),
                ]);

                $flash = isset($res['error']) ? $res['error'] : 'Статья сохранена';

                if ( ! isset($res['error']))
                {
                    $saved_id = $id;
                }
            }
            elseif ($act === 'delete' AND $id > 0)
            {
                Service_Nj602_Content::remove($id);
                $flash = 'Статья удалена';
            }
            else
            {
                $flash = 'Неизвестное действие';
            }

            // Flash — через сессию (get_once), а не через GET-строку: ссылка
            // с сообщением в URL висит в истории браузера и чужими
            // префетчерами дёргается как GET (плюс выглядит страшно).
            // AJAX-ветка (publish/reject/retry/delete из таблицы): JSON вместо
            // перезагрузки — таблица большая, строки обновим на месте.
            if ($this->request->post('ajax') === '1'
                OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest')
            {
                $this->auto_render = FALSE;
                $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                    ->body(json_encode([
                        'ok'    => ! preg_match('~^(Сессия|Неизвестное|Не получилось)~u', $flash),
                        'flash' => $flash,
                    ], JSON_UNESCAPED_UNICODE));

                return;
            }

            Session::instance()->set('nj602_flash', $flash);

            // Вернуть пользователя на «его» статью: после запуска — к живому
            // ряду сбора, после остановки — к той же карточке.
            if (isset($saved_id))
            {
                Session::instance()->set('nj602_flash', $flash);
                HTTP::redirect('/nj602/admin/edit/'.(int) $saved_id, 303);
            }

            // Действие пришло со страницы ручной правки — вернуть туда же,
            // а не в общий список (модератор правит статью и не теряет контекст).
            if ((string) $this->request->post('back') === 'edit' AND $id > 0)
            {
                Session::instance()->set('nj602_flash', $flash);
                HTTP::redirect('/nj602/admin/edit/'.(int) $id, 303);
            }

            $back = isset($created_code) ? $created_code : (isset($stop_code) ? $stop_code : '');

            HTTP::redirect('/nj602/admin'.($back !== '' ? '?code='.urlencode($back) : ''), 303);
        }

        $s = Service_Nj602_Content::settings();
        $code = self::_code_in_focus((string) $this->request->query('code'));
        $csrf = Security::token();
        $f    = $this->_filters();

        $this->template->content = View::factory('602/admin', [
            'items'       => Service_Nj602_Content::admin_list($f['per'], ($f['page'] - 1) * $f['per'], $f['q'], $f['status']),
            'f'           => $f,
            'counts'      => Service_Nj602_Content::counts(),
            'trace'       => self::_trace_payload($code),
            'live_html'   => self::_live_html($code, $csrf, TRUE),
            'live_code'   => $code,
            'flash'       => (string) Session::instance()->get_once('nj602_flash', ''),
            'csrf'        => $csrf,
            'settings'    => $s,
            'predefined'  => Service_Nj602_Content::predefined_topics(),
            // Сколько схем модерация не увидит (mermaid их не рисует) — по этой
            // цифре работает кнопка «Починить схемы» (оценка считается в SQL,
            // чтобы страница не тянула тексты всех схем).
            'broken_schemas' => Service_Nj602_Content::mermaid_broken_count(),
            'fix_status'     => Service_Nj602_Content::mermaid_fix_status(),
            'next_topic'  => $s['enabled'] ? Service_Nj602_Content::pick_topic() : '',
            // Бюджет токенов служебного ключа конвейера: отказ очереди по
            // бюджету раньше был виден только в логе сервера.
            'budget'      => class_exists('Service_Nj120_Budget') ? Service_Nj120_Budget::state('s:nj602') : [],
        ]);
    }

    /**
     * GET /nj602/admin/mermaid_fix_status — состояние пакетной починки схем.
     *
     * Контракт статус-ряда как у остальных модулей: status/stage/elapsed_sec/
     * error + счётчики fixed/skipped/total из result задачи.
     */
    public function action_mermaid_fix_status()
    {
        $this->auto_render = FALSE;

        $this->response->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode(Service_Nj602_Content::mermaid_fix_status(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * GET /nj602/admin/status/<code> — тонкий JSON для живого статус-ряда.
     * Контракт общий для всех модулей (см. Service_Nj120_JobQueue::live_status):
     * status/stage/stages/elapsed_sec/silent_sec/error. Действует как «сайтовый
     * сторож»: молчащий воркер гасится прямо здесь, при поллинге страницы.
     */
    public function action_status()
    {
        $code = (string) $this->request->param('id');

        // Статья «собирается», а задача уже закрыта — честный финал, а не вечное
        // «собирается» (иначе конвейер отказывает в новых статьях).
        if ($code !== '')
        {
            Service_Nj602_Content::reconcile_article($code);
        }

        $live = Service_Nj602_Content::live_of($code);

        // Авто-остановка застрявшего воркера — здесь, потому что страницу
        // опрашивает именно этот endpoint (три правила из одного места):
        //   1) пульса нет дольше JobQueue::silent_stop_sec() (~4 мин) — воркер
        //      мёртв или заморожен;
        //   2) пульс есть, но прогресс не обновлялся дольше порога
        //      (auto_stop_stalled) — «живой, но стоит на месте»;
        //   3) ряд обязан завершиться сам: ниже подставляется последняя задача.
        if (($live['status'] ?? '') === 'running' AND class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $queue   = Service_Nj120_JobQueue::instance();
                $stopped = FALSE;

                if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
                {
                    $stopped = $queue->auto_stop_silent((int) $live['job_id']) > 0;
                }

                if ( ! $stopped AND $queue->auto_stop_stalled((int) $live['job_id']) > 0)
                {
                    $stopped = TRUE;
                }

                if ($stopped)
                {
                    // Статья обязана получить финал: иначе она вечно «собирается».
                    Service_Nj602_Content::reconcile_article($code);
                    $live = Service_Nj602_Content::live_of($code);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj602: авто-стоп застрявшего сбора: :e', [':e' => $e->getMessage()]);
            }
        }

        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode([
            'ok'          => TRUE,
            'code'        => (string) $live['code'],
            'status'      => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => array_map(function ($s) { return ['message' => (string) ($s['message'] ?? '')]; }, (array) $live['stages']),
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'error'       => (string) $live['error'],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Фильтры списка статей: поиск (q), статус, страница и размер страницы.
     * Размер запоминается браузером (n2j:per:/nj602/admin), как в /nj593/admin.
     */
    protected function _filters()
    {
        $q      = trim((string) $this->request->query('q'));
        $status = trim((string) $this->request->query('status'));
        $page   = max(1, (int) $this->request->query('page'));
        $per    = (int) $this->request->query('per');

        if ( ! in_array($per, [20, 50, 100], TRUE))
        {
            $per = 20;
        }

        $total = Service_Nj602_Content::admin_count($q, $status);
        $pages = max(1, (int) ceil($total / $per));

        if ($page > $pages)
        {
            $page = $pages;
        }

        return [
            'q'      => $q,
            'status' => $status,
            'page'   => $page,
            'per'    => $per,
            'total'  => $total,
            'pages'  => $pages,
        ];
    }

    /**
     * GET /nj602/admin/edit/<id> — ручная правка статьи: заголовок, лид, тело
     * и mermaid-схема (с превью) плюс быстрые действия модерации.
     */
    public function action_edit()
    {
        $id = (int) $this->request->param('id');
        $a  = Service_Nj602_Content::article_payload_by_id($id);

        if ( ! $a)
        {
            throw HTTP_Exception::factory(404, 'Статья не найдена');
        }

        $this->set_title('Правка статьи · '.mb_substr((string) $a['title'], 0, 60));

        $this->template->content = View::factory('602/edit', [
            'article' => $a,
            'flash'   => (string) Session::instance()->get_once('nj602_flash', ''),
            'csrf'    => Security::token(),
            'live'    => Service_Nj602_Content::mermaid_job_status((string) $a['code']),
            'is_admin' => TRUE,
        ]);
    }

    /**
     * POST /nj602/admin/regen_mermaid — «Перегенерировать схему» из блока
     * «Модерация» на странице статьи. Воркер получает ту же задачу конвейера
     * в режиме mode=mermaid: текст и публикация не трогаются, меняется схема.
     */
    public function action_regen_mermaid()
    {
        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');

        if ($this->request->method() !== Request::POST
            OR ! Security::check((string) $this->request->post('csrf')))
        {
            $this->response->body(json_encode(
                ['ok' => FALSE, 'flash' => 'Сессия устарела — обновите страницу и попробуйте снова'],
                JSON_UNESCAPED_UNICODE));

            return;
        }

        $res = Service_Nj602_Content::regen_mermaid((int) $this->request->post('id'));
        $ok  = ! isset($res['error']);

        $this->response->body(json_encode([
            'ok'    => $ok,
            'flash' => $ok ? (string) ($res['flash'] ?? 'Схема перегенерируется') : (string) $res['error'],
            'code'  => (string) ($res['code'] ?? ''),
            'job'   => (int) ($res['job'] ?? 0),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * GET /nj602/admin/mermaid_status/<code> — состояние перегенерации схемы
     * (контракт статус-ряда /trends: status/stage/elapsed_sec/error).
     */
    public function action_mermaid_status()
    {
        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');

        $live = Service_Nj602_Content::mermaid_job_status((string) $this->request->param('id'));

        $this->response->body(json_encode([
            'ok'          => TRUE,
            'status'      => (string) ($live['status'] ?? 'none'),
            'stage'       => (string) ($live['stage'] ?? ''),
            'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
            'error'       => (string) ($live['error'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Код статьи, вокруг которой строится страница: явный ?code= (он же после
     * запуска/остановки), иначе — самая свежая статья в статусе «собирается».
     */
    protected static function _code_in_focus($code)
    {
        $code = trim((string) $code);

        if ($code !== '' AND Service_Nj602_Content::by_code($code) !== NULL)
        {
            return $code;
        }

        foreach (Service_Nj602_Content::admin_list(20) as $a)
        {
            if ((string) $a['status'] === 'collecting')
            {
                return (string) $a['code'];
            }
        }

        return $code;
    }

    /** Готовый HTML живого ряда (фрагмент 602/live) — для страницы и AJAX. */
    protected static function _live_html($code, $csrf, $with_template = FALSE)
    {
        $code = trim((string) $code);

        if ($code !== '')
        {
            Service_Nj602_Content::reconcile_article($code);
        }

        return View::factory('602/live', [
            'live'          => Service_Nj602_Content::live_of($code),
            'csrf'          => (string) $csrf,
            'with_template' => (bool) $with_template,
        ])->render();
    }

    /**
     * Живой журнал прогона строкой кода (для подстановки шаблоном 602/trace).
     * Если article ещё не видна в списке (мгновенный клик после запуска) —
     * покажем пустой блок с подсказкой; блок подхватится AJAX-обновлением.
     */
    protected static function _trace_payload($code)
    {
        $code = (string) $code;

        if ($code === '' OR Service_Nj602_Content::by_code($code) === NULL)
        {
            return ['code' => '', 'items' => []];
        }

        return ['code' => $code, 'items' => Service_Nj602_Content::progress_of($code)];
    }
}
