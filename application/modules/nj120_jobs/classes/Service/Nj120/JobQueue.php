<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Очередь фоновых задач (модуль NJ-120).
 *
 * Единственная точка работы с nj_120_jobs из PHP. Модули НЕ пишут в таблицу
 * напрямую — только через этот сервис:
 *
 *   // поставить задачу
 *   $id = Service_Nj120_JobQueue::push('embeddings.index', ['ids' => [1,2,3]]);
 *
 *   // с опциями
 *   $id = Service_Nj120_JobQueue::push('llm.summarize', $data, [
 *       'delay_sec' => 60, 'priority' => 10, 'max_attempts' => 5,
 *       'module' => 'nj401', 'callback_url' => 'https://.../nj401/api/job_cb',
 *   ]);
 *
 * Воркеры (python, папка workers/) забирают задачи либо напрямую из PostgreSQL,
 * либо через HTTP-контроллер Controller_Nj120_Api (токен WORKER_API_TOKEN).
 *
 * Конкурентность: захват задачи — UPDATE ... WHERE id IN
 *   (SELECT id ... FOR UPDATE SKIP LOCKED), поэтому несколько воркеров
 *   не схватят одну задачу дважды. Пробуждение — pg_notify('nj120_jobs').
 */
class Service_Nj120_JobQueue {

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE       = 'done';
    const STATUS_FAILED     = 'failed';
    const STATUS_CANCELLED  = 'cancelled';

    /** Канал NOTIFY для пробуждения воркеров */
    const NOTIFY_CHANNEL = 'nj120_jobs';

    /** @var Database */
    protected $_db;

    /** @var string */
    protected $_db_group;

    public function __construct($db_group = NULL)
    {
        $this->_db_group = $db_group !== NULL
            ? $db_group
            : Kohana::$config->load('nj120_jobs.db_group');
        $this->_db = Database::instance($this->_db_group);
    }

    public static function instance($db_group = NULL)
    {
        return new self($db_group);
    }

    // ---------------------------------------------------------------------
    // Постановка задач
    // ---------------------------------------------------------------------

    /**
     * Поставить задачу в очередь.
     *
     * @param  string $channel  канал: 'embeddings.index', 'llm.summarize', ...
     * @param  array  $payload  данные для воркера (будут в JSON)
     * @param  array  $options  delay_sec|priority|max_attempts|timeout_sec|
     *                          module|created_by|callback_url
     * @return int id задачи
     */
    public static function push($channel, array $payload = [], array $options = [])
    {
        $queue = self::instance();

        // Бюджет токенов владельца (nj120/006): задача от человека с
        // исчерпанным дневным/месячным бюджетом в очередь не встаёт.
        // Служебные задачи без visitor_id и вызовы с 'budget' => FALSE
        // (например, повтор администратором) не проверяются.
        $visitor = isset($options['visitor_id']) ? trim((string) $options['visitor_id']) : '';

        if ($visitor !== '' AND ( ! isset($options['budget']) OR $options['budget'] !== FALSE)
            AND class_exists('Service_Nj120_Budget'))
        {
            $verdict = Service_Nj120_Budget::check($visitor);

            if ( ! $verdict['ok'])
            {
                Kohana::$log->add(Log::NOTICE, 'nj120 budget: отказ :c для :v', [':c' => $channel, ':v' => $visitor]);

                throw new Nj120_Budget_Exception($verdict['message'], $verdict['state']);
            }
        }

        $delay   = isset($options['delay_sec'])   ? (int) $options['delay_sec'] : 0;
        $values  = [
            'channel'      => (string) $channel,
            'payload'      => $queue->_safe_json($payload),
            'priority'     => isset($options['priority'])     ? (int) $options['priority']     : 100,
            'max_attempts' => isset($options['max_attempts']) ? (int) $options['max_attempts'] : 3,
            'timeout_sec'  => isset($options['timeout_sec'])  ? (int) $options['timeout_sec']  : 600,
            'module'       => isset($options['module'])       ? $queue->_db->quote((string) $options['module']) : 'NULL',
            'created_by'   => isset($options['created_by'])   ? (int) $options['created_by']   : 'NULL',
            'callback_url' => isset($options['callback_url']) ? $queue->_db->quote((string) $options['callback_url']) : 'NULL',
            'visitor_id'   => isset($options['visitor_id'])   ? $queue->_db->quote((string) $options['visitor_id'])   : 'NULL',
            'available_at' => $delay > 0 ? "now() + interval '".$delay." seconds'" : 'now()',
        ];

        $sql = "INSERT INTO nj_120_jobs
                (channel, payload, priority, max_attempts, timeout_sec, module, created_by, callback_url, visitor_id, available_at)
                VALUES (".$queue->_db->quote($values['channel']).",
                        ".$queue->_db->quote($values['payload']).",
                        ".$values['priority'].",
                        ".$values['max_attempts'].",
                        ".$values['timeout_sec'].",
                        ".$values['module'].",
                        ".$values['created_by'].",
                        ".$values['callback_url'].",
                        ".$values['visitor_id'].",
                        ".$values['available_at'].")
                RETURNING id";

        // NOTE: тип SELECT + INSERT..RETURNING — так драйвер PostgreSQL вернёт
        // результат выборки, а не массив (insert_id, rows).
        $result = $queue->_db->query(Database::SELECT, $sql, FALSE);
        $row    = $result->current();
        $job_id = $row ? (int) $row['id'] : 0;

        $queue->_log_event($job_id, 'created', 'Задача поставлена в очередь', ['channel' => $channel]);
        $queue->_notify($channel);

        return $job_id;
    }

    /**
     * Повторить задачу: тот же канал и тот же payload — НОВОЙ задачей.
     *
     * Зачем: модульные панели (первым — /nj115) показывают последние задачи
     * канала. Кнопка «Повторить» должна работать без знания, что именно лежит
     * в payload: берём payload завершившейся задачи и ставим её заново.
     * Провалившуюся строку при этом НЕ воскрешаем — журнал попыток остаётся
     * честным (видно, что первый заход упал).
     *
     * @param  int $job_id задача-образец
     * @return int id новой задачи (0 — образец не найден)
     */
    public function retry($job_id)
    {
        $job = $this->get((int) $job_id);

        if (empty($job))
        {
            return 0;
        }

        $payload = $job['payload'];

        if (is_string($payload))
        {
            $decoded = json_decode($payload, TRUE);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $options = [
            'priority'     => (int) ($job['priority'] ?? 100),
            'max_attempts' => (int) ($job['max_attempts'] ?? 3),
            'timeout_sec'  => (int) ($job['timeout_sec'] ?? 600),
        ];

        foreach (['module', 'callback_url', 'visitor_id'] as $key)
        {
            if ( ! empty($job[$key]))
            {
                $options[$key] = (string) $job[$key];
            }
        }

        if ( ! empty($job['created_by']))
        {
            $options['created_by'] = (int) $job['created_by'];
        }

        $new_id = self::push((string) $job['channel'], (array) $payload, $options);

        $this->_log_event($new_id, 'created', 'Повтор задачи #'.(int) $job_id.' (по кнопке «Повторить»)');

        return $new_id;
    }

    // ---------------------------------------------------------------------
    // Работа воркера (используется и HTTP-API, и python напрямую не отсюда)
    // ---------------------------------------------------------------------

    /**
     * Захватить следующую задачу (FOR UPDATE SKIP LOCKED — безопасно для N воркеров).
     *
     * @param  array  $channels список каналов; пусто = любые
     * @param  string $worker   имя воркера
     * @return array|null строка задачи или null (очередь пуста)
     */
    public function claim(array $channels, $worker)
    {
        $where_channels = '';
        if ( ! empty($channels))
        {
            $list = implode(',', array_map([$this->_db, 'quote'], array_map('strval', $channels)));
            $where_channels = " AND channel IN (".$list.")";
        }

        // error = NULL: текст прошлой попытки ('Воркер перезапущен — задача
        // возвращена в очередь…') не должен висеть на ЖИВОЙ задаче. Монитор
        // показывал «Выполняется» и эту ошибку одновременно, и владелец читал
        // это как «воркер завис на задаче» (жалоба по семантическому индексу).
        $sql = "UPDATE nj_120_jobs SET
                    status = '".self::STATUS_PROCESSING."',
                    claimed_by = ".$this->_db->quote((string) $worker).",
                    claimed_at = now(),
                    heartbeat_at = now(),
                    started_at = COALESCE(started_at, now()),
                    error = NULL,
                    attempts = attempts + 1
                WHERE id = (
                    SELECT id FROM nj_120_jobs
                    WHERE status = '".self::STATUS_PENDING."'
                      AND available_at <= now()
                      AND attempts < max_attempts".$where_channels."
                    ORDER BY priority ASC, id ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                )
                RETURNING *";

        // Тип SELECT (а не UPDATE): нужен сам RETURNING-ряд, а не число строк —
        // драйвер PostgreSQL для UPDATE возвращает int.
        $result = $this->_db->query(Database::SELECT, $sql, FALSE);
        $row = $result->current();

        if (empty($row))
            return NULL;

        $row = (array) $row;
        $this->_log_event((int) $row['id'], 'claimed', 'Захвачено воркером '.$worker);
        $this->_notify($row['channel']);

        return $this->_normalize($row);
    }

    /**
     * Отметка «жив ли воркер» (для зависших задач).
     */
    public function heartbeat($job_id, $worker, $note = NULL)
    {
        // ВАЖНО: heartbeat_at всегда ставит сам _update_status — повтор в $fields
        // даёт PostgreSQL «многочисленные присвоения одному столбцу heartbeat_at».
        return (bool) $this->_update_status($job_id, $worker, [],
            $note ? ['event' => 'heartbeat', 'message' => (string) $note] : []);
    }

    /**
     * Промежуточный рапорт воркера: не меняет состояние задачи, ставит
     * heartbeat_at и пишет событие 'progress' в журнал nj_120_job_events.
     * Сбой журнала не считается провалом (пульс всё равно обновился).
     */
    public function progress($job_id, $worker, $note = NULL)
    {
        $ok = $this->heartbeat($job_id, $worker, $note);

        if ($note !== NULL AND trim((string) $note) !== '')
        {
            $this->_log_event((int) $job_id, 'progress', (string) $note);
        }

        return (bool) $ok;
    }

    /**
     * Дожим «результат ДОСТАВЛЕН на сайт, но очередь не закрыта».
     *
     * Единая точка для приёмок модулей (nj591/nj598/nj602/nj607/nj639…): если
     * обработчик фактически отдал артефакт, задача обязана стать «Готово», а не
     * остаться processing и не превратиться в failed по таймауту пульса.
     *
     * Почему нельзя обойтись обычным complete(): воркер присылает его со своим
     * именем (claimed_by), а приёмка сайта — не воркер, и задача к этому моменту
     * может быть:
     *   * processing — обычный случай (воркер ещё не успел complete);
     *   * pending    — свипер вернул её в очередь (воркер «молчал»);
     *   * failed     — свипер отказал её за секунды/минуты до доставки
     *                  (именно так статья nj602 #813 была принята через 2 с
     *                  после «задача отказана»).
     * Все три случая — один итог: результат есть, статус обязан быть done.
     *
     * Идемпотентно: done не трогаем; cancelled не воскрешаем (отмену админа
     * уважаем); failed оживляем только свежий ($recent_sec), чтобы не тянуть
     * в done вчерашние провалы при повторной доставке того же артефакта.
     *
     * @param int    $job_id     задача очереди
     * @param string $note       человекочитаемая причина для журнала
     * @param mixed  $result     краткий итог (необязательно; объёмное не шлём)
     * @param int    $recent_sec насколько свежий failed можно оживить
     * @return bool TRUE = строка сейчас в done (закрыта этим вызовом или была)
     */
    public function mark_delivered($job_id, $note = NULL, $result = NULL, $recent_sec = 86400)
    {
        $job_id = (int) $job_id;

        if ($job_id <= 0)
        {
            return FALSE;
        }

        $row = $this->get($job_id);

        if ($row === NULL)
        {
            return FALSE;
        }

        $status = (string) $row['status'];

        if ($status === self::STATUS_DONE)
        {
            return TRUE;
        }

        if ($status === self::STATUS_CANCELLED)
        {
            return FALSE;
        }

        if ($status === self::STATUS_FAILED)
        {
            // оживляем только свежий провал: результат пришёл ПОСЛЕ отказа
            $finished = ! empty($row['finished_at']) ? strtotime((string) $row['finished_at']) : NULL;

            if ($finished === NULL OR (time() - $finished) > (int) $recent_sec)
            {
                return FALSE;
            }
        }

        try
        {
            $sets = [
                'status = '.$this->_db->quote(self::STATUS_DONE),
                'finished_at = now()',
                'heartbeat_at = now()',
                'error = NULL',
            ];

            if ($result !== NULL)
            {
                $sets[] = 'result = '.$this->_db->quote($this->_safe_json($result));
            }

            $rows = $this->_db->query(Database::UPDATE,
                'UPDATE nj_120_jobs SET '.implode(', ', $sets).'
                 WHERE id = '.$job_id.'
                   AND status NOT IN (\''.self::STATUS_DONE.'\', \''.self::STATUS_CANCELLED.'\')', FALSE);

            $ok = is_numeric($rows) && (int) $rows > 0;

            if ( ! $ok)
            {
                // гонка: строку закрыли между чтением и UPDATE — итог тот же
                $fresh = $this->get($job_id);
                return (bool) ($fresh AND (string) $fresh['status'] === self::STATUS_DONE);
            }

            $this->_log_event($job_id, 'done',
                ($note !== NULL AND trim((string) $note) !== '')
                    ? (string) $note
                    : 'Результат доставлен на сайт — задача закрыта из приёмки ('.$status.' → done)');
            $this->_notify($row['channel']);

            return TRUE;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj120: mark_delivered #:i: :e',
                [':i' => $job_id, ':e' => $e->getMessage()]);

            return FALSE;
        }
    }

    /**
     * Зеркало mark_delivered() для ОШИБКИ: приёмка сайта получила финальный
     * ответ воркера «не вышло» — строка не имеет права остаться processing.
     *
     * Зачем: живой ряд опрашивает браузер, а «нет пульса N мин» появлялось
     * поверх уже законченной работы (владелец: «Всё отработал, но процесс
     * залип»). Если попытки у задачи ещё есть — не трогаем: ретрай и backoff
     * остаются за воркером (он присылает это же состояние обычным fail()).
     *
     * Идемпотентно: done/failed не трогаем, cancelled уважаем.
     *
     * @param int    $job_id задача очереди
     * @param string $error  текст ошибки для страницы
     * @param string $note   человекочитаемая причина для журнала
     * @return bool TRUE = строка в failed (закрыта или была)
     */
    public function mark_failed_from_site($job_id, $error, $note = NULL)
    {
        $job_id = (int) $job_id;

        if ($job_id <= 0)
        {
            return FALSE;
        }

        $row = $this->get($job_id);

        if ($row === NULL)
        {
            return FALSE;
        }

        $status = (string) $row['status'];

        if ($status === self::STATUS_FAILED)
        {
            return TRUE;
        }

        if ($status === self::STATUS_DONE OR $status === self::STATUS_CANCELLED)
        {
            return FALSE;
        }

        // Ретраи — забота воркера: попытки не исчерпаны, значит fail() ещё
        // вернёт задачу в очередь с backoff, и закрывать её тут нельзя.
        if ((int) $row['attempts'] < (int) $row['max_attempts'])
        {
            return FALSE;
        }

        try
        {
            $rows = $this->_db->query(Database::UPDATE,
                "UPDATE nj_120_jobs SET
                    status = '".self::STATUS_FAILED."',
                    error = ".$this->_db->quote(mb_substr((string) $error, 0, 1000)).",
                    finished_at = now(),
                    heartbeat_at = now(),
                    claimed_by = NULL
                 WHERE id = ".$job_id."
                   AND status IN ('".self::STATUS_PENDING."', '".self::STATUS_PROCESSING."')", FALSE);

            $ok = is_numeric($rows) && (int) $rows > 0;

            if ( ! $ok)
            {
                $fresh = $this->get($job_id);

                return (bool) ($fresh AND (string) $fresh['status'] === self::STATUS_FAILED);
            }

            $this->_log_event($job_id, 'failed',
                ($note !== NULL AND trim((string) $note) !== '')
                    ? (string) $note
                    : 'Финальный ответ воркера принят сайтом: работа закончилась ошибкой');
            $this->_notify((string) $row['channel']);

            return TRUE;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj120: mark_failed_from_site #:i: :e',
                [':i' => $job_id, ':e' => $e->getMessage()]);

            return FALSE;
        }
    }

    /**
     * Задача выполнена успешно.
     */
    public function complete($job_id, $worker, $result = NULL, array $usage = NULL)
    {
        // НЕ даём финализации упасть: что бы ни случилось с «богатым» UPDATE
        // (огромный/повреждённый result), строка обязана уйти в terminal.
        try
        {
            $ok = $this->_update_status($job_id, $worker, [
                'status'      => $this->_db->quote(self::STATUS_DONE),
                'result'      => $this->_db->quote($this->_safe_json($result)),
                'finished_at' => 'now()',
                'error'       => 'NULL',
            ], ['event' => 'done', 'message' => 'Выполнено воркером '.$worker]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj120: complete #:i (первая запись): :e',
                [':i' => (int) $job_id, ':e' => $e->getMessage()]);
            $ok = FALSE;
        }

        if ( ! $ok)
        {
            // Строка вышла из processing у этого воркера (свипер вернул в
            // pending, задача перехвачена/отменена). Воркер присылает
            // complete() ПОСЛЕ того, как реально сделал работу (результат уже
            // доставлен на сайт) — фиксируем terminal идемпотентно, чтобы
            // задача не висела processing вечно.
            $row = $this->get($job_id);

            if ($row === NULL)
            {
                return FALSE;
            }

            $status = (string) $row['status'];

            if ($status === self::STATUS_DONE)
            {
                return TRUE;
            }

            if ($status === self::STATUS_FAILED OR $status === self::STATUS_CANCELLED)
            {
                return FALSE;
            }

            // pending — отменили до захвата повторного прогона; обработка
            // воркером фактически завершена, но результат уже получен.
            $this->_db->query(Database::UPDATE,
                "UPDATE nj_120_jobs SET
                    status = '".self::STATUS_DONE."',
                    result = ".$this->_db->quote($this->_safe_json($result)).",
                    finished_at = now(),
                    error = NULL,
                    heartbeat_at = now(),
                    claimed_by = COALESCE(claimed_by, ".$this->_db->quote((string) $worker).")
                 WHERE id = ".(int) $job_id."
                   AND status NOT IN ('".self::STATUS_DONE."', '".self::STATUS_FAILED."', '".self::STATUS_CANCELLED."')", FALSE);

            $this->_log_event((int) $job_id, 'done',
                'Завершено python-воркером '.$worker.' (принудительно из '.$status.')');
            $ok = TRUE;
        }

        if ($ok)
        {
            // учёт ресурсов (отчёт воркера) — сбой учёта не роняет результат.
            // Воркер в http-режиме шлёт usage отдельным полем (не внутрь result),
            // в pg-режиме подмешивает его в result['usage'] (см. base.py).
            if ($usage === NULL AND is_array($result) AND isset($result['usage']) AND is_array($result['usage']))
            {
                $usage = $result['usage'];
            }

            if ($usage !== NULL)
            {
                $this->_usage_ingest($job_id, $usage, FALSE);
            }

            // nj597: уведомление владельцу о готовности. НАЙДЕНО 21.09: хук был
            // только в fail() — «Готово: …» не приходило никому и никогда.
            if (class_exists('Service_Nj597_Notify'))
            {
                try
                {
                    $row597 = $this->get($job_id);

                    if ($row597 !== NULL)
                    {
                        Service_Nj597_Notify::job_update((array) $row597, 'done');
                    }
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'nj597: notify done: :e', [':e' => $e->getMessage()]);
                }
            }

            // событие вторично: не даём ему «уронить» уже записанный результат
            try
            {
                $payload = [
                    'job_id'  => (int) $job_id,
                    'channel' => $this->_channel_of($job_id),
                    'result'  => $result,
                    'worker'  => $worker,
                ];
                // владелец задачи — адресат уведомления (шина erp208 → слушатели/nj597)
                $owner = $this->_owner_of($job_id);
                if ($owner !== NULL)
                {
                    $payload['target_user_id'] = $owner;
                    $payload['force_notification'] = TRUE; // воркер — не инициатор, но Auth в HTTP-контексте пуст
                }
                // шина erp208 (журнал + слушатели); Kohana Event::fire не используем —
                // слушателей у него нет, событие уходило «в пустоту»
                if (class_exists('Service_Erp208_EventBus'))
                {
                    Service_Erp208_EventBus::instance()->publish('nj120.job_done', $payload);
                }
            }
            catch (Exception $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj120: событие job_done не сработало: :e', [':e' => $e->getMessage()]);
            }
            $this->_callback($job_id, self::STATUS_DONE, $result, NULL);
        }

        // Последний рубеж: строка обязана быть done. Если «богатая» запись не
        // сработала (result слишком велик/повреждён, гонка со свипером) —
        // закрываем минимальным UPDATE. Без этого монитор вечно показывает
        // «Выполняется» при уже доставленной на сайт статье (nj602).
        if ( ! $ok)
        {
            $ok = $this->_finalize_force($job_id, self::STATUS_DONE,
                'не удалась обычная запись complete() — результат уже доставлен на сайт');
        }

        return $ok;
    }

    /**
     * Гарантированный terminal-дожим: строка не должна остаться processing.
     *
     * Применяется, когда обычная запись complete()/fail() не сработала
     * (вернула ok=FALSE или упала): пишем ТОЛЬКО статус+finished_at по id,
     * без result (объёмные/повреждённые payload'ы тут не участвуют — нечему
     * ронять UPDATE). Идемпотентно: done/failed/cancelled не трогаем.
     */
    protected function _finalize_force($job_id, $status, $message)
    {
        try
        {
            $rows = $this->_db->query(Database::UPDATE,
                "UPDATE nj_120_jobs SET
                    status = ".$this->_db->quote((string) $status).",
                    finished_at = now(),
                    heartbeat_at = now(),
                    error = COALESCE(error, ".$this->_db->quote(mb_substr((string) $message, 0, 500)).")
                 WHERE id = ".(int) $job_id."
                   AND status = '".self::STATUS_PROCESSING."'", FALSE);

            if (is_numeric($rows) && (int) $rows > 0)
            {
                $this->_log_event((int) $job_id, 'done', 'Задача закрыта принудительно: '.(string) $message);
                return TRUE;
            }

            return FALSE;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj120: force-finalize #:i: :e',
                [':i' => (int) $job_id, ':e' => $e->getMessage()]);
            return FALSE;
        }
    }

    /**
     * Учёт ресурсов задачи (отчёт воркера): nj_120_usage, upsert по job_id.
     * Таблицу создаёт миграция nj120/004; пока не применена — тихо пропускаем.
     */
    protected function _usage_ingest($job_id, array $usage, $had_error)
    {
        try
        {
            $row  = $this->get($job_id);

            if ($row === NULL)
            {
                return;
            }

            $visitor = (string) $row['visitor_id'];
            $user_id = (strncmp($visitor, 'u:', 2) === 0) ? (int) substr($visitor, 2) : NULL;
            // скобки обязательны: AND слабее ?:, без них $models/$errors — bool
            // и array_values() падает (статистика использования молча терялась)
            $models  = (isset($usage['models']) AND is_array($usage['models'])) ? $usage['models'] : [];
            $errors  = (isset($usage['errors']) AND is_array($usage['errors'])) ? $usage['errors'] : [];

            // срез payload и статус — чтобы учёт читался после удаления задачи
            // (миграция 005 сняла каскад; до неё колонок нет — второй запрос)
            $brief = $this->_payload_brief($row['payload'] ?? NULL);
            $sql   = function ($extra_cols, $extra_vals, $extra_set) use ($job_id, $row, $visitor, $user_id, $models, $errors, $usage, $had_error) {
                return 'INSERT INTO nj_120_usage (job_id, channel, visitor_id, user_id, models,
                     llm_calls, prompt_tokens, completion_tokens, web_fetches,
                     search_queries, errors, had_error, elapsed_ms'.$extra_cols.')
                 VALUES ('.(int) $job_id.', '.$this->_db->quote((string) $row['channel']).',
                     '.$this->_db->quote($visitor).', '.($user_id === NULL ? 'NULL' : $user_id).',
                     '.$this->_db->quote($this->_safe_json(array_values($models))).',
                     '.(int) ($usage['llm_calls'] ?? 0).', '.(int) ($usage['prompt_tokens'] ?? 0).',
                     '.(int) ($usage['completion_tokens'] ?? 0).', '.(int) ($usage['web_fetches'] ?? 0).',
                     '.(int) ($usage['search_queries'] ?? 0).',
                     '.$this->_db->quote($this->_safe_json(array_values($errors))).',
                     '.($had_error ? 1 : 0).', '.(int) ($usage['elapsed_ms'] ?? 0).$extra_vals.')
                 ON CONFLICT (job_id) DO UPDATE SET
                     models = EXCLUDED.models, llm_calls = EXCLUDED.llm_calls,
                     prompt_tokens = EXCLUDED.prompt_tokens,
                     completion_tokens = EXCLUDED.completion_tokens,
                     web_fetches = EXCLUDED.web_fetches,
                     search_queries = EXCLUDED.search_queries,
                     errors = EXCLUDED.errors, had_error = EXCLUDED.had_error,
                     elapsed_ms = EXCLUDED.elapsed_ms, updated_at = now()'.$extra_set;
            };

            try
            {
                $this->_db->query(Database::INSERT, $sql(', payload_brief, status',
                    ', '.$this->_db->quote($this->_safe_json($brief)).', '.$this->_db->quote((string) ($row['status'] ?? '')),
                    ', payload_brief = EXCLUDED.payload_brief, status = EXCLUDED.status'), FALSE);
            }
            catch (Throwable $e)
            {
                $this->_db->query(Database::INSERT, $sql('', '', ''), FALSE);
            }

            // бюджет: пересчитать и при 90 %/100 % предупредить владельца
            if ($visitor !== '' AND class_exists('Service_Nj120_Budget'))
            {
                Service_Nj120_Budget::after_usage($visitor);
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj120: usage ingest: :e', [':e' => $e->getMessage()]);
        }
    }

    /**
     * Скалярный срез payload для nj_120_usage.payload_brief: только строки и
     * числа верхнего уровня, строки до 1000 символов. Массивы (граф, файлы)
     * не кладём — учёт должен оставаться лёгким.
     */
    protected function _payload_brief($payload)
    {
        if (is_string($payload))
        {
            $decoded = json_decode($payload, TRUE);
            $payload = is_array($decoded) ? $decoded : [];
        }

        if ( ! is_array($payload))
        {
            return [];
        }

        $brief = [];

        foreach ($payload as $key => $value)
        {
            if (is_string($value))
            {
                $brief[(string) $key] = UTF8::substr($value, 0, 1000);
            }
            elseif (is_int($value) OR is_float($value) OR is_bool($value))
            {
                $brief[(string) $key] = $value;
            }

            if (count($brief) >= 24)
            {
                break;
            }
        }

        return $brief;
    }

    /**
     * Задача упала. Если попытки не исчерпаны — возврат в pending с backoff.
     */
    public function fail($job_id, $worker, $error_message, $retry = TRUE, array $usage = NULL)
    {
        $row = $this->get($job_id);
        if ($row === NULL)
            return FALSE;

        // учёт ресурсов даже при падении — для анализа причин и потребления
        if ($usage !== NULL)
        {
            $this->_usage_ingest($job_id, $usage, TRUE);
        }

        $attempts    = (int) $row['attempts'];
        $max         = (int) $row['max_attempts'];
        $will_retry  = $retry && $attempts < $max;

        if ($will_retry)
        {
            // Backoff: 15s, 60s, 135s... (15 * n^2)
            try
            {
                $ok = $this->_update_status($job_id, $worker, [
                    'status'       => $this->_db->quote(self::STATUS_PENDING),
                    'error'        => $this->_db->quote((string) $error_message),
                    'available_at' => "now() + interval '".$delay." seconds'",
                ], ['event' => 'failed', 'message' => 'Попытка '.$attempts.'/'.$max.' не удалась, retry через '.$delay.'s']);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj120: fail #:i (retry): :e',
                    [':i' => (int) $job_id, ':e' => $e->getMessage()]);
                $ok = FALSE;
            }
        }
        else
        {
            try
            {
                $ok = $this->_update_status($job_id, $worker, [
                    'status'      => $this->_db->quote(self::STATUS_FAILED),
                    'error'       => $this->_db->quote((string) $error_message),
                    'finished_at' => 'now()',
                ], ['event' => 'failed', 'message' => 'Попытки исчерпаны (воркер '.$worker.')']);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj120: fail #:i (terminal): :e',
                    [':i' => (int) $job_id, ':e' => $e->getMessage()]);
                $ok = FALSE;
            }

            // Последний рубеж: попытки исчерпаны — строка ОБЯЗАНА уйти в
            // failed, иначе «источники не нашлись» вечно висит processing.
            if ( ! $ok)
            {
                $ok = $this->_finalize_force($job_id, self::STATUS_FAILED,
                    $error_message ?: 'Попытки исчерпаны');
            }

            if ($ok)
            {
                $payload = [
                    'job_id'  => (int) $job_id,
                    'channel' => $row['channel'],
                    'error'   => $error_message,
                    'worker'  => $worker,
                ];
                $owner = ! empty($row['created_by']) ? (int) $row['created_by'] : NULL;
                if ($owner !== NULL)
                {
                    $payload['target_user_id'] = $owner;
                    $payload['force_notification'] = TRUE;
                }
                if (class_exists('Service_Erp208_EventBus'))
                {
                    Service_Erp208_EventBus::instance()->publish('nj120.job_failed', $payload);
                }
                $this->_callback($job_id, self::STATUS_FAILED, NULL, $error_message);

                // nj597: уведомление владельцу об окончательной ошибке
                if (class_exists('Service_Nj597_Notify'))
                {
                    try
                    {
                        Service_Nj597_Notify::job_update((array) $row, 'failed', (string) $error_message);
                    }
                    catch (Throwable $e)
                    {
                        Kohana::$log->add(Log::WARNING, 'nj597: notify failed: :e', [':e' => $e->getMessage()]);
                    }
                }
            }
        }

        return $ok;
    }

    /**
     * Отменить ожидающую задачу.
     */
    public function cancel($job_id, $force = FALSE)
    {
        // force — прервать и ИСПОЛНЯЮЩУЮСЯ задачу: воркер свой complete()/fail()
        // потом не применит (статус уже не processing) — это штатно
        $in = $force ? "('pending', 'processing')" : "('pending')";
        $sql = "UPDATE nj_120_jobs SET status = '".self::STATUS_CANCELLED."', finished_at = now()
                WHERE id = ".(int) $job_id." AND status IN ".$in;
        $this->_db->query(Database::UPDATE, $sql, FALSE);
        $this->_log_event((int) $job_id, 'cancelled', $force ? 'Прервана администратором' : 'Отменена');

        return TRUE;
    }

    /** Удалить ЗАВЕРШЕННУЮ задачу (nj_120_usage уйдёт каскадом). */
    public function delete_job($job_id)
    {
        $this->_db->query(Database::DELETE,
            "DELETE FROM nj_120_jobs
             WHERE id = ".(int) $job_id." AND status IN ('done', 'failed', 'cancelled')", FALSE);

        return TRUE;
    }

    /**
     * Очистить ВСЕ записи монитора (завершённые + ожидающие + исполняющиеся).
     * @return int удалённых строк
     */
    public function clear_all()
    {
        // сперва журнал жизни задач (по ссылке на jobs), затем сами задачи;
        // обратный порядок (=delete_job без него) оставил бы висячие события.
        $this->_db->query(Database::DELETE,
            'DELETE FROM nj_120_job_events WHERE job_id IN (SELECT id FROM nj_120_jobs)', FALSE);
        $rows = $this->_db->query(Database::DELETE,
            'DELETE FROM nj_120_jobs', FALSE);

        return is_numeric($rows) ? (int) $rows : 0;
    }

    /** Человеко-читаемое имя канала (из channels_ui или сам канал). */
    public static function channel_label($channel)
    {
        static $ui = NULL;
        if ($ui === NULL)
        {
            $ui = Manifest::channels_ui();
        }

        return (string) ($ui[(string) $channel]['label'] ?? (string) $channel);
    }

    /**
     * Здоровье воркерного контура: счётчики по статусам, пульс воркера
     * (heartbeat исполняющихся задач), «застрявшие», имена воркеров за 24 ч,
     * что воркер делает СЕЙЧАС и чем завершились последние задачи.
     */
    public function monitor_health()
    {
        $db = $this->_db;

        $counts = [];
        foreach ($db->query(Database::SELECT,
            'SELECT status, count(*) AS c FROM nj_120_jobs GROUP BY status', FALSE)->as_array() as $r)
        {
            $counts[(string) $r['status']] = (int) $r['c'];
        }

        // «Застряли» — ровно те строки, которые уборка (requeue_stale) закроет
        // в этом же проходе: пульс молчит дольше порога авто-стопа ИЛИ прогресс
        // не обновлялся дольше порога «пульс есть, работы нет». Раньше счётчик
        // смотрел только на heartbeat_at (и считал «пустым пульсом» живую
        // строку) — число расходилось с правилами уборки: монитор показывал
        // «завис» там, где закрывать нечего, и молчал там, где надо чистить.
        $silent  = $this->silent_stop_sec();
        $stalled = $this->stalled_progress_sec();

        $beat = $db->query(Database::SELECT,
            "SELECT max(".$this->_pulse_sql().") AS beat,
                    count(*) FILTER (WHERE ".$this->_pulse_sql()." < now() - ".(int) $silent." * interval '1 second') AS silent_now,
                    count(*) FILTER (WHERE COALESCE(
                              (SELECT max(e.created_at) FROM nj_120_job_events e WHERE e.job_id = j.id),
                              j.claimed_at, j.created_at) < now() - ".(int) $stalled." * interval '1 second') AS stalled_now,
                    count(*) FILTER (WHERE ".$this->_pulse_sql()." < now() - ".(int) $silent." * interval '1 second'
                                        OR COALESCE(
                                              (SELECT max(e.created_at) FROM nj_120_job_events e WHERE e.job_id = j.id),
                                              j.claimed_at, j.created_at) < now() - ".(int) $stalled." * interval '1 second') AS stuck
             FROM nj_120_jobs j WHERE j.status = 'processing'", FALSE)->current();

        $workers = [];
        foreach ($db->query(Database::SELECT,
            "SELECT DISTINCT claimed_by FROM nj_120_jobs
             WHERE claimed_by IS NOT NULL AND claimed_by <> ''
               AND coalesce(heartbeat_at, finished_at, created_at) > now() - interval '24 hours'
             LIMIT 10", FALSE)->as_array() as $r)
        {
            $workers[] = (string) $r['claimed_by'];
        }

        // Чем занят воркер СЕЙЧАС: исполняющиеся задачи (по свежести пульса).
        $now_doing = [];
        foreach ($db->query(Database::SELECT,
            'SELECT j.channel, j.payload, j.heartbeat_at, j.started_at, j.claimed_by,
                    j.claimed_at, j.created_at, ev.message AS stage, ev.created_at AS stage_at
               FROM nj_120_jobs j
               LEFT JOIN LATERAL (
                   SELECT e.message, e.created_at FROM nj_120_job_events e
                    WHERE e.job_id = j.id AND e.event = \'progress\'
                      AND e.message IS NOT NULL AND e.message <> \'\'
                    ORDER BY e.id DESC LIMIT 1
               ) ev ON TRUE
              WHERE j.status = \'processing\'
              ORDER BY coalesce(j.heartbeat_at, j.claimed_at, j.started_at, j.created_at) DESC NULLS LAST
              LIMIT 5', FALSE)->as_array() as $r)
        {
            $now_doing[] = [
                'channel' => (string) $r['channel'],
                'label'   => self::channel_label((string) $r['channel']),
                'title'   => self::job_title_of($r['payload']),
                'stage'   => (string) ($r['stage'] ?? ''),
                'worker'  => (string) ($r['claimed_by'] ?? ''),
                'beat_at' => (string) ($r['heartbeat_at'] ?? $r['started_at'] ?? ''),
                'claimed_at' => (string) ($r['claimed_at'] ?? ''),
                // Возраст последнего рапорта: по нему видно «пульс есть, работы
                // нет» — раньше монитор показывал только пульс, и застывший
                // этап выглядел как живая работа.
                'progress_ago' => ! empty($r['stage_at'])
                    ? max(0, time() - strtotime((string) $r['stage_at']))
                    : NULL,
            ];
        }

        // Чем воркер занимался ПОСЛЕДНИМ: свежие завершённые задачи.
        $last_finished = [];
        foreach ($db->query(Database::SELECT,
            'SELECT channel, status, finished_at, claimed_by
               FROM nj_120_jobs
              WHERE status IN (\'done\', \'failed\') AND finished_at IS NOT NULL
              ORDER BY finished_at DESC LIMIT 3', FALSE)->as_array() as $r)
        {
            $last_finished[] = [
                'channel'  => (string) $r['channel'],
                'label'    => self::channel_label((string) $r['channel']),
                'status'   => (string) $r['status'],
                'worker'   => (string) ($r['claimed_by'] ?? ''),
                'finish_at'=> (string) $r['finished_at'],
            ];
        }

        $avg_ms = NULL;

        try
        {
            $r = $db->query(Database::SELECT,
                "SELECT avg(elapsed_ms)::int AS a FROM nj_120_usage
                 WHERE created_at > now() - interval '24 hours'", FALSE)->current();

            if ($r AND $r['a'] !== NULL)
            {
                $avg_ms = (int) $r['a'];
            }
        }
        catch (Throwable $e) { /* таблицы ещё нет — не страшно */ }

        $processing = (int) ($counts['processing'] ?? 0);
        $beat_ts    = ($beat AND ! empty($beat['beat'])) ? strtotime((string) $beat['beat']) : NULL;
        $stuck      = $beat ? (int) $beat['stuck'] : 0;

        // Вердикт — по тому же счёту, что и «застряли»: он отвечает на вопрос
        // «есть ли что чистить», а не «был ли пульс последние 2 минуты».
        $verdict = 'idle';

        if ($processing > 0)
        {
            $verdict = $stuck > 0 ? 'stuck' : 'ok';
        }

        return [
            'counts'         => $counts,
            'processing'     => $processing,
            'stuck'          => $stuck,
            'silent_now'     => $beat ? (int) $beat['silent_now'] : 0,
            'stalled_now'    => $beat ? (int) $beat['stalled_now'] : 0,
            'silent_sec'     => $silent,
            'stalled_sec'    => $stalled,
            'max_age_sec'    => $this->max_age_sec(),
            'last_beat'      => $beat_ts,
            'beat_ago'       => $beat_ts ? (time() - $beat_ts) : NULL,
            'workers'        => $workers,
            'now_doing'      => $now_doing,
            'last_finished'  => $last_finished,
            'done_24h'       => (int) ($db->query(Database::SELECT,
                "SELECT count(*) AS c FROM nj_120_jobs WHERE status = 'done'
                 AND finished_at > now() - interval '24 hours'", FALSE)->current()['c'] ?? 0),
            'failed_24h'     => (int) ($db->query(Database::SELECT,
                "SELECT count(*) AS c FROM nj_120_jobs WHERE status = 'failed'
                 AND finished_at > now() - interval '24 hours'", FALSE)->current()['c'] ?? 0),
            'avg_ms_24h'     => $avg_ms,
            'verdict'        => $verdict, // ok | stuck | idle
        ];
    }

    /**
     * Диагноз задачи: «почему висит и что с этим будет».
     *
     * Владелец (28.09): «Почему монитор воркеров всегда показывает зависший
     * процесс?» — по карточке задачи (#969, semantic.index, попытки 3/3,
     * «Выполняется») было непонятно, ждать ли, чинить ли воркер и когда это
     * закончится. Здесь это ОДНО место: возраст пульса, возраст последнего
     * рапорта о ходе, применимое правило уборки и через сколько секунд оно
     * сработает.
     *
     * @param  int $job_id
     * @return array|NULL NULL — задачи нет
     */
    public function diagnose($job_id)
    {
        $row = $this->get((int) $job_id);

        if ($row === NULL)
        {
            return NULL;
        }

        $status  = (string) $row['status'];
        $silent  = $this->silent_stop_sec();
        $stalled = $this->stalled_progress_sec();
        $max_age = $this->max_age_sec();
        $timeout = (int) ($row['timeout_sec'] ?? 0);
        $now     = time();

        // Пульс: heartbeat_at → claimed_at → started_at → created_at
        // (то же выражение, что в SQL уборки — см. _pulse_sql()).
        $pulse_ts = NULL;

        foreach (['heartbeat_at', 'claimed_at', 'started_at', 'created_at'] as $field)
        {
            if ( ! empty($row[$field]))
            {
                $pulse_ts = strtotime((string) $row[$field]);
                break;
            }
        }

        // Прогресс: последнее событие журнала, иначе захват/постановка.
        $progress_ts = NULL;

        try
        {
            $ev = $this->_db->query(Database::SELECT,
                "SELECT max(created_at) AS at FROM nj_120_job_events WHERE job_id = ".(int) $job_id, FALSE)->current();

            if ($ev AND ! empty($ev['at']))
            {
                $progress_ts = strtotime((string) $ev['at']);
            }
        }
        catch (Throwable $e) { /* журнала нет — считаем по захвату */ }

        foreach (['claimed_at', 'created_at'] as $field)
        {
            if ($progress_ts === NULL AND ! empty($row[$field]))
            {
                $progress_ts = strtotime((string) $row[$field]);
            }
        }

        $pulse_ago    = $pulse_ts !== NULL ? max(0, $now - $pulse_ts) : NULL;
        $progress_ago = $progress_ts !== NULL ? max(0, $now - $progress_ts) : NULL;

        $result = [
            'status'       => $status,
            'attempts'     => (int) ($row['attempts'] ?? 0),
            'max_attempts' => (int) ($row['max_attempts'] ?? 0),
            'pulse_ago'    => $pulse_ago,
            'progress_ago' => $progress_ago,
            'silent_sec'   => $silent,
            'stalled_sec'  => $stalled,
            'max_age_sec'  => $max_age,
            'close_in_sec' => NULL,
            'action'       => 'terminal',
            'text'         => 'Задача завершена — смотрите журнал ниже.',
        ];

        if ($status !== self::STATUS_PROCESSING)
        {
            if ($status === self::STATUS_PENDING)
            {
                $result['text'] = 'Задача ждёт свободного воркера: если воркер не заберёт её дольше '
                    .self::_ago_text(43200).', её закроют, чтобы не висела в «Ожидает» вечно.';
            }

            return $result;
        }

        // Возраст задачи считаем от НАЧАЛА работы, а не от пульса: пульс
        // обновляется, поэтому «возраст по пульсу» никогда не растёт. Ровно так
        // же считает правило в requeue_stale().
        $age_ts     = NULL;

        foreach (['started_at', 'claimed_at', 'created_at'] as $field)
        {
            if ( ! empty($row[$field]))
            {
                $age_ts = strtotime((string) $row[$field]);
                break;
            }
        }

        // Правила уборки: сначала те, чей порог УЖЕ пройден (их и закроет
        // ближайшая уборка), иначе — гарантированный предельный возраст.
        $triggered = [];

        if ($pulse_ago !== NULL AND $pulse_ago >= $silent)
        {
            $triggered[] = ['will_close_silent', $silent - $pulse_ago, $pulse_ago,
                'Воркер не подаёт пульс уже '.self::_ago_text($pulse_ago).' (порог '.self::_ago_text($silent).').'];
        }

        if ($progress_ago !== NULL AND $progress_ago >= $stalled)
        {
            $triggered[] = ['will_close_stalled', $stalled - $progress_ago, $progress_ago,
                'Пульс идёт, но рапортов о ходе нет уже '.self::_ago_text($progress_ago).' (порог '.self::_ago_text($stalled).').'];
        }

        $age_limit = $max_age > 0 ? max($timeout * 3, $max_age) : NULL;

        if ($age_limit !== NULL AND $age_ts !== NULL AND ($now - $age_ts) >= $age_limit)
        {
            $triggered[] = ['will_close_age', $age_limit - ($now - $age_ts), $now - $age_ts,
                'Задача идёт '.self::_ago_text($now - $age_ts).' при предельных '.self::_ago_text($age_limit).'.'];
        }

        if ($triggered)
        {
            // Самое «просроченное» правило и есть причина, по которой строку
            // закроют: у него порог пройден раньше всех.
            usort($triggered, function ($a, $b) { return $a[1] <=> $b[1]; });

            list($action, $left, $ago, $text) = $triggered[0];

            $result['action']       = $action;
            $result['close_in_sec'] = 0;
            $result['text']         = $text.' Порог пройден — задача закроется при первой уборке очереди '
                .'(она идёт при каждом заходе на сайт и по крону), авто-стоп даст «Ошибку» или «Отменено». '
                .'Если ждать не нужно — «Прервать» в мониторе.';

            return $result;
        }

        // Ничего не просрочено. Пока пульс идёт, закрывать нечего: показываем
        // гарантированный срок (предельный возраст) и напоминаем, что быстрее
        // задача закроется только если воркер замолчит или прогресс встанет.
        if ($age_limit !== NULL AND $age_ts !== NULL)
        {
            $left = $age_limit - ($now - $age_ts);

            $result['action']       = 'will_close_age';
            $result['close_in_sec'] = (int) max(0, $left);
            $result['text']         = 'Задача работает: пульс '
                .($pulse_ago !== NULL ? self::_ago_text($pulse_ago) : '—')
                .' назад, рапорт '.($progress_ago !== NULL ? self::_ago_text($progress_ago) : '—').' назад. '
                .'Дольше '.self::_ago_text($age_limit).' она не проживёт — осталось '.self::_ago_text($left)
                .'. Если пульс замолчит дольше '.self::_ago_text($silent).' или прогресс встанет дольше '
                .self::_ago_text($stalled).', закроется раньше.';

            return $result;
        }

        $result['text'] = 'Задача работает: пульс '
            .($pulse_ago !== NULL ? self::_ago_text($pulse_ago) : '—')
            .' назад, рапорт '.($progress_ago !== NULL ? self::_ago_text($progress_ago) : '—').' назад. '
            .'Если пульс замолчит дольше '.self::_ago_text($silent).' или прогресс встанет дольше '
            .self::_ago_text($stalled).', задача закроется сама.';

        return $result;
    }

    /** «N мин» / «N ч» / «N с» — для текстов диагноза. */
    protected static function _ago_text($sec)
    {
        $sec = max(0, (int) $sec);

        if ($sec >= 3600)
        {
            return round($sec / 3600, 1).' ч';
        }

        if ($sec >= 60)
        {
            return (int) round($sec / 60).' мин';
        }

        return $sec.' с';
    }

    /** Последние задачи для монитора (опционально по статусу). */
    public function monitor_jobs($status = NULL, $limit = 50)
    {
        $where = in_array($status, ['pending', 'processing', 'done', 'failed', 'cancelled'], TRUE)
            ? "WHERE j.status = '".((string) $status)."'"
            : '';

        // stage — последний рапорт воркера ('progress'): в мониторе видно, ЧТО
        // именно делает задача прямо сейчас (единая точка для всех 26 каналов,
        // эталон — статус-ряд /trends/<code>). LATERAL — один запрос без N+1.
        return $this->_db->query(Database::SELECT,
            'SELECT j.id, j.channel, j.status, j.payload, j.error, j.attempts, j.max_attempts,
                    j.visitor_id, j.created_by, j.created_at, j.started_at, j.finished_at, j.heartbeat_at,
                    u.username AS owner_name,
                    ev.message AS stage, ev.created_at AS stage_at
             FROM nj_120_jobs j
             LEFT JOIN users u ON u.id = j.created_by
             LEFT JOIN LATERAL (
                 SELECT e.message, e.created_at FROM nj_120_job_events e
                  WHERE e.job_id = j.id AND e.event = \'progress\'
                    AND e.message IS NOT NULL AND e.message <> \'\'
                  ORDER BY e.id DESC LIMIT 1
             ) ev ON TRUE '.$where.'
             ORDER BY j.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Последние задачи одного канала — «здоровье воркера» в модульных
     * админках (nj605/606/607/609): работает ли воркер, когдаLastError.
     */
    public function channel_jobs($channel, $limit = 8)
    {
        return $this->_db->query(Database::SELECT,
            'SELECT j.id, j.channel, j.status, j.payload, j.error, j.attempts, j.max_attempts,
                    j.created_at, j.started_at, j.finished_at, j.claimed_by
             FROM nj_120_jobs j
             WHERE j.channel = '.$this->_db->quote((string) $channel).'
             ORDER BY j.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Последние задачи одного посетителя (visitor_id вида u:<id> / g:<ubid>) —
     * «что человек реально делал» для карточки предпочтений (nj600) и
     * администрирования пользователей (nj595). Чужим модулям таблицу очереди
     * напрямую читать не нужно — зовут этот метод.
     */
    public function visitor_jobs($visitor_id, $limit = 30)
    {
        $visitor_id = trim((string) $visitor_id);

        if ($visitor_id === '')
        {
            return [];
        }

        return $this->_db->query(Database::SELECT,
            'SELECT j.id, j.channel, j.status, j.payload, j.error, j.module,
                    j.created_at, j.started_at, j.finished_at
             FROM nj_120_jobs j
             WHERE j.visitor_id = '.$this->_db->quote($visitor_id).'
             ORDER BY j.id DESC LIMIT '.max(1, min(200, (int) $limit)), FALSE)->as_array();
    }

    /** Короткое «имя» задачи из payload (что делали). */
    public static function job_title_of($payload)
    {
        $p = is_array($payload) ? $payload : json_decode((string) $payload, TRUE);

        if ( ! is_array($p))
        {
            return '';
        }

        foreach (['title', 'company', 'query', 'city', 'direction', 'description', 'name', 'url', 'code', 'ogrn', 'q'] as $k)
        {
            if ( ! empty($p[$k]) AND is_string($p[$k]))
            {
                return mb_substr(trim($p[$k]), 0, 60);
            }
        }

        return '';
    }

    // ---------------------------------------------------------------------
    // Чтение / обслуживание
    // ---------------------------------------------------------------------

    /**
     * Задача по id (ассоциативный массив) или NULL.
     */
    public function get($job_id)
    {
        $result = $this->_db->query(Database::SELECT,
            "SELECT * FROM nj_120_jobs WHERE id = ".(int) $job_id, FALSE);
        $row = $result->current();
        return $row ? $this->_normalize((array) $row) : NULL;
    }

    /**
     * Журнал задачи: события created/claimed/progress/done/failed/cancelled.
     */
    public function events($job_id, $limit = 100)
    {
        $result = $this->_db->query(Database::SELECT,
            'SELECT event, message, meta, created_at
               FROM nj_120_job_events
              WHERE job_id = '.(int) $job_id.'
              ORDER BY id ASC LIMIT '.(int) $limit, FALSE);

        return $result->as_array();
    }

    /**
     * Активная (pending/processing) задача канала по значению поля payload.
     *
     * Для дожима «сайт принял артефакт, а задача не закрылась» в модулях без
     * собственной колонки job_id (nj606: привязка лежит в payload.flow_id).
     * Возвращает id самой свежей такой задачи или 0. Только чтение — запись
     * через complete()/fail() остаётся единственным путём в terminal.
     */
    public function find_active($channel, $payload_key, $payload_value)
    {
        $result = $this->_db->query(Database::SELECT,
            "SELECT id FROM nj_120_jobs
              WHERE channel = ".$this->_db->quote((string) $channel)."
                AND payload->>".$this->_db->quote((string) $payload_key)." = ".$this->_db->quote((string) $payload_value)."
                AND status IN ('".self::STATUS_PENDING."', '".self::STATUS_PROCESSING."')
              ORDER BY id DESC LIMIT 1", FALSE);
        $row = $result->current();
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Задача канала по значению поля payload — «кандидат на дожим» для модулей
     * БЕЗ собственной колонки job_id (nj605/nj606/nj609/nj610/nj612/nj613:
     * привязка лежит в payload.code / payload.watch_id / payload.flow_id…).
     *
     * В отличие от find_active() смотрит и на СВЕЖИЙ failed: свипер мог отказать
     * задачу за секунды до того, как приёмка сайта приняла артефакт — тогда
     * результат есть, а статус «Ошибка». Приёмка вызывает этот метод и следом
     * mark_delivered(), который и ставит «Готово».
     *
     * @param int $recent_failed_sec окно, в котором отказанную задачу можно оживить
     * @return int id задачи или 0
     */
    public function find_for_delivery($channel, $payload_key, $payload_value, $recent_failed_sec = 86400)
    {
        // Пустой ключ = искать только по каналу (задачи без значимого payload:
        // пинок nj610.inbox, служебные прогоны) — привязка берётся по свежести.
        $where_payload = ((string) $payload_key === '')
            ? ''
            : " AND payload->>".$this->_db->quote((string) $payload_key)." = ".$this->_db->quote((string) $payload_value);

        $result = $this->_db->query(Database::SELECT,
            "SELECT id, status FROM nj_120_jobs
              WHERE channel = ".$this->_db->quote((string) $channel).$where_payload."
                AND (
                      status IN ('".self::STATUS_PENDING."', '".self::STATUS_PROCESSING."')
                   OR (status = '".self::STATUS_FAILED."'
                       AND finished_at > now() - ".(int) $recent_failed_sec." * interval '1 second')
                )
              ORDER BY id DESC LIMIT 1", FALSE);
        $row = $result->current();

        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Живое состояние задачи для «статус-ряда» на странице модуля.
     *
     * Единый контракт (эталон — /trends/<code>): страница модуля поллит свой
     * thin-endpoint, а тот зовёт этот метод и получает ОДИН набор полей:
     *
     *   status      queued|running|done|failed|cancelled|none
     *   stage       последний этап воркера (из событий 'progress')
     *   stages[]    последние этапы по порядку (свежие в конце)
     *   elapsed_sec секундомер от захвата задачи
     *   silent_sec  сколько секунд без пульса (NULL — пульса не было)
     *   error       текст ошибки терминального статуса
     *
     * Задачу можно задать id ($job_id > 0) либо парой channel + payload-ключ
     * (find_for_delivery): модулям без колонки job_id второй вариант удобнее.
     *
     * @return array
     */
    public function live_status($channel, $payload_key = NULL, $payload_value = NULL, $job_id = 0)
    {
        $empty = $this->_empty_status();

        $job_id = (int) $job_id;

        if ($job_id <= 0 AND $channel !== NULL AND $payload_key !== NULL)
        {
            $job_id = $this->find_for_delivery((string) $channel, (string) $payload_key, (string) $payload_value);
        }
        elseif ($job_id <= 0 AND $channel !== NULL AND ($payload_key === NULL OR $payload_key === ''))
        {
            // канал без значимого payload (пинки): берём самую свежую задачу
            $job_id = $this->find_for_delivery((string) $channel, '', '');
        }

        if ($job_id <= 0)
        {
            return $empty;
        }

        return $this->_status_of($job_id, $empty);
    }

    /**
     * Последняя задача канала — включая УЖЕ ЗАВЕРШЁННУЮ.
     *
     * Зачем: live_status() видит только активную (или свежеотказанную) задачу, и
     * после доставки страница модуля оставалась без следа работы — владелец
     * жаловался «нет готово». Этот метод даёт ТУ ЖЕ структуру, но для последней
     * задачи канала: страница рисует по ней постоянную плашку «Готово»/«Ошибка»
     * с временем и результатом (эталон /trends/<code>, зелёный бейдж).
     *
     * @return array как live_status(), но status никогда не 'none', если задача была
     */
    public function last_status($channel, $payload_key = NULL, $payload_value = NULL)
    {
        $empty = $this->_empty_status();

        if ($channel === NULL)
        {
            return $empty;
        }

        $where_payload = ((string) $payload_key === '')
            ? ''
            : " AND payload->>".$this->_db->quote((string) $payload_key)." = ".$this->_db->quote((string) $payload_value);

        $row = $this->_db->query(Database::SELECT,
            "SELECT id FROM nj_120_jobs
              WHERE channel = ".$this->_db->quote((string) $channel).$where_payload."
              ORDER BY id DESC LIMIT 1", FALSE)->current();

        if (empty($row))
        {
            return $empty;
        }

        return $this->_status_of((int) $row['id'], $empty);
    }

    /**
     * Пустая структура статус-ряда — ОДНО место для live_status/last_status/
     * status_of: иначе поля расходятся, и модуль рисует «прочерк» там, где
     * обязан показать статус.
     */
    protected function _empty_status()
    {
        return [
            'found'        => FALSE,
            'job_id'       => 0,
            'status'       => 'none',
            'stage'        => '',
            'stages'       => [],
            'elapsed_sec'  => 0,
            'started_at'   => NULL,
            'finished_at'  => NULL,
            'silent_sec'   => NULL,
            'error'        => '',
            'note'         => '',
            'attempts'     => 0,
            'max_attempts' => 0,
        ];
    }

    /**
     * Статус задачи по её id — общий контракт живого статус-ряда
     * (partials/live_status) для модулей, которые держат ЗАДАЧУ, а не канал:
     * профиль компании (nj591), досье (nj598), дизайн (nj590) и т.д. Раньше
     * каждый такой модуль писал свой поллинг и свой таймер, и «зависло»
     * выглядело и объяснялось по-разному.
     */
    public function status_of($job_id)
    {
        return $this->_status_of((int) $job_id, $this->_empty_status());
    }


    /**
     * Единый разбор задачи в структуру статус-ряда (общий для live_status и
     * last_status): статус, этапы из журнала, секундомер, пульс, ошибка.
     */
    protected function _status_of($job_id, array $empty)
    {
        $job_id = (int) $job_id;
        $row    = $job_id > 0 ? $this->get($job_id) : NULL;

        if ($row === NULL)
        {
            return $empty;
        }

        $status = (string) $row['status'];
        $map    = [
            self::STATUS_PENDING    => 'queued',
            self::STATUS_PROCESSING => 'running',
            self::STATUS_DONE       => 'done',
            self::STATUS_FAILED     => 'failed',
            self::STATUS_CANCELLED  => 'cancelled',
        ];

        $started = ! empty($row['claimed_at']) ? (string) $row['claimed_at'] : (string) $row['created_at'];
        $started_ts = $started !== '' ? strtotime($started) : NULL;

        // Секундомер. ВАЖНО: ветки разведены if/elseif, а не «AND … ? … : …» —
        // у AND приоритет НИЖЕ тернарника, поэтому такая запись возвращала
        // булево: у идущей задачи elapsed_sec = 1 («0:01» на любой идущей
        // работе), у завершённой = 0 («Готово за 0:00» в статус-ряде). Ловится
        // линтером .tools/php_precedence_lint.py.
        if (($status === self::STATUS_PROCESSING OR $status === self::STATUS_PENDING) AND $started_ts)
        {
            $elapsed = max(0, time() - $started_ts);
        }
        elseif ( ! empty($row['finished_at']) AND $started_ts)
        {
            $elapsed = max(0, strtotime((string) $row['finished_at']) - $started_ts);
        }
        else
        {
            $elapsed = 0;
        }

        $beat = ! empty($row['heartbeat_at']) ? strtotime((string) $row['heartbeat_at']) : NULL;

        // этапы: только события 'progress' (их пишет ctx.progress() воркера);
        // 'created'/'claimed' в статус-ряд не подмешиваем — это не этапы работы
        $stages = [];

        try
        {
            $rows = $this->_db->query(Database::SELECT,
                "SELECT message, created_at FROM nj_120_job_events
                  WHERE job_id = ".$job_id." AND event = 'progress'
                    AND message IS NOT NULL AND message <> ''
                  ORDER BY id DESC LIMIT 12", FALSE)->as_array();

            foreach (array_reverse($rows) as $e)
            {
                $stages[] = [
                    'message' => (string) $e['message'],
                    'at'      => (string) $e['created_at'],
                ];
            }
        }
        catch (Throwable $e)
        {
            // журнал может быть недоступен — статус-ряд покажет хотя бы статус
            Kohana::$log->add(Log::WARNING, 'nj120: live_status этапы: :e', [':e' => $e->getMessage()]);
        }

        // Итоговая приписка модуля (result.note): «собрано по эвристике, модель не
        // ответила» и подобное. Раньше каждый модуль показывал это по-своему или
        // не показывал вовсе — владелец не понимал, откуда взялись данные.
        $note = '';

        if ( ! empty($row['result']))
        {
            $res = is_array($row['result'])
                ? $row['result']
                : json_decode((string) $row['result'], TRUE);

            if (is_array($res) AND isset($res['note']))
            {
                $note = mb_substr(trim((string) $res['note']), 0, 300);
            }
        }

        return [
            'found'        => TRUE,
            'job_id'       => $job_id,
            'status'       => isset($map[$status]) ? $map[$status] : (string) $status,
            'stage'        => $stages ? $stages[count($stages) - 1]['message'] : '',
            'stages'       => $stages,
            'elapsed_sec'  => (int) $elapsed,
            'started_at'   => $started,
            'finished_at'  => ! empty($row['finished_at']) ? (string) $row['finished_at'] : NULL,
            // Пульс не может быть «старше» захвата задачи. Если heartbeat_at меньше
            // claimed_at (строка осталась от прошлой попытки или задача перехвачена
            // другим воркером), считаем от старта: иначе ряд показывал «нет пульса
            // 6 мин» при секундомере 2:57, и владелец читал это как «воркер молчит»
            // (жалоба 26.09 по /cad). Настоящую остановку пульса это не скрывает:
            // там heartbeat_at свежее claimed_at и он берётся как есть.
            'silent_sec'   => ($status === self::STATUS_PROCESSING AND $beat)
                ? max(0, time() - max($beat, (int) ($started_ts ?: $beat)))
                : NULL,
            'error'        => (string) ($row['error'] ?? ''),
            'note'         => $note,
            'attempts'     => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
        ];
    }



    /**
     * Сводка очереди по статусам/каналам.
     */
    public function stats()
    {
        $result = $this->_db->query(Database::SELECT,
            "SELECT status, COUNT(*) AS cnt FROM nj_120_jobs GROUP BY status", FALSE);

        $stats = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($result as $row)
        {
            $row = (array) $row;
            $stats[$row['status']] = (int) $row['cnt'];
        }
        return $stats;
    }

    /**
     * Дожим результатов, ДОСТАВЛЕННЫХ на сайт, но не закрытых в очереди.
     *
     * Сценарий «статья создалась, а статуса Готово нет» (nj602): воркер через
     * HTTP доставил артефакт (записал статью/профиль/документ на сайт), но не
     * успел выполнить complete() — упал, рестартовал, транспорт оборвался.
     * В pg-режиме такие строки донимает python-свипер; в HTTP-режиме воркер к
     * БД не ходит, и строку никто не закрывает, пока пульс воркера свеж (правило
     * 2а ниже «воркера не трогаем») — задача висит processing вечно, а «Готово
     * за 24 ч» не растёт.
     *
     * Здесь НЕ «верим пульсу», а сверяемся с фактическим состоянием модуля:
     * reconcile_hooks в config/nj120_jobs.php сопоставляет каналу SQL-условие
     * «артефакт виден». Зависшая строка (пульс протух — воркер её уже не ведёт:
     * упал или закончил и не выполнил complete()), у которой артефакт есть,
     * переводится в done; остальные разбираются по правилам requeue_stale()
     * ниже. Живую задачу не трогаем: пока обработчик работает, фоновый
     * heartbeat (30 с) держит пульс свежим.
     *
     * @param int $older_sec «молчать дольше N секунд» — строка считается зависшей
     * @return int закрытых задач
     */
    public function reconcile_outcomes($older_sec = 300)
    {
        $hooks = Manifest::reconcile_hooks();

        if ( ! $hooks)
        {
            return 0;
        }

        $count = 0;
        $db    = $this->_db;

        foreach ($hooks as $channel => $sql_where)
        {
            $sql_where = (string) $sql_where;
            $channel   = (string) $channel;

            if ($sql_where === '' OR $channel === '')
            {
                continue;
            }

            try
            {
                // Артефакт доставлен: штрихуем в done. finished_at нужен счётчику
                // «Готово за 24 ч» — его не ставит ни один другой путь, когда
                // complete() не дошёл. Journal ведём по RETURNING id.
                $done = $db->query(Database::SELECT,
                    "UPDATE nj_120_jobs j SET
                        status = '".self::STATUS_DONE."',
                        finished_at = now(),
                        heartbeat_at = now(),
                        error = NULL
                     WHERE j.status = '".self::STATUS_PROCESSING."'
                       AND j.channel = ".$db->quote($channel)."
                       AND coalesce(j.heartbeat_at, j.claimed_at) < now() - ".(int) $older_sec." * interval '1 second'
                       AND EXISTS (".$sql_where.")
                     RETURNING id", FALSE);

                foreach ($done as $r)
                {
                    $id = (int) $r['id'];
                    $this->_log_event($id, 'done',
                        'Результат доставлен на сайт — задача закрыта при сверке (reconcile)');
                    $count++;
                }

                // Артефакт есть, а строка УЖЕ отказана: свипер успел её провалить
                // за секунды до доставки (типовой случай nj602 #813 — статья
                // принята через 2 с после «воркер перестал подавать признаки
                // жизни»). Результат фактически получен, поэтому возвращаем
                // честный «Готово» — иначе монитор навсегда показывает ошибку
                // там, где работа выполнена. Свежесть ограничена сутками.
                $revived = $db->query(Database::SELECT,
                    "UPDATE nj_120_jobs j SET
                        status = '".self::STATUS_DONE."',
                        finished_at = COALESCE(j.finished_at, now()),
                        heartbeat_at = now(),
                        error = NULL
                     WHERE j.status = '".self::STATUS_FAILED."'
                       AND j.channel = ".$db->quote($channel)."
                       AND j.finished_at > now() - interval '24 hours'
                       AND EXISTS (".$sql_where.")
                     RETURNING id", FALSE);

                foreach ($revived as $r)
                {
                    $id = (int) $r['id'];
                    $this->_log_event($id, 'done',
                        'Результат доставлен на сайт позже отказа свипера — статус исправлен на «Готово» (reconcile)');
                    $count++;
                }
            }
            catch (Throwable $e)
            {
                // чужой хук не должен ронять обслуживание очереди
                Kohana::$log->add(Log::WARNING, 'nj120: reconcile (:c): :e',
                    [':c' => $channel, ':e' => $e->getMessage()]);
            }
        }

        return $count;
    }

    /**
     * Освободить задачи этого воркера, оставшиеся от ПРЕДЫДУЩЕГО запуска.
     *
     * Зачем: процесс воркера могли убить (OOM, деплой, рестарт systemd) —
     * строки оставались в processing с мёртвым пульсом, и владелец видел
     * «Выполняется, пульс 20 мин» без конца. Свежий процесс при старте зовёт
     * этот метод (POST /nj120/api/release_mine) и работа возвращается в очередь
     * сразу, не дожидаясь авто-стопа по таймауту.
     *
     * Доставленные результаты при этом не теряются: перед возвратом каждой
     * строки зовётся reconcile_outcomes(), и если артефакт уже на сайте —
     * задача закрывается как 'done', а не перезапускается.
     *
     * @param  string $worker имя воркера (пусто = все воркеры этого имени нет)
     * @return int число освобождённых задач
     */
    public function release_mine($worker)
    {
        $worker = (string) $worker;
        if ($worker === '')
            return 0;

        // Артефакты, которые уже доставлены на сайт, закрываем как выполненные.
        $count = $this->reconcile_outcomes();

        $message = 'Воркер перезапущен — задача возвращена в очередь сразу '
                 . '(без ожидания таймаута)';

        // «Осиротевшей» считаем только задачу с МЁРТВЫМ пульсом: свежий пульс
        // означает, что эту задачу прямо сейчас ведёт ДРУГОЙ живой процесс с тем
        // же именем воркера (второй запуск, systemd + ручной старт). Забрать её
        // нельзя: живой обработчик получил бы отказ heartbeat, бросил работу, а
        // задача ходила бы по кругу «перезапуск → возврат в очередь → снова
        // перезапуск» (владелец видел это как «Выполняется, пульс 18 мин»).
        $alive = $this->_db->query(Database::SELECT,
            "SELECT count(*) AS n FROM nj_120_jobs
             WHERE status = '".self::STATUS_PROCESSING."'
               AND claimed_by = ".$this->_db->quote($worker)."
               AND ".$this->_pulse_sql()." >= now() - interval '120 seconds'", FALSE)->current();

        if ($alive AND (int) $alive['n'] > 0)
        {
            Kohana::$log->add(Log::WARNING,
                'release_mine: под именем воркера :w есть ЖИВЫЕ задачи (:n шт.) — похоже, запущено больше одной копии воркера; они не тронуты',
                [':w' => $worker, ':n' => (int) $alive['n']]);
        }

        // Попытки остались — задача честно перезапустится новым процессом.
        $result = $this->_db->query(Database::SELECT,
            "UPDATE nj_120_jobs SET
                status = '".self::STATUS_PENDING."',
                claimed_by = NULL,
                claimed_at = NULL,
                available_at = now(),
                error = ".$this->_db->quote($message)."
             WHERE status = '".self::STATUS_PROCESSING."'
               AND claimed_by = ".$this->_db->quote($worker)."
               AND attempts < max_attempts
               AND ".$this->_pulse_sql()." < now() - interval '120 seconds'
             RETURNING id", FALSE);

        foreach ($result as $row)
        {
            $id = (int) $row['id'];
            $this->_log_event($id, 'requeued',
                'Воркер «'.$worker.'» перезапустился — задача возвращена в очередь сразу (без ожидания таймаута)');
            $count++;
        }

        // Попытки исчерпаны — иначе вышел бы вечный цикл «воркер упал →
        // задача вернулась → снова упал». Проваливаем честно и заметно.
        // (06.10.2026: сообщение раньше склеивалось PHP-точками ВНУТРИ
        // SQL-строки — PostgreSQL получал «error = '…' . '…'» и падал
        // «ошибка синтаксиса (примерное положение: .)», воркер не мог
        // закрыть задачи прошлого запуска. Теперь — обычный quote().)
        $dead_message = 'Воркер перезапускался на этой задаче, попытки исчерпаны. '
            . 'Смотрите журнал задачи и запустите заново.';
        $dead = $this->_db->query(Database::SELECT,
            "UPDATE nj_120_jobs SET
                status = '".self::STATUS_FAILED."',
                error = ".$this->_db->quote($dead_message)."
                finished_at = now(),
                claimed_by = NULL
             WHERE status = '".self::STATUS_PROCESSING."'
               AND claimed_by = ".$this->_db->quote($worker)."
               AND attempts >= max_attempts
               AND ".$this->_pulse_sql()." < now() - interval '120 seconds'
             RETURNING id", FALSE);

        foreach ($dead as $row)
        {
            $this->_log_event((int) $row['id'], 'failed',
                'Задача снята с прошлого запуска воркера: попытки исчерпаны');
            $count++;
        }

        return $count;
    }

    /**
     * Вернуть зависшие задачи: processing без heartbeat дольше timeout_sec.
     * (Вызывать периодически — из Api::claim, монитора, cron/Minion.)
     *
     * Правила (иначе получался вечный цикл «requeue → die → requeue»
     * с попытками 80/1):
     *   - провал ТОЛЬКО при реальном молчании воркера (heartbeat/claim старше
     *     timeout*4, но не меньше 6 часов); попытки исчерпаны уточняют текст
     *     ошибки. БЕЗ проверки живости свипер убивал живые задачи с
     *     max_attempts=1 (пинки nj603: claim даёт attempts=1>=1 — любой заход
     *     в монитор «проваливал» пинок через секунду после claim, 22.09);
     *   - остальные зависшие (молчание дольше timeout) → назад в pending.
     *
     * @return int количество разобранных задач
     */
    public function requeue_stale()
    {
        $count = 0;

        // 0) Дожим доставленных результатов (HTTP-режим воркеров).
        //    В pg-режиме свипер живёт в python (PgQueue.claim → _sweep_stale),
        //    но в HTTP-режиме воркер БД не касается, и «статья создалась, а
        //    статуса Готово нет» навсегда останется processing: воркер может
        //    умереть ПОСЛЕ доставки артефакта, не успев прислать complete().
        //    Здесь по reconcile_hooks-условию (артефакт виден на сайте)
        //    зависшая строка честно закрывается в done.
        //
        //    ВАЖНО (порядок): reconcile идёт ДО авто-остановки. Иначе задача,
        //    чей результат уже лежит на сайте, сначала получала 'cancelled'
        //    от авто-стопа и только потом правилась на 'done' — в журнале
        //    оставался мусорный «отменено» (а часть каналов оставалась
        //    отменённой навсегда, если хук не срабатывал).
        $count += $this->reconcile_outcomes();

        // 0-бис) Авто-остановка застрявшего воркера.
        //    Владелец: «нет пульса 4 мин — застрявший воркер лучше автоматически
        //    останавливать». Живой обработчик подаёт пульс каждые 30 с, поэтому
        //    молчание дольше нескольких минут = воркер умер или заблокирован.
        //    Такой сбор переводим в 'cancelled' (НЕ failed): пользователь видит
        //    «Отменено» + этапы, найденное сохраняется, а сбор можно запустить
        //    заново. Важно: поздняя доставка результата всё равно долетит и будет
        //    подхвачена reconcile_outcomes() — он уже отработал выше, поэтому
        //    на следующем проходе (через 10 минут) строка вернётся в 'done'.
        $count += $this->auto_stop_silent();

        // 0-тер) «Пульс есть, работы нет»: обработчик жив (heartbeat идёт
        //      фоновым циклом), но прогресс не обновлялся дольше порога —
        //      пользователь видел вечный спиннер с застывшим этапом.
        $count += $this->auto_stop_stalled();

        // 0-четвёртое) АБСОЛЮТНЫЙ предел возраста задачи. Пульс может идти
        //     (воркер другой версии, живой heartbeat-цикл при зацикленном
        //     обработчике) — и тогда ни одно правило выше не срабатывает, а
        //     строка висит в «Выполняется» бесконечно: ровно то, что владелец
        //     видел как «монитор ВСЕГДА показывает зависший процесс» (28.09).
        //     Порог: три таймаута задачи, но не меньше N2J_JOB_MAX_AGE_SEC
        //     (по умолчанию 3600 с); отрицательное значение выключает правило.
        if ($this->max_age_sec() > 0)
        {
            $count += $this->_rule('правило 0 (предельный возраст)',
                "UPDATE nj_120_jobs SET
                    status = '".self::STATUS_CANCELLED."',
                    error = 'Задача шла дольше ' || (GREATEST(timeout_sec * 3, ".(int) $this->max_age_sec().") / 60)
                          || ' мин — закрыта автоматически, чтобы не висела вечно. Запустите заново.',
                    finished_at = now(),
                    claimed_by = NULL
                 WHERE status = '".self::STATUS_PROCESSING."'
                   AND coalesce(started_at, claimed_at, created_at) < now()
                       - GREATEST(timeout_sec * 3, ".(int) $this->max_age_sec().") * interval '1 second'
                 RETURNING id", FALSE,
                'cancelled', 'Задача шла слишком долго — закрыта по предельному возрасту');
        }

        // 1) провал ТОЛЬКО при реальном молчании воркера (не resurrect).
        //    Нижний порог LEAST(timeout*8, 6 ч): пинки с timeout 600 c падают
        //    через ~80 мин, если воркер мёртв, а не немедленно. ВАЖНО: условия
        //    `attempts >= max_attempts` здесь НЕТ намеренно — для max_attempts=1
        //    (пинки nj603) оно истинно сразу после claim (attempts=1>=1) и
        //    любая задача с одной попыткой «сгорала» при первом же проходе.
        // Правило в своём try/catch: одно упавшее не отключает остальные
        // (раньше исключение в середине оставляло разбор незавершённым).
        $count += $this->_rule('правило 1 (молчание дольше timeout)',
            "UPDATE nj_120_jobs SET
                status = '".self::STATUS_FAILED."',
                error = CASE WHEN attempts >= max_attempts
                             THEN 'Воркер молчал дольше timeout — попытки исчерпаны'
                             ELSE 'Воркер молчал слишком долго — задача провалена' END,
                finished_at = now(),
                claimed_by = NULL
             WHERE status = '".self::STATUS_PROCESSING."'
               AND ".$this->_pulse_sql()." < now() - GREATEST(timeout_sec * 4, LEAST(timeout_sec * 8, 21600)) * interval '1 second'
             RETURNING id", FALSE,
            'failed', 'Зависшая задача провалена (воркер молчал)');

        // 2) свежезависшие с запасом попыток — назад в очередь.
        //    Порог timeout*2 (мин. 15 мин): у воркера всегда есть шанс
        //    «задышать» после длинного тихого этапа (генерация ждёт видеопамять).
        //    ВАЖНО: строки с исчерпанными попытками сюда НЕ попадают — claim их
        //    не возьмёт (attempts < max_attempts), и такая задача зависла бы в
        //    «Ожидает» навсегда. Их разбирает правило 2а (терминальный failed).
        // Правило в своём try/catch: одно упавшее не отключает остальные
        // (раньше исключение в середине оставляло разбор незавершённым).
        $count += $this->_rule('правило 2 (возврат в очередь)',
            "UPDATE nj_120_jobs SET
                status = '".self::STATUS_PENDING."',
                claimed_by = NULL,
                claimed_at = NULL,
                available_at = now()
             WHERE status = '".self::STATUS_PROCESSING."'
               AND attempts < max_attempts
               AND ".$this->_pulse_sql()." < now() - GREATEST(timeout_sec * 2, 900) * interval '1 second'
             RETURNING id", FALSE,
            'failed', 'Воркер молчит дольше timeout*2 — задача возвращена в очередь');

        // 2а) задача «молчит», а её воркер исчерпал попытки (max_attempts=1 у
        //     пинков nj603) и продолжает занимать слот обработки — ранний отказ
        //     БЕЗ роста attempts: следующий cron поставит пинок заново, а не
        //     оставит строку processing висеть вечно.
        //     УБРАНА проверка «жив ли воркер» (120-с окно чужих задач): живой
        //     воркер в claim-цикле вечно освежает это окно, и терминально
        //     закончившаяся задача с max_attempts=1 никогда не уходила в
        //     failed — «источники не нашлись» висела processing вечно, а
        //     монитор рисовал «Выполняется». Теперь критерий — только пробел
        //     в heartbeat САМОЙ задачи: обработчик, который ещё реально
        //     работает, ставит пульс чаще интервала heartbeat (30 с), поэтому
        //     живая задача под правило не попадает.
        //     Порог GREATEST(timeout*2, 900) — как у правила 2: пока задача
        //     имела шанс вернуться в очередь, отказывать её нельзя. Длинные
        //     конвейеры (nj602/nj607: 10-20 мин поиска и LLM без промежуточных
        //     рапортов) иначе получали «Ошибку» за секунды до доставки
        //     результата, и «Готово» не приходило никому.
        // Правило в своём try/catch: одно упавшее не отключает остальные
        // (раньше исключение в середине оставляло разбор незавершённым).
        $count += $this->_rule('правило 2а (отказ исчерпанных)',
            "UPDATE nj_120_jobs SET
                status = '".self::STATUS_FAILED."',
                error = 'Воркер перестал подавать признаки жизни — задача отказана (попытки исчерпаны)',
                finished_at = now(),
                claimed_by = NULL
             WHERE status = '".self::STATUS_PROCESSING."'
               AND attempts >= max_attempts
               AND ".$this->_pulse_sql()." < now() - GREATEST(timeout_sec * 2, 900) * interval '1 second'
             RETURNING id", FALSE,
            'failed', 'Воркер не подавал признаков жизни — задача отказана без роста попыток');

        // 3) «Ожидает» навсегда. pending-строка не может быть взята, если
        //    attempts >= max_attempts (claim требует attempts < max_attempts) —
        //    её никто и никогда не заберёт; а если воркер не забрал строку
        //    часами, значит очередь/воркер недоступны. И то и другое раньше
        //    висело в мониторе вечным ожиданием без результата.
        // Правило в своём try/catch: одно упавшее не отключает остальные
        // (раньше исключение в середине оставляло разбор незавершённым).
        $count += $this->_rule('правило 3 (pending-тупик)',
            "UPDATE nj_120_jobs SET
                status = '".self::STATUS_FAILED."',
                error = CASE WHEN attempts >= max_attempts
                             THEN 'Попытки исчерпаны — задача не может быть взята воркером'
                             ELSE 'Воркер не забрал задачу из очереди (очередь недоступна?) — запустите заново' END,
                finished_at = now(),
                claimed_by = NULL
             WHERE status = '".self::STATUS_PENDING."'
               AND coalesce(available_at, created_at) < now() - (CASE WHEN attempts >= max_attempts
                                                                    THEN GREATEST(timeout_sec * 2, 1800)
                                                                    ELSE 43200 END) * interval '1 second'
             RETURNING id", FALSE,
            'failed', 'Задача не была взята воркером — закрыта, чтобы не висела вечно');

        if ($count > 0)
        {
            $this->_notify(NULL);
        }

        return $count;
    }

    /**
     * Самообслуживание очереди БЕЗ крона: дешёвая проверка на любом заходе.
     *
     * Почему так: requeue_stale() раньше звали только cron «Пиналки»
     * (/nj603/kick, расписание maintenance раз в 10 минут) и открытие
     * /nj120/monitor или /nj120/task/<id>. Если cron на хостинге не установлен,
     * а монитор никто не открывает, зависшая строка висит в processing сколько
     * угодно — это и есть жалоба владельца «монитор воркеров ВСЕГДА показывает
     * зависший процесс» (28.09). Теперь очередь разбирает себя при первом же
     * заходе на любую страницу сайта.
     *
     * Стоимость: один COUNT по индексу (status, …) — если зависших нет,
     * выходим сразу и ничего не пишем. Если есть — берём advisory-lock
     * PostgreSQL, чтобы из десятка одновременных заходов уборку провёл ровно
     * один, и зовём requeue_stale(). Любой сбой глушится: страница не имеет
     * права ломаться из-за уборки очереди.
     *
     * @param int|NULL $older_sec порог «мёртвого пульса» (по умолчанию silent floor)
     * @return int сколько задач разобрано (0 — нечего было делать)
     */
    public function maybe_maintain($older_sec = NULL)
    {
        $older_sec = $older_sec !== NULL ? (int) $older_sec : $this->silent_stop_sec();

        try
        {
            $row = $this->_db->query(Database::SELECT,
                "SELECT count(*) AS c FROM nj_120_jobs
                 WHERE status = '".self::STATUS_PROCESSING."'
                   AND ".$this->_pulse_sql()." < now() - ".(int) $older_sec." * interval '1 second'", FALSE)->current();

            if ( ! $row OR (int) $row['c'] === 0)
            {
                return 0;
            }
        }
        catch (Throwable $e)
        {
            // таблицы ещё нет / БД недоступна — это не дело страницы
            return 0;
        }

        try
        {
            // Ключ advisory-lock: свои 120120 (модуль nj120).
            $lock = $this->_db->query(Database::SELECT,
                'SELECT pg_try_advisory_lock(120120) AS locked', FALSE)->current();

            if (empty($lock) OR ! in_array((string) $lock['locked'], ['1', 't', 'true'], TRUE))
            {
                return 0;   // уборку уже проводит другой запрос — не мешаем
            }

            try
            {
                $n = $this->requeue_stale();

                if ($n > 0)
                {
                    Kohana::$log->add(Log::INFO, 'nj120: очередь разобрала зависшие задачи сама: :n', [':n' => $n]);
                }

                return $n;
            }
            finally
            {
                $this->_db->query(Database::SELECT, 'SELECT pg_advisory_unlock(120120)', FALSE);
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj120: авто-обслуживание очереди: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Предельный возраст задачи в processing (секунд).
     *
     * Три таймаута самой задачи, но не меньше N2J_JOB_MAX_AGE_SEC (3600 с по
     * умолчанию). Задача, которая «жива пульсом», но идёт дольше, закрывается
     * автоматически: иначе один зацикленный или устаревший воркер держит
     * строку в «Выполняется» вечно. Отрицательное значение — правило выключено.
     */
    public function max_age_sec()
    {
        $raw = getenv('N2J_JOB_MAX_AGE_SEC');
        $raw = ($raw === FALSE OR $raw === '') ? NULL : (int) $raw;

        if ($raw !== NULL AND $raw < 0)
        {
            return 0;
        }

        return $raw ? $raw : 3600;
    }

    /**
     * Выполнить ОДНО правило уборки очереди: свои try/catch и журнал.
     *
     * Зачем: раньше requeue_stale() был линейным списком UPDATE-ов без
     * обработки ошибок — исключение в середине (например, из-за отсутствующей
     * таблицы хука reconcile) оставляло остальные проверки невыполненными, и
     * зависшие строки жили до следующего удачного захода. Теперь падение одного
     * правила только пишется в лог.
     *
     * @param  string $name    имя правила (для лога)
     * @param  string $sql     UPDATE … RETURNING id
     * @param  bool   $as      третий аргумент query() (как в остальном сервисе)
     * @param  string $event   событие журнала задачи (NULL — не писать)
     * @param  string $message текст события
     * @return int сколько строк разобрано
     */
    protected function _rule($name, $sql, $as = FALSE, $event = NULL, $message = NULL)
    {
        try
        {
            $rows = $this->_db->query(Database::SELECT, $sql, $as);
            $n = 0;

            foreach ($rows as $row)
            {
                $n++;

                if ($event !== NULL)
                {
                    $this->_log_event((int) $row['id'], (string) $event, (string) $message);
                }
            }

            return $n;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj120: правило уборки «:r»: :e',
                [':r' => (string) $name, ':e' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * SQL-выражение «пульса» задачи — ОДНО место для всех проверок живости.
     *
     * Почему не голый heartbeat_at: монитор показывал «застряли: 1» для строки
     * с ПУСТЫМ пульсом (heartbeat_at IS NULL), но ни одно правило уборки её не
     * видело — `NULL < now() - …` = NULL = «не подходит». Такая задача висела в
     * processing вечно, и кнопка «Прервать зависшие» её тоже не брала
     * (stale_count считал по голому heartbeat_at). Проверено 28.09 на локальном
     * PostgreSQL: задача semantic.index 3/3 с пустым пульсом переживала и
     * сайтовый, и питоновский свипер.
     *
     * Фолбэки heartbeat_at → claimed_at → started_at → created_at (NOT NULL по
     * схеме) гарантируют: без присмотра не остаётся ни одна строка.
     */
    protected function _pulse_sql($alias = '')
    {
        $p = $alias !== '' ? (string) $alias.'.' : '';

        return 'coalesce('.$p.'heartbeat_at, '.$p.'claimed_at, '.$p.'started_at, '.$p.'created_at)';
    }

    /**
     * Авто-остановка задач, чей воркер замолчал.
     *
     * Порог: GREATEST(N2J_SILENT_STOP_SEC (по умолчанию 240 c), timeout_sec/4) —
     * heartbeat идёт раз в 30 с, поэтому такое молчание означает мёртвый или
     * заблокированный воркер. Статус — cancelled с понятной причиной: страница
     * гасит спиннер, показывает этапы и предлагает запустить сбор заново.
     *
     * @param int $job_id 0 — пройти по всем задачам (свипер), иначе только эта
     * @return int сколько остановлено
     */
    /**
     * Порог «воркер молчит» — единственное место, где он задан (секунд).
     *
     * Пульс задачи подаётся каждые 30 с (фоновый цикл воркера), поэтому
     * молчание дольше нескольких минут означает мёртвый или замороженный
     * обработчик. Модули (nj602/nj605/nj606) сравнивают свой silent_sec с этим
     * значением — раньше в каждом месте было своё «240», и правки расходились.
     *
     * С 06.10.2026 канал может поднять себе порог: config nj120_jobs →
     * silent_stop_channels (nj661.airadar — конвейер с локальной LLM:
     * генерация занимает минуты, общий порог снимал живую задачу).
     * Эффективный порог канала = max(общий env-порог, значение канала).
     *
     * @param  string $channel канал или NULL — общий порог
     * @return int
     */
    public function silent_stop_sec($channel = NULL)
    {
        $floor = (int) getenv('N2J_SILENT_STOP_SEC');
        $floor = $floor > 0 ? $floor : 240;

        $channel = (string) $channel;

        if ($channel !== '')
        {
            $map = $this->silent_stop_channels();

            if (isset($map[$channel]))
            {
                $floor = max($floor, (int) $map[$channel]);
            }
        }

        return $floor;
    }

    /**
     * Каналы с особым порогом молчания: config nj120_jobs.silent_stop_channels.
     *
     * @return array канал => секунды (мусор отфильтрован)
     */
    public function silent_stop_channels()
    {
        try
        {
            $map = (array) Kohana::$config->load('nj120_jobs.silent_stop_channels');
        }
        catch (Throwable $e)
        {
            return [];
        }

        $out = [];

        foreach ($map as $channel => $sec)
        {
            $channel = (string) $channel;
            $sec     = (int) $sec;

            if ($channel !== '' AND $sec > 0)
            {
                $out[$channel] = $sec;
            }
        }

        return $out;
    }

    public function auto_stop_silent($job_id = 0)
    {
        // ВАЖНО: порог — РОВНО этот floor, без «или timeout_sec/4» (было
        // GREATEST(floor, timeout_sec/4)). Для каналов с длинным таймаутом
        // (nj606.leads: 900 с → 225 с, но у nj602/др. доходило до 15-25 мин)
        // вторая половина условия отодвигала остановку далеко за обещанные
        // 4 минуты: владелец видел «нет пульса 10 мин» и живой спиннер, хотя
        // воркер давно молчал. Пульс не зависит от длины задачи, поэтому
        // порог не должен зависеть от timeout_sec.
        // Каналы из silent_stop_channels идут отдельным проходом со СВОИМ
        // порогом (06.10.2026, nj661: локальная LLM считает минуты — общий
        // порог в 4 минуты снимал живую задачу, хотя прогон доходил до конца).
        $special = $this->silent_stop_channels();

        // Одна конкретная задача — её каналом и её порогом (так зовут
        // страницы модулей: «прервать зависшее»).
        if ((int) $job_id > 0)
        {
            $row     = $this->_db->query(Database::SELECT,
                'SELECT channel FROM nj_120_jobs WHERE id = '.(int) $job_id, FALSE)->current();
            $channel = $row ? (string) $row['channel'] : '';

            return $this->_stop_silent($this->silent_stop_sec($channel !== '' ? $channel : NULL),
                ' AND id = '.(int) $job_id);
        }

        $count = $this->_stop_silent($this->silent_stop_sec(),
            $special
                ? ' AND channel NOT IN ('.implode(',', array_map([$this->_db, 'quote'], array_keys($special))).')'
                : '');

        foreach ($special as $channel => $sec)
        {
            $count += $this->_stop_silent($this->silent_stop_sec($channel),
                ' AND channel = '.$this->_db->quote($channel));
        }

        return $count;
    }

    /**
     * Один проход авто-остановки молчащих: перевести в cancelled.
     *
     * @param  int    $floor_sec   порог молчания, секунд
     * @param  string $where_extra доп. условие (канал или конкретная задача)
     * @return int    сколько остановлено
     */
    protected function _stop_silent($floor_sec, $where_extra = '')
    {
        $floor_sec = (int) $floor_sec;

        // ВАЖНО: минуты считает PHP и подставляет ЧИСЛО. Раньше эти вызовы
        // (`max(1, (int) round($floor / 60))`) стояли внутри двойной кавычки —
        // PHP-код уходил в SQL текстом, запрос падал с синтаксической ошибкой, и
        // авто-остановка молчащих задач НЕ РАБОТАЛА ВООБЩЕ (ошибка глушилась
        // catch-ом и писалась только в лог). Это и есть вторая половина ответа
        // на «почему монитор всегда показывает зависший процесс» (28.09).
        $floor_min = max(1, (int) round($floor_sec / 60));

        try
        {
            $rows = $this->_db->query(Database::SELECT,
                "UPDATE nj_120_jobs SET
                    status = '".self::STATUS_CANCELLED."',
                    error = 'Воркер не отвечал больше ".(int) $floor_min." мин — сбор остановлен автоматически. Найденное сохранено, запустите заново.',
                    finished_at = now(),
                    claimed_by = NULL
                 WHERE status = '".self::STATUS_PROCESSING."'
                   AND ".$this->_pulse_sql()." < now() - ".(int) $floor_sec." * interval '1 second'".((string) $where_extra)."
                 RETURNING id", FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj120: авто-остановка молчащих задач: :e', [':e' => $e->getMessage()]);

            return 0;
        }

        $n = 0;

        foreach ($rows as $row)
        {
            $this->_log_event((int) $row['id'], 'cancelled', 'Воркер замолчал — сбор остановлен автоматически');
            $n++;
        }

        if ($n > 0)
        {
            $this->_notify(NULL);
        }

        return $n;
    }

    /**
     * Порог «прогресс не обновлялся» — для задач, которые ПОДАЮТ пульс, но
     * стоят на одном месте (фоновый heartbeat воркера живёт отдельным циклом и
     * молчит только при мёртвом/замороженном обработчике: там срабатывает
     * порог молчания выше). Здесь ловим второй случай — «пульс есть, работы
     * нет»: длинный шаг без единого рапорта о ходе (владелец, /leads:
     * «ИИ извлекает компании источников: 3» — и тишина 10 минут).
     */
    public function stalled_progress_sec()
    {
        $sec = (int) getenv('N2J_STALLED_PROGRESS_SEC');

        return $sec > 0 ? $sec : 600;
    }

    /**
     * Погасить задачи, у которых прогресс не обновлялся дольше порога.
     *
     * В отличие от auto_stop_silent() смотрит не на heartbeat_at (его ставит
     * фоновый цикл воркера, даже когда обработчик застрял), а на время
     * последнего события задачи: рапорт о ходе, захват или постановку.
     * Возвращает число закрытых задач; идемпотентно, дешёвый UPDATE.
     */
    public function auto_stop_stalled($job_id = 0, $silence_sec = NULL)
    {
        $sec   = $silence_sec !== NULL ? (int) $silence_sec : $this->stalled_progress_sec();
        $where = (int) $job_id > 0 ? ' AND j.id = '.(int) $job_id : '';

        // Минуты считает PHP (см. комментарий в auto_stop_silent): строка
        // «... '.max(1, ...).' ...» внутри SQL делала запрос невалидным.
        $sec_min = max(1, (int) round($sec / 60));

        try
        {
            $rows = $this->_db->query(Database::SELECT,
                "UPDATE nj_120_jobs j SET
                    status = '".self::STATUS_CANCELLED."',
                    error = 'Воркер не выходил на связь больше ".(int) $sec_min." мин — сбор остановлен автоматически. Найденное сохранено, запустите заново.',
                    finished_at = now(),
                    claimed_by = NULL
                 WHERE j.status = '".self::STATUS_PROCESSING."'
                   AND COALESCE(
                         (SELECT max(e.created_at) FROM nj_120_job_events e WHERE e.job_id = j.id),
                         j.claimed_at, j.created_at
                       ) < now() - ".(int) $sec." * interval '1 second'".$where."
                 RETURNING j.id", FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj120: авто-остановка застрявших задач: :e', [':e' => $e->getMessage()]);

            return 0;
        }

        $n = 0;

        foreach ($rows as $row)
        {
            $this->_log_event((int) $row['id'], 'cancelled', 'Прогресс не обновлялся — сбор остановлен автоматически');
            $n++;
        }

        if ($n > 0)
        {
            $this->_notify(NULL);
        }

        return $n;
    }

    /**
     * Прервать ВСЕ зависшие processing (без пульса дольше $older_sec) —
     * кнопка «Прервать зависшие» в мониторе: чистит зомби одним кликом.
     * @return int сколько прервано
     */
    public function kill_stale($older_sec = 1800)
    {
        $rows = $this->_db->query(Database::SELECT,
            "UPDATE nj_120_jobs SET
                status = '".self::STATUS_CANCELLED."',
                error = 'Прервана администратором: пульс задачи молчит дольше ".(int) round($older_sec / 60)." мин',
                finished_at = now(),
                claimed_by = NULL
             WHERE status = '".self::STATUS_PROCESSING."'
               AND ".$this->_pulse_sql()." < now() - ".(int) $older_sec." * interval '1 second'
             RETURNING id", FALSE);

        foreach ($rows as $row)
        {
            $this->_log_event((int) $row['id'], 'cancelled', 'Прервана администратором (зависшая)');
        }

        return count($rows);
    }

    /** Сколько сейчас зависших processing (для красной кнопки в мониторе). */
    public function stale_count($older_sec = 1800)
    {
        $row = $this->_db->query(Database::SELECT,
            "SELECT COUNT(*) AS cnt FROM nj_120_jobs
             WHERE status = '".self::STATUS_PROCESSING."'
               AND ".$this->_pulse_sql()." < now() - ".(int) $older_sec." * interval '1 second'", FALSE)->current();

        return empty($row) ? 0 : (int) $row['cnt'];
    }

    // ---------------------------------------------------------------------
    // Внутреннее
    // ---------------------------------------------------------------------

    protected function _update_status($job_id, $worker, array $fields, array $event = [])
    {
        // $fields может быть пуст (heartbeat) — базовый "heartbeat_at = now()" есть всегда
        $sets = ["heartbeat_at = now()"];
        foreach ($fields as $col => $val)
        {
            $sets[] = $col.' = '.$val; // значения уже квотированы вызывающим кодом
        }

        $sql = "UPDATE nj_120_jobs SET ".implode(', ', $sets)."
                WHERE id = ".(int) $job_id."
                  AND claimed_by = ".$this->_db->quote((string) $worker)."
                  AND status IN ('".self::STATUS_PROCESSING."','".self::STATUS_PENDING."')";
        // PostgreSQL возвращает число обновлённых строк: > 0 = задача найдена и обновлена.
        $rows = $this->_db->query(Database::UPDATE, $sql, FALSE);

        $ok = (is_numeric($rows) && (int) $rows > 0);
        if ($ok && ! empty($event))
        {
            $this->_log_event((int) $job_id,
                isset($event['event']) ? $event['event'] : 'progress',
                isset($event['message']) ? $event['message'] : NULL);
        }
        return $ok;
    }

    protected function _log_event($job_id, $event, $message = NULL, array $meta = [])
    {
        try
        {
            $sql = "INSERT INTO nj_120_job_events (job_id, event, message, meta)
                    VALUES (".(int) $job_id.", "
                            .$this->_db->quote((string) $event).", "
                            .($message !== NULL ? $this->_db->quote((string) $message) : 'NULL').", "
                            .$this->_db->quote($this->_safe_json($meta)).")";
            $this->_db->query(Database::INSERT, $sql, FALSE);
        }
        catch (Exception $e)
        {
            // журнал не должен ломать основную работу очереди
            Kohana::$log->add(Log::ERROR, 'nj120: не удалось записать событие: :e',
                [':e' => $e->getMessage()]);
        }
    }

    /**
     * JSON, гарантированно валидный для PostgreSQL jsonb.
     *
     * json_encode() возвращает FALSE, если в строке результата/мете есть
     * повреждённый UTF-8 (вёрстка внешних страниц, обрывки LLM-вывода). Тогда
     * quote(FALSE) давал '0', и `UPDATE nj_120_jobs SET result = '0'` против jsonb
     * падал с 500 — complete()/fail() молча отваливались, а задача вечно висела
     * processing, хотя статья/артефакт были уже доставлены на сайт.
     * Здесь невалидные байты подменяются U+FFFD — запись доходит всегда.
     */
    protected function _safe_json($value)
    {
        $j = json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($j !== FALSE)
        {
            return $j;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Пробуждение воркеров (best effort; опрос остаётся fallback'ом).
     */
    protected function _notify($channel)
    {
        $config = Kohana::$config->load('nj120_jobs');
        if (empty($config['notify_enabled']))
            return;

        try
        {
            $payload = $this->_safe_json(['channel' => $channel]);
            $this->_db->query(Database::UPDATE,
                "SELECT pg_notify('".self::NOTIFY_CHANNEL."', ".$this->_db->quote($payload).")", FALSE);
        }
        catch (Exception $e)
        {
            // молча: воркеры в любом случае опрашивают очередь
        }
    }

    protected function _channel_of($job_id)
    {
        $row = $this->get($job_id);
        return $row ? $row['channel'] : NULL;
    }

    /** user_id инициатора (для уведомлений erp217) или NULL. */
    protected function _owner_of($job_id)
    {
        $row = $this->get($job_id);

        return ($row AND ! empty($row['created_by'])) ? (int) $row['created_by'] : NULL;
    }

    /**
     * HTTP-колбэк по завершении задачи (best effort).
     * Вызывается ТОЛЬКО если у задачи указан callback_url.
     */
    protected function _callback($job_id, $status, $result, $error)
    {
        $row = $this->get($job_id);
        if (empty($row['callback_url']))
            return;

        try
        {
            $data = [
                'job_id' => (int) $job_id,
                'channel'=> $row['channel'],
                'status' => $status,
                'result' => $result,
                'error'  => $error,
            ];

            // CurlRequest (модуль llm): статический POST с JSON-телом.
            CurlRequest::post($row['callback_url'], $data, [], 10);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj120: callback не удался для задачи :id: :e',
                [':id' => $job_id, ':e' => $e->getMessage()]);
        }
    }

    /**
     * Нормализация строки задачи для потребителя: jsonb -> массивы.
     */
    protected function _normalize(array $row)
    {
        foreach (['payload', 'result'] as $col)
        {
            if (isset($row[$col]) && is_string($row[$col]))
            {
                $row[$col] = json_decode($row[$col], TRUE);
            }
        }
        return $row;
    }
}
