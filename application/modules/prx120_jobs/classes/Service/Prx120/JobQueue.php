<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Очередь фоновых задач (модуль PRX-120).
 *
 * Единственная точка работы с prx_120_jobs из PHP. Модули НЕ пишут в таблицу
 * напрямую — только через этот сервис:
 *
 *   // поставить задачу
 *   $id = Service_Prx120_JobQueue::push('embeddings.index', ['ids' => [1,2,3]]);
 *
 *   // с опциями
 *   $id = Service_Prx120_JobQueue::push('llm.summarize', $data, [
 *       'delay_sec' => 60, 'priority' => 10, 'max_attempts' => 5,
 *       'module' => 'prx401', 'callback_url' => 'https://.../prx401/api/job_cb',
 *   ]);
 *
 * Воркеры (python, папка workers/) забирают задачи либо напрямую из PostgreSQL,
 * либо через HTTP-контроллер Controller_Prx120_Api (токен WORKER_API_TOKEN).
 *
 * Конкурентность: захват задачи — UPDATE ... WHERE id IN
 *   (SELECT id ... FOR UPDATE SKIP LOCKED), поэтому несколько воркеров
 *   не схватят одну задачу дважды. Пробуждение — pg_notify('prx120_jobs').
 */
class Service_Prx120_JobQueue {

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE       = 'done';
    const STATUS_FAILED     = 'failed';
    const STATUS_CANCELLED  = 'cancelled';

    /** Канал NOTIFY для пробуждения воркеров */
    const NOTIFY_CHANNEL = 'prx120_jobs';

    // Обслуживание: сверка исходов, возврат сирот, авто-остановки, уборка.
    use Service_Prx120_JobQueue_Maintenance;
    // Монитор: здоровье контура и диагноз конкретной задачи.
    use Service_Prx120_JobQueue_Monitor;

    /** @var Database */
    protected $_db;

    /** @var string */
    protected $_db_group;

    public function __construct($db_group = NULL)
    {
        $this->_db_group = $db_group !== NULL
            ? $db_group
            : Kohana::$config->load('prx120_jobs.db_group');
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

        // Бюджет токенов владельца (prx120/006): задача от человека с
        // исчерпанным дневным/месячным бюджетом в очередь не встаёт.
        // Служебные задачи без visitor_id и вызовы с 'budget' => FALSE
        // (например, повтор администратором) не проверяются.
        $visitor = isset($options['visitor_id']) ? trim((string) $options['visitor_id']) : '';

        if ($visitor !== '' AND ( ! isset($options['budget']) OR $options['budget'] !== FALSE)
            AND class_exists('Service_Prx120_Budget'))
        {
            $verdict = Service_Prx120_Budget::check($visitor);

            if ( ! $verdict['ok'])
            {
                Kohana::$log->add(Log::NOTICE, 'prx120 budget: отказ :c для :v', [':c' => $channel, ':v' => $visitor]);

                throw new Prx120_Budget_Exception($verdict['message'], $verdict['state']);
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

        $sql = "INSERT INTO prx_120_jobs
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
     * Зачем: модульные панели (первым — /prx115) показывают последние задачи
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
        $sql = "UPDATE prx_120_jobs SET
                    status = '".self::STATUS_PROCESSING."',
                    claimed_by = ".$this->_db->quote((string) $worker).",
                    claimed_at = now(),
                    heartbeat_at = now(),
                    started_at = COALESCE(started_at, now()),
                    error = NULL,
                    attempts = attempts + 1
                WHERE id = (
                    SELECT id FROM prx_120_jobs
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
     * heartbeat_at и пишет событие 'progress' в журнал prx_120_job_events.
     * Сбой журнала не считается провалом (пульс всё равно обновился).
     */
    public function progress($job_id, $worker, $note = NULL)
    {
        $ok = $this->heartbeat($job_id, $worker, $note);

        if ($ok AND $note !== NULL AND trim((string) $note) !== '')
        {
            $this->_log_event((int) $job_id, 'progress', (string) $note);
        }

        return (bool) $ok;
    }

    /**
     * Дожим «результат ДОСТАВЛЕН на сайт, но очередь не закрыта».
     *
     * Единая точка для приёмок модулей (prx591/prx598/prx602/prx607/prx639…): если
     * обработчик фактически отдал артефакт, задача обязана стать «Готово», а не
     * остаться processing и не превратиться в failed по таймауту пульса.
     *
     * Почему нельзя обойтись обычным complete(): воркер присылает его со своим
     * именем (claimed_by), а приёмка сайта — не воркер, и задача к этому моменту
     * может быть:
     *   * processing — обычный случай (воркер ещё не успел complete);
     *   * pending    — свипер вернул её в очередь (воркер «молчал»);
     *   * failed     — свипер отказал её за секунды/минуты до доставки
     *                  (именно так статья prx602 #813 была принята через 2 с
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
                'UPDATE prx_120_jobs SET '.implode(', ', $sets).'
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
            Kohana::$log->add(Log::ERROR, 'prx120: mark_delivered #:i: :e',
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
                "UPDATE prx_120_jobs SET
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
            Kohana::$log->add(Log::ERROR, 'prx120: mark_failed_from_site #:i: :e',
                [':i' => $job_id, ':e' => $e->getMessage()]);

            return FALSE;
        }
    }

    /**
     * Задача выполнена успешно.
     */
    public function complete($job_id, $worker, $result = NULL, array $usage = NULL)
    {
        // Нельзя завершать обработку, которую уже захватил другой воркер.
        $claimed = $this->get($job_id);
        if ($claimed !== NULL AND $claimed['status'] === self::STATUS_PROCESSING
            AND (string) $claimed['claimed_by'] !== (string) $worker)
        {
            return FALSE;
        }
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
            Kohana::$log->add(Log::ERROR, 'prx120: complete #:i (первая запись): :e',
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
            if ($status === self::STATUS_PROCESSING AND (string) $row['claimed_by'] !== (string) $worker) { return FALSE; }

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
            $changed = $this->_db->query(Database::UPDATE,
                "UPDATE prx_120_jobs SET
                    status = '".self::STATUS_DONE."',
                    result = ".$this->_db->quote($this->_safe_json($result)).",
                    finished_at = now(),
                    error = NULL,
                    heartbeat_at = now(),
                    claimed_by = COALESCE(claimed_by, ".$this->_db->quote((string) $worker).")
                 WHERE id = ".(int) $job_id."
                   AND (status = '".self::STATUS_PENDING."'
                        OR (status = '".self::STATUS_PROCESSING."'
                            AND claimed_by = ".$this->_db->quote((string) $worker)."))", FALSE);

            // Проверка владельца обязана быть в самом UPDATE: между get() и
            // записью строку может захватить другой воркер или отменить админ.
            if ( ! is_numeric($changed) OR (int) $changed === 0) return FALSE;
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

            // prx597: уведомление владельцу о готовности. НАЙДЕНО 21.09: хук был
            // только в fail() — «Готово: …» не приходило никому и никогда.
            if (class_exists('Service_Prx597_Notify'))
            {
                try
                {
                    $row597 = $this->get($job_id);

                    if ($row597 !== NULL)
                    {
                        Service_Prx597_Notify::job_update((array) $row597, 'done');
                    }
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'prx597: notify done: :e', [':e' => $e->getMessage()]);
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
                // владелец задачи — адресат уведомления (шина prx208 → слушатели/prx597)
                $owner = $this->_owner_of($job_id);
                if ($owner !== NULL)
                {
                    $payload['target_user_id'] = $owner;
                    $payload['force_notification'] = TRUE; // воркер — не инициатор, но Auth в HTTP-контексте пуст
                }
                // шина prx208 (журнал + слушатели) через фасад; инициатор — система:
                // событие публикует воркер, а не владелец задачи
                Event::publish('prx120.job_done', $payload);
            }
            catch (Exception $e)
            {
                Kohana::$log->add(Log::ERROR, 'prx120: событие job_done не сработало: :e', [':e' => $e->getMessage()]);
            }
            $this->_callback($job_id, self::STATUS_DONE, $result, NULL);
        }

        // Последний рубеж: строка обязана быть done. Если «богатая» запись не
        // сработала (result слишком велик/повреждён, гонка со свипером) —
        // закрываем минимальным UPDATE. Без этого монитор вечно показывает
        // «Выполняется» при уже доставленной на сайт статье (prx602).
        if ( ! $ok)
        {
            $ok = $this->_finalize_force($job_id, $worker, self::STATUS_DONE,
                'не удалась обычная запись complete() — результат уже доставлен на сайт');
        }

        return $ok;
    }

    /**
     * Гарантированный terminal-дожим: строка не должна остаться processing.
     *
     * Применяется, когда обычная запись complete()/fail() не сработала
     * (вернула ok=FALSE или упала): пишем минимальный итог по id и владельцу,
     * без result (объёмные/повреждённые payload'ы тут не участвуют — нечему
     * ронять UPDATE). Идемпотентно: done/failed/cancelled не трогаем.
     */
    protected function _finalize_force($job_id, $worker, $status, $message)
    {
        try
        {
            $rows = $this->_db->query(Database::UPDATE,
                "UPDATE prx_120_jobs SET
                    status = ".$this->_db->quote((string) $status).",
                    finished_at = now(),
                    heartbeat_at = now(),
                    error = COALESCE(error, ".$this->_db->quote(mb_substr((string) $message, 0, 500)).")
                 WHERE id = ".(int) $job_id."
                   AND claimed_by = ".$this->_db->quote((string) $worker)."
                   AND status = '".self::STATUS_PROCESSING."'", FALSE);

            if (is_numeric($rows) && (int) $rows > 0)
            {
                $this->_log_event((int) $job_id, (string) $status, 'Задача закрыта принудительно: '.(string) $message);
                return TRUE;
            }

            return FALSE;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'prx120: force-finalize #:i: :e',
                [':i' => (int) $job_id, ':e' => $e->getMessage()]);
            return FALSE;
        }
    }

    /**
     * Учёт ресурсов задачи (отчёт воркера): prx_120_usage, upsert по job_id.
     * Таблицу создаёт миграция prx120/004; пока не применена — тихо пропускаем.
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
                return 'INSERT INTO prx_120_usage (job_id, channel, visitor_id, user_id, models,
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
            if ($visitor !== '' AND class_exists('Service_Prx120_Budget'))
            {
                Service_Prx120_Budget::after_usage($visitor);
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx120: usage ingest: :e', [':e' => $e->getMessage()]);
        }
    }

    /**
     * Скалярный срез payload для prx_120_usage.payload_brief: только строки и
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
        if ($row === NULL OR $row['status'] !== self::STATUS_PROCESSING
            OR (string) $row['claimed_by'] !== (string) $worker)
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
            $delay = 15 * $attempts * $attempts;
            try
            {
                $ok = $this->_update_status($job_id, $worker, [
                    'status'       => $this->_db->quote(self::STATUS_PENDING),
                    'error'        => $this->_db->quote((string) $error_message),
                    'available_at' => "now() + interval '".$delay." seconds'",
                    'claimed_by'   => 'NULL',
                    'claimed_at'   => 'NULL',
                ], ['event' => 'failed', 'message' => 'Попытка '.$attempts.'/'.$max.' не удалась, retry через '.$delay.'s']);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'prx120: fail #:i (retry): :e',
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
                Kohana::$log->add(Log::ERROR, 'prx120: fail #:i (terminal): :e',
                    [':i' => (int) $job_id, ':e' => $e->getMessage()]);
                $ok = FALSE;
            }

            // Последний рубеж: попытки исчерпаны — строка ОБЯЗАНА уйти в
            // failed, иначе «источники не нашлись» вечно висит processing.
            if ( ! $ok)
            {
                $ok = $this->_finalize_force($job_id, $worker, self::STATUS_FAILED,
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
                Event::publish('prx120.job_failed', $payload);
                $this->_callback($job_id, self::STATUS_FAILED, NULL, $error_message);

                // prx597: уведомление владельцу об окончательной ошибке
                if (class_exists('Service_Prx597_Notify'))
                {
                    try
                    {
                        Service_Prx597_Notify::job_update((array) $row, 'failed', (string) $error_message);
                    }
                    catch (Throwable $e)
                    {
                        Kohana::$log->add(Log::WARNING, 'prx597: notify failed: :e', [':e' => $e->getMessage()]);
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
        $sql = "UPDATE prx_120_jobs SET status = '".self::STATUS_CANCELLED."', finished_at = now()
                WHERE id = ".(int) $job_id." AND status IN ".$in;
        $this->_db->query(Database::UPDATE, $sql, FALSE);
        $this->_log_event((int) $job_id, 'cancelled', $force ? 'Прервана администратором' : 'Отменена');

        return TRUE;
    }

    /** Удалить ЗАВЕРШЕННУЮ задачу (prx_120_usage уйдёт каскадом). */
    public function delete_job($job_id)
    {
        $this->_db->query(Database::DELETE,
            "DELETE FROM prx_120_jobs
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
            'DELETE FROM prx_120_job_events WHERE job_id IN (SELECT id FROM prx_120_jobs)', FALSE);
        $rows = $this->_db->query(Database::DELETE,
            'DELETE FROM prx_120_jobs', FALSE);

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
             FROM prx_120_jobs j
             LEFT JOIN users u ON u.id = j.created_by
             LEFT JOIN LATERAL (
                 SELECT e.message, e.created_at FROM prx_120_job_events e
                  WHERE e.job_id = j.id AND e.event = \'progress\'
                    AND e.message IS NOT NULL AND e.message <> \'\'
                  ORDER BY e.id DESC LIMIT 1
             ) ev ON TRUE '.$where.'
             ORDER BY j.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Лента задач для «Использование модулей» (/prx100/usage): чужие модули
     * читают очередь только через этот публичный метод — SQL живёт здесь,
     * у владельца. Окно и модуль-инициатор задает вызывающий.
     */
    public function usage_jobs($interval, $module_prefix, $limit = 400)
    {
        if (preg_match('/^\\d+ (hour|hours|minute|minutes|day|days)$/', (string) $interval) === 1)
        {
            $interval = '24 hours';
        }

        $sql = 'SELECT j.id, j.channel, j.module, j.status, j.attempts, j.error, j.created_by, j.created_at, j.finished_at,
                       u.username AS owner_name
                FROM prx_120_jobs j
                LEFT JOIN users u ON u.id = j.created_by
                WHERE j.created_at >= now() - CAST(:window AS interval)'
            .($module_prefix !== '' ? ' AND j.module = :m' : '')
            .' ORDER BY j.created_at DESC LIMIT '.(int) $limit;

        $params = [':window' => (string) $interval];
        if ($module_prefix !== '') { $params[':m'] = (string) $module_prefix; }

        return $this->_db->query(Database::SELECT, $sql, FALSE)->parameters($params)->as_array();
    }

    /**
     * Последние задачи модуля по ключу payload — для статус-рядов модульных
     * страниц (эталон — монитор): статус, попытки, последний рапорт воркера
     * (stage). SQL у владельца очереди; вызывающий фильтрует по своему префиксу.
     */
    public function payload_jobs($module, $key, $value, $limit = 20)
    {
        // DB::query + parameters(): $db->query() выполняет SQL сразу, и
        // плейсхолдеры :m/:key/:value уходили в PostgreSQL как есть.
        return DB::query(Database::SELECT,
            'SELECT j.id, j.channel, j.status, j.attempts, j.max_attempts, j.error, j.payload,
                    j.created_at, j.started_at, j.finished_at, j.claimed_by,
                    ev.message AS stage, ev.created_at AS stage_at
             FROM prx_120_jobs j
             LEFT JOIN LATERAL (
                 SELECT e.message, e.created_at FROM prx_120_job_events e
                  WHERE e.job_id = j.id AND e.event = \'progress\'
                    AND e.message IS NOT NULL AND e.message <> \'\'
                  ORDER BY e.id DESC LIMIT 1
             ) ev ON TRUE
             WHERE j.module = :m AND j.payload->>:key = :value
             ORDER BY j.id DESC LIMIT '.(int) $limit)->parameters([
                ':m' => (string) $module,
                ':key' => (string) $key,
                ':value' => (string) $value,
            ])->execute($this->_db)->as_array();
    }

    /**
     * Последние задачи одного канала — «здоровье воркера» в модульных
     * админках (prx605/606/607/609): работает ли воркер, когдаLastError.
     */
    public function channel_jobs($channel, $limit = 8)
    {
        return $this->_db->query(Database::SELECT,
            'SELECT j.id, j.channel, j.status, j.payload, j.error, j.attempts, j.max_attempts,
                    j.created_at, j.started_at, j.finished_at, j.claimed_by
             FROM prx_120_jobs j
             WHERE j.channel = '.$this->_db->quote((string) $channel).'
             ORDER BY j.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Последние задачи одного посетителя (visitor_id вида u:<id> / g:<ubid>) —
     * «что человек реально делал» для карточки предпочтений (prx600) и
     * администрирования пользователей (prx595). Чужим модулям таблицу очереди
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
             FROM prx_120_jobs j
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
            "SELECT * FROM prx_120_jobs WHERE id = ".(int) $job_id, FALSE);
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
               FROM prx_120_job_events
              WHERE job_id = '.(int) $job_id.'
              ORDER BY id ASC LIMIT '.(int) $limit, FALSE);

        return $result->as_array();
    }

    /**
     * Активная (pending/processing) задача канала по значению поля payload.
     *
     * Для дожима «сайт принял артефакт, а задача не закрылась» в модулях без
     * собственной колонки job_id (prx606: привязка лежит в payload.flow_id).
     * Возвращает id самой свежей такой задачи или 0. Только чтение — запись
     * через complete()/fail() остаётся единственным путём в terminal.
     */
    public function find_active($channel, $payload_key, $payload_value)
    {
        $result = $this->_db->query(Database::SELECT,
            "SELECT id FROM prx_120_jobs
              WHERE channel = ".$this->_db->quote((string) $channel)."
                AND payload->>".$this->_db->quote((string) $payload_key)." = ".$this->_db->quote((string) $payload_value)."
                AND status IN ('".self::STATUS_PENDING."', '".self::STATUS_PROCESSING."')
              ORDER BY id DESC LIMIT 1", FALSE);
        $row = $result->current();
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Задача канала по значению поля payload — «кандидат на дожим» для модулей
     * БЕЗ собственной колонки job_id (prx605/prx606/prx609/prx610/prx612/prx613:
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
        // пинок prx610.inbox, служебные прогоны) — привязка берётся по свежести.
        $where_payload = ((string) $payload_key === '')
            ? ''
            : " AND payload->>".$this->_db->quote((string) $payload_key)." = ".$this->_db->quote((string) $payload_value);

        $result = $this->_db->query(Database::SELECT,
            "SELECT id, status FROM prx_120_jobs
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
            "SELECT id FROM prx_120_jobs
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
     * профиль компании (prx591), досье (prx598), дизайн (prx590) и т.д. Раньше
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
                "SELECT message, created_at FROM prx_120_job_events
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
            Kohana::$log->add(Log::WARNING, 'prx120: live_status этапы: :e', [':e' => $e->getMessage()]);
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
    /** Завершённые задачи, ещё не обработанные сборщиком уведомлений. Доверенный серверный API. */
    public function pending_notifications(int $limit = 40): array
    {
        return (new Service_Prx120_NotificationJobs($this->_db))->pending($limit);
    }

    public function mark_notification_processed(int $id): bool
    {
        return (new Service_Prx120_NotificationJobs($this->_db))->mark_processed($id);
    }

    public function stats()
    {
        $result = $this->_db->query(Database::SELECT,
            "SELECT status, COUNT(*) AS cnt FROM prx_120_jobs GROUP BY status", FALSE);

        $stats = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($result as $row)
        {
            $row = (array) $row;
            $stats[$row['status']] = (int) $row['cnt'];
        }
        return $stats;
    }

    protected function _update_status($job_id, $worker, array $fields, array $event = [])
    {
        // $fields может быть пуст (heartbeat) — базовый "heartbeat_at = now()" есть всегда
        $sets = ["heartbeat_at = now()"];
        foreach ($fields as $col => $val)
        {
            $sets[] = $col.' = '.$val; // значения уже квотированы вызывающим кодом
        }

        $sql = "UPDATE prx_120_jobs SET ".implode(', ', $sets)."
                WHERE id = ".(int) $job_id."
                  AND claimed_by = ".$this->_db->quote((string) $worker)."
                  AND status = '".self::STATUS_PROCESSING."'";
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
            $sql = "INSERT INTO prx_120_job_events (job_id, event, message, meta)
                    VALUES (".(int) $job_id.", "
                            .$this->_db->quote((string) $event).", "
                            .($message !== NULL ? $this->_db->quote((string) $message) : 'NULL').", "
                            .$this->_db->quote($this->_safe_json($meta)).")";
            $this->_db->query(Database::INSERT, $sql, FALSE);
        }
        catch (Exception $e)
        {
            // журнал не должен ломать основную работу очереди
            Kohana::$log->add(Log::ERROR, 'prx120: не удалось записать событие: :e',
                [':e' => $e->getMessage()]);
        }
    }

    /**
     * JSON, гарантированно валидный для PostgreSQL jsonb.
     *
     * json_encode() возвращает FALSE, если в строке результата/мете есть
     * повреждённый UTF-8 (вёрстка внешних страниц, обрывки LLM-вывода). Тогда
     * quote(FALSE) давал '0', и `UPDATE prx_120_jobs SET result = '0'` против jsonb
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
        $config = Kohana::$config->load('prx120_jobs');
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

    /** user_id инициатора (для уведомлений prx217) или NULL. */
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
            Kohana::$log->add(Log::ERROR, 'prx120: callback не удался для задачи :id: :e',
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
