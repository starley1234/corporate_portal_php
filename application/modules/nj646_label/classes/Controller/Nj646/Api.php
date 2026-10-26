<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Разбора состава» (NJ-646).
 *
 * Публичные точки (без логина, с лимитами по посетителю и IP):
 *   POST /label/api/analyze     текст состава → разбор, оценка, советы
 *   POST /label/api/photo       фото этикетки (base64) → очередь nj646.label
 *   GET  /label/api/status      статус прогона распознавания/изучения
 *   GET  /label/api/ingredient  карточка вещества по slug/id
 *   GET  /label/api/search      поиск по базе знаний
 *   POST /label/api/vote        подтвердить/оспорить оценку вещества
 *   POST /label/api/profile     профиль посетителя (кожа, диета, ограничения)
 *   POST /label/api/study       попросить ИИ изучить незнакомое вещество
 *   POST /label/api/studies     ход изучения своих прогонов (опрос страницы)
 *   POST /label/api/study_pending  что из незнакомых уже разобрано (полный ответ)
 *
 * Служебная точка воркера:
 *   POST /label/api/worker_result   результат прогона (X-Worker-Token)
 */
class Controller_Nj646_Api extends Controller
{
    public $auto_render = FALSE;

    /** @var array тело запроса */
    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();

        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }

        if (empty($this->_body) AND $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this->response;
    }

    protected function _fail($message, $status = 400)
    {
        return $this->_respond(['ok' => FALSE, 'error' => (string) $message], $status);
    }

    protected function _visitor()
    {
        return class_exists('Visitor') ? (string) Visitor::uid() : '';
    }

    protected function _user_id()
    {
        return class_exists('Visitor') ? Visitor::user_id() : NULL;
    }

    protected function _text($key, $max)
    {
        $text = (string) ($this->_body[$key] ?? '');
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);

        return trim(mb_substr($text, 0, (int) $max));
    }

    protected function _int($key, $default = 0)
    {
        return isset($this->_body[$key]) ? (int) $this->_body[$key] : (int) $default;
    }

    /**
     * Булев флаг из тела запроса.
     *
     * JS шлёт `detailed: true`/`studies: 0`, форма — «1»/«on»: принимаем и то,
     * и другое (строка «0» и «false» — это «нет», а не «непустая строка»).
     */
    protected function _flag($key, $default = FALSE)
    {
        if ( ! array_key_exists($key, $this->_body))
        {
            return (bool) $default;
        }

        $value = $this->_body[$key];

        if (is_bool($value))
        {
            return $value;
        }

        if (is_string($value))
        {
            return ! in_array(strtolower(trim($value)), ['0', '', 'false', 'no', 'off'], TRUE);
        }

        return (bool) $value;
    }

    protected function _check_worker()
    {
        $token = (string) ($_SERVER['HTTP_X_WORKER_TOKEN'] ?? '');
        $want  = (string) getenv('WORKER_API_TOKEN');

        return ($want !== '' AND $token !== '' AND hash_equals($want, $token));
    }

    // ------------------------------------------------------------------- разбор

    public function action_analyze()
    {
        Service_Nj646_Seed::ensure();

        $text = $this->_text('text', Service_Nj646_Store::limit('text_max', 8000));

        if (mb_strlen($text) < 3)
        {
            return $this->_fail('Вставьте состав с упаковки — хотя бы пару ингредиентов.');
        }

        $visitor  = $this->_visitor();
        $per_hour = Service_Nj646_Store::limit('analyses_per_hour', 30);

        if ($visitor !== '' AND Service_Nj646_Store::analyses_last_hour($visitor) >= $per_hour)
        {
            return $this->_fail('Лимит разборов: не больше '.$per_hour.' в час. Это защита базы от перегрузки.', 429);
        }

        $kind = (string) ($this->_body['kind'] ?? 'auto');

        if ( ! in_array($kind, ['auto', 'food', 'cosmetic'], TRUE))
        {
            $kind = 'auto';
        }

        // На разбор веществ идёт ТОЛЬКО блок состава: название, изготовитель
        // и пищевая ценность из вставленного текста иначе становились
        // «неизвестными веществами» и уходили в очередь изучения.
        $items  = Service_Nj646_Label::parse(Service_Nj646_Label::composition_block($text));
        $items  = array_slice($items, 0, Service_Nj646_Store::limit('items_max', 120));
        $study_limit = FALSE;
        $index  = Service_Nj646_Store::index();
        $match  = Service_Nj646_Label::match($items, $index);
        $result = Service_Nj646_Label::score($match['matched'], $match['unknown']);
        $kind   = $kind === 'auto' ? Service_Nj646_Label::kind_guess($text, $match['matched']) : $kind;

        // Тип продукта («зубная паста», «печенье», «пиво») — модуль определяет
        // сам, посетитель ничего не выбирает. Ярлык уходит в БД: по нему
        // строится список разборов (иконка и подпись), статистика и будущие
        // сравнения товаров (владелец: «пускай сама определяет»).
        $product = Service_Nj646_Label::product_kind($text, $kind);

        $profile = $visitor !== '' ? Service_Nj646_Store::profile($visitor) : [];
        $reasons = (array) Service_Nj646_Store::cfg('avoid_reasons', []);
        $advice  = Service_Nj646_Label::advice($match['matched'], $match['unknown'], $profile, $reasons);

        // Накопление: +1 к встречаемости каждого найденного вещества.
        foreach ($match['matched'] as $row)
        {
            Service_Nj646_Store::ingredient_hit((int) $row['id']);
        }

        // Развёрнутый ответ (NJ646_DETAILED, по умолчанию включён). Обычный
        // разбор отдаёт то, что уже знает база; развёрнутый — доводит дело до
        // конца: незнакомые вещества уходят на изучение СРАЗУ (не дожидаясь
        // второго упоминания), страница показывает ход изучения, а когда
        // карточки готовы — пересчитывает ответ уже по пополненной базе.
        // Владелец (03.10.2026): «нужен режим, при котором пользователь
        // всё-таки получает развёрнутый ответ». `studies: 0` (пересчёт после
        // изучения) новых задач не ставит — иначе каждый пересчёт заново
        // забивал бы очередь.
        $detailed = $this->_flag('detailed', (bool) Service_Nj646_Store::cfg('limits.detailed', 1));

        // ПОЛНЫЙ ОТВЕТ (NJ646_FULL_ANSWER). Отличие от развёрнутого: изучаются
        // ВСЕ незнакомые вещества состава (в развёрнутом — пачка побольше),
        // у автоизучения свой суточный лимит, а страница ждёт дольше. Владелец
        // (03.10.2026): «режим, при котором пользователю выдаётся полный
        // готовый ответ (то что есть + автоматически изучается всё неизученное
        // — полный ответ)». Режим включает конфиг; клиент может только
        // отказаться от изучения (studies: 0 — пересчёт после ожидания).
        $full     = ($detailed AND (bool) Service_Nj646_Store::cfg('limits.full_answer', 0));
        $studies  = ($detailed AND $this->_flag('studies', TRUE));

        // Незнакомые позиции — в очередь базы знаний; в развёрнутом режиме
        // «созревшие» уходят на изучение сразу и пачкой побольше, в полном —
        // вообще все, и лимит у пачки свой.
        $queued   = [];
        $study_runs = [];
        $due_seen = $detailed ? 1 : Service_Nj646_Store::limit('unknown_min_seen', 2);
        $budget   = $detailed
            ? Service_Nj646_Store::limit('detailed_studies', 3)
            : Service_Nj646_Store::limit('unknown_per_run', 3);

        if ($full)
        {
            // 0 в конфиге — без предела: «полный» ответ обязан покрыть весь состав.
            $budget = max(0, Service_Nj646_Store::limit('full_studies', 0));
            $budget = $budget === 0 ? count($match['unknown']) : $budget;
        }

        foreach (array_unique($match['unknown']) as $name)
        {
            $touch = Service_Nj646_Store::unknown_touch($name, $kind, $due_seen);

            if ( ! $touch)
            {
                continue;
            }

            if ( ! $studies OR ! $touch['due'] OR count($queued) >= $budget
                 OR ! Service_Nj646_Store::llm_ready())
            {
                continue;
            }

            $push = Service_Nj646_Store::study_push((int) $touch['id'], (string) $name, $kind, [
                'manual'  => FALSE,
                'visitor' => $visitor,
                'user_id' => $this->_user_id(),
                // Полный режим: у автоизучения свой, более высокий суточный
                // потолок — иначе «изучить всё» встанет на третьем веществе.
                'daily_limit' => $full ? Service_Nj646_Store::limit('full_per_day', 60) : NULL,
                'limit_key'   => $full ? 'NJ646_FULL_PER_DAY' : 'NJ646_STUDY_PER_DAY',
            ]);

            if ( ! empty($push['run_id']))
            {
                $queued[] = (string) $name;
                $study_runs[] = [
                    'run_id'  => (int) $push['run_id'],
                    'unknown_id' => (int) $touch['id'],
                    'name'    => (string) $name,
                    'status'  => 'queued',
                ];
            }
            elseif ($push['code'] === 'limit')
            {
                // Лимит изучений на сегодня: дальше пробовать бессмысленно —
                // честно говорим об этом в ответе (страница покажет плашкой).
                $study_limit = TRUE;
                break;
            }
            elseif ($push['code'] === 'already' AND ! empty($push['slug']))
            {
                $queued[] = (string) $name;
            }
        }

        // Сопутствующая информация с этикетки (товар, производитель): разбор её
        // сохраняет в общие таблицы проекта — и в снимок разбора. Для фото
        // берём СТРОКИ ЭТИКЕТКИ прогона: в тексте состава производителя нет,
        // а в транскрипте он есть. Чужой прогон не читаем.
        $label_text = $text;
        $run_id     = $this->_int('run_id');
        $source     = 'text';
        $transcript = '';

        if ($run_id > 0)
        {
            $run = Service_Nj646_Store::run_get($run_id);

            // Чужой прогон не читаем — то же правило, что на странице разбора
            // (`Store::analysis_owned()`). Здесь был баг приоритетов: `$own = $run
            // AND (…)` присваивал `$own` сам прогон (массив всегда truthy), а
            // проверка «свой ли он» отбрасывалась — в текст разбора попадал
            // транскрипт чужой этикетки.
            $own = $run ? Service_Nj646_Store::analysis_owned((array) $run, $visitor, $this->_user_id()) : FALSE;

            if ($own)
            {
                $source = (string) ($run['mode'] ?? 'photo') === 'photo' ? 'photo' : 'text';
                $transcript = trim((string) ($run['result']['transcript'] ?? ''));

                if ($transcript !== '')
                {
                    $label_text = $transcript."\n".$text;
                }
            }
        }

        // Название товара: с этикетки (без модели), иначе РАБОЧЕЕ — «Мыло без
        // названия» по типу продукта, с пометкой `auto`. Пометка едет в БД и в
        // интерфейс: списки показывают, что имя придумано модулем, а не найдено
        // на этикетке. Если имени нет, модель попробует дать настоящее отдельной
        // задачей (`title_improve`) — тогда пометка снимется.
        $title_info = Service_Nj646_Store::title_auto_info($label_text, $this->_text('title', 200),
            (string) $product['title']);
        $title      = $title_info['title'];

        // Товар сохраняется ВСЕГДА: списки показывают разбор ТОВАРА, а не строку
        // «63/100 · нейтрально». Вместе с товаром уезжает тип продукта, имя
        // (возможно, рабочее) и признак этого.
        $goods = Service_Nj646_Store::goods_save($label_text, [
            'title'        => $title,
            'title_auto'   => (bool) $title_info['auto'],
            'product_kind' => (string) $product['slug'],
            'kind'         => $kind,
        ]);

        // Строки этикетки храним рядом со снимком товара: на странице разбора
        // их можно сверить с фото (модель на мелком шрифте угадывает, и это
        // видно ровно по строкам). Отдельной колонки не заводим — снимок
        // `about` и есть «что было на этикетке в тот момент».
        $about = (array) ($goods['about'] ?? []);

        if ($transcript !== '')
        {
            $about['transcript'] = mb_substr($transcript, 0, 6000);
        }

        $analysis_id = Service_Nj646_Store::analysis_save([
            'user_id'  => $this->_user_id(),
            'visitor'  => $visitor,
            'kind'     => $kind,
            'product_kind' => (string) $product['slug'],
            'source'   => $source,
            'title'    => $title,
            'title_auto' => (bool) $title_info['auto'],
            'raw_text' => $text,
            'score'    => $result['score'],
            'verdict'  => $result['verdict'],
            'counts'   => $result['counts'],
            'items'    => array_map(function ($row) {
                return [
                    'slug' => $row['slug'], 'name' => $row['name_ru'], 'risk' => (int) $row['risk'],
                    'verdict' => $row['verdict'], 'how' => $row['how'] ?? 'exact',
                ];
            }, $match['matched']),
            'unknown'  => array_values($match['unknown']),
            'profile'  => ['skin' => $profile['skin'] ?? '', 'diet' => $profile['diet'] ?? 'none',
                           'conditions' => $profile['conditions'] ?? []],
            'about'      => $about,
            'producer_id' => (int) ($goods['ids']['producer_id'] ?? 0),
            'product_id'  => (int) ($goods['ids']['product_id'] ?? 0),
        ]);

        // Итог разбора — на товар: карточка и списки показывают оценку и
        // вердикт товара, не читая разборы заново (см. Store::_list_sql).
        Service_Nj646_Store::product_touch(
            (int) ($goods['ids']['product_id'] ?? 0),
            $analysis_id,
            (int) $result['score'],
            (string) $result['verdict']
        );

        if ($title_info['auto'] AND $analysis_id > 0)
        {
            Service_Nj646_Store::title_improve($analysis_id, $label_text, [
                'visitor'   => $visitor,
                'user_id'   => $this->_user_id(),
                'client_ip' => Service_Nj100_Ratelimit::client_ip(),
            ]);
        }

        return $this->_respond([
            'ok'       => TRUE,
            'analysis' => $analysis_id,
            'title'    => $title,
            'title_auto' => (bool) $title_info['auto'],
            'kind'     => $kind,
            // Тип продукта — сразу в ответе: страница показывает его в итоге
            // («зубная паста»), и по нему же выбирается иконка.
            'product'  => ['id' => (int) ($goods['ids']['product_id'] ?? 0),
                           'name' => (string) ($goods['product']['name'] ?? $title),
                           'name_auto' => Service_Nj646_Store::flag($goods['product']['name_auto'] ?? FALSE),
                           'producer' => (string) ($goods['producer']['name'] ?? ''),
                           'slug' => $product['slug'], 'title' => $product['title'],
                           'icon' => $product['icon'], 'score' => (int) $product['score']],
            'source'   => $source,
            'score'    => $result['score'],
            'verdict'  => $result['verdict'],
            'verdict_label' => Service_Nj646_Label::verdict_label($result['verdict']),
            'score_label'   => Service_Nj646_Label::score_label($result['score']),
            'counts'   => $result['counts'],
            'items'    => array_map(function ($row) {
                return [
                    'slug'    => $row['slug'],
                    'name'    => $row['name_ru'],
                    'name_int' => $row['name_int'],
                    'risk'    => (int) $row['risk'],
                    'verdict' => $row['verdict'],
                    'verdict_label' => Service_Nj646_Label::verdict_label($row['verdict']),
                    'category' => $row['category'],
                    'e_code'  => $row['e_code'],
                    'summary' => $row['summary'],
                    'avoid'   => $row['avoid'],
                    'how'     => $row['how'] ?? 'exact',
                    'percent' => $row['percent'] ?? NULL,
                    // Карточка, собранная ИИ, лежит черновиком до проверки
                    // редактором. Развёрнутый режим доводит ответ до конца
                    // ИМЕННО такими карточками, поэтому страница обязана
                    // честно помечать их: «изучено ИИ, ждёт проверки».
                    'draft'   => ((string) ($row['status'] ?? '') === 'draft'),
                    'source'  => (string) ($row['source'] ?? ''),
                ];
            }, $match['matched']),
            'unknown'  => array_values($match['unknown']),
            'queued'   => $queued,
            'advice'   => $advice,
            'stats'    => Service_Nj646_Store::stats(),
            // Развёрнутый ответ: что уже ушло на изучение, сколько секунд
            // страница может подождать и можно ли ждать вообще.
            'detailed' => $detailed,
            'full'     => $full,
            'studies'  => $study_runs,
            // Сопутствующая информация с этикетки — товар и производитель с
            // готовыми ссылками (карточки, онтология предприятий, справочник).
            'goods'    => Service_Nj646_Store::goods_view(
                $goods['product'] ?? NULL, $goods['producer'] ?? NULL),
            'study_limit' => ! empty($study_limit),
            'study_limit_key' => $full ? 'NJ646_FULL_PER_DAY' : 'NJ646_STUDY_PER_DAY',
            'study_wait_sec' => $full
                ? (int) Service_Nj646_Store::cfg('limits.full_wait_sec', 300)
                : (int) Service_Nj646_Store::cfg('limits.detailed_wait_sec', 45),
            'llm_ready' => Service_Nj646_Store::llm_ready(),
        ]);
    }

    // --------------------------------------------------------------------- фото

    public function action_photo()
    {
        if ( ! Service_Nj646_Store::llm_ready())
        {
            return $this->_fail('Распознавание фото пока недоступно: на сервере не подключена vision-модель. Вставьте состав текстом — разбор работает без ИИ.', 200);
        }

        $raw = (string) ($this->_body['image'] ?? '');

        if ($raw === '')
        {
            return $this->_fail('Прикрепите фото этикетки.');
        }

        // Поддерживаем и data URL, и «голый» base64.
        if (strpos($raw, 'data:') === 0)
        {
            $comma = strpos($raw, ',');
            $raw   = $comma !== FALSE ? substr($raw, $comma + 1) : '';
        }

        $bytes = base64_decode(preg_replace('/\s+/', '', $raw), TRUE);

        if ($bytes === FALSE OR ! Service_Nj646_Label::looks_like_image($bytes))
        {
            return $this->_fail('Это не похоже на изображение (JPEG/PNG/WebP).');
        }

        $max_mb = Service_Nj646_Store::limit('photo_mb', 8);

        if (strlen($bytes) > $max_mb * 1024 * 1024)
        {
            return $this->_fail('Фото больше '.$max_mb.' МБ. Сожмите его или сфотографируйте текст крупнее.');
        }

        $ip       = Service_Nj100_Ratelimit::client_ip();
        $per_hour = Service_Nj646_Store::limit('photo_per_hour', 10);

        if (Service_Nj646_Store::runs_last_hour($ip, 'photo') >= $per_hour)
        {
            return $this->_fail('Лимит распознаваний: не больше '.$per_hour.' в час.', 429);
        }

        $visitor = $this->_visitor();
        $run_id  = Service_Nj646_Store::run_create([
            'user_id'   => $this->_user_id(),
            'visitor'   => $visitor,
            'mode'      => 'photo',
            'client_ip' => $ip,
        ]);

        if ( ! $run_id)
        {
            return $this->_fail('Не удалось создать прогон', 500);
        }

        // Кадр — НА ДИСК, а в задаче едет только id прогона. Раньше base64
        // (8 МБ → ~10,7 МБ) лежал в payload: строка оседала в jsonb общей
        // очереди проекта, её тянул каждый опрос воркера, а мёртвая задача
        // держала фото вечно. Воркер забирает файл отдельным запросом
        // (action_photo_file) — см. пояснение у Store::photo_dir().
        $ext = Service_Nj646_Store::photo_save($run_id, $bytes);

        if ($ext === '')
        {
            Service_Nj646_Store::run_apply($run_id, ['status' => 'error',
                'error' => 'Не удалось сохранить фото на сервере']);

            return $this->_fail('Не удалось сохранить фото — попробуйте ещё раз или вставьте состав текстом.', 500);
        }

        // Заодно подчищаем кадры задач, которые умерли вместе с воркером.
        Service_Nj646_Store::photo_gc();

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj646.label', [
                'run_id'     => $run_id,
                'photo'      => TRUE,
                'media_type' => Service_Nj646_Label::mime($bytes),
                'kind'       => (string) ($this->_body['kind'] ?? 'auto'),
            ], [
                'module'       => 'nj646_label',
                // Больше NJ646_LLM_TIMEOUT воркера (+30 с на разбор ответа и
                // доставку): иначе задача снимается ровно в момент ответа
                // модели, и на сайт не уезжает ни текст, ни ошибка.
                'timeout_sec'  => 360,
                'max_attempts' => 2,
            ]);

            Service_Nj646_Store::run_set_job($run_id, $job_id);

            // С этого места судьба фото решается в воркере: прогон, задача и
            // размер кадра — в лог сайта, чтобы потом было с чем сверять
            // ротируемый лог воркера (workers/logs/n2j-worker.log).
            Log_Plain::event(Log::INFO,
                'label: фото принято — прогон :run, задача :job, :kb КБ, IP :ip',
                [':run' => $run_id, ':job' => $job_id,
                 ':kb' => (int) ceil(strlen($bytes) / 1024),
                 ':ip' => $ip]);
        }
        catch (Throwable $e)
        {
            Service_Nj646_Store::run_apply($run_id, ['status' => 'error',
                'error' => 'Не удалось поставить задачу в очередь: '.$e->getMessage()]);
            Service_Nj646_Store::photo_drop($run_id);

            Log_Plain::event(Log::ERROR,
                'label: прогон :run создан, но задачу поставить не удалось: :e',
                [':run' => $run_id, ':e' => get_class($e).': '.$e->getMessage()],
                [':run' => $run_id, ':e' => get_class($e).': '.$e->getMessage(), 'exception' => $e]);

            return $this->_fail('Очередь недоступна: '.$e->getMessage(), 503);
        }

        return $this->_respond([
            'ok'      => TRUE,
            'run_id'  => $run_id,
            'message' => 'Фото ушло на распознавание — обычно это несколько секунд.',
        ]);
    }

    /** Статус прогона: фото этикетки или изучение ингредиента. */
    public function action_status()
    {
        $id = $this->_int('id');

        if ($id <= 0)
        {
            return $this->_fail('Нужен id прогона');
        }

        // Дожим: если воркер не смог сдать результат сам (нет токена, сеть,
        // 500 на приёмке), забираем его из задачи очереди — иначе страница
        // «Распознаёт этикетку…» бесконечно, хотя работа сделана.
        $watch   = Service_Nj646_Store::run_watch($id);
        $run     = $watch['run'];
        $visitor = $this->_visitor();

        if ( ! $run OR ($visitor !== '' AND (string) $run['visitor'] !== $visitor AND $this->_user_id() === NULL))
        {
            return $this->_fail('Прогон не найден', 404);
        }

        // Живой статус-ряд: этапы воркера, секундомер, «нет пульса» — общий
        // контракт платформы (partials/live_status). Владелец (03.10.2026):
        // «процесс индикации воркера сделай нагляднее (посмотри на эталон)».
        // Поле status остаётся своим (queued|running|done|error), ряд читает
        // status_live — так страница не путает статус прогона со статусом ряда.
        $live = Service_Nj646_Store::run_live($run);

        return $this->_respond([
            'ok'     => TRUE,
            'run_id' => $id,
            'mode'   => $run['mode'],
            'status' => $run['status'],
            'result' => $run['result'],
            'error'  => $run['error'],
            'hint'   => (string) ($watch['hint'] ?? ''),
            // контракт статус-ряда (partials/live_status_js)
            'status_live' => (string) $live['status'],
            'stage'       => (string) ($live['stage'] !== '' ? $live['stage'] : ''),
            'stages'      => (array) $live['stages'],
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'note'        => (string) ($live['note'] !== '' ? $live['note'] : (string) ($watch['hint'] ?? '')),
            'attempts'    => (int) $live['attempts'],
        ]);
    }

    // ------------------------------------------------------------------ база

    public function action_ingredient()
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($this->_body['slug'] ?? '')));
        $row  = $slug !== '' ? Service_Nj646_Store::ingredient($slug) : NULL;

        if ( ! $row AND $this->_int('id') > 0)
        {
            $row = Service_Nj646_Store::ingredient_by_id($this->_int('id'));
        }

        if ( ! $row OR $row['status'] === 'hidden')
        {
            return $this->_fail('Вещество не найдено', 404);
        }

        return $this->_respond([
            'ok'        => TRUE,
            'ingredient' => [
                'id' => (int) $row['id'],
                'slug' => $row['slug'], 'name_ru' => $row['name_ru'], 'name_int' => $row['name_int'],
                'kind' => $row['kind'], 'risk' => (int) $row['risk'], 'verdict' => $row['verdict'],
                'verdict_label' => Service_Nj646_Label::verdict_label($row['verdict']),
                'category' => $row['category'], 'e_code' => $row['e_code'],
                'summary' => $row['summary'], 'details' => $row['details'],
                'synonyms' => $row['synonyms'], 'avoid' => $row['avoid'], 'sources' => $row['sources'],
                'source' => $row['source'], 'status' => $row['status'], 'hits' => (int) $row['hits'],
            ],
            'votes' => Service_Nj646_Store::votes((int) $row['id']),
            // Похожие из той же категории: карточка была тупиком — человек
            // прочитал про одно вещество и уходил. Теперь есть следующий шаг
            // (долг модуля «подсказки похожих веществ»).
            'similar' => Service_Nj646_Store::ingredient_similar($row, 6),
        ]);
    }

    public function action_search()
    {
        $q = mb_substr(trim((string) ($this->_body['q'] ?? '')), 0, 120);

        return $this->_respond([
            'ok'    => TRUE,
            'query' => $q,
            'items' => Service_Nj646_Store::ingredient_search($q, 12),
        ]);
    }

    /**
     * «Удалить мои данные»: профиль, разборы и прогоны этого посетителя.
     *
     * Данные медицинского характера (аллергии, особенности) человек должен уметь
     * стереть сам, не дожидаясь автоочистки. Токен сессии обязателен: действие
     * необратимое, и чужая страница не должна отправлять его за посетителя.
     * Метка берётся из куки — свою историю человек удаляет, только стоя в своём
     * браузере; чужие разборы этим запросом не достать.
     */
    public function action_forget()
    {
        if ( ! Security::check((string) ($this->_body['csrf'] ?? '')))
        {
            return $this->_fail('Сессия устарела — обновите страницу и попробуйте ещё раз.', 403);
        }

        $visitor = $this->_visitor();

        if ($visitor === '')
        {
            return $this->_fail('Не удалось определить посетителя — включите куки.');
        }

        $user_id = class_exists('Visitor') ? (int) Visitor::user_id() : 0;
        $counts  = Service_Nj646_Store::forget_visitor($visitor, $user_id);

        if (class_exists('Log_Plain'))
        {
            Log_Plain::event('nj646.forget', [
                'visitor'  => mb_substr($visitor, 0, 10),
                'analyses' => $counts['analyses'],
                'runs'     => $counts['runs'],
            ]);
        }

        return $this->_respond(['ok' => TRUE, 'deleted' => $counts]);
    }

    public function action_vote()
    {
        Service_Nj646_Seed::ensure();

        $id = $this->_int('ingredient_id');

        if ($id <= 0 OR ! Service_Nj646_Store::ingredient_by_id($id))
        {
            return $this->_fail('Вещество не найдено', 404);
        }

        $visitor = $this->_visitor();

        if ($visitor === '')
        {
            return $this->_fail('Не удалось определить посетителя — включите куки.');
        }

        $per_day = Service_Nj646_Store::limit('corrections_per_day', 20);
        $row     = Service_Nj646_Store::db()->query(Database::SELECT,
            'SELECT COUNT(*) AS c FROM nj_646_votes WHERE visitor = '.Service_Nj646_Store::db()->quote($visitor).
            ' AND created_at > now() - interval \'1 day\'', FALSE)->current();

        if ($row AND (int) $row['c'] >= $per_day)
        {
            return $this->_fail('Лимит правок: не больше '.$per_day.' в сутки.', 429);
        }

        $value = $this->_int('value', 1) >= 0 ? 1 : -1;
        $note  = mb_substr((string) ($this->_body['note'] ?? ''), 0, 400);

        Service_Nj646_Store::vote($id, $visitor, $value, $note);

        return $this->_respond([
            'ok'    => TRUE,
            'votes' => Service_Nj646_Store::votes($id),
        ]);
    }

    public function action_profile()
    {
        $visitor = $this->_visitor();

        if ($visitor === '')
        {
            return $this->_fail('Не удалось определить посетителя — включите куки.');
        }

        if ( ! isset($this->_body['skin']) AND ! isset($this->_body['diet']) AND ! isset($this->_body['conditions']))
        {
            return $this->_respond(['ok' => TRUE, 'profile' => Service_Nj646_Store::profile($visitor)]);
        }

        $profile = Service_Nj646_Store::profile_save($visitor, [
            'skin'       => (string) ($this->_body['skin'] ?? ''),
            'diet'       => (string) ($this->_body['diet'] ?? 'none'),
            'conditions' => (array) ($this->_body['conditions'] ?? []),
            'allergies'  => (string) ($this->_body['allergies'] ?? ''),
        ]);

        return $this->_respond(['ok' => TRUE, 'profile' => $profile]);
    }

    /** Попросить ИИ изучить незнакомое вещество (кнопка «изучить» в разборе). */
    public function action_study()
    {
        Service_Nj646_Seed::ensure();

        $unknown_id = $this->_int('unknown_id');
        $name       = $this->_text('name', 200);

        if ($unknown_id <= 0 AND $name === '')
        {
            return $this->_fail('Укажите незнакомое вещество.');
        }

        // Постановку ведёт Store::study_push — одна точка для страницы и
        // админки, и она называет ПРИЧИНУ отказа кодом (лимит, нет модели,
        // очередь), а не «что-то не получилось».
        $push = Service_Nj646_Store::study_push($unknown_id, $name, 'both', [
            'manual'  => TRUE,
            'visitor' => $this->_visitor(),
            'user_id' => $this->_user_id(),
        ]);

        if ( ! $push['ok'])
        {
            return $this->_fail($push['message'], $push['code'] === 'limit' ? 429 : 200);
        }

        $message = $push['code'] === 'already'
            ? 'Это вещество уже изучено — карточка в базе знаний.'
            : 'Вещество ушло на изучение: состав уточнится, как только модель ответит.';

        return $this->_respond([
            'ok'      => TRUE,
            'already' => $push['code'] === 'already',
            'run_id'  => (int) $push['run_id'],
            'slug'    => (string) $push['slug'],
            'message' => $message,
        ]);
    }

    /**
     * Состояние прогонов изучения — опрос со страницы (развёрнутый ответ).
     *
     * Тело: `{runs: [12, 13]}`. Отвечаем только по своим прогонам (или админу):
     * чужой id в списке просто не появится в ответе.
     */
    public function action_studies()
    {
        Service_Nj646_Seed::ensure();

        $ids = (array) ($this->_body['runs'] ?? []);

        return $this->_respond([
            'ok'    => TRUE,
            'runs'  => Service_Nj646_Store::study_state($ids, $this->_visitor()),
        ]);
    }

    /**
     * Что из этих незнакомых веществ уже разобрано — опрос ПОЛНОГО ответа.
     *
     * Страница помнит неизвестные позиции последнего разбора и ждёт, пока они
     * станут карточками; по этому запросу она видит, сколько осталось, и сама
     * пересчитывает разбор, когда ждать больше нечего. Список — не секрет
     * (это же текст, который человек сам прислал), поэтому имена принимаем
     * прямо в теле, а не по id.
     */
    public function action_study_pending()
    {
        Service_Nj646_Seed::ensure();

        $names = (array) ($this->_body['names'] ?? []);
        $state = Service_Nj646_Store::unknowns_pending_by_names($names);

        return $this->_respond([
            'ok'      => TRUE,
            'pending' => $state['pending'],
            'ready'   => $state['ready'],
            'left'    => count($state['pending']),
        ]);
    }

    // ------------------------------------------------------------------ воркер

    /**
     * Приёмка результата прогона от воркера.
     *
     * ВАЖНО про журнал. 03.10.2026 именно эта точка падала с 500 (jsonb
     * дважды закавычен), и разбираться пришлось догадками: воркер видел только
     * «HTTP 500», а на сайте не было ни одной записи о том, что приёмка
     * вообще была. Теперь каждый исход пишется в плоский лог сайта
     * (`application/logs/n2j-site.log`, см. Log_Plain):
     *
     *   label: результат прогона 51 принят | {"run":51,"status":"done"}
     *   label: приёмка прогона 51 УПАЛА | {"class":"Database_Exception",...}
     *
     * Причина падения возвращается воркеру в теле ответа — значит, попадёт и
     * в его ротируемый лог (workers/logs/n2j-worker.log): две стороны об одном
     * событии, и гадать больше не нужно.
     */
    /**
     * Кадр прогона для воркера: POST /label/api/photo_file {run_id} → {image_b64}.
     *
     * Воркер ходит по HTTPS с другого хоста, поэтому путь к файлу ему бесполезен —
     * он забирает содержимое здесь. Токен тот же, что у приёмки результата
     * (`X-Worker-Token`), и другого доступа к кадру нет: имя файла собирается из
     * целого id прогона, в путь не попадает ничего из запроса.
     *
     * Отвечаем base64, а не потоком: у воркера уже есть готовый разбор base64
     * (он шлёт фото модели как data URL), и один лишний шаг в транспорте —
     * меньше кода на обеих сторонах.
     */
    public function action_photo_file()
    {
        if ( ! $this->_check_worker())
        {
            return $this->_fail('Неверный токен воркера', 403);
        }

        $id = $this->_int('run_id');

        if ($id <= 0)
        {
            return $this->_fail('Нужен run_id', 400);
        }

        list($bytes, $mime) = Service_Nj646_Store::photo_bytes($id);

        if ($bytes === NULL)
        {
            // Файла нет: прогон уже закрыт приёмкой (кадр удалён) или задача
            // вычищена. Честный 404 — воркер напишет это в лог и не зависнет.
            Log_Plain::event(Log::WARNING, 'label: кадр прогона :run не найден (задача #:job)', [
                ':run' => $id,
                ':job' => (string) ($this->_body['job_id'] ?? ''),
            ]);

            return $this->_fail('Кадр прогона не найден', 404);
        }

        return $this->_respond([
            'ok'         => TRUE,
            'image_b64'  => base64_encode($bytes),
            'media_type' => $mime !== '' ? $mime : Service_Nj646_Label::mime($bytes),
            'kb'         => (int) ceil(strlen($bytes) / 1024),
        ]);
    }

    public function action_worker_result()
    {
        if ( ! $this->_check_worker())
        {
            Log_Plain::event(Log::WARNING,
                'label: приёмка результата — неверный токен воркера (:ip, режим :mode)',
                [':ip' => Service_Nj100_Ratelimit::client_ip(),
                 ':mode' => (string) ($this->_body['mode'] ?? '')]);

            return $this->_fail('Неверный токен воркера', 403);
        }

        $id = $this->_int('run_id');

        if ($id <= 0 OR ! Service_Nj646_Store::run_get($id))
        {
            Log_Plain::event(Log::WARNING, 'label: приёмка результата — прогон :run не найден',
                [':run' => $id]);

            return $this->_fail('Прогон не найден', 404);
        }

        // Разбор payload и запись — в Store: та же точка работает у дожима
        // (run_watch) из опроса статуса, иначе поля разъедутся.
        try
        {
            $applied = Service_Nj646_Store::run_apply_worker($id, $this->_body);
        }
        catch (Throwable $e)
        {
            // Прогон останется queued, страница будет крутить «Распознаю…» —
            // поэтому в лог уходит ВСЁ, чего не хватало 03.10.2026: класс и
            // текст исключения, режим, размер состава и задачи, длина payload.
            $reason = get_class($e).': '.$e->getMessage();

            $context = [
                ':run'    => $id,
                ':e'      => $reason,
                ':mode'   => (string) ($this->_body['mode'] ?? ''),
                ':status' => (string) ($this->_body['status'] ?? ''),
                ':len'    => mb_strlen((string) ($this->_body['text'] ?? '')),
                ':bytes'  => strlen((string) $this->request->body()),
            ];

            Log_Plain::event(Log::ERROR,
                'label: приёмка прогона :run УПАЛА — прогон останется queued: :e',
                $context,
                $context + ['exception' => $e]);

            // Тот же текст уходит воркеру: он пишет его в свой ротируемый лог,
            // и причина 500 видна с обеих сторон, без доступа к серверу.
            return $this->_fail('приёмка результата упала: '.mb_substr($reason, 0, 300), 500);
        }

        Log_Plain::event(Log::INFO, 'label: результат прогона :run принят (:status, :mode, :len знаков)',
            [':run' => $id,
             ':status' => (string) $applied['status'],
             ':mode' => (string) ($this->_body['mode'] ?? ''),
             ':len' => mb_strlen((string) ($this->_body['text'] ?? ''))]);

        return $this->_respond([
            'ok'       => TRUE,
            'status'   => $applied['status'],
            'slug'     => $applied['slug'],
            'stats'    => Service_Nj646_Store::stats(),
        ]);
    }
}
