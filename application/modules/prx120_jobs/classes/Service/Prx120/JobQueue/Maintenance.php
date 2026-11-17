<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Обслуживание очереди prx120: сверка исходов, возврат сирот, авто-остановки,
 * уборка зависших строк и правила порогов.
 *
 * Живёт трейтом, чтобы Service_Prx120_JobQueue оставался циклом и контрактом
 * постановки/завершения задач, а эксплуатационная часть читалась отдельно.
 * Ожидает от класса: $_db и константы STATUS_*; пишет через _update_status,
 * _log_event, _notify и Database.
 */
trait Service_Prx120_JobQueue_Maintenance
{
    /**
     * Дожим результатов, ДОСТАВЛЕННЫХ на сайт, но не закрытых в очереди.
     *
     * Сценарий «статья создалась, а статуса Готово нет» (prx602): воркер через
     * HTTP доставил артефакт (записал статью/профиль/документ на сайт), но не
     * успел выполнить complete() — упал, рестартовал, транспорт оборвался.
     * В pg-режиме такие строки донимает python-свипер; в HTTP-режиме воркер к
     * БД не ходит, и строку никто не закрывает, пока пульс воркера свеж (правило
     * 2а ниже «воркера не трогаем») — задача висит processing вечно, а «Готово
     * за 24 ч» не растёт.
     *
     * Здесь НЕ «верим пульсу», а сверяемся с фактическим состоянием модуля:
     * reconcile_hooks в config/prx120_jobs.php сопоставляет каналу SQL-условие
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
                    "UPDATE prx_120_jobs j SET
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
                // за секунды до доставки (типовой случай prx602 #813 — статья
                // принята через 2 с после «воркер перестал подавать признаки
                // жизни»). Результат фактически получен, поэтому возвращаем
                // честный «Готово» — иначе монитор навсегда показывает ошибку
                // там, где работа выполнена. Свежесть ограничена сутками.
                $revived = $db->query(Database::SELECT,
                    "UPDATE prx_120_jobs j SET
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
                Kohana::$log->add(Log::WARNING, 'prx120: reconcile (:c): :e',
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
     * этот метод (POST /prx120/api/release_mine) и работа возвращается в очередь
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
            "SELECT count(*) AS n FROM prx_120_jobs
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
            "UPDATE prx_120_jobs SET
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
            "UPDATE prx_120_jobs SET
                status = '".self::STATUS_FAILED."',
                error = ".$this->_db->quote($dead_message).",
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
     *     max_attempts=1 (пинки prx603: claim даёт attempts=1>=1 — любой заход
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
        //     Порог: три таймаута задачи, но не меньше PRX_JOB_MAX_AGE_SEC
        //     (по умолчанию 3600 с); отрицательное значение выключает правило.
        if ($this->max_age_sec() > 0)
        {
            $count += $this->_rule('правило 0 (предельный возраст)',
                "UPDATE prx_120_jobs SET
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
        //    (пинки prx603) оно истинно сразу после claim (attempts=1>=1) и
        //    любая задача с одной попыткой «сгорала» при первом же проходе.
        // Правило в своём try/catch: одно упавшее не отключает остальные
        // (раньше исключение в середине оставляло разбор незавершённым).
        $count += $this->_rule('правило 1 (молчание дольше timeout)',
            "UPDATE prx_120_jobs SET
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
            "UPDATE prx_120_jobs SET
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
        //     пинков prx603) и продолжает занимать слот обработки — ранний отказ
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
        //     конвейеры (prx602/prx607: 10-20 мин поиска и LLM без промежуточных
        //     рапортов) иначе получали «Ошибку» за секунды до доставки
        //     результата, и «Готово» не приходило никому.
        // Правило в своём try/catch: одно упавшее не отключает остальные
        // (раньше исключение в середине оставляло разбор незавершённым).
        $count += $this->_rule('правило 2а (отказ исчерпанных)',
            "UPDATE prx_120_jobs SET
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
            "UPDATE prx_120_jobs SET
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
     * (/prx603/kick, расписание maintenance раз в 10 минут) и открытие
     * /prx120/monitor или /prx120/task/<id>. Если cron на хостинге не установлен,
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
                "SELECT count(*) AS c FROM prx_120_jobs
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
            // Ключ advisory-lock: свои 120120 (модуль prx120).
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
                    Kohana::$log->add(Log::INFO, 'prx120: очередь разобрала зависшие задачи сама: :n', [':n' => $n]);
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
            Kohana::$log->add(Log::WARNING, 'prx120: авто-обслуживание очереди: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Предельный возраст задачи в processing (секунд).
     *
     * Три таймаута самой задачи, но не меньше PRX_JOB_MAX_AGE_SEC (3600 с по
     * умолчанию). Задача, которая «жива пульсом», но идёт дольше, закрывается
     * автоматически: иначе один зацикленный или устаревший воркер держит
     * строку в «Выполняется» вечно. Отрицательное значение — правило выключено.
     */
    public function max_age_sec()
    {
        $raw = getenv('PRX_JOB_MAX_AGE_SEC');
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
            Kohana::$log->add(Log::WARNING, 'prx120: правило уборки «:r»: :e',
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
     * Порог: GREATEST(PRX_SILENT_STOP_SEC (по умолчанию 240 c), timeout_sec/4) —
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
     * обработчик. Модули (prx602/prx605/prx606) сравнивают свой silent_sec с этим
     * значением — раньше в каждом месте было своё «240», и правки расходились.
     *
     * С 06.10.2026 канал может поднять себе порог: config prx120_jobs →
     * silent_stop_channels (prx661.airadar — конвейер с локальной LLM:
     * генерация занимает минуты, общий порог снимал живую задачу).
     * Эффективный порог канала = max(общий env-порог, значение канала).
     *
     * @param  string $channel канал или NULL — общий порог
     * @return int
     */
    public function silent_stop_sec($channel = NULL)
    {
        $floor = (int) getenv('PRX_SILENT_STOP_SEC');
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
     * Каналы с особым порогом молчания: config prx120_jobs.silent_stop_channels.
     *
     * @return array канал => секунды (мусор отфильтрован)
     */
    public function silent_stop_channels()
    {
        try
        {
            $map = (array) Kohana::$config->load('prx120_jobs.silent_stop_channels');
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
        // (prx606.leads: 900 с → 225 с, но у prx602/др. доходило до 15-25 мин)
        // вторая половина условия отодвигала остановку далеко за обещанные
        // 4 минуты: владелец видел «нет пульса 10 мин» и живой спиннер, хотя
        // воркер давно молчал. Пульс не зависит от длины задачи, поэтому
        // порог не должен зависеть от timeout_sec.
        // Каналы из silent_stop_channels идут отдельным проходом со СВОИМ
        // порогом (06.10.2026, prx661: локальная LLM считает минуты — общий
        // порог в 4 минуты снимал живую задачу, хотя прогон доходил до конца).
        $special = $this->silent_stop_channels();

        // Одна конкретная задача — её каналом и её порогом (так зовут
        // страницы модулей: «прервать зависшее»).
        if ((int) $job_id > 0)
        {
            $row     = $this->_db->query(Database::SELECT,
                'SELECT channel FROM prx_120_jobs WHERE id = '.(int) $job_id, FALSE)->current();
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
                "UPDATE prx_120_jobs SET
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
            Kohana::$log->add(Log::WARNING, 'prx120: авто-остановка молчащих задач: :e', [':e' => $e->getMessage()]);

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
        $sec = (int) getenv('PRX_STALLED_PROGRESS_SEC');

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
                "UPDATE prx_120_jobs j SET
                    status = '".self::STATUS_CANCELLED."',
                    error = 'Воркер не выходил на связь больше ".(int) $sec_min." мин — сбор остановлен автоматически. Найденное сохранено, запустите заново.',
                    finished_at = now(),
                    claimed_by = NULL
                 WHERE j.status = '".self::STATUS_PROCESSING."'
                   AND COALESCE(
                         (SELECT max(e.created_at) FROM prx_120_job_events e WHERE e.job_id = j.id),
                         j.claimed_at, j.created_at
                       ) < now() - ".(int) $sec." * interval '1 second'".$where."
                 RETURNING j.id", FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx120: авто-остановка застрявших задач: :e', [':e' => $e->getMessage()]);

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
            "UPDATE prx_120_jobs SET
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
            "SELECT COUNT(*) AS cnt FROM prx_120_jobs
             WHERE status = '".self::STATUS_PROCESSING."'
               AND ".$this->_pulse_sql()." < now() - ".(int) $older_sec." * interval '1 second'", FALSE)->current();

        return empty($row) ? 0 : (int) $row['cnt'];
    }

    // ---------------------------------------------------------------------
    // Внутреннее
    // ---------------------------------------------------------------------
}
