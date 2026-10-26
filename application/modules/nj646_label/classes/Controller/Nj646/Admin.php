<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Админка «Разбора состава» (NJ-646): /nj646/admin — модерация карточек,
 * очередь неизвестных веществ и правки посетителей.
 *
 * Доступ — только роль admin. Все действия строго POST + CSRF, после действия
 * редирект 303 с flash-сообщением (правило платформы: GET ничего не меняет).
 *
 * Сообщение едет через СЕССИЮ (`nj646_admin_flash`), а не через `?flash=` в
 * адресной строке: владелец (03.10.2026) — «на /nj646/admin не используй get».
 * Параметр в query и был тем GET-ом, который копился в истории, ломался при
 * перезагрузке и позволял подсунуть странице любой текст ссылкой
 * (`/nj646/admin?flash=что угодно`). Образец — nj602/nj605/nj606/admin других
 * модулей: set('…_flash') до redirect, get_once() при отрисовке.
 */
class Controller_Nj646_Admin extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Разбор состава · админ');
        // Меню модуля: со стороны админки — только рабочие разделы и выход в
        // публичную часть. Восемь чипов в шапке (и публичные страницы, и все
        // админские) читались как строка иконок — оставляем то, что нужно здесь.
        $this->set_module_nav([
            ['title' => 'Разбор состава', 'href' => '/label', 'icon' => 'fas fa-bottle-droplet'],
            ['title' => 'Дашборд', 'href' => '/nj646/admin', 'icon' => 'fas fa-gauge-high', 'roles' => ['admin']],
            ['title' => 'Модерация', 'href' => '/nj646/admin/moderation', 'icon' => 'fas fa-file-circle-check', 'roles' => ['admin']],
            ['title' => 'Справочник', 'href' => '/nj646/admin/catalog', 'icon' => 'fas fa-sitemap', 'roles' => ['admin']],
            ['title' => 'Товары', 'href' => '/nj646/admin/goods', 'icon' => 'fas fa-barcode', 'roles' => ['admin']],
        ]);
    }

    /**
     * Дашборд модуля: цифры, ход изучения и что разбирали люди.
     *
     * Владелец (03.10.2026): «/nj646/admin — тоже разбить на две страницы, одна
     * про дашборд и про то что разбирали, а вторая про карточки и незнакомые
     * вещества». Здесь — состояние базы и потока разборов; работа с карточками
     * (черновики, незнакомые вещества) живёт на /nj646/admin/moderation.
     */
    public function action_index()
    {
        Service_Nj646_Seed::ensure();

        $this->template->content = View::factory('646/admin', [
            'stats'    => Service_Nj646_Store::stats(),
            // Сколько черновиков ждёт модерации — видно с дашборда, работа — на
            // странице модерации.
            'unknown_todo' => Service_Nj646_Store::unknowns_pending_count(),
            'drafts_total' => Service_Nj646_Store::drafts_count(),
            'analyses_total' => Service_Nj646_Store::analyses_count(),
            'history_recent' => Service_Nj646_Store::analyses_page(4, 0),
            'votes'    => Service_Nj646_Store::votes_recent(20),
            'flash'    => (string) Session::instance()->get_once('nj646_admin_flash', ''),
            'flash_kind' => (string) Session::instance()->get_once('nj646_admin_flash_kind', 'ok'),
            'csrf'     => Security::token(),
            // Здоровье очереди изучения: владелец нажал «Изучить» и увидел
            // «Очередь недоступна» — при том, что очередь была жива. Теперь
            // рядом с кнопкой видно, что реально происходит с задачами.
            'study_queue' => Service_Nj646_Store::study_queue_state(8),
            'llm_ready'   => Service_Nj646_Store::llm_ready(),
            'study_limits' => [
                'per_day' => Service_Nj646_Store::limit('study_per_day', 10),
                'detailed' => (int) Service_Nj646_Store::cfg('limits.detailed', 1),
                'detailed_studies' => Service_Nj646_Store::limit('detailed_studies', 3),
            ],
            // Сколько накопилось товаров и производителей с этикеток — видно
            // из шапки админки, не заходя на отдельную страницу.
            'goods'      => Service_Nj646_Store::goods_counts(),
            'study_used' => Service_Nj646_Store::studies_last_day(
                class_exists('Service_Nj100_Ratelimit') ? Service_Nj100_Ratelimit::client_ip() : ''),
        ]);
    }

    /**
     * Модерация: карточки (черновики ИИ, правки) и незнакомые вещества.
     *
     * Владелец (03.10.2026): «вторая — про карточки и незнакомые вещества».
     * Массовые операции и опрос прогонов изучения остались здесь же: без списка
     * строк они бессмысленны.
     */
    public function action_moderation()
    {
        Service_Nj646_Seed::ensure();

        $this->template->content = View::factory('646/admin_moderation', [
            'drafts'   => Service_Nj646_Store::drafts_list(40),
            'unknowns' => Service_Nj646_Store::unknowns(NULL, 40),
            // Подпись кнопки «изучить всё» — по ВСЕЙ очереди, а не по показанным
            // 40 строкам: иначе кнопка обещала бы меньше, чем может поставить.
            'unknown_todo' => Service_Nj646_Store::unknowns_pending_count(),
            'drafts_total' => Service_Nj646_Store::drafts_count(),
            'stats'    => Service_Nj646_Store::stats(),
            'flash'    => (string) Session::instance()->get_once('nj646_admin_flash', ''),
            'flash_kind' => (string) Session::instance()->get_once('nj646_admin_flash_kind', 'ok'),
            'csrf'     => Security::token(),
            'llm_ready'   => Service_Nj646_Store::llm_ready(),
            // Мусор в очереди показываем ТОЛЬКО по кнопке: удаление — всегда
            // после списка, который человек увидел (как в импорте словаря).
            'junk'     => Session::instance()->get_once('nj646_junk_preview', []),
        ]);
    }

    /**
     * Показать мусор в очереди незнакомых (служебные строки этикетки).
     *
     * До `Label::composition_block()` разбор брал весь вставленный текст, и в
     * ОПЛАЧИВАЕМУЮ очередь изучения уезжали «5 г», «ГОСТ 24901-2014»,
     * «Изготовитель: ООО …». Кнопка ничего не удаляет — кладёт находки в
     * сессию, страница их показывает, и только вторая кнопка стирает.
     */
    public function action_unknowns_junk()
    {
        $this->_guard();

        $junk = Service_Nj646_Store::unknowns_junk(200);

        Session::instance()->set('nj646_junk_preview', $junk);

        $this->_flash($junk
            ? 'Нашёл в очереди служебных строк: '.count($junk).'. Посмотрите список и удалите, если согласны.'
            : 'Мусора в очереди не нашлось — там только вещества.', $junk ? 'ok' : 'warn');

        HTTP::redirect('/nj646/admin/moderation', 303);
    }

    /** Удалить показанный мусор (второй клик: список человек уже видел). */
    public function action_unknowns_junk_delete()
    {
        $this->_guard();

        $ids = array_map('intval', (array) Arr::get($_POST, 'ids', []));
        $gone = Service_Nj646_Store::unknowns_delete($ids);

        Session::instance()->delete('nj646_junk_preview');

        $this->_flash($gone > 0
            ? 'Удалил из очереди служебных строк: '.$gone.'.'
            : 'Удалять было нечего: строки уже убраны или по ним есть карточка.', $gone > 0 ? 'ok' : 'warn');

        HTTP::redirect('/nj646/admin/moderation', 303);
    }

    /**
     * Положить сообщение в сессию для следующей отрисовки админки.
     *
     * GET-параметров у админки быть не должно: сообщение — только из сессии,
     * куда его кладёт САМ контроллер после действия. Вид (ok | warn) едет
     * рядом, иначе после редиректа отказ выглядел бы как успех.
     */
    protected function _flash($message, $kind = 'ok')
    {
        Session::instance()->set('nj646_admin_flash', (string) $message);
        Session::instance()->set('nj646_admin_flash_kind', $kind === 'warn' ? 'warn' : 'ok');

        return $this;
    }

    /** Опубликовать / вернуть в черновики / скрыть карточку. */
    public function action_ingredient_status()
    {
        $id = (int) $this->request->param('id');

        $this->_guard();

        // Статус приходит и формой (админка), и JSON'ом (кнопки в справочнике),
        // поэтому читаем оба тела одним способом.
        $in     = $this->_input();
        $status = (string) ($in['status'] ?? 'published');

        if ( ! in_array($status, ['published', 'draft', 'hidden'], TRUE))
        {
            $status = 'draft';
        }

        Service_Nj646_Store::ingredient_status($id, $status);

        $message = $status === 'published'
            ? 'Карточка опубликована.'
            : ($status === 'hidden' ? 'Карточка скрыта.' : 'Карточка возвращена в черновики.');

        if ($this->_wants_json())
        {
            return $this->_json(['ok' => TRUE, 'code' => 'ok', 'status' => $status, 'message' => $message]);
        }

        $this->_flash($message);

        HTTP::redirect('/nj646/admin/moderation', 303);
    }

    /** Правка карточки (риск, вердикт, пояснение). */
    public function action_ingredient_save()
    {
        $id = (int) $this->request->param('id');

        $this->_guard();

        // Поля берём из формы ИЛИ из JSON-тела (справочник правит карточку
        // fetch'ем, формы админки шлют обычный POST). Меняем ТОЛЬКО присланные
        // поля: частичная правка не должна обнулять то, чего в запросе нет.
        $in     = $this->_input();
        $fields = [];

        foreach (['name_ru', 'name_int', 'category', 'e_code', 'summary', 'details'] as $key)
        {
            if (array_key_exists($key, $in))
            {
                $fields[$key] = (string) $in[$key];
            }
        }

        foreach (['risk' => 'int', 'verdict' => 'text', 'kind' => 'text', 'status' => 'text'] as $key => $type)
        {
            if (array_key_exists($key, $in))
            {
                $fields[$key] = $type === 'int' ? (int) $in[$key] : (string) $in[$key];
            }
        }

        // Списки правятся прямо в карточке справочника: синонимы решают,
        // найдёт ли поиск вещество; «ограничения» — что покажет разбор.
        foreach (['synonyms' => 12, 'avoid' => 12, 'sources' => 8] as $key => $cap)
        {
            if (array_key_exists($key, $in))
            {
                $fields[$key] = self::_list($in[$key], $cap);
            }
        }

        // Кто правил: из справочника это админ, у переизучения — источник
        // карточки. Если поле не прислали, карточку всё равно правил человек.
        $fields['source'] = in_array(($in['source'] ?? ''), ['seed', 'llm', 'user', 'admin', 'csv'], TRUE)
            ? (string) $in['source'] : 'admin';

        Service_Nj646_Store::ingredient_update($id, $fields);

        // Запись в лог сайта: за справочником следят несколько человек, и «кто
        // поменял вердикт» не должно быть загадкой при разборе.
        if (class_exists('Log_Plain'))
        {
            Log_Plain::event(Log::INFO, 'label: карточка вещества :id поправлена',
                [':id' => $id],
                ['поля' => implode(', ', array_keys($fields))]);
        }

        if ($this->_wants_json())
        {
            return $this->_json(['ok' => TRUE, 'code' => 'ok', 'message' => 'Карточка сохранена.']);
        }

        $this->_flash('Карточка сохранена.');

        HTTP::redirect('/nj646/admin/moderation', 303);
    }

    public function action_study()
    {
        $id = (int) $this->request->param('id');

        $this->_guard();

        $push = Service_Nj646_Store::study_push($id, '', 'both', [
            'manual'  => TRUE,
            'visitor' => 'admin',
            'user_id' => Visitor::user_id(),
        ]);

        if ($this->_wants_json())
        {
            $state = ! empty($push['run_id'])
                ? Service_Nj646_Store::study_state([(int) $push['run_id']], 'admin')
                : [];

            return $this->_json([
                'ok'      => (bool) $push['ok'],
                'code'    => (string) $push['code'],
                'run_id'  => (int) $push['run_id'],
                'slug'    => (string) $push['slug'],
                'name'    => (string) $push['name'],
                'message' => (string) $push['message'],
                'state'   => $state ? $state[0] : NULL,
                'queue'   => Service_Nj646_Store::study_queue_state(6),
            ], $push['ok'] ? 200 : 200);
        }

        $this->_flash($push['message'], $push['ok'] ? 'ok' : 'warn');

        HTTP::redirect('/nj646/admin/moderation', 303);
    }

    /**
     * Состояние прогонов изучения для опроса со страницы админки.
     *
     * Тело: `{runs: [12, 13]}`. Админ видит все прогоны (visitor='admin'
     * допустим в Store::study_state) — включая созданные посетителями.
     */
    public function action_states()
    {
        $this->_guard();

        $ids = (array) (json_decode((string) $this->request->body(), TRUE)['runs'] ?? []);

        return $this->_json([
            'ok'    => TRUE,
            'runs'  => Service_Nj646_Store::study_state($ids, 'admin'),
            'queue' => Service_Nj646_Store::study_queue_state(6),
        ]);
    }

    /** Ответ JSON для fetch-запросов админки. */
    protected function _json(array $data, $status = 200)
    {
        $this->auto_render = FALSE;
        $this->response->status((int) $status);
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this->response;
    }

    /**
     * Пришёл ли запрос из fetch/JS.
     *
     * Смотрим и заголовок X-Requested-With (его ставит fetch из script.php),
     * и поле ajax в форме: если JS отключён, форма отправится обычным POST и
     * страница получит честный редирект с сообщением.
     */
    protected function _wants_json()
    {
        return $this->request->is_ajax()
            OR trim((string) Arr::get($_POST, 'ajax', '')) !== '';
    }

    /** Игнорировать вещество (не изучать). */
    public function action_ignore()
    {
        $id = (int) $this->request->param('id');

        $this->_guard();

        DB::update('nj_646_unknowns')
            ->set(['status' => 'ignored', 'last_seen' => DB::expr('now()')])
            ->where('id', '=', $id)
            ->execute();

        $this->_flash('Вещество исключено из очереди.');

        HTTP::redirect('/nj646/admin/moderation', 303);
    }

    /**
     * Справочник веществ: дерево по разным критериям + редактирование.
     *
     * Владелец (03.10.2026): «добавь страницу для админа для просмотра
     * сформировавшегося справочника веществ — удобное иерархическое дерево
     * (выбор разных вариантов построения дерева по разным критериям)… (элементов,
     * наверное, будет тысячи) — назначение просмотр справочника и удобное
     * редактирование (удалить, переизучить и т.п.)».
     *
     * Страница — обычная (GET без параметров, как и вся админка). Дерево
     * подтягивается AJAX'ом по уровням: тысяч строк в HTML не бывает, каждый
     * узел раскрывается запросом, листья листаются.
     */
    public function action_catalog()
    {
        $this->template->content = View::factory('646/catalog', [
            'modes'   => Service_Nj646_Store::catalog_modes(),
            // Коды ограничений — подписями: карточка читается без расшифровки.
            'reasons' => (array) Service_Nj646_Store::cfg('avoid_reasons', []),
            'stats'   => Service_Nj646_Store::stats(),
            'counts'  => Service_Nj646_Store::catalog_counts(),
            'total'   => Service_Nj646_Store::ingredients_count(),
            'page'    => (int) Service_Nj646_Store::limit('catalog_page', 60),
            'csrf'    => Security::token(),
            'flash'   => (string) Session::instance()->get_once('nj646_admin_flash', ''),
            'flash_kind' => (string) Session::instance()->get_once('nj646_admin_flash_kind', 'ok'),
        ]);
    }

    /**
     * Один уровень дерева справочника (AJAX): ветви или вещества.
     *
     * Тело: `{mode, path: [...], q, offset}`. Ветви отдаём всегда с размерами
     * (чтобы было видно, куда идти), листья — постранично.
     */
    public function action_tree()
    {
        $this->_guard();

        if ( ! $this->_wants_json())
        {
            // Без JS дерева не бывает: страница без него покажет подсказку,
            // а этот запрос — честный отказ вместо пустой страницы.
            throw HTTP_Exception::factory(400, 'Дерево строится запросом из браузера');
        }

        $in     = $this->_input();
        $mode   = (string) ($in['mode'] ?? 'category');
        $path   = array_values(array_slice(array_map('strval', (array) ($in['path'] ?? [])), 0, 4));
        $q      = mb_substr(trim((string) ($in['q'] ?? '')), 0, 80);
        $offset = max(0, (int) ($in['offset'] ?? 0));
        $page   = (int) Service_Nj646_Store::limit('catalog_page', 60);

        $modes  = Service_Nj646_Store::catalog_modes();
        $spec   = isset($modes[$mode]) ? $modes[$mode] : $modes['category'];

        // Путь длиннее описания режима (сменили критерий, не сбросив путь) —
        // лишнее отрезаем: запрос не должен зависеть от чужих значений.
        $path   = array_slice($path, 0, count($spec['levels']));
        // flat — «сразу список по этому пути»: так работают пресеты («черновики»,
        // «изучено ИИ»), иначе кнопка с числом открывала бы ещё один уровень
        // дерева вместо самих карточек. Поиск ведёт себя так же.
        $search = $q !== '';
        $flat   = ! empty($in['flat']);
        $leaf   = ($search OR $flat OR count($path) >= count($spec['levels']));

        $payload = [
            'ok'     => TRUE,
            'mode'   => $mode,
            'path'   => $path,
            'leaf'   => $leaf,
            'search' => $search,
            'flat'   => $flat,
            'groups' => [],
            'items'  => [],
            'total'  => 0,
            'offset' => $offset,
            'page'   => $page,
            'crumbs' => $this->_catalog_crumbs($spec, $path),
        ];

        if ( ! $leaf)
        {
            $payload['groups'] = Service_Nj646_Store::catalog_groups($mode, $path);
            return $this->_json($payload);
        }

        // Листья строятся по ПОЛНОМУ пути (последнее выбранное значение — это
        // и есть ветвь, которую раскрыли). Поиск ищет в ТЕКУЩЕЙ ветке: на корне
        // это весь справочник, внутри ветви — её вещества. Так строка поиска
        // работает на любом уровне, а не только в раскрытом листе.
        $items = Service_Nj646_Store::catalog_items($mode, $path, $q, $offset, $page);
        $payload['items']  = $items['items'];
        $payload['total']  = $items['total'];
        $payload['q']      = $q;

        return $this->_json($payload);
    }

    /**
     * Крошки дерева: значение уровня → понятная подпись.
     *
     * Значения приходят из браузера, поэтому подпись берём только из описания
     * уровня (метка режима), а не подставляем в HTML как есть.
     */
    protected function _catalog_crumbs(array $spec, array $path)
    {
        $crumbs = [];

        foreach ($path as $i => $value)
        {
            if ( ! isset($spec['levels'][$i]))
            {
                break;
            }

            $labels = (array) ($spec['levels'][$i]['labels'] ?? []);
            $crumbs[] = [
                'key'   => (string) $value,
                'label' => array_key_exists((string) $value, $labels)
                    ? (string) $labels[(string) $value]
                    : ((string) $value !== '' ? (string) $value : 'прочее'),
            ];
        }

        return $crumbs;
    }

    /** Удалить карточку вещества (из справочника). */
    public function action_ingredient_delete()
    {
        $id = (int) $this->request->param('id');

        $this->_guard();

        $removed = Service_Nj646_Store::ingredient_delete($id);

        if ( ! $removed)
        {
            return $this->_answer([
                'ok' => FALSE, 'code' => 'unknown',
                'message' => 'Карточка уже удалена — обновите дерево.',
            ]);
        }

        $seed = (string) $removed['source'] === 'seed';

        return $this->_answer([
            'ok' => TRUE, 'code' => 'ok',
            'message' => 'Карточка «'.$removed['name'].'» удалена.'
                .($seed ? ' Это вещество из стартовой базы — при следующем обновлении базы оно вернётся.' : ''),
        ]);
    }

    /** Переизучить вещество: поставить модель заново и заменить карточку ИИ. */
    public function action_ingredient_restudy()
    {
        $id = (int) $this->request->param('id');

        $this->_guard();

        $push = Service_Nj646_Store::ingredient_restudy($id, [
            'visitor' => 'admin',
            'user_id' => Visitor::user_id(),
        ]);

        return $this->_answer([
            'ok'      => (bool) $push['ok'],
            'code'    => (string) $push['code'],
            'message' => (string) $push['message'],
            'run_id'  => (int) ($push['run_id'] ?? 0),
        ]);
    }

    /**
     * Ответ действия справочника: JSON для fetch, иначе редирект с flash.
     * Справочник живёт на AJAX, но каждая кнопка обязана работать и формой —
     * поэтому у обоих путей один и тот же разбор действий.
     */
    protected function _answer(array $payload)
    {
        if ($this->_wants_json())
        {
            return $this->_json($payload, 200);
        }

        $this->_flash((string) $payload['message'], empty($payload['ok']) ? 'warn' : 'ok');

        HTTP::redirect('/nj646/admin/catalog', 303);
    }

    /**
     * Массовые операции по спискам: «замодерировать всё» и «изучить всё».
     *
     * Владелец (03.10.2026): «сделай удобнее массовые операции для админа
     * (изучить всё, замодерировать всё и т.п.) — может через селекты».
     *
     * Одна ручка на оба списка: entity (draft | unknown), action
     * (publish|hide|draft | study|ignore), scope (selected | all) и ids[].
     * При scope=all идентификаторы берутся заново из базы (drafts_ids /
     * unknowns_todo_ids), а не из формы: страница могла висеть открытой,
     * пока список менялся, и «ко всем» должно значить «ко всем сейчас».
     * Пачка ограничена 200 строками за раз — остаток называем в сообщении.
     *
     * Повторная отправка безопасна: массовая модерация пишет те же статусы,
     * а изучение уже стоящих в очереди отдаёт 'already' без второго прогона.
     */
    // ---------------------------------------- товары и производители (этикетки)

    /**
     * «Товары и производители» — что накопилось с этикеток.
     *
     * Владелец (03.10.2026): «сопутствующую информацию о производителе и
     * товаре с этикетки тоже в БД сохраняй (для проекта в целом)». Здесь видно,
     * что именно накопилось, откуда взялись реквизиты и куда организация
     * привязалась: карточка производителя, справочник организаций (nj594) и
     * общая онтология предприятий (nj607).
     *
     * Поиск — через POST в сессию (`nj646_goods_q`): у админки нет GET-параметров.
     */
    public function action_goods()
    {
        $q = trim((string) Session::instance()->get_once('nj646_goods_q', ''));

        $this->template->content = View::factory('646/goods', [
            'q'         => $q,
            'counts'    => Service_Nj646_Store::goods_counts(),
            'producers' => Service_Nj646_Store::producers_list(100, 0, $q),
            'products'  => Service_Nj646_Store::products_list(100, 0, $q),
            'producer_hits' => [],
            'csrf'      => Security::token(),
            'flash'     => (string) Session::instance()->get_once('nj646_admin_flash', ''),
            'flash_kind' => (string) Session::instance()->get_once('nj646_admin_flash_kind', 'ok'),
            // Онтология/справочник могут быть не подключены — честно скажем,
            // прежде чем обещать ссылки «в проекте».
            'modules'   => [
                'ontology' => class_exists('Service_Nj607_Ontology'),
                'orgs'     => class_exists('Service_Nj594_Orgs'),
            ],
        ]);
    }

    /**
     * Собрать товары из старых разборов (кнопка на странице товаров).
     *
     * Разборы, сделанные до появления товаров, показывались в списках строкой
     * «63/100 · нейтрально». Кнопка заводит по снимку этикетки товар и
     * производителя — тем же кодом, что и новые разборы.
     */
    public function action_goods_backfill()
    {
        $this->_guard();

        $done = Service_Nj646_Store::analyses_backfill_products(200);

        Session::instance()->set('nj646_admin_flash', $done > 0
            ? 'Собрал товары из старых разборов: '.$done.'. Нажмите ещё раз, если записей больше.'
            : 'Все разборы уже связаны с товарами.');
        Session::instance()->set('nj646_admin_flash_kind', $done > 0 ? 'ok' : 'warn');

        HTTP::redirect('/nj646/admin/goods', 303);
    }

    /**
     * Дочитать пищевую ценность у старых товаров (кнопка на странице товаров).
     *
     * Колонка появилась миграцией 005 — у карточек, разобранных раньше, она
     * пустая. Кнопка перечитывает последний разбор каждого такого товара тем
     * же парсером, что и живой поток; введённое руками не трогается.
     */
    public function action_goods_nutrition_backfill()
    {
        $this->_guard();

        $done = Service_Nj646_Store::products_backfill_nutrition(200);

        Session::instance()->set('nj646_admin_flash', $done > 0
            ? 'Дочитал пищевую ценность: товаров '.$done.'. Нажмите ещё раз, если записей больше.'
            : 'Новой пищевой ценности в старых разборах не нашлось.');
        Session::instance()->set('nj646_admin_flash_kind', $done > 0 ? 'ok' : 'warn');

        HTTP::redirect('/nj646/admin/goods', 303);
    }

    /** Поиск по товарам/производителям (POST → сессия → 303, без GET). */
    public function action_goods_filter()
    {
        $this->_guard();
        Session::instance()->set('nj646_goods_q', mb_substr(trim((string) Arr::get($_POST, 'q', '')), 0, 120));

        HTTP::redirect('/nj646/admin/goods', 303);
    }

    /** Правка карточки производителя или товара. */
    public function action_goods_save()
    {
        $this->_guard();

        $kind = (string) Arr::get($_POST, 'kind', '');
        $id   = (int) Arr::get($_POST, 'id', 0);
        $in   = $this->_input();
        $ok   = FALSE;

        if ($kind === 'producer')
        {
            $fields = [];

            foreach (['name', 'address', 'country', 'inn', 'ogrn', 'phone', 'site', 'role'] as $key)
            {
                if (array_key_exists($key, $in))
                {
                    $fields[$key] = (string) $in[$key];
                }
            }

            $ok = Service_Nj646_Store::producer_save($id, $fields);
        }
        elseif ($kind === 'product')
        {
            $fields = [];

            foreach (['name', 'brand', 'barcode', 'weight', 'gost', 'shelf_life', 'storage', 'country', 'kind'] as $key)
            {
                if (array_key_exists($key, $in))
                {
                    $fields[$key] = (string) $in[$key];
                }
            }

            $ok = Service_Nj646_Store::product_save($id, $fields);

            // Пищевая ценность — отдельной дверью (`product_nutrition_save`):
            // это таблица, а не поле, и после правки руками её не должна
            // перетирать следующая этикетка (nutrition_source = manual).
            if ($ok AND array_key_exists('nutrition', $in))
            {
                $nutrition = (array) $in['nutrition'];

                $ok = Service_Nj646_Store::product_nutrition_save($id, [
                    'basis'   => (string) ($in['nutrition_basis'] ?? '100g'),
                    'portion' => (string) ($in['nutrition_portion'] ?? ''),
                    'values'  => $nutrition,
                ], 'manual');
            }
        }

        if (class_exists('Log_Plain'))
        {
            Log_Plain::event(Log::INFO, 'label: карточка :kind :id сохранена',
                [':kind' => $kind === 'producer' ? 'производителя' : 'товара', ':id' => $id],
                ['итог' => $ok ? 'ок' : 'отказ']);
        }

        $this->_flash($ok ? 'Карточка сохранена.' : 'Не получилось сохранить: проверьте поля (например, такое название уже есть).',
            $ok ? 'ok' : 'warn');

        HTTP::redirect('/nj646/admin/goods', 303);
    }

    /** Пересобрать связи производителя: онтология предприятий и справочник. */
    public function action_goods_link()
    {
        $this->_guard();

        $id   = (int) Arr::get($_POST, 'id', 0);
        $row  = Service_Nj646_Store::producer_get($id);
        $link = $row ? Service_Nj646_Store::producer_link_external($id) : [];

        $this->_flash(
            $row
                ? 'Связи обновлены: '
                    .(! empty($link['ontology_url']) ? 'онтология предприятий — да. ' : 'в онтологию попасть не удалось (модуль не подключён или ошибка). ')
                    .(! empty($link['org_id']) ? 'Справочник организаций — карточка найдена.' : 'в справочнике организаций совпадений нет.')
                : 'Производитель не найден.',
            $row ? 'ok' : 'warn');

        HTTP::redirect('/nj646/admin/goods', 303);
    }

    /** Удалить карточку товара или производителя (разборы остаются). */
    public function action_goods_delete()
    {
        $this->_guard();

        $kind = (string) Arr::get($_POST, 'kind', '');
        $id   = (int) Arr::get($_POST, 'id', 0);

        if ($kind === 'producer')
        {
            Service_Nj646_Store::producer_delete($id);
        }
        elseif ($kind === 'product')
        {
            Service_Nj646_Store::product_delete($id);
        }

        $this->_flash('Карточка удалена. Разборы и снимок этикетки остались на месте.', 'warn');

        HTTP::redirect('/nj646/admin/goods', 303);
    }

    /**
     * Выгрузка базы веществ в CSV (для правок в таблице и для переезда).
     *
     * Долг модуля «экспорт накопленной базы»: владелец правит десятки карточек
     * — в таблице это удобнее, чем в веб-форме. POST + CSRF (у админки нет GET),
     * ответ — файл.
     */
    public function action_export()
    {
        $this->_guard();

        $this->auto_render = FALSE;

        $rows = Service_Nj646_Store::ingredients_export(5000);
        $out  = fopen('php://temp', 'r+');

        fputcsv($out, ['slug', 'name_ru', 'name_int', 'kind', 'risk', 'verdict', 'category', 'e_code',
                       'status', 'source', 'confidence', 'hits', 'summary', 'synonyms', 'avoid'], ';');

        foreach ($rows as $row)
        {
            fputcsv($out, [
                (string) $row['slug'], (string) $row['name_ru'], (string) $row['name_int'],
                (string) $row['kind'], (int) $row['risk'], (string) $row['verdict'],
                (string) $row['category'], (string) $row['e_code'], (string) $row['status'],
                (string) $row['source'], (int) $row['confidence'], (int) $row['hits'],
                (string) $row['summary'],
                Service_Nj646_Store::list_text($row['synonyms']),
                Service_Nj646_Store::list_text($row['avoid']),
            ], ';');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        if (class_exists('Log_Plain'))
        {
            Log_Plain::event(Log::INFO, 'label: выгрузка базы веществ', [], ['строк' => count($rows)]);
        }

        $this->response->headers('Content-Type', 'text/csv; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="nj646-ingredients.csv"');
        $this->response->body("\xEF\xBB\xBF".$csv);

        return $this->response;
    }

    /**
     * СЛИЯНИЕ ДУБЛЕЙ: /nj646/admin/merge — подозрения на дубли и объединение.
     *
     * Владелец (P2-8): «две карточки одной организации живут порознь» — на
     * странице товаров видно, что разборы разложены по двум страницам, а
     * счётчики разъехались. Ключ карточки — нормализованное имя, и от этого никуда
     * не деться: этикетки пишут один завод по-разному.
     *
     * Поэтому здесь не автомат, а подсказка: пары с причиной («ИНН совпадает»,
     * «название — часть второго»), что переедет, о чём предупредить (разные ИНН)
     * и две кнопки — которую из карточек оставить главной. Объединение — в два
     * клика, как удаление на странице товаров.
     */
    public function action_merge()
    {
        $pairs    = Service_Nj646_Store::goods_duplicates(200);
        $prepared = ['producers' => [], 'products' => []];

        foreach (['producers' => 'producer', 'products' => 'product'] as $bucket => $kind)
        {
            foreach ((array) ($pairs[$bucket] ?? []) as $pair)
            {
                $counts = Service_Nj646_Store::goods_merge_counts($kind, (int) $pair['keep']['id'], (int) $pair['drop']['id']);

                $prepared[$bucket][] = [
                    'pair' => $pair,
                    'plan' => Service_Nj646_Merge::plan($kind, $pair['keep'], $pair['drop'], $counts),
                ];
            }
        }

        $this->template->content = View::factory('646/admin_merge', [
            'groups' => $prepared,
            'counts' => Service_Nj646_Store::goods_counts(),
            'csrf'   => Security::token(),
            'flash'  => (string) Session::instance()->get_once('nj646_admin_flash', ''),
            'flash_kind' => (string) Session::instance()->get_once('nj646_admin_flash_kind', 'ok'),
        ]);
    }

    /** Объединить пару: `keep` остаётся, вторая карточка исчезает. */
    public function action_goods_merge()
    {
        $this->_guard();

        $kind = (string) Arr::get($_POST, 'kind', '');
        $id_a = (int) Arr::get($_POST, 'id_a', 0);
        $id_b = (int) Arr::get($_POST, 'id_b', 0);
        $keep = (int) Arr::get($_POST, 'keep', 0);

        // Главной может стать только одна из показанных карточек: иначе по
        // устаревшей странице можно было бы слить чужие записи.
        if ( ! in_array($kind, ['producer', 'product'], TRUE)
            OR $id_a <= 0 OR $id_b <= 0 OR $id_a === $id_b
            OR ! in_array($keep, [$id_a, $id_b], TRUE))
        {
            $this->_flash('Не понял, какие карточки объединять — откройте страницу заново.', 'warn');
            HTTP::redirect('/nj646/admin/merge', 303);
        }

        $drop = $keep === $id_a ? $id_b : $id_a;

        $result = $kind === 'producer'
            ? Service_Nj646_Store::producer_merge($keep, $drop)
            : Service_Nj646_Store::product_merge($keep, $drop);

        if ( ! $result)
        {
            $this->_flash('Объединить не удалось: карточка уже исчезла или база отказала. Обновите список.', 'warn');
            HTTP::redirect('/nj646/admin/merge', 303);
        }

        $row = $kind === 'producer' ? Service_Nj646_Store::producer_get($keep) : Service_Nj646_Store::product_get($keep);

        $message = $kind === 'producer'
            ? 'Объединено в «'.(string) ($row['name'] ?? '').'»: разборов переехало '.(int) $result['analyses']
                .', товаров '.(int) $result['products'].'.'
            : 'Товары объединены: разборов переехало '.(int) $result['analyses'].'.';

        if ( ! empty($result['glued']))
        {
            $message .= ' Одноимённых товаров слито: '.(int) $result['glued'].'.';
        }

        if (class_exists('Log_Plain'))
        {
            Log_Plain::event(Log::INFO, 'label: карточки объединены',
                [],
                ['вид' => $kind, 'главная' => $keep, 'вторая' => $drop,
                 'разборов' => (int) $result['analyses'], 'товаров' => (int) $result['products']]);
        }

        $this->_flash($message);

        HTTP::redirect('/nj646/admin/merge', 303);
    }

    /**
     * ИМПОРТ СЛОВАРЯ (CSV): /nj646/admin/import — форма и предпросмотр.
     *
     * Владелец (03.10.2026): экспорт из админки был, импорта не было — словарь
     * приходилось забивать по карточке. Здесь обратный ход: файл или вставленный
     * текст разбирается, сверяется с базой и показывается ПЛАНОМ — что
     * добавится, что обновится (с «было → станет»), что без изменений и что не
     * прошло проверку. Пока админ не нажал «применить», в базу не уходит ничего.
     *
     * Формат — тот же, что у выгрузки (`action_export`), поэтому круг «скачал →
     * поправил в таблице → загрузил» работает без переучивания; шаблон с
     * образцом лежит прямо на странице. Всё POST + CSRF: у админки нет GET-ов.
     */
    public function action_import()
    {
        $this->template->content = View::factory('646/admin_import', [
            'plan'    => (array) Session::instance()->get('nj646_import_plan', []),
            'csv'     => (string) Session::instance()->get_once('nj646_import_csv', ''),
            'template' => Service_Nj646_Import::template(),
            'total'   => Service_Nj646_Store::ingredients_count(),
            'csrf'    => Security::token(),
            'flash'   => (string) Session::instance()->get_once('nj646_admin_flash', ''),
            'flash_kind' => (string) Session::instance()->get_once('nj646_admin_flash_kind', 'ok'),
        ]);
    }

    /**
     * Проверка файла: разобрать и построить план (ничего не записывая).
     *
     * План живёт в сессии: предпросмотр переживает перезагрузку страницы, а
     * «применить» берёт ровно то, что человек видел. Файл читаем из $_FILES, а
     * если его нет — из текстового поля (вставить CSV руками тоже можно).
     */
    public function action_import_check()
    {
        $this->_guard();

        $in     = $this->_input();
        $status = in_array((string) ($in['status'] ?? ''), ['published', 'draft'], TRUE) ? (string) $in['status'] : 'published';

        $csv  = '';
        $name = '';

        if ( ! empty($_FILES['csv']['tmp_name']) AND is_uploaded_file($_FILES['csv']['tmp_name']))
        {
            // 4 МБ — с запасом на словарь в тысячи строк; больше читать в память
            // и держать в сессии всё равно неразумно.
            if ((int) $_FILES['csv']['size'] > 4 * 1024 * 1024)
            {
                $this->_flash('Файл больше 4 МБ — разбейте словарь на части.', 'warn');
                HTTP::redirect('/nj646/admin/import', 303);
            }

            $csv  = (string) file_get_contents($_FILES['csv']['tmp_name']);
            $name = (string) ($_FILES['csv']['name'] ?? '');
        }
        else
        {
            $csv = (string) ($in['csv_text'] ?? '');
        }

        if (trim($csv) === '')
        {
            $this->_flash('Пусто: приложите файл CSV или вставьте строки в поле.', 'warn');
            HTTP::redirect('/nj646/admin/import', 303);
        }

        $parsed = Service_Nj646_Import::parse($csv);

        // План строим только по ТЕМ слагам, что есть в файле: тянуть всю базу
        // в память незачем — карточек в справочнике тысячи.
        $slugs = [];

        foreach ($parsed['rows'] as $row)
        {
            $slug = (string) ($row['slug'] ?? '');

            if ($slug !== '')
            {
                $slugs[] = $slug;
            }
        }

        $existing = $slugs ? Service_Nj646_Store::ingredients_by_slugs($slugs) : [];

        $plan = Service_Nj646_Import::plan($parsed['rows'], $existing);

        // Ошибки двух родов: строки файла, не прошедшие проверку, и конфликты
        // самого плана (две строки об одной карточке). Показываем одним списком.
        $plan['errors'] = array_merge($parsed['errors'], $plan['errors']);
        $plan['counts']['errors'] = count($plan['errors']);
        $plan['status'] = $status;
        $plan['file']   = $name;

        Session::instance()->set('nj646_import_plan', $plan);

        // Вставленный руками текст держим до показа страницы (get_once на GET):
        // если строки не прошли проверку, человек правит их на месте, а не ищет
        // исходник. Файл не запоминаем — он у человека в файле и в 4 МБ смысла нет.
        if ($name === '')
        {
            Session::instance()->set('nj646_import_csv', mb_substr($csv, 0, 200000));
        }

        if (class_exists('Log_Plain'))
        {
            Log_Plain::event(Log::INFO, 'label: словарь проверен перед импортом',
                [],
                ['файл' => $name, 'план' => Service_Nj646_Import::describe($plan)]);
        }

        $this->_flash('Проверено: '.Service_Nj646_Import::describe($plan).'. Ничего не записано — посмотрите план.');

        HTTP::redirect('/nj646/admin/import', 303);
    }

    /** Применить просмотренный план: создать и обновить карточки. */
    public function action_import_apply()
    {
        $this->_guard();

        $plan = (array) Session::instance()->get('nj646_import_plan', []);

        if (empty($plan['create']) AND empty($plan['update']))
        {
            $this->_flash('Применять нечего: плана нет или в нём нет новых и изменённых строк.', 'warn');
            HTTP::redirect('/nj646/admin/import', 303);
        }

        $default_status = in_array((string) ($plan['status'] ?? ''), ['published', 'draft'], TRUE) ? (string) $plan['status'] : 'published';

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ((array) $plan['create'] as $row)
        {
            $row['source'] = 'csv';
            $row['status'] = in_array((string) ($row['status'] ?? ''), ['published', 'draft', 'hidden'], TRUE)
                ? (string) $row['status'] : $default_status;

            // Строки с ошибкой в план не попадают, но слаг мог занять кто-то
            // другой за время просмотра — тогда карточку не создаём (ON CONFLICT).
            Service_Nj646_Store::ingredient_create($row) ? $created++ : $skipped++;
        }

        foreach ((array) $plan['update'] as $row)
        {
            $fields = (array) ($row['apply'] ?? []);

            if ( ! $fields)
            {
                continue;
            }

            $fields['source'] = 'csv';
            Service_Nj646_Store::ingredient_update((int) $row['id'], $fields);
            $updated++;
        }

        Session::instance()->delete('nj646_import_plan');

        $message = 'Импорт: добавлено '.$created.', обновлено '.$updated.'.';

        if ($skipped)
        {
            $message .= ' Пропущено '.$skipped.' — слаг заняли за время просмотра.';
        }

        if ( ! empty($plan['counts']['errors']))
        {
            $message .= ' Строк с ошибкой не импортировали: '.(int) $plan['counts']['errors'].'.';
        }

        if (class_exists('Log_Plain'))
        {
            Log_Plain::event(Log::INFO, 'label: словарь импортирован',
                [],
                ['файл' => (string) ($plan['file'] ?? ''), 'добавлено' => $created,
                 'обновлено' => $updated, 'пропущено' => $skipped]);
        }

        $this->_flash($message);

        HTTP::redirect('/nj646/admin/import', 303);
    }

    /** Отменить план: файл остался в базе, в базу ничего не пойдёт. */
    public function action_import_cancel()
    {
        $this->_guard();

        Session::instance()->delete('nj646_import_plan');

        $this->_flash('План импорта отменён — в базу ничего не записано.');

        HTTP::redirect('/nj646/admin/import', 303);
    }

    public function action_bulk()
    {
        $this->_guard();

        $in     = $this->_input();
        $entity = (string) ($in['entity'] ?? 'draft') === 'unknown' ? 'unknown' : 'draft';
        $action = (string) ($in['action'] ?? '');
        $scope  = (string) ($in['scope'] ?? '') === 'all' ? 'all' : 'selected';

        $allowed = $entity === 'unknown'
            ? ['study', 'ignore']
            : ['publish', 'hide', 'draft'];

        if ( ! in_array($action, $allowed, TRUE))
        {
            return $this->_bulk_answer($entity, [
                'ok'      => FALSE,
                'code'    => 'bad_request',
                'message' => 'Непонятное действие: обновите страницу и попробуйте снова.',
                'count'   => 0,
                'runs'    => [],
                'queued'  => [],
            ]);
        }

        $ids = $scope === 'all' ? [] : self::_ids($in['ids'] ?? []);

        if ($scope === 'all')
        {
            // «Ко всем» — по всей очереди модуля, а не по показанным 40 строкам:
            // список на странице урезан, и кнопка не должна об этом умалчивать.
            $ids = $entity === 'unknown'
                ? Service_Nj646_Store::unknowns_todo_ids(200)
                : Service_Nj646_Store::drafts_ids(200);
        }

        if ( ! $ids)
        {
            return $this->_bulk_answer($entity, [
                'ok'      => TRUE,
                'code'    => 'empty',
                'message' => $entity === 'unknown'
                    ? 'Очередь пуста: изучать нечего.'
                    : 'Черновиков нет — база в порядке.',
                'count'   => 0,
                'runs'    => [],
                'queued'  => [],
            ]);
        }

        $answer = $entity === 'unknown'
            ? $this->_bulk_unknowns($action, $ids, $scope)
            : $this->_bulk_drafts($action, $ids, $scope);

        return $this->_bulk_answer($entity, $answer);
    }

    /** Массовая модерация карточек: «опубликовать/скрыть/в черновики» на пачку. */
    protected function _bulk_drafts($action, array $ids, $scope)
    {
        $status = ['publish' => 'published', 'hide' => 'hidden', 'draft' => 'draft'][$action];
        $count  = Service_Nj646_Store::ingredient_status_many($ids, $status);
        $total  = Service_Nj646_Store::drafts_count();

        $what = $action === 'publish'
            ? 'Опубликовано'
            : ($action === 'hide' ? 'Скрыто' : 'Возвращено в черновики');

        $message = $what.': '.$count.' из '.count($ids).' выбранных';

        if ($scope === 'all')
        {
            $message = $what.' всех черновиков: '.$count;

            if ($total > count($ids))
            {
                // Хвост за лимитом пачки называем честно, иначе «все» — неправда.
                $message .= '. Осталось ещё '.($total - count($ids)).' — нажмите «ко всем» ещё раз.';
            }
        }

        return [
            'ok'      => TRUE,
            'code'    => 'ok',
            'message' => $message.'.',
            'count'   => (int) $count,
            'runs'    => [],
            'queued'  => [],
            'drafts_total' => $total,
            'unknown_todo' => Service_Nj646_Store::unknowns_pending_count(),
        ];
    }

    /** Массовые операции по очереди неизвестных: изучить пачкой или убрать мусор. */
    protected function _bulk_unknowns($action, array $ids, $scope)
    {
        if ($action === 'ignore')
        {
            $count = Service_Nj646_Store::unknowns_ignore_many($ids);

            return [
                'ok'      => TRUE,
                'code'    => 'ok',
                'message' => 'Убрано из очереди (игнор): '.$count.' из '.count($ids).' выбранных.',
                'count'   => (int) $count,
                'runs'    => [],
                'queued'  => [],
                'drafts_total' => Service_Nj646_Store::drafts_count(),
                'unknown_todo' => Service_Nj646_Store::unknowns_pending_count(),
            ];
        }

        // Изучение: ядро то же, что у кнопки «изучить всё» — одна позиция
        // проходит через study_push(), поэтому дублей и лишних прогонов нет.
        $push = Service_Nj646_Store::study_push_ids($ids, [
            'visitor' => 'admin',
            'user_id' => Visitor::user_id(),
            'total'   => max(count($ids), Service_Nj646_Store::unknowns_pending_count()),
        ]);

        $message = (string) $push['message'];

        if ($scope === 'all' AND count($ids) === 200 AND (int) $push['checked'] === 200)
        {
            $left = max(0, Service_Nj646_Store::unknowns_pending_count() - count((array) $push['queued']));

            if ($left > 0)
            {
                $message .= ' Осталось ещё '.$left.' — нажмите «изучить всё» ещё раз.';
            }
        }

        return [
            'ok'      => (bool) $push['ok'],
            'code'    => (string) $push['code'],
            'message' => $message,
            'count'   => count((array) $push['queued']),
            'runs'    => (array) $push['runs'],
            'queued'  => (array) $push['queued'],
            'drafts_total' => Service_Nj646_Store::drafts_count(),
            'unknown_todo' => Service_Nj646_Store::unknowns_pending_count(),
        ];
    }

    /**
     * Ответ на массовую операцию: JSON для fetch и редирект с flash для формы.
     * Без JS страница после действия возвращается на тот список, где нажимали.
     */
    protected function _bulk_answer($entity, array $payload)
    {
        if ($this->_wants_json())
        {
            return $this->_json($payload, 200);
        }

        $this->_flash($payload['message'], empty($payload['ok']) ? 'warn' : 'ok');

        HTTP::redirect('/nj646/admin/moderation'.($entity === 'unknown' ? '#nj646-unknowns' : '#nj646-drafts'), 303);
    }

    /**
     * История разборов: что и когда присылали люди (последние N записей).
     *
     * Владелец (03.10.2026): «добавь страницу для админа с перечнем что
     * анализировали (последние n записей)». Страница — обычная (GET без
     * параметров), а перелистывание — формой POST: владелец просил не таскать
     * состояние админки в адресной строке.
     */
    public function action_history()
    {
        $default = (int) Service_Nj646_Store::limit('history_admin', 30);
        $limit   = (int) Arr::get($_POST, 'limit', $default);
        $offset  = (int) Arr::get($_POST, 'offset', 0);

        $limit  = max(5, min(200, $limit));
        $offset = max(0, $offset);

        $total = Service_Nj646_Store::analyses_count();

        if ($offset >= $total AND $offset > 0)
        {
            // Ушли за конец списка (данные почистились) — показываем начало.
            $offset = 0;
        }

        $this->template->content = View::factory('646/history', [
            'rows'     => Service_Nj646_Store::analyses_page($limit, $offset),
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'flash'    => (string) Session::instance()->get_once('nj646_admin_flash', ''),
            'flash_kind' => (string) Session::instance()->get_once('nj646_admin_flash_kind', 'ok'),
            'csrf'     => Security::token(),
        ]);
    }

    /**
     * Поля действия: форма (обычный POST) или JSON-тело (fetch).
     *
     * Формы админки шлют application/x-www-form-urlencoded — там $_POST; JS
     * шлёт JSON — тогда $_POST пуст и всё лежит в теле запроса.
     */
    protected function _input()
    {
        $body = json_decode((string) $this->request->body(), TRUE);

        return (is_array($body) AND $body) ? $body : (array) $_POST;
    }

    /**
     * Список строк из формы или JSON: синонимы, ограничения, источники.
     *
     * Приходит и массивом (JSON из справочника), и строкой через запятую или
     * переносы (обычная форма) — принимаем оба вида, чистим пустое и дубли.
     * Пустой список — это осознанное «стереть»: значение уезжает в карточку.
     */
    protected static function _list($raw, $cap = 12)
    {
        $parts = is_array($raw) ? $raw : preg_split('/[,\n;]+/u', (string) $raw);
        $list  = [];

        foreach ((array) $parts as $part)
        {
            $part = trim((string) $part);

            if ($part !== '')
            {
                $list[$part] = $part;
            }
        }

        return array_slice(array_values($list), 0, (int) $cap);
    }

    /** Список id из формы (ids[]) или JSON: только положительные целые. */
    protected static function _ids($raw)
    {
        $ids = [];

        foreach ((array) $raw as $id)
        {
            $id = (int) $id;

            if ($id > 0)
            {
                $ids[$id] = $id;
            }
        }

        return array_slice(array_values($ids), 0, 200);
    }

    protected function _guard()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        // Токен ищем и в форме, и в JSON-теле: fetch-запросы админки шлют
        // JSON (формы — обычный POST).
        $csrf = (string) Arr::get($_POST, 'csrf', '');

        if ($csrf === '')
        {
            $body = json_decode((string) $this->request->body(), TRUE);
            $csrf = (string) (is_array($body) ? ($body['csrf'] ?? '') : '');
        }

        if ( ! Security::check($csrf))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }
    }
}
