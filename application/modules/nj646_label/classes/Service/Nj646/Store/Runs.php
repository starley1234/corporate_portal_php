<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Runs`: прогоны очереди (nj120): постановка, статусы, дозапись, отмена.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Runs
{

    public static function run_create(array $data)
    {
        self::ensure_tables();

        $db = self::db();

        $sql = 'INSERT INTO nj_646_runs (user_id, visitor, mode, client_ip, status)
                VALUES (
                    '.($data['user_id'] !== NULL ? (int) $data['user_id'] : 'NULL').',
                    '.$db->quote((string) ($data['visitor'] ?? '')).',
                    '.$db->quote((string) ($data['mode'] ?? 'photo')).',
                    '.$db->quote((string) ($data['client_ip'] ?? '')).',
                    \'queued\') RETURNING id';

        $row = self::db()->query(Database::SELECT, $sql, FALSE)->current();

        return $row ? (int) $row['id'] : 0;
    }

    public static function run_set_job($id, $job_id)
    {
        DB::query(NULL, 'UPDATE nj_646_runs SET job_id = '.(int) $job_id.' WHERE id = '.(int) $id)->execute();
    }

    public static function run_get($id)
    {
        self::ensure_tables();

        $row = self::_one('SELECT * FROM nj_646_runs WHERE id = '.(int) $id);

        if ($row)
        {
            $row['result'] = self::_json_decode($row['result']);
        }

        return $row;
    }

    /** Применить результат воркера (идемпотентно: повторный POST не ломает). */
    public static function run_apply($id, array $result)
    {
        $status = (string) ($result['status'] ?? 'done');

        if ( ! in_array($status, ['done', 'error', 'cancelled'], TRUE))
        {
            $status = 'done';
        }

        // Предел на запись: у дожима (`run_watch`) результат берётся уже из
        // этой колонки, поэтому он обязан остаться читаемым как массив —
        // просто соразмерным. Обрезаем строки и ставим `_cut` (см. `_json_cut`),
        // чтобы страница не выдавала сокращённый вывод за полный.
        $result = self::_json_cut($result, self::limit('result_kb', 256) * 1024, $result_cut);

        if ($result_cut)
        {
            Kohana::$log->add(Log::INFO, 'nj646: результат прогона :id обрезан до лимита записи', [
                ':id' => (int) $id,
            ]);
        }

        $set = [
            'status'      => $status,
            // ВНИМАНИЕ: result — jsonb, а `DB::update()->set()` экранирует
            // значение ЕЩЁ РАЗ. `_json()` возвращает уже закавыченный литерал,
            // поэтому без DB::expr получалось '''{…}''' → PostgreSQL
            // «invalid input syntax for type json» → HTTP 500 на приёмке
            // /label/api/worker_result, и прогон навсегда оставался queued
            // (владелец видел вечное «Распознаю этикетку…» при готовом
            // результате в очереди). Та же грабля была в nj645 — там строка
            // едет через DB::expr (Store::run_apply), повторяем образец.
            'result'      => DB::expr(self::_json($result)),
            'error'       => mb_substr((string) ($result['error'] ?? ''), 0, 1000),
            'model'       => mb_substr((string) ($result['model'] ?? ''), 0, 160),
            'elapsed_ms'  => (int) ($result['elapsed_ms'] ?? 0),
            'finished_at' => DB::expr('now()'),
        ];

        DB::update('nj_646_runs')->set($set)->where('id', '=', (int) $id)->execute();

        return $status;
    }

    /**
     * Применить ответ воркера к прогону — ЕДИНАЯ точка приёмки.
     *
     * Вызывается из двух мест: POST /label/api/worker_result (воркер сам сдал
     * результат) и run_watch() — дожим из очереди, когда доставка не прошла
     * (токен, сеть, 500 на приёмке). Разбор payload обязан быть один: иначе
     * дожим и приёмка начнут расходиться полями.
     *
     * @return array ['status' => done|error|cancelled, 'slug' => slug черновика]
     */
    public static function run_apply_worker($id, array $payload)
    {
        $mode   = (string) ($payload['mode'] ?? '');
        $status = self::run_apply($id, [
            'status'     => (string) ($payload['status'] ?? 'done'),
            'mode'       => $mode,
            'text'       => mb_substr((string) ($payload['text'] ?? ''), 0, 8000),
            'kind'       => (string) ($payload['kind'] ?? ''),
            // note — честная пометка воркера к составу: «модель ответила, но
            // ответ оборвался — проверьте конец списка», блик, мелкий шрифт.
            // Раньше поле молча терялось на приёмке, и страница показывала
            // обрезанный состав как полный.
            'note'       => mb_substr((string) ($payload['note'] ?? ''), 0, 300),
            // transcript — строки этикетки, переписанные моделью (двухпроходный
            // режим воркера). Состав собирается ИЗ НИХ, поэтому владелец должен
            // видеть их на странице: сверил строки с фото — и сразу понял, где
            // модель выдумала (на мелком шрифте это главный источник ошибок).
            'transcript' => mb_substr((string) ($payload['transcript'] ?? ''), 0, 6000),
            'error'      => mb_substr((string) ($payload['error'] ?? ''), 0, 1000),
            'model'      => (string) ($payload['model'] ?? ''),
            'elapsed_ms' => (int) ($payload['elapsed_ms'] ?? 0),
        ]);

        $slug = '';

        // Рабочее название разбора от модели (режим `title`): заменяем только
        // придуманное модулем (колонка title_auto) — см. analysis_title_set.
        if ($mode === 'title' AND $status === 'done')
        {
            $title = trim(preg_replace('/\s+/u', ' ', (string) ($payload['title'] ?? '')));

            if ($title !== '' AND (int) ($payload['analysis_id'] ?? 0) > 0)
            {
                self::analysis_title_set((int) $payload['analysis_id'], $title);
            }
        }

        // Изучение вещества: черновик карточки + отметка в очереди неизвестных.
        if ($mode === 'study' AND $status === 'done')
        {
            $ingredient = (array) ($payload['ingredient'] ?? []);

            if ( ! empty($ingredient['name_ru']))
            {
                $saved = self::ingredient_draft([
                    'name_ru'    => (string) $ingredient['name_ru'],
                    'name_int'   => (string) ($ingredient['name_int'] ?? ''),
                    'kind'       => (string) ($ingredient['kind'] ?? 'both'),
                    'risk'       => (int) ($ingredient['risk'] ?? 2),
                    'verdict'    => (string) ($ingredient['verdict'] ?? 'caution'),
                    'category'   => (string) ($ingredient['category'] ?? ''),
                    'e_code'     => (string) ($ingredient['e_code'] ?? ''),
                    'summary'    => (string) ($ingredient['summary'] ?? ''),
                    'details'    => (string) ($ingredient['details'] ?? ''),
                    'synonyms'   => (array) ($ingredient['synonyms'] ?? []),
                    'avoid'      => (array) ($ingredient['avoid'] ?? []),
                    'sources'    => (array) ($ingredient['sources'] ?? []),
                    'confidence' => (int) ($payload['confidence'] ?? 60),
                ], ! empty($payload['refresh']));

                if ($saved AND (int) ($payload['unknown_id'] ?? 0) > 0)
                {
                    self::unknown_studied((int) $payload['unknown_id'], (int) $saved['id']);
                    $slug = (string) ($saved['slug'] ?? '');
                }
            }
        }

        // Кадр своё отработал: результат уже на сайте, а фото этикетки — данные
        // посетителя. Держим файл только на время прогона.
        //
        // Убираем ТОЛЬКО при успехе. Попытка задачи может повториться
        // (`max_attempts` 2, backoff на стороне очереди): при сбое модели воркер
        // не присылает результат, сайт дожимает прогон через run_watch — и
        // удалённый файл сломал бы повтор. Неудачные кадры снимает `photo_gc()`.
        if ($mode === 'photo' AND $status === 'done')
        {
            self::photo_drop((int) $id);
        }

        return ['status' => $status, 'slug' => $slug];
    }

    /**
     * Живое состояние прогона — контракт ОБЩЕГО статус-ряда платформы.
     *
     * Эталон (/trends/<code>, partials/live_status): спиннер + название этапа +
     * секундомер, список этапов и честное «нет пульса». Воркер и так рапортует
     * каждый шаг (`ctx.progress` → события `progress` в nj_120_job_events), и
     * nj120 умеет отдавать их одной структурой (`JobQueue::status_of`). Модулю
     * оставалось только прокинуть их на страницу: до этого владелец видел
     * серое «Распознаю этикетку…» без единого признака жизни.
     *
     * @return array status|stage|stages|elapsed_sec|silent_sec|error|note|found|job_id
     */
    public static function run_live(array $run)
    {
        $run_status = (string) ($run['status'] ?? 'queued');
        $created    = ! empty($run['created_at']) ? strtotime((string) $run['created_at']) : time();
        $finished   = ! empty($run['finished_at']) ? strtotime((string) $run['finished_at']) : NULL;

        $out = [
            'found'        => FALSE,
            'job_id'       => (int) ($run['job_id'] ?? 0),
            'status'       => self::_live_status($run_status),
            'stage'        => '',
            'stages'       => [],
            'elapsed_sec'  => max(0, ($finished ?: time()) - $created),
            'silent_sec'   => NULL,
            'error'        => trim((string) ($run['error'] ?? '')),
            'note'         => '',
            'attempts'     => 0,
            'max_attempts' => 0,
        ];

        // Терминальный прогон опрашивать не о чем: этапы уже не изменятся.
        if (in_array($run_status, ['queued', 'running'], TRUE)
            AND $out['job_id'] > 0 AND class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $live = Service_Nj120_JobQueue::instance()->status_of($out['job_id']);

                if ( ! empty($live['found']))
                {
                    $out['found']        = TRUE;
                    $out['stage']        = (string) ($live['stage'] ?? '');
                    $out['stages']       = (array) ($live['stages'] ?? []);
                    $out['elapsed_sec']  = max($out['elapsed_sec'], (int) ($live['elapsed_sec'] ?? 0));
                    $out['silent_sec']   = $live['silent_sec'] ?? NULL;
                    $out['note']         = (string) ($live['note'] ?? '');
                    $out['attempts']     = (int) ($live['attempts'] ?? 0);
                    $out['max_attempts'] = (int) ($live['max_attempts'] ?? 0);

                    if ($out['error'] === '')
                    {
                        $out['error'] = (string) ($live['error'] ?? '');
                    }

                    // Задача уже отказалась, а прогон ещё «running» (дожим
                    // закроет его отдельно) — статус-ряд не должен врать.
                    if (in_array((string) $live['status'], ['failed', 'cancelled', 'done'], TRUE))
                    {
                        $out['status'] = (string) $live['status'];
                    }
                }
            }
            catch (Throwable $e)
            {
                // очередь недоступна — отдаём хотя бы статус и секундомер
                Kohana::$log->add(Log::WARNING, 'nj646: живой статус прогона :i: :e',
                    [':i' => (int) ($run['id'] ?? 0), ':e' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /** Статус прогона → статус общего статус-ряда (error → failed). */
    protected static function _live_status($status)
    {
        $map = ['queued' => 'queued', 'running' => 'running', 'done' => 'done',
                'error' => 'failed', 'cancelled' => 'cancelled'];

        return $map[(string) $status] ?? 'queued';
    }

    /**
     * Свежее состояние прогона + честная подсказка, если он не двигается.
     *
     * Это и есть ДОЖИМ: страница /label опрашивает ИМЕННО nj_646_runs, а
     * заполняет его только приёмка сайта. Если доставка не прошла (нет токена
     * у воркера, сеть, 500) — прогон висел бы «Распознаю этикетку…» вечно,
     * хотя работа сделана (`delivered: false` в nj_120_jobs.result). Поэтому
     * результат при необходимости забирается ИЗ задачи очереди — поток здесь
     * обратный, в отличие от nj602/nj605/nj606 (find_for_delivery +
     * mark_delivered). Отдельной обёртки `run_catchup()` больше нет: она
     * только звала этот метод и никем не вызывалась.
     *
     * @return array ['run' => array|null, 'hint' => string]
     */
    public static function run_watch($id)
    {
        $run = self::run_get((int) $id);

        if ( ! $run)
        {
            return ['run' => NULL, 'hint' => ''];
        }

        $hint = '';

        try
        {
            $job = self::_job_of_run($run);

            if ($job)
            {
                $run = self::_run_from_job($run, $job);
            }

            if (in_array((string) $run['status'], ['queued', 'running'], TRUE) AND $job)
            {
                // Задача стоит, её никто не брал и она не двигается — молчать
                // нельзя: страница иначе «Распознаёт» бесконечно, а виноват
                // незапущенный воркер или канал не в WORKER_CHANNELS.
                $job_status = (string) ($job['status'] ?? '');
                $wait_sec   = max(60, (int) self::cfg('limits.stall_hint_sec', 150));

                if ($job_status === 'pending' AND empty($job['claimed_at']) AND
                    (time() - strtotime((string) $job['created_at'])) > $wait_sec)
                {
                    $hint = 'Задача в очереди уже больше '.round($wait_sec / 60)
                          .' мин, но её никто не взял: проверьте, что воркер запущен, '
                          .'а канал nj646.label есть в WORKER_CHANNELS.';
                }
            }
        }
        catch (Throwable $e)
        {
            // очередь недоступна — статус прогона всё равно отдаём
            Kohana::$log->add(Log::WARNING, 'nj646: дожим прогона :i: :e',
                [':i' => (int) $id, ':e' => $e->getMessage()]);
        }

        return ['run' => $run, 'hint' => $hint];
    }

    /** Задача очереди, привязанная к прогону (по job_id), или NULL. */
    protected static function _job_of_run(array $run)
    {
        $job_id = (int) ($run['job_id'] ?? 0);

        if ($job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        return Service_Nj120_JobQueue::instance()->get($job_id);
    }

    /**
     * Догнать прогон по задаче очереди: done → применить результат, терминальный
     * отказ → показать ошибку. Незавершённую задачу не трогаем — её ведёт воркер.
     */
    protected static function _run_from_job(array $run, array $job)
    {
        $status = (string) ($job['status'] ?? '');
        $result = $job['result'] ?? NULL;

        if (is_string($result) AND $result !== '')
        {
            $result = self::_json_decode($result);
        }

        if ($status === 'done' AND is_array($result) AND ! empty($result['mode']))
        {
            self::run_apply_worker((int) $run['id'], $result);
        }
        elseif ($status === 'failed' OR $status === 'cancelled')
        {
            // Попытки ещё есть — задачу вернут в очередь, прогон не закрываем.
            $attempts     = (int) ($job['attempts'] ?? 0);
            $max_attempts = (int) ($job['max_attempts'] ?? 0);

            if ($status === 'cancelled' OR ($max_attempts > 0 AND $attempts >= $max_attempts))
            {
                $error = trim((string) ($job['error'] ?? ''));

                self::run_apply((int) $run['id'], [
                    'status' => 'error',
                    'mode'   => (string) ($run['mode'] ?? 'photo'),
                    'error'  => $error !== ''
                        ? 'Задача распознавания не выполнена: '.$error
                        : 'Задача распознавания не выполнена (причина не записана).',
                ]);
            }
        }

        return self::run_get((int) $run['id']);
    }

    public static function runs_mine($visitor, $limit = 8)
    {
        self::ensure_tables();

        $rows = self::_all('SELECT id, mode, status, result, error, created_at
                            FROM nj_646_runs WHERE visitor = '.self::db()->quote((string) $visitor).'
                            ORDER BY id DESC LIMIT '.(int) max(1, min(20, $limit)));

        foreach ($rows as $i => $row)
        {
            $rows[$i]['result'] = self::_json_decode($row['result']);
        }

        return $rows;
    }

    /** Сколько изучений уже ушло за сутки с этого IP (дневной лимит). */
    public static function studies_last_day($client_ip)
    {
        return (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_runs
                                  WHERE client_ip = '.self::db()->quote((string) $client_ip)."
                                    AND mode = 'study' AND created_at > now() - interval '1 day'", 'c');
    }

    /** Сколько прогонов режима за последний час (лимит на распознавание). */
}
