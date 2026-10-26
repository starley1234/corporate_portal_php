<?php defined('SYSPATH') or die('No direct script access.');

/**
 * LLM-агенты (модуль NJ-130).
 *
 * Архитектура исполнения (stateless-воркер):
 *   1. start_run(): создаёт run + сообщение user + задачу `agent.run` в очереди.
 *      В payload задачи кладётся ВСЁ нужное воркеру: конфиг агента и история
 *      сообщений — воркер не ходит в БД агентов и работает в любом режиме
 *      (pg/http), в том числе на отдельном сервере.
 *   2. Воркер вызывает LLM и возвращает ответ в result задачи.
 *   3. sync_run(): вызывается при чтении статуса (GET /nj130/api/run/<id>)
 *      — применяет результат задачи: дописывает сообщение assistant и
 *      закрывает run. Никаких внешних колбэков и секретов не требуется.
 *
 * Другие модули НЕ работают с nj_130_* напрямую — только через этот сервис.
 */
class Service_Nj130_Agents {

    // ---------------------------------------------------------------------
    // Определения агентов
    // ---------------------------------------------------------------------

    /**
     * Создать/обновить агента (upsert по slug).
     *
     * @param  string $slug   'support', 'doc-review', ...
     * @param  array  $fields name|system_prompt|model|temperature|max_tokens|config|enabled
     * @return int id агента
     */
    public static function define($slug, array $fields = [])
    {
        $db = Database::instance();
        $slug = (string) $slug;

        $name          = isset($fields['name'])          ? (string) $fields['name']          : $slug;
        $system_prompt = isset($fields['system_prompt']) ? (string) $fields['system_prompt'] : '';
        $model         = isset($fields['model'])         ? (string) $fields['model']         : '';
        $temperature   = isset($fields['temperature'])   ? (float) $fields['temperature']    : 0.2;
        $max_tokens    = isset($fields['max_tokens'])    ? (int) $fields['max_tokens']       : 0;
        $config        = json_encode(isset($fields['config']) ? $fields['config'] : [], JSON_UNESCAPED_UNICODE);
        // Скобки обязательны: OR слабее присваивания — без них агент включался
        // только когда поля enabled в запросе нет, а «включить» не работало.
        $enabled       = ( ! isset($fields['enabled']) OR (bool) $fields['enabled']);

        $result = $db->query(Database::SELECT,
            "INSERT INTO nj_130_agents
                (slug, name, system_prompt, model, temperature, max_tokens, config, enabled)
             VALUES (".$db->quote($slug).", ".$db->quote($name).", ".$db->quote($system_prompt).",
                     ".$db->quote($model).", ".$db->quote($temperature).", ".($max_tokens > 0 ? $max_tokens : 'NULL').",
                     ".$db->quote($config).", ".($enabled ? 'TRUE' : 'FALSE').")
             ON CONFLICT (slug) DO UPDATE SET
                name = EXCLUDED.name,
                system_prompt = EXCLUDED.system_prompt,
                model = EXCLUDED.model,
                temperature = EXCLUDED.temperature,
                max_tokens = EXCLUDED.max_tokens,
                config = EXCLUDED.config,
                enabled = EXCLUDED.enabled,
                updated_at = now()
             RETURNING id", FALSE);

        return (int) $result->current()['id'];
    }

    /**
     * Агент по slug или NULL.
     */
    public static function get($slug)
    {
        $db = Database::instance();
        $row = $db->query(Database::SELECT,
            "SELECT * FROM nj_130_agents WHERE slug = ".$db->quote((string) $slug), FALSE)->current();

        return $row ? self::_normalize((array) $row) : NULL;
    }

    /**
     * Все агенты.
     */
    public static function list_agents()
    {
        $db = Database::instance();
        $result = $db->query(Database::SELECT,
            "SELECT id, slug, name, model, temperature, enabled, updated_at
             FROM nj_130_agents ORDER BY slug", FALSE);

        $rows = [];
        foreach ($result as $row)
        {
            $rows[] = self::_normalize((array) $row);
        }
        return $rows;
    }

    // ---------------------------------------------------------------------
    // Запуски
    // ---------------------------------------------------------------------

    /**
     * Запустить агента: создаёт run, сообщение user и задачу в очереди.
     *
     * @param  string $slug    slug агента
     * @param  mixed  $input   ['message' => '...'] или произвольные данные (будут в jsonb)
     * @param  int    $user_id автор запуска
     * @param  array  $opts    опции задачи очереди (priority, callback_url...)
     * @return int run_id
     */
    public static function start_run($slug, $input, $user_id = NULL, array $opts = [])
    {
        $agent = self::get($slug);

        if ($agent === NULL)
        {
            throw new Kohana_Exception('Агент ":slug" не найден', [':slug' => $slug]);
        }
        if (empty($agent['enabled']))
        {
            throw new Kohana_Exception('Агент ":slug" отключён', [':slug' => $slug]);
        }

        $db = Database::instance();

        $run_id = (int) $db->query(Database::SELECT,
            "INSERT INTO nj_130_agent_runs (agent_id, user_id, status, input)
             VALUES (".(int) $agent['id'].", ".($user_id ? (int) $user_id : 'NULL').", 'pending',
                     ".$db->quote(json_encode($input, JSON_UNESCAPED_UNICODE)).")
             RETURNING id", FALSE)->current()['id'];

        // Скобки обязательны: AND слабее тернарника — без них в историю прогона
        // уезжало bool («1») вместо текста сообщения пользователя.
        $message = (is_array($input) AND isset($input['message']))
            ? (string) $input['message']
            : json_encode($input, JSON_UNESCAPED_UNICODE);

        self::_append_message($run_id, 'user', $message);

        $job_id = Service_Nj120_JobQueue::push('agent.run', self::_job_payload($run_id),
            ['module' => 'nj130'] + $opts);

        $db->query(Database::UPDATE,
            "UPDATE nj_130_agent_runs SET job_id = ".(int) $job_id." WHERE id = ".(int) $run_id, FALSE);

        return $run_id;
    }

    /**
     * Продолжить диалог: новое сообщение пользователя и новый запуск.
     */
    public static function continue_run($run_id, $message, array $opts = [])
    {
        $run = self::run_row($run_id);

        if ($run === NULL)
        {
            throw new Kohana_Exception('Запуск :id не найден', [':id' => $run_id]);
        }
        if ($run['status'] === 'pending')
        {
            throw new Kohana_Exception('Запуск :id ещё выполняется', [':id' => $run_id]);
        }

        $db = Database::instance();
        self::_append_message((int) $run_id, 'user', (string) $message);

        $job_id = Service_Nj120_JobQueue::push('agent.run', self::_job_payload((int) $run_id),
            ['module' => 'nj130'] + $opts);

        $db->query(Database::UPDATE,
            "UPDATE nj_130_agent_runs SET status = 'pending', error = NULL, job_id = ".(int) $job_id.",
                    finished_at = NULL
             WHERE id = ".(int) $run_id, FALSE);

        return (int) $run_id;
    }

    /**
     * Применить результат задачи очереди к запуску (вызывается при чтении статуса).
     *
     * @return array свежая строка run
     */
    public static function sync_run($run_id)
    {
        $run = self::run_row($run_id);

        if ($run === NULL OR $run['status'] !== 'pending' OR empty($run['job_id']))
        {
            return $run;
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $run['job_id']);

        if ($job === NULL)
        {
            return $run;
        }

        $db = Database::instance();

        if ($job['status'] === 'done')
        {
            $result = is_array($job['result']) ? $job['result'] : [];
            $reply  = isset($result['reply']) ? trim((string) $result['reply']) : '';

            if ($reply !== '')
            {
                self::_append_message((int) $run_id, 'assistant', $reply);
            }

            $db->query(Database::UPDATE,
                "UPDATE nj_130_agent_runs SET
                    status = 'done',
                    result = ".$db->quote(json_encode($result, JSON_UNESCAPED_UNICODE)).",
                    model_used = ".$db->quote(isset($result['model']) ? (string) $result['model'] : '').",
                    finished_at = now()
                 WHERE id = ".(int) $run_id, FALSE);
        }
        elseif (in_array($job['status'], ['failed', 'cancelled'], TRUE))
        {
            // неудачная попытка задачи ещё может уйти в retry — статус pending
            // оставляем, если у задачи остались попытки; иначе фиксируем провал.
            // Скобки обязательны: AND слабее присваивания. Без них $will_retry
            // означало «статус failed» и карточка считала, что попытки ещё есть.
            $will_retry = ($job['status'] === 'failed'
                AND isset($job['attempts'], $job['max_attempts'])
                AND (int) $job['attempts'] < (int) $job['max_attempts']);

            if ( ! $will_retry)
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_130_agent_runs SET
                        status = 'failed',
                        error = ".$db->quote((string) (isset($job['error']) ? $job['error'] : 'задача отменена')).",
                        finished_at = now()
                     WHERE id = ".(int) $run_id, FALSE);
            }
        }

        return self::run_row($run_id);
    }

    /**
     * Строка запуска или NULL.
     */
    public static function run_row($run_id)
    {
        $db = Database::instance();
        $row = $db->query(Database::SELECT,
            "SELECT * FROM nj_130_agent_runs WHERE id = ".(int) $run_id, FALSE)->current();

        return $row ? self::_normalize((array) $row) : NULL;
    }

    /**
     * Диалог запуска.
     */
    public static function transcript($run_id)
    {
        $db = Database::instance();
        $result = $db->query(Database::SELECT,
            "SELECT role, content, created_at FROM nj_130_agent_messages
             WHERE run_id = ".(int) $run_id." ORDER BY id", FALSE);

        $rows = [];
        foreach ($result as $row)
        {
            $rows[] = (array) $row;
        }
        return $rows;
    }

    // ---------------------------------------------------------------------
    // Внутреннее
    // ---------------------------------------------------------------------

    /**
     * Payload для воркера: агент + история сообщений (stateless-задача).
     */
    protected static function _job_payload($run_id)
    {
        $run = self::run_row($run_id);

        if ($run === NULL)
        {
            throw new Kohana_Exception('Запуск :id не найден для постановки в очередь', [':id' => $run_id]);
        }

        $db = Database::instance();
        $agent_row = $db->query(Database::SELECT,
            "SELECT slug, name, system_prompt, model, temperature, max_tokens, config
             FROM nj_130_agents WHERE id = ".(int) $run['agent_id'], FALSE)->current();

        if ($agent_row === NULL)
        {
            throw new Kohana_Exception('Агент запуска :id не найден', [':id' => $run_id]);
        }

        $agent_row = (array) $agent_row;

        return [
            'run_id'   => (int) $run_id,
            'agent'    => [
                'slug'        => (string) $agent_row['slug'],
                'system'      => (string) $agent_row['system_prompt'],
                'model'       => (string) $agent_row['model'],
                'temperature' => $agent_row['temperature'] !== NULL ? (float) $agent_row['temperature'] : NULL,
                'max_tokens'  => $agent_row['max_tokens'] !== NULL ? (int) $agent_row['max_tokens'] : NULL,
                'config'      => is_string($agent_row['config']) ? json_decode($agent_row['config'], TRUE) : [],
            ],
            'messages' => self::transcript($run_id),
        ];
    }

    protected static function _append_message($run_id, $role, $content)
    {
        $db = Database::instance();
        $db->query(Database::INSERT,
            "INSERT INTO nj_130_agent_messages (run_id, role, content)
             VALUES (".(int) $run_id.", ".$db->quote((string) $role).", ".$db->quote((string) $content).")", FALSE);
    }

    /**
     * jsonb-поля из строк в массивы.
     */
    protected static function _normalize(array $row)
    {
        foreach (['input', 'result', 'config'] as $col)
        {
            if (isset($row[$col]) AND is_string($row[$col]))
            {
                $row[$col] = json_decode($row[$col], TRUE);
            }
        }
        return $row;
    }
}
