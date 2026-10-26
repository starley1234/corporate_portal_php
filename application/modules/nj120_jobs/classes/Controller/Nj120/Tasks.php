<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Мои задачи» (NJ-120): единый список задач воркеров текущего посетителя
 * из всех модулей. Идентификация — Visitor::uid() (логин или кука ubid),
 * поэтому работает и для анонимов.
 */
class Controller_Nj120_Tasks extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Мои задачи — N2J.ru');
        $this->set_description('Статус ваших фоновых задач: ответы на отзывы, поиски вакансий и другие операции ИИ-воркеров N2J.ru.');

    }

    public function action_index()
    {
        // /tasks/<code> — страница задачи по коду артефакта (из уведомлений)
        $code = (string) $this->request->param('code');

        if ($code !== '')
        {
            $this->_view_task($code);

            return;
        }

        // пока владелец смотрит «Мои задачи» — дотащим уведомления о свежих
        // завершённых задачах (pg-воркеры пишут в БД мимо PHP-хука)
        if (class_exists('Service_Nj597_Notify'))
        {
            try
            {
                Service_Nj597_Notify::sweep_jobs(20);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj120 tasks: sweep: :e', [':e' => $e->getMessage()]);
            }
        }

        $uid      = Visitor::uid();
        $channels = Manifest::channels_ui();
        $db       = Database::instance();
        $limit    = 30;

        // Этап работы — из журнала событий (пишет ctx.progress() воркера):
        // строка «выполняется» без этапа выглядит зависшей, владелец жаловался
        // «нет готово». LATERAL берёт последнее событие 'progress' на задачу.
        $rows = $db->query(Database::SELECT,
            'SELECT j.id, j.channel, j.status, j.payload, j.error, j.created_at, j.finished_at,
                    ev.message AS stage, ev.created_at AS stage_at
             FROM nj_120_jobs j
             LEFT JOIN LATERAL (
                 SELECT message, created_at FROM nj_120_job_events e
                 WHERE e.job_id = j.id AND e.event = \'progress\'
                   AND e.message IS NOT NULL AND e.message <> \'\'
                 ORDER BY e.id DESC LIMIT 1
             ) ev ON TRUE
             WHERE j.visitor_id = '.$db->quote((string) $uid).'
             ORDER BY j.id DESC LIMIT '.$limit, FALSE)->as_array();

        $tasks = [];

        foreach ($rows as $row)
        {
            $row     = (array) $row;
            $payload = [];
            is_string($row['payload']) AND $payload = (array) json_decode($row['payload'], TRUE);

            $ui    = isset($channels[$row['channel']]) ? (array) $channels[$row['channel']] : [];
            $label = ! empty($ui['label']) ? $ui['label'] : $row['channel'];
            $url   = NULL;

            if ( ! empty($ui['url']) AND ! empty($payload['code']))
            {
                $url = str_replace('<code>', urlencode((string) $payload['code']), (string) $ui['url']);
            }

            $tasks[] = [
                'id'         => (int) $row['id'],
                'label'      => $label,
                'title'      => (string) ($payload['direction'] ?? ($payload['review'] ?? '')),
                'status'     => (string) $row['status'],
                'url'        => $url,
                'error'      => (string) $row['error'],
                'stage'      => (string) ($row['stage'] ?? ''),
                'stage_at'   => $row['stage_at'] ?? NULL,
                'created_at' => $row['created_at'],
                'finished_at'=> $row['finished_at'],
            ];
        }

        $budget = [];

        try
        {
            $budget = Service_Nj120_Budget::state((string) $uid);
        }
        catch (Throwable $e)
        {
            $budget = [];
        }

        $this->template->content = View::factory('120/tasks', [
            'tasks'       => $tasks,
            'budget'      => $budget,
            'logged_in'   => Auth::instance()->logged_in(),
            'notify_url'  => array_key_exists('nj597_notifications', Kohana::modules()) ? '/nj597?tab=settings' : NULL,
        ]);
    }

    /**
     * /tasks/<code> — страница задачи (задач) по коду артефакта из payload.
     * Показываем только задачи текущего посетителя (Visitor::uid), поэтому
     * чужие ссылки не раскрывают чужие данные. Служит посадкой для ссылок
     * из уведомлений nj597 (раньше вели в 404, если у канала нет своей
     * страницы <base>/<code>).
     */
    protected function _view_task(string $code)
    {
        $uid = (string) Visitor::uid();
        $db  = Database::instance();

        $channels = Manifest::channels_ui();

        // числовой код — возможно, ссылка на конкретную задачу по id
        $by_id = ctype_digit($code) ? ' OR id = '.(int) $code : '';

        $rows = $db->query(Database::SELECT,
            'SELECT id, channel, status, payload, error, result,
                    attempts, max_attempts, timeout_sec,
                    created_at, started_at, finished_at
             FROM nj_120_jobs
             WHERE visitor_id = '.$db->quote($uid).'
               AND (payload::jsonb ->> '.$db->quote('code').' = '.$db->quote($code).$by_id.')
             ORDER BY id DESC LIMIT 12', FALSE)->as_array();

        $tasks      = [];
        $latest     = NULL;
        $result_url = NULL;

        foreach ($rows as $row)
        {
            $row     = (array) $row;
            $payload = [];
            is_string($row['payload']) AND $payload = (array) json_decode($row['payload'], TRUE);
            $result = [];
            is_string($row['result']) AND $result = (array) json_decode($row['result'], TRUE);

            $ui    = isset($channels[$row['channel']]) ? (array) $channels[$row['channel']] : [];
            $label = ! empty($ui['label']) ? (string) $ui['label'] : (string) $row['channel'];

            if ($latest === NULL)
            {
                $latest = [
                    'id'       => (int) $row['id'],
                    'channel'  => (string) $row['channel'],
                    'label'    => $label,
                    'status'   => (string) $row['status'],
                    'error'    => (string) $row['error'],
                    'attempts' => (int) $row['attempts'],
                    'max'      => (int) $row['max_attempts'],
                    'timeout'  => (int) $row['timeout_sec'],
                    'created'  => (string) $row['created_at'],
                    'started'  => (string) $row['started_at'],
                    'finished' => (string) $row['finished_at'],
                    'payload'  => $payload,
                    'result'   => $result,
                ];

                // ссылка на результат — по карте каналов
                if ( ! empty($ui['url']) AND isset($payload['code']))
                {
                    $result_url = str_replace('<code>', rawurlencode((string) $payload['code']), (string) $ui['url']);
                }
            }

            $tasks[] = [
                'id'       => (int) $row['id'],
                'label'    => $label,
                'status'   => (string) $row['status'],
                'error'    => (string) $row['error'],
                'created'  => (string) $row['created_at'],
                'finished' => (string) $row['finished_at'],
            ];
        }

        $this->set_title('Задача '.$code.' — N2J.ru');

        // Своя вьюха для «списка задач по коду»: 120/task.php — экран ОДНОЙ
        // задачи (job/job_id/label/events), и подстановка её сюда давала живой
        // фатал «Undefined variable $job_id» (/tasks/cad5e0ff1e1, 27.09.2026).
        $this->template->content = View::factory('120/tasks_code', [
            'code'       => $code,
            'latest'     => $latest,
            'tasks'      => $tasks,
            'result_url' => $result_url,
        ]);
    }
}
