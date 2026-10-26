<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Unknowns`: очередь незнакомых веществ: встречи, изучение моделью, отказы.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Unknowns
{

    /**
     * Встретили незнакомое название: записать/обновить и сказать, пора ли
     * звать ИИ. Один ключ — одна запись; счётчик встреч накапливается.
     *
     * @return array ['id' => int, 'seen' => int, 'status' => string, 'due' => bool]
     */
    public static function unknown_touch($name, $kind = 'both', $due_at = 2)
    {
        self::ensure_tables();

        $name = trim(mb_substr((string) $name, 0, 200));

        if ($name === '')
        {
            return NULL;
        }

        $key = hash('sha256', Service_Nj646_Label::normalize($name).'|'.(string) $kind);
        $db  = self::db();

        $sql = 'INSERT INTO nj_646_unknowns (name_key, name_raw, kind, seen_count, last_seen)
                VALUES ('.$db->quote($key).', '.$db->quote($name).', '.$db->quote((string) $kind).', 1, now())
                ON CONFLICT ("name_key") DO UPDATE SET
                    seen_count = nj_646_unknowns.seen_count + 1,
                    name_raw = EXCLUDED.name_raw,
                    last_seen = now()
                RETURNING id, seen_count, status';

        $row = self::db()->query(Database::SELECT, $sql, FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        return [
            'id'     => (int) $row['id'],
            'seen'   => (int) $row['seen_count'],
            'status' => (string) $row['status'],
            'due'    => (string) $row['status'] === 'new' AND (int) $row['seen_count'] >= (int) $due_at,
        ];
    }

    /** Пометить, что на изучение ушла задача (и какая). */
    public static function unknown_queue($id, $run_id)
    {
        DB::query(NULL, 'UPDATE nj_646_unknowns SET status = \'queued\', run_id = '.(int) $run_id.',
                        last_seen = now()
                    WHERE id = '.(int) $id.' AND status NOT IN (\'studied\')')->execute();
    }

    public static function unknown_studied($id, $ingredient_id)
    {
        DB::query(NULL, 'UPDATE nj_646_unknowns SET status = \'studied\', ingredient_id = '.(int) $ingredient_id.',
                        last_seen = now()
                    WHERE id = '.(int) $id)->execute();
    }

    public static function unknown_by_id($id)
    {
        return self::_one('SELECT * FROM nj_646_unknowns WHERE id = '.(int) $id);
    }

    /**
     * Какие из этих незнакомых веществ уже разобраны — для ПОЛНОГО ответа.
     *
     * Страница знает список неизвестных позиций последнего разбора и
     * спрашивает по именам: что уже готово, а что ещё изучается. Возвращаем
     * только «ещё нет» — по нему видно, доводить ли разбор до конца.
     * Имя сравниваем так же, как очередь (`Service_Nj646_Label::normalize`),
     * поэтому совпадает и «E 211», и «е211».
     *
     * @param array $names названия незнакомых веществ из разбора
     *
     * @return array ['pending' => [['name' => ..., 'status' => ...]], 'ready' => int]
     */
    public static function unknowns_pending_by_names(array $names)
    {
        self::ensure_tables();

        $pending = [];
        $ready   = 0;

        foreach (array_slice(array_values(array_unique(array_map('strval', $names))), 0, 100) as $name)
        {
            $name = trim($name);

            if ($name === '')
            {
                continue;
            }

            // Ключ очереди включает вид продукта (food/cosmetic/both): один и
            // тот же «E211» может лежать в нескольких строках — берём ту, где
            // уже есть результат, иначе любую.
            $keys = [];

            foreach (['food', 'cosmetic', 'both', 'auto'] as $kind)
            {
                $keys[] = self::db()->quote(hash('sha256', Service_Nj646_Label::normalize($name).'|'.$kind));
            }

            $row = self::_one('SELECT status, ingredient_id FROM nj_646_unknowns
                                WHERE name_key IN ('.implode(',', $keys).')
                                ORDER BY (status = \'studied\' AND ingredient_id > 0) DESC, id DESC
                                LIMIT 1');

            // Разобрано (или убрано как мусор) — значит, ответ по этому
            // веществу уже полный: в списке ожидания ему делать нечего.
            if ($row AND (string) $row['status'] === 'studied' AND (int) $row['ingredient_id'] > 0)
            {
                $ready++;
                continue;
            }

            if ($row AND (string) $row['status'] === 'ignored')
            {
                continue;
            }

            $pending[] = ['name' => $name, 'status' => $row ? (string) $row['status'] : 'new'];
        }

        return ['pending' => $pending, 'ready' => $ready];
    }

    /**
     * Что стало с незнакомыми веществами разбора — для страницы разбора.
     *
     * «База ещё не знает X» — снимок на момент разбора; с тех пор вещество
     * могли изучить (тогда показываем карточку), поставить в очередь или
     * убрать как мусор. Возвращаем по каждому имени состояние и slug карточки,
     * чтобы страница говорила правду, а не пугала старым списком.
     *
     * @return array ['name' => ['status' => new|queued|studied|ignored, 'slug' => string]]
     */
    public static function unknowns_status_by_names(array $names)
    {
        self::ensure_tables();

        $out = [];

        foreach (array_slice(array_values(array_unique(array_map('strval', $names))), 0, 100) as $name)
        {
            $name = trim($name);

            if ($name === '')
            {
                continue;
            }

            // Ключ очереди включает вид продукта: одна и та же позиция может
            // лежать в нескольких строках — берём ту, где есть результат.
            $keys = [];

            foreach (['food', 'cosmetic', 'both', 'auto'] as $kind)
            {
                $keys[] = self::db()->quote(hash('sha256', Service_Nj646_Label::normalize($name).'|'.$kind));
            }

            $row = self::_one('SELECT status, ingredient_id, run_id FROM nj_646_unknowns
                                WHERE name_key IN ('.implode(',', $keys).')
                                ORDER BY (status = \'studied\' AND ingredient_id > 0) DESC, id DESC
                                LIMIT 1');

            $slug = '';

            if ($row AND (int) $row['ingredient_id'] > 0)
            {
                $card = self::ingredient_by_id((int) $row['ingredient_id']);
                $slug = (string) ($card['slug'] ?? '');
            }

            $out[$name] = [
                'status' => $row ? (string) $row['status'] : 'new',
                // Слаг показываем только у изученного: у «в очереди» карточки ещё нет.
                'slug'   => (string) (($row AND (string) $row['status'] === 'studied') ? $slug : ''),
                // Прогон изучения: по нему страница разбора рисует живой ряд
                // (спиннер + этап + секундомер) — см. partials/live_status.
                'run_id' => (int) ($row['run_id'] ?? 0),
                'ingredient_id' => (int) ($row['ingredient_id'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Поставить изучение вещества в очередь — ЕДИНАЯ точка (админка и API).
     *
     * Зачем отдельный метод и почему возвращается код. Раньше постановка жила
     * в контроллерах и отдавала NULL на ЛЮБУЮ неудачу: лимит, отсутствие
     * модели, недоступная очередь. Админка показывала на всё одну фразу
     * «Очередь недоступна: » (с пустой причиной) — при том, что очередь была
     * жива, а упирался владелец в дневной лимит изучений. Теперь причина
     * названа кодом, и каждая сторона говорит своё:
     *
     *   queued      — задача поставлена, run_id в ответе;
     *   already     — вещество уже изучено (карточка есть);
     *   no_llm      — на сервере нет языковой модели;
     *   limit       — дневной лимит изучений на IP исчерпан;
     *   unknown     — неизвестного вещества нет в базе;
     *   queue_error — задача не поставилась: error с текстом;
     *   run_error   — прогон не создался (БД молчит).
     *
     * @param array $options ['manual' => bool, 'visitor' => string, 'user_id' => int|null,
     *                        'due_seen' => int (порог «пора изучать»), 'timeout_sec' => int]
     *
     * @return array ['code' => ..., 'ok' => bool, 'run_id' => int, 'ingredient_id' => int,
     *                'slug' => string, 'name' => string, 'error' => string, 'message' => string]
     */
    public static function study_push($unknown_id, $name = '', $kind = 'both', array $options = [])
    {
        $manual  = ! empty($options['manual']);
        $visitor = (string) ($options['visitor'] ?? '');
        $row     = (int) $unknown_id > 0 ? self::unknown_by_id((int) $unknown_id) : NULL;

        if ( ! $row AND $name !== '')
        {
            $touch = self::unknown_touch($name, $kind, max(1, (int) ($options['due_seen'] ?? 1)));
            $row   = $touch ? self::unknown_by_id($touch['id']) : NULL;
        }

        if ( ! $row)
        {
            return self::_study_result('unknown', 'Вещество не найдено в очереди базы знаний.');
        }

        $name = (string) $row['name_raw'];

        // Уже изучено: второй раз модель не гоняем — отдаём готовую карточку.
        if ((string) $row['status'] === 'studied' AND (int) $row['ingredient_id'] > 0)
        {
            $ingredient = self::ingredient_by_id((int) $row['ingredient_id']);

            return self::_study_result('already', 'Вещество уже изучено: карточка «'
                .((string) ($ingredient['name_ru'] ?? '') ?: $name).'».', [
                    'ingredient_id' => (int) $row['ingredient_id'],
                    'slug'          => (string) ($ingredient['slug'] ?? ''),
                    'name'          => $name,
                ]);
        }

        // Изучение уже идёт: вторую задачу на то же вещество не ставим —
        // иначе «Изучить» дважды подряд кладёт в очередь дубль, а админка
        // показывает два прогона на один и тот же ингредиент.
        if ((string) $row['status'] === 'queued' AND (int) $row['run_id'] > 0)
        {
            $active = self::run_get((int) $row['run_id']);

            if ($active AND in_array((string) $active['status'], ['queued', 'running'], TRUE))
            {
                return self::_study_result('already', 'Вещество уже изучается (задача '
                    .(int) $row['run_id'].') — результат появится здесь же.', [
                        'run_id' => (int) $row['run_id'],
                        'name'   => $name,
                    ]);
            }
        }

        if ( ! self::llm_ready())
        {
            return self::_study_result('no_llm',
                'Изучение недоступно: на сервере не подключена языковая модель.', ['name' => $name]);
        }

        // Лимит — ДНЕВНОЙ и на IP: очередь не должна забиваться «на всякий случай».
        // Полный режим приводит СВОЙ лимит (NJ646_FULL_PER_DAY): «изучить всё»
        // не должно упираться в потолок ручных изучений на третьем веществе.
        $ip  = class_exists('Service_Nj100_Ratelimit') ? Service_Nj100_Ratelimit::client_ip() : '';
        $per = max(1, (int) ($options['daily_limit'] ?? self::limit('study_per_day', 10)));
        $per_key = (string) ($options['limit_key'] ?? 'NJ646_STUDY_PER_DAY');
        $row_count = self::_one('SELECT COUNT(*) AS c FROM nj_646_runs
                                  WHERE client_ip = '.self::db()->quote($ip)."
                                    AND mode = 'study' AND created_at > now() - interval '1 day'", 'c');

        if ((int) $row_count >= $per)
        {
            return self::_study_result('limit',
                'Лимит изучений на сегодня ('.$per.' в сутки) исчерпан — карточки появятся позже, '
                .'а лимит можно поднять ключом '.$per_key.'.', ['name' => $name]);
        }

        $run_id = self::run_create([
            'user_id'   => $options['user_id'] ?? NULL,
            'visitor'   => $visitor,
            'mode'      => 'study',
            'client_ip' => $ip,
        ]);

        if ( ! $run_id)
        {
            return self::_study_result('run_error',
                'Не удалось создать прогон изучения — база не приняла запись.', ['name' => $name]);
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj646.study', [
                'run_id'     => $run_id,
                'unknown_id' => (int) $row['id'],
                'name'       => $name,
                'kind'       => (string) $row['kind'],
                'manual'     => $manual,
                // Переизучение: приёмка заменит карточку ИИ, а не промолчит.
                'refresh'    => ! empty($options['refresh']),
            ], [
                'module'       => 'nj646_label',
                'timeout_sec'  => max(120, (int) ($options['timeout_sec'] ?? 300)),
                'max_attempts' => 2,
            ]);

            self::run_set_job($run_id, $job_id);
        }
        catch (Throwable $e)
        {
            self::run_apply($run_id, ['status' => 'error',
                'error' => 'Очередь недоступна: '.$e->getMessage()]);

            return self::_study_result('queue_error',
                'Очередь недоступна: '.$e->getMessage(), ['name' => $name, 'run_id' => $run_id]);
        }

        self::unknown_queue((int) $row['id'], $run_id);

        return self::_study_result('queued', 'Задача на изучение «'.$name.'» поставлена в очередь.', [
            'run_id' => $run_id,
            'name'   => $name,
        ]);
    }

    /**
     * Поставить на изучение ВСЮ очередь незнакомых веществ — кнопка «изучить всё».
     *
     * Владелец (03.10.2026): «сделай кнопку "изучить всё"». До неё очередь
     * разбиралась по одному веществу, а очередь измеряется десятками — и
     * каждое нажатие снова проверяло модель, лимит и активный прогон.
     *
     * @param array $options ['limit' => сколько смотреть, 'visitor', 'user_id']
     * @return array см. study_push_ids()
     */
    public static function study_push_all(array $options = [])
    {
        self::ensure_tables();

        $limit = max(0, min(50, (int) ($options['limit'] ?? 20)));

        if ($limit === 0)
        {
            // Страница присылает счётчик очереди: ноль — значит изучать нечего,
            // и говорить об этом надо сразу, не трогая БД.
            return self::_study_batch_empty(count($options['ids'] ?? []));
        }

        $options['total'] = self::unknowns_pending_count();

        return self::study_push_ids(self::unknowns_pending_ids($limit), $options);
    }

    /**
     * Поставить на изучение КОНКРЕТНЫЕ вещества (выбранные галочками в админке).
     *
     * Одна реализация на две кнопки — «изучить всё» и «изучить выбранные»:
     * каждая позиция всё равно идёт через study_push(), поэтому дублей нет,
     * уже изучаемое отдаёт 'already', а дневной лимит останавливает пачку и
     * называет себя. Идентификаторы чистятся от нечислового ввода (_clean_ids).
     *
     * @param array $options ['visitor', 'user_id', 'total' => полный размер очереди]
     * @return array ['ok','code','message','queued'=>[],'already'=>[],'runs'=>[],'limit'=>bool,'checked'=>int]
     */
    public static function study_push_ids(array $ids, array $options = [])
    {
        self::ensure_tables();

        $ids     = self::_clean_ids($ids);
        $visitor = (string) ($options['visitor'] ?? 'admin');

        if ( ! $ids)
        {
            return self::_study_batch_empty(0);
        }

        $by_id = [];

        foreach (self::_all('SELECT id, name_raw, kind FROM nj_646_unknowns
                              WHERE id IN ('.implode(',', $ids).')') as $row)
        {
            $by_id[(int) $row['id']] = $row;
        }

        // Порядок — как в списке выбора/очереди, а не как отдала база.
        $rows = [];

        foreach ($ids as $id)
        {
            if (isset($by_id[$id]))
            {
                $rows[] = $by_id[$id];
            }
        }

        if ( ! $rows)
        {
            return self::_study_batch_empty(0);
        }

        if ( ! self::llm_ready())
        {
            return [
                'ok' => FALSE, 'code' => 'no_llm',
                'message' => 'Изучение недоступно: на сервере не подключена языковая модель.',
                'queued' => [], 'already' => [], 'runs' => [], 'limit' => FALSE, 'checked' => count($rows),
            ];
        }

        // Полный размер очереди ДО пачки: если он больше выбранного, надо
        // честно сказать про остаток (в «изучить всё» это остаток за лимитом).
        $total = max(count($rows), (int) ($options['total'] ?? count($rows)));

        $queued  = [];
        $already = [];
        $runs    = [];
        $limited = FALSE;
        $failed  = '';

        foreach ($rows as $row)
        {
            $push = self::study_push((int) $row['id'], '', (string) $row['kind'], [
                'manual'  => TRUE,
                'visitor' => $visitor,
                'user_id' => $options['user_id'] ?? NULL,
            ]);

            if ( ! empty($push['run_id']) AND $push['code'] === 'queued')
            {
                $queued[] = [
                    'unknown_id' => (int) $row['id'],
                    'name'       => (string) $row['name_raw'],
                    'run_id'     => (int) $push['run_id'],
                ];
                $runs[] = (int) $push['run_id'];
                continue;
            }

            if ($push['code'] === 'already')
            {
                $already[] = (string) $row['name_raw'];
                continue;
            }

            if ($push['code'] === 'limit')
            {
                // Дальше пробовать бессмысленно: лимит считается по всем
                // прогонам за сутки, следующий вызов упрётся в то же самое.
                $limited = TRUE;
                break;
            }

            // Прочие коды (queue_error/run_error/unknown) — это уже беда, а не
            // «нечего изучать»: причину показываем, а не прячем в счётчике.
            $failed = (string) $push['message'];
            break;
        }

        $done     = count($queued);
        $selected = count($rows);

        if ($failed !== '')
        {
            $message = 'Поставил '.$done.' из '.$selected.' — дальше сбой: '.$failed;
        }
        elseif ($limited)
        {
            $message = $done > 0
                ? 'Поставил '.$done.' задач — дальше упёрся в дневной лимит изучений ('.$done.' из '.$selected.'). Лимит поднимается ключом NJ646_STUDY_PER_DAY.'
                : 'Дневной лимит изучений исчерпан — ни одну задачу поставить не удалось. Лимит поднимается ключом NJ646_STUDY_PER_DAY.';
        }
        elseif ($done > 0)
        {
            $message = 'Поставил на изучение '.$done.' '.self::_plural($done, 'вещество', 'вещества', 'веществ')
                .' — ход видно в очереди ниже, карточки придут в черновики.';
        }
        elseif ($already)
        {
            $message = 'Все незнакомые вещества уже изучаются или изучены ('.$done.' новых задач не потребовалось).';
        }
        else
        {
            $message = 'Ни одну задачу поставить не удалось.';
        }

        if ($already)
        {
            $message .= ' Уже в работе: '.count($already).'.';
        }

        // Про остаток говорим, только если он реально достижим: при лимите
        // «нажмите ещё раз» было бы враньём — следующее нажатие упрётся в него же.
        if ($done > 0 AND $failed === '' AND ! $limited AND $total > $selected)
        {
            $message .= ' Не выбрано ещё '.($total - $selected).' — нажмите «изучить всё» ещё раз.';
        }

        return [
            'ok'      => ($done > 0),
            'code'    => $failed !== '' ? 'error' : ($done > 0 ? 'queued' : ($limited ? 'limit' : 'already')),
            'message' => $message,
            'queued'  => $queued,
            'already' => $already,
            'runs'    => $runs,
            'limit'   => $limited,
            'checked' => $selected,
        ];
    }

    /** Пустой ответ пачки изучения: изучать нечего (очередь пуста или id не найдены). */
    protected static function _study_batch_empty($checked = 0)
    {
        return [
            'ok' => FALSE, 'code' => 'empty',
            'message' => 'Изучать нечего: в очереди нет незнакомых веществ.',
            'queued' => [], 'already' => [], 'runs' => [], 'limit' => FALSE, 'checked' => (int) $checked,
        ];
    }

    /** Русское склонение по числу: 1 вещество, 2 вещества, 5 веществ. */
    protected static function _plural($n, $one, $few, $many)
    {
        $n  = abs((int) $n) % 100;
        $n1 = $n % 10;

        if ($n > 10 AND $n < 20)
        {
            return $many;
        }

        if ($n1 === 1)
        {
            return $one;
        }

        return ($n1 >= 2 AND $n1 <= 4) ? $few : $many;
    }

    /** Единый вид ответа study_push(): код + сообщение + данные. */
    protected static function _study_result($code, $message, array $extra = [])
    {
        return array_merge([
            'ok'      => in_array($code, ['queued', 'already'], TRUE),
            'code'    => (string) $code,
            'message' => (string) $message,
            'run_id'  => 0,
            'slug'    => '',
            'name'    => '',
            'error'   => '',
        ], $extra, [
            'ok' => in_array($code, ['queued', 'already'], TRUE),
        ]);
    }

    /**
     * Состояние прогонов изучения по их id — для опроса со страницы.
     *
     * Отдаём только то, что можно показать: статус, ошибку, готовую карточку
     * (slug и название черновика). Строки чужих посетителей не возвращаем:
     * страница опрашивает ИМЕННО свои прогоны.
     *
     * @param array  $run_ids список id прогонов
     * @param string $visitor посетитель, которому разрешено их видеть
     * @return array [['run_id' => …, 'status' => …, 'error' => …, 'slug' => …, 'name' => …, 'done' => bool], ...]
     */
    public static function study_state(array $run_ids, $visitor)
    {
        $ids = [];

        foreach ($run_ids as $id)
        {
            $id = (int) $id;

            if ($id > 0)
            {
                $ids[$id] = $id;
            }
        }

        if ( ! $ids)
        {
            return [];
        }

        $rows = self::_all('SELECT id, mode, status, visitor, error, job_id, created_at, finished_at
                              FROM nj_646_runs WHERE id IN ('.implode(',', $ids).')');

        // Вещество связано с прогоном ОБРАТНОЙ ссылкой (nj_646_unknowns.run_id):
        // в самом прогоне колонки unknown_id нет — так решила миграция 001.
        $linked = [];

        foreach (self::_all('SELECT id, name_raw, run_id, ingredient_id, status
                               FROM nj_646_unknowns
                              WHERE run_id IN ('.implode(',', $ids).')') as $unknown)
        {
            $linked[(int) $unknown['run_id']] = $unknown;
        }

        $out = [];

        foreach ($rows as $row)
        {
            $id = (int) $row['id'];

            // Чужой прогон не показываем (админ видит всё: его visitor — 'admin').
            if ($visitor !== 'admin' AND (string) $row['visitor'] !== (string) $visitor)
            {
                continue;
            }

            $unknown = $linked[$id] ?? NULL;
            $slug    = '';
            $name    = (string) ($unknown['name_raw'] ?? '');
            $card    = NULL;

            if ($unknown AND (int) $unknown['ingredient_id'] > 0)
            {
                $card = self::ingredient_by_id((int) $unknown['ingredient_id']);

                if ($card)
                {
                    $slug = (string) ($card['slug'] ?? '');
                    $name = trim((string) ($card['name_ru'] ?? '')) !== ''
                        ? (string) $card['name_ru'] : $name;
                }
            }

            $status = (string) $row['status'];

            $out[] = [
                'run_id'  => $id,
                'name'    => $name,
                'status'  => $status,
                'error'   => mb_substr(trim((string) $row['error']), 0, 300),
                'slug'    => $slug,
                'draft'   => ($slug !== '' AND $card !== NULL AND (string) ($card['status'] ?? '') !== 'published'),
                'done'    => in_array($status, ['done', 'error', 'cancelled'], TRUE),
                'ok'      => ($status === 'done' AND $slug !== ''),
                // Живой ход работы (этапы воркера, секундомер) — для статус-ряда
                // на странице: «изучаю» без этапа и времени выглядит зависанием.
                'live'    => self::run_live($row),
            ];
        }

        return $out;
    }

    /**
     * Здоровье очереди изучения: последние задачи канала + сколько висит.
     *
     * Админке это нужнее всего: владелец нажал «Изучить», увидел «Очередь
     * недоступна» и не понял, что произошло. Здесь видно честно — задачи есть
     * (и какие), либо очередь пуста, либо задача никем не взята.
     *
     * @return array ['jobs' => [...], 'pending' => int, 'unclaimed' => int, 'hint' => string]
     */
    public static function study_queue_state($limit = 6)
    {
        $jobs = [];
        $pending = 0;
        $unclaimed = 0;
        $wait_sec = max(60, self::limit('stall_hint_sec', 150));

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['jobs' => [], 'pending' => 0, 'unclaimed' => 0,
                'hint' => 'Очередь задач (nj120) недоступна: модуль не подключён.'];
        }

        try
        {
            $queue = Service_Nj120_JobQueue::instance();

            foreach ($queue->channel_jobs('nj646.study', max(1, min(20, $limit))) as $job)
            {
                $payload = is_array($job['payload']) ? $job['payload'] : json_decode((string) $job['payload'], TRUE);
                $status  = (string) $job['status'];
                $age     = time() - strtotime((string) $job['created_at']);
                // Скобки обязательны: у AND приоритет НИЖЕ присваивания — без
                // них $waited получил бы только первое сравнение, и «зависшей»
                // считалась бы любая свежая задача (поймано тестом).
                $waited  = ($status === 'pending' AND empty($job['claimed_at']) AND $age > $wait_sec);

                if (in_array($status, ['pending', 'processing'], TRUE))
                {
                    $pending++;
                }

                if ($waited)
                {
                    $unclaimed++;
                }

                $jobs[] = [
                    'id'       => (int) $job['id'],
                    'name'     => mb_substr((string) ($payload['name'] ?? ''), 0, 60),
                    'status'   => $status,
                    'attempts' => (int) $job['attempts'],
                    'claimed'  => (string) ($job['claimed_by'] ?? ''),
                    'error'    => mb_substr(trim((string) $job['error']), 0, 160),
                    'age_min'  => (int) round($age / 60),
                    'stuck'    => $waited,
                    'created'  => (string) $job['created_at'],
                    // Последний этап воркера (ctx.progress → события задачи):
                    // в админке видно, ЧТО он делает сейчас, а не только «ждёт».
                    'stage'    => self::_job_stage($queue, (int) $job['id']),
                ];
            }
        }
        catch (Throwable $e)
        {
            return ['jobs' => [], 'pending' => 0, 'unclaimed' => 0,
                'hint' => 'Очередь недоступна: '.$e->getMessage()];
        }

        $hint = '';

        if ($unclaimed > 0)
        {
            $hint = 'Задача изучения ждёт в очереди больше '.round($wait_sec / 60)
                  .' мин и её никто не взял: проверьте, что воркер запущен и канал nj646.study есть в WORKER_CHANNELS.';
        }

        return ['jobs' => $jobs, 'pending' => $pending, 'unclaimed' => $unclaimed, 'hint' => $hint];
    }

    /**
     * Последний этап задачи из журнала очереди ('' — если рапортов не было).
     *
     * Читаем публичным методом nj120 (`events`), а не своей выборкой по его
     * таблицам: контракт журнала — его дело. Сбой чтения не должен ломать
     * страницу админки — молча отдаём пустую строку.
     */
    protected static function _job_stage($queue, $job_id)
    {
        try
        {
            foreach (array_reverse((array) $queue->events((int) $job_id, 20)) as $event)
            {
                if ((string) ($event['event'] ?? '') === 'progress' AND trim((string) ($event['message'] ?? '')) !== '')
                {
                    return mb_substr(trim((string) $event['message']), 0, 120);
                }
            }
        }
        catch (Throwable $e)
        {
            return '';
        }

        return '';
    }

    public static function unknowns($status = NULL, $limit = 30)
    {
        self::ensure_tables();

        $where = '';

        if ($status !== NULL AND in_array($status, ['new', 'queued', 'studied', 'ignored'], TRUE))
        {
            $where = ' WHERE status = '.self::db()->quote($status);
        }

        return self::_all('SELECT * FROM nj_646_unknowns'.$where.
            ' ORDER BY seen_count DESC, id DESC LIMIT '.(int) max(1, min(100, $limit)));
    }

    /**
     * Мусор в очереди незнакомых: служебные строки этикетки, а не вещества.
     *
     * До `Label::composition_block()` разбор брал весь вставленный текст, и в
     * очередь (ОПЛАЧИВАЕМУЮ: каждая строка идёт на изучение моделью) попадали
     * «5 г», «ГОСТ 24901-2014», «Изготовитель: ООО …» — с одной этикетки
     * печенья набралось 14 таких. Накопленное надо убрать руками, поэтому
     * метод только ПОКАЗЫВАЕТ находки: удаляет человек кнопкой, увидев список.
     *
     * Правила — `Service_Nj646_Label::looks_like_service()` (чистая функция,
     * проверяется тестами). Уже изученные строки не трогаем: по ним есть
     * карточка, и её судьбу решает модерация, а не чистка.
     *
     * @param  int $limit сколько находок вернуть
     * @return array строки очереди (id, name, kind, seen_count, status)
     */
    public static function unknowns_junk($limit = 200)
    {
        self::ensure_tables();

        $limit = max(1, min(500, (int) $limit));
        $junk  = [];

        $rows = self::_all("SELECT id, name_raw AS name, kind, seen_count, status, last_seen
                              FROM nj_646_unknowns
                             WHERE status IN ('new', 'queued', 'ignored')
                             ORDER BY id ASC");

        foreach ($rows as $row)
        {
            if (Service_Nj646_Label::looks_like_service($row['name'] ?? ''))
            {
                $junk[] = $row;
            }

            if (count($junk) >= $limit)
            {
                break;
            }
        }

        return $junk;
    }

    /**
     * Удалить строки очереди НАСОВСЕМ (чистка мусора).
     *
     * Игнор («не изучать») мусор не убирает: строка остаётся в очереди,
     * мозолит глаза и снова всплывает в счётчиках. Здесь именно удаление, и
     * только тех строк, по которым НЕТ карточки (`studied` не трогаем — иначе
     * вещество в базе осталось бы без следа происхождения).
     *
     * @return int сколько строк удалено
     */
    public static function unknowns_delete(array $ids)
    {
        self::ensure_tables();

        $ids = self::_clean_ids($ids);

        if ( ! $ids)
        {
            return 0;
        }

        return (int) DB::query(NULL, "DELETE FROM nj_646_unknowns
                                       WHERE id IN (".implode(',', $ids).")
                                         AND status <> 'studied'")->execute();
    }

    /** Идентификаторы веществ, ждущих изучения (для пачек «изучить всё»). */
    public static function unknowns_pending_ids($limit = 50)
    {
        self::ensure_tables();

        $ids = [];

        foreach (self::_all("SELECT id FROM nj_646_unknowns
                              WHERE status IN ('new', 'queued')
                              ORDER BY seen_count DESC, id ASC
                              LIMIT ".(int) max(1, min(100, $limit))) as $row)
        {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Массовый игнор: пометить пачку незнакомцев «не изучать».
     *
     * Один UPDATE на пачку — по тому же счёту, что и в модерации карточек:
     * мусорных строк в очереди бывает много, и они приходят из чужих разборов.
     *
     * @return int сколько строк изменилось
     */
    public static function unknowns_ignore_many(array $ids)
    {
        self::ensure_tables();

        $ids = self::_clean_ids($ids);

        if ( ! $ids)
        {
            return 0;
        }

        $result = DB::query(NULL, "UPDATE nj_646_unknowns
                                      SET status = 'ignored', last_seen = now()
                                    WHERE id IN (".implode(',', $ids).")
                                      AND status NOT IN ('studied')")->execute();

        return (int) $result;
    }

    /** Идентификаторы очереди (для «игнорировать всё, кроме изученного»). */
    public static function unknowns_todo_ids($limit = 200)
    {
        self::ensure_tables();

        $ids = [];

        foreach (self::_all("SELECT id FROM nj_646_unknowns
                              WHERE status NOT IN ('studied')
                              ORDER BY seen_count DESC, id ASC
                              LIMIT ".(int) max(1, min(500, $limit))) as $row)
        {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Список id → только положительные целые, без повторов.
     *
     * Массовые действия принимают id из формы/JSON, то есть из чужого ввода:
     * всё, что не число, отбрасываем здесь, а не собираем SQL из строк.
     */
    protected static function _clean_ids(array $ids)
    {
        $clean = [];

        foreach ($ids as $id)
        {
            $id = (int) $id;

            if ($id > 0)
            {
                $clean[$id] = $id;
            }
        }

        return array_values($clean);
    }

    /** Сколько веществ реально ждут изучения (для подписи кнопки «изучить всё»). */
    public static function unknowns_pending_count()
    {
        self::ensure_tables();

        return (int) self::_one("SELECT COUNT(*) AS c FROM nj_646_unknowns
                                  WHERE status IN ('new', 'queued')", 'c');
    }
}
