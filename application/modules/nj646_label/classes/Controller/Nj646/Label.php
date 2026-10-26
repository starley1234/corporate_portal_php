<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Разбор состава» (NJ-646): /label — разбор этикетки еды или косметики.
 *
 * Страница одна; вкладки внутри: «Текст», «Фото», «База знаний», «Профиль».
 * Данные вкладок считаются здесь, интерактив ходит в /label/api/*.
 */
class Controller_Nj646_Label extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        $this->set_title('Разбор состава: что в этикетке и чем это грозит вам');
        $this->set_description(
            'Вставьте состав с упаковки или сфотографируйте этикетку — сервис разберёт ингредиенты по накопленной базе знаний: риск и пояснение для каждого, персональные предупреждения под ваш профиль и история разборов. Новые вещества база изучает сама.');
        $this->set_keywords('разбор состава этикетка ингредиенты еда косметика E-добавки инци аллергия риск вред полезность кожа диета');

        // Владелец (03.10.2026): «/label перегружена функционалом… база знаний,
        // мои разборы, мой профиль — свои страницы». Навигация модуля и есть
        // переключатель между ними: вкладок внутри страницы больше нет.
        $this->set_module_nav([
            ['title' => 'Состав', 'href' => '/label', 'icon' => 'fas fa-bottle-droplet'],
            ['title' => 'База знаний', 'href' => '/label/knowledge', 'icon' => 'fas fa-book-open'],
            ['title' => 'Мои разборы', 'href' => '/label/my_analysis', 'icon' => 'fas fa-clock-rotate-left'],
            ['title' => 'Мой профиль', 'href' => '/label/profile', 'icon' => 'fas fa-user-gear'],
            ['title' => 'Модуль · админ', 'href' => '/nj646/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
        ]);
    }

    /**
     * Страница разбора: /label/analysis/<id>.
     *
     * Владелец (03.10.2026): «не хватает страницы разбора». До неё разбор жил
     * только в ответе API и в списке «Мои разборы» — открыть свой прошлый
     * разбор (вернуться к нему, показать, разобрать заново) было нечем.
     *
     * ЧТО ПОКАЗЫВАЕМ. Не снимок из `nj_646_analyses`, а сегодняшнее состояние
     * базы: по slug'ам позиций берём актуальные карточки (их могли изучить,
     * поправить, скрыть после разбора), а снимок служит подстраховкой, если
     * вещество удалили. Список «база не знала» тоже пересчитывается: у каждого
     * имени видно, что с ним стало — изучено (ссылка на карточку), в очереди,
     * убрано как мусор или так и ждёт.
     *
     * КОМУ ВИДНО. Свой разбор — посетителю (анонимная метка браузера или
     * вход), любой — админу. Остальным 404: содержимое разбора — это текст,
     * который человек вставил, и раздавать его по последовательным id нельзя.
     */
    public function action_analysis()
    {
        $id  = (int) $this->request->param('id');
        $row = Service_Nj646_Store::analysis_get($id);

        if ( ! $row)
        {
            throw HTTP_Exception::factory(404, 'Разбор не найден');
        }

        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;
        $admin   = Auth::instance()->logged_in('admin');

        if ( ! $admin AND ! Service_Nj646_Store::analysis_owned($row, $visitor, $user_id))
        {
            // 404, а не 403: существование чужого разбора — тоже информация.
            throw HTTP_Exception::factory(404, 'Разбор не найден');
        }

        // Актуальные карточки позиций + состояние незнакомцев.
        $cards   = Service_Nj646_Store::ingredients_by_slugs(array_map(function ($item) {
            return is_array($item) ? ($item['slug'] ?? '') : '';
        }, $row['items']));

        $unknown = Service_Nj646_Store::unknowns_status_by_names((array) $row['unknown']);

        // Совет и ОЦЕНКУ пересчитываем по актуальным карточкам: если вещество
        // изучили после разбора, результат должен это учитывать, а не жить в
        // прошлом. Снимок в базе остаётся как был — по нему строится история,
        // а на странице видно «сегодняшнее» состояние базы.
        $matched = array_values($cards);
        $left    = array_keys(array_filter($unknown, function ($state) {
            return (string) $state['status'] !== 'studied';
        }));
        $advice  = Service_Nj646_Label::advice($matched, $left, (array) $row['profile'], (array) Service_Nj646_Store::cfg('avoid_reasons', []));

        $fresh = Service_Nj646_Label::score($matched, $left);
        $score_now = (int) $fresh['score'];
        $verdict_now = (string) $fresh['verdict'];
        $refined = ($score_now !== (int) $row['score'] OR $verdict_now !== (string) $row['verdict']);

        // Изучение, которое идёт прямо сейчас: по каждому веществу — живой ряд
        // (спиннер + этап воркера + секундомер) прямо на странице разбора.
        // Ряд сам перезагрузит страницу, когда задача закончится, — человеку не
        // нужно гадать, «идёт ещё или уже всё».
        $studies = [];

        foreach ((array) $row['unknown'] as $name)
        {
            $state = $unknown[$name] ?? NULL;

            if ($state AND (string) $state['status'] === 'queued' AND (int) $state['run_id'] > 0)
            {
                $studies[] = ['name' => (string) $name, 'run_id' => (int) $state['run_id']];
            }
        }

        $this->template->content = View::factory('646/analysis', [
            'row'      => $row,
            'cards'    => $cards,
            'unknown'  => $unknown,
            'advice'   => $advice,
            'score_now' => $score_now,
            'verdict_now' => $verdict_now,
            'refined'  => $refined,
            'studies'  => $studies,
            'admin'    => $admin,
            'csrf'     => Security::token(),
            'kind_labels' => ['food' => 'еда', 'cosmetic' => 'косметика', 'both' => 'всё', 'auto' => 'состав'],
            // Тип продукта («зубная паста», «печенье»): в старых разборах колонки
            // ещё не было — считаем по тексту, чтобы страница была одинаковой.
            // ТОВАР разбора: имя для заголовка (этикетка или рабочее имя модуля),
            // пометка «имя рабочее» и ссылка на карточку товара. У старых разборов
            // товара нет — показываем название разбора, как раньше.
            'tovar'    => [
                'id'        => (int) ($row['product']['id'] ?? 0),
                'name'      => trim((string) ($row['product']['name'] ?? '')) !== ''
                    ? (string) $row['product']['name'] : (string) $row['title'],
                'name_auto' => Service_Nj646_Store::flag($row['product']['name_auto'] ?? FALSE)
                    OR (empty($row['product']) AND Service_Nj646_Store::flag($row['title_auto'] ?? FALSE)),
                'producer'  => trim((string) ($row['producer']['name'] ?? '')),
                'producer_id' => (int) ($row['producer']['id'] ?? 0),
                'score'     => (int) ($row['product']['last_score'] ?? 0),
            ],
            'kind_info' => Service_Nj646_Label::product_kind_info(
                (string) ($row['product_kind'] ?? '') !== ''
                    ? (string) $row['product_kind']
                    : Service_Nj646_Label::product_kind((string) $row['raw_text'], (string) $row['kind'])['slug']),
        ]);
    }

    /**
     * «Разобрать заново»: вернуть текст разбора на страницу /label.
     *
     * POST + 303: текст едет в сессию, а не в адресную строку (состав может
     * быть длинным и с переносами — в URL он не помещается и мусорит историю).
     * Права те же, что у страницы разбора.
     */
    public function action_replay()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        // Токен проверяем: действие меняет сессию посетителя, и чужая страница
        // не должна подсовывать сюда текст от его имени.
        if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        $id  = (int) $this->request->param('id');
        $row = Service_Nj646_Store::analysis_get($id);

        if ( ! $row)
        {
            throw HTTP_Exception::factory(404, 'Разбор не найден');
        }

        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;

        if ( ! Auth::instance()->logged_in('admin')
             AND ! Service_Nj646_Store::analysis_owned($row, $visitor, $user_id))
        {
            throw HTTP_Exception::factory(404, 'Разбор не найден');
        }

        Session::instance()->set('nj646_replay_text', (string) $row['raw_text']);
        Session::instance()->set('nj646_replay_id', (int) $row['id']);

        HTTP::redirect('/label', 303);
    }

    /**
     * Карточка товара: /label/product/<id>.
     *
     * Зачем отдельная страница. Сопутствующая информация с этикетки копится в
     * общих таблицах проекта (см. Store::goods_save) и живёт дольше одного
     * разбора: у товара меняется состав от партии к партии, и история разборов
     * показывает это наглядно — «какой состав чище» перестаёт быть вопросом.
     *
     * ЧУЖИХ РАЗБОРОВ НЕ ПОКАЗЫВАЕМ: список разборов товара — только оценки и
     * даты, без текста состава (текст видел лишь тот, кто его прислал) и без
     * метки браузера.
     */
    public function action_product()
    {
        $id      = (int) $this->request->param('id');
        $product = Service_Nj646_Store::product_get($id);

        if ( ! $product)
        {
            throw HTTP_Exception::factory(404, 'Товар не найден');
        }

        $producer = (int) $product['producer_id'] > 0
            ? Service_Nj646_Store::producer_get((int) $product['producer_id']) : NULL;

        $analyses = Service_Nj646_Store::product_analyses($id, 20);
        $scores   = [];

        foreach ($analyses as $row)
        {
            $scores[] = (int) $row['score'];
        }

        // «С кем сравнить»: товары того же типа, у которых уже есть разбор.
        // Без разбора у самого товара сравнивать нечего — ссылок не даём.
        $similar = (int) $product['last_analysis_id'] > 0
            ? Service_Nj646_Store::products_similar((string) $product['product_kind'], $id, 6)
            : [];

        $this->set_title('Товар: '.($product['name'] !== '' ? $product['name'] : 'без названия').' — разбор состава');
        $this->template->content = View::factory('646/product', [
            'product'  => $product,
            'producer' => $producer,
            'similar'  => $similar,
            'view'     => Service_Nj646_Store::goods_view($product, $producer),
            'analyses' => $analyses,
            'average'  => $scores ? (int) round(array_sum($scores) / count($scores)) : NULL,
            'best'     => $scores ? max($scores) : NULL,
            'admin'    => Auth::instance()->logged_in('admin'),
        ]);
    }

    /**
     * Сравнение двух товаров: /label/compare/<a>/<b> — «какой состав чище».
     *
     * Без пары (или если у одного из товаров нет разбора) страница превращается
     * в выбор: с кем сравнить. Ссылками, без query-параметров — адрес пары
     * можно послать знакомому и открыть когда угодно, и он же работает без JS.
     *
     * Оценки пересчитываются по актуальной базе (`Label::compare`), а не берутся
     * из сохранённых разборов: вещество могли изучить после разбора, и мерить
     * два товара разными линейками было бы неправдой.
     */
    public function action_compare()
    {
        $a_id = (int) $this->request->param('a');
        $b_id = (int) $this->request->param('b');

        $a = $a_id > 0 ? Service_Nj646_Store::product_get($a_id) : NULL;

        if ($a_id > 0 AND ! $a)
        {
            throw HTTP_Exception::factory(404, 'Товар не найден');
        }

        $b = ($b_id > 0 AND $b_id !== $a_id) ? Service_Nj646_Store::product_get($b_id) : NULL;

        // «С кем сравнивать» — когда пары нет или один из товаров ещё не разобран.
        if ( ! $a OR ! $b OR (int) $a['last_analysis_id'] <= 0 OR (int) $b['last_analysis_id'] <= 0)
        {
            $note = '';

            if ($a AND (int) $a['last_analysis_id'] <= 0)
            {
                $note = 'У «'.$this->product_label($a).'» ещё нет разобранного состава — сравнивать нечего. Разберите его на /label, и он вернётся в сравнение.';
            }
            elseif ($b AND (int) $b['last_analysis_id'] <= 0)
            {
                $note = 'У «'.$this->product_label($b).'» ещё нет разобранного состава — сравнивать нечего. Разберите его на /label, и он вернётся в сравнение.';
            }

            $this->set_title('Сравнить составы двух товаров — разбор состава');
            $this->template->content = View::factory('646/compare_pick', [
                'product' => $a,
                'pool'    => Service_Nj646_Store::products_similar(
                    $a ? (string) $a['product_kind'] : '', $a ? (int) $a['id'] : 0, 24),
                'note'    => $note,
            ]);

            return;
        }

        $analysis_a = Service_Nj646_Store::analysis_get((int) $a['last_analysis_id']);
        $analysis_b = Service_Nj646_Store::analysis_get((int) $b['last_analysis_id']);

        $a['producer_name'] = $this->producer_name($a);
        $b['producer_name'] = $this->producer_name($b);

        $compare = Service_Nj646_Label::compare(
            (array) ($analysis_a['items'] ?? []),
            (array) ($analysis_b['items'] ?? []),
            Service_Nj646_Store::index());

        $this->set_title('Что чище: '.$this->product_label($a).' или '.$this->product_label($b).' — сравнение составов');
        $this->template->content = View::factory('646/compare', [
            'a'       => $a,
            'b'       => $b,
            'compare' => $compare,
        ]);
    }

    /** Имя товара для заголовков: настоящее, иначе рабочее. */
    protected function product_label(array $product)
    {
        return (string) $product['name'] !== '' ? (string) $product['name'] : 'товар без названия';
    }

    /** Имя производителя товара ('' — если его на этикетке не было). */
    protected function producer_name(array $product)
    {
        $producer = (int) ($product['producer_id'] ?? 0) > 0
            ? Service_Nj646_Store::producer_get((int) $product['producer_id']) : NULL;

        return (string) ($producer['name'] ?? '');
    }

    /** Карточка производителя (импортёра): /label/producer/<id>. */
    public function action_producer()
    {
        $id       = (int) $this->request->param('id');
        $producer = Service_Nj646_Store::producer_get($id);

        if ( ! $producer)
        {
            throw HTTP_Exception::factory(404, 'Производитель не найден');
        }

        $this->set_title('Производитель: '.$producer['name'].' — что известно');
        $this->template->content = View::factory('646/producer', [
            'producer' => $producer,
            'view'     => Service_Nj646_Store::goods_view(NULL, $producer)['producer'],
            'products' => Service_Nj646_Store::producer_products($id, 30),
            'analyses' => Service_Nj646_Store::producer_analyses($id, 20),
            'admin'    => Auth::instance()->logged_in('admin'),
        ]);
    }

    /**
     * Публичная страница вещества: /label/ingredient/<slug>.
     *
     * Зачем. До неё у каждого вещества был только адрес /label/knowledge?slug= —
     * страница-инструмент с поиском и оценками. Для поисковика и для ссылки
     * «посмотри, что это за E211» нужен обычный адрес: постоянный, без query,
     * с осмысленным заголовком. Таких страниц в базе сотни (и будет больше —
     * модель изучает новые), каждая отвечает на конкретный вопрос, и все они
     * перелинкованы с соседями по категории. Это самый дешёвый способ получить
     * естественный трафик (ревизия 03.10.2026, P1 п. 1).
     *
     * ЧТО ВИДИТ ПОСЕТИТЕЛЬ. Только опубликованные карточки: черновик и скрытое
     * на публичном адресе — это утечка модерации (админу показываем с пометкой,
     * чтобы он мог проверить, как вещество будет выглядеть).
     *
     * Голосовать здесь негде — оценка вещества («согласен/спорно») живёт в базе
     * знаний, где для этого есть рабочий интерфейс; тут только счётчик голосов
     * и ссылка туда.
     */
    public function action_ingredient()
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $this->request->param('slug')));
        $card = $slug !== '' ? Service_Nj646_Store::ingredient($slug) : NULL;

        if ( ! $card)
        {
            throw HTTP_Exception::factory(404, 'Вещество не найдено');
        }

        $admin = Auth::instance()->logged_in('admin');

        if ((string) $card['status'] !== 'published' AND ! $admin)
        {
            throw HTTP_Exception::factory(404, 'Вещество не найдено');
        }

        $name = (string) $card['name_ru'] !== '' ? (string) $card['name_ru'] : (string) $card['name_int'];
        $verdict = Service_Nj646_Label::verdict_label($card['verdict']);

        $this->set_title($name.' ('.($card['e_code'] !== '' ? $card['e_code'].', ' : '').$verdict.') — что это в составе');
        $this->set_description(mb_substr(trim(strip_tags((string) $card['summary'])), 0, 240));
        $this->set_keywords($name.', '.$card['e_code'].', '.$card['category'].', состав, риск, '.$verdict);

        $this->template->content = View::factory('646/ingredient', [
            'card'    => $card,
            'similar' => Service_Nj646_Store::ingredient_similar($card, 10),
            'votes'   => Service_Nj646_Store::votes((int) $card['id']),
            'admin'   => $admin,
            // Коды ограничений — словами: страница публичная и должна читаться
            // без расшифровки «allergy», «kids» и прочих меток профиля.
            'reasons' => (array) Service_Nj646_Store::cfg('avoid_reasons', []),
        ]);
    }

    /**
     * Главная: только ввод — состав текстом и (по желанию) фото этикетки.
     *
     * Владелец (03.10.2026): «тут должна быть простая страница для ввода
     * информации в поле состав текстом + фото этикетки (опционально)».
     * Разбор открывается страницей /label/analysis/<id> — туда же ведут ссылки
     * из «Моих разборов».
     */
    public function action_index()
    {
        Service_Nj646_Seed::ensure();

        // «Разобрать заново» со страницы разбора: текст приезжает через сессию
        // (POST + 303), здесь его подставляем в поле и подсвечиваем источник.
        $replay_text = (string) Session::instance()->get_once('nj646_replay_text', '');
        $replay_id   = (int) Session::instance()->get_once('nj646_replay_id', 0);

        $this->template->content = View::factory('646/index', [
            'replay_text' => $replay_text,
            'replay_id'   => $replay_id,
            // Счётчики базы и число прошлых разборов тут не нужны: владелец
            // попросил ЛЁГКУЮ страницу входа, а список разборов живёт на
            // /label/my_analysis вместе со своими цифрами.
            'limits'   => (array) Service_Nj646_Store::cfg('limits', []),
            'llm_ready' => Service_Nj646_Store::llm_ready(),
            // Развёрнутый ответ включён по умолчанию (NJ646_DETAILED): незнакомые
            // вещества изучаются, а разбор на странице уточняется по пополненной
            // базе. 0 — быстрый разбор.
            'detailed'  => (bool) Service_Nj646_Store::cfg('limits.detailed', 1),
            // Полный ответ (NJ646_FULL_ANSWER): учим всё неизученное и ждём
            // карточки дольше. Это поведение сервера, клиент только видит его.
            'full'      => ((bool) Service_Nj646_Store::cfg('limits.full_answer', 0)
                AND (bool) Service_Nj646_Store::cfg('limits.detailed', 1)),
            'full_wait_sec' => (int) Service_Nj646_Store::cfg('limits.full_wait_sec', 300),
        ]);
    }

    /** База знаний: справочник веществ — список слева, карточка справа. */
    public function action_knowledge()
    {
        Service_Nj646_Seed::ensure();

        $this->set_title('База знаний: что известно о веществах в составах');
        $this->set_description(
            'Справочник веществ «Разбора состава»: риск и пояснение по каждому ингредиенту еды и косметики, синонимы, E-коды, источники (EFSA, FDA, ВОЗ, SCCS). Пополняется автоматически: незнакомые вещества база изучает сама.');

        $this->template->content = View::factory('646/knowledge', [
            'stats'  => Service_Nj646_Store::stats(),
            'limits' => (array) Service_Nj646_Store::cfg('limits', []),
        ]);
    }

    /** Мои разборы: страница истории посетителя (без чужих записей). */
    public function action_my_analysis()
    {
        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';

        $this->set_title('Мои разборы состава — что я разбирал');

        $this->template->content = View::factory('646/my_analysis', [
            'history' => $visitor !== '' ? Service_Nj646_Store::analyses_mine(
                $visitor, (int) Service_Nj646_Store::cfg('limits.history_user', 20)) : [],
            'history_total' => $visitor !== '' ? Service_Nj646_Store::analyses_mine_count($visitor) : 0,
            'csrf'    => Security::token(),
            'visitor' => $visitor,
        ]);
    }

    /**
     * Старый адрес «Моих разборов» — переадресация.
     *
     * Владелец (03.10.2026) назвал страницу `/label/my_analysis`; пока адрес был
     * `/label/history`, ссылки на него разошлись по страницам модуля и могли
     * осесть в чужих закладках. 301 — чтобы поисковик и браузер пришли на новый
     * адрес, а не на копию страницы.
     */
    public function action_history()
    {
        HTTP::redirect('/label/my_analysis', 301);
    }

    /** Мой профиль: настройка предупреждений (аллергии, диета, кожа). */
    public function action_profile()
    {
        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $profile = $visitor !== '' ? Service_Nj646_Store::profile($visitor) : [];

        $this->set_title('Мой профиль — предупреждения в разборе состава');

        $this->template->content = View::factory('646/profile', [
            'profile' => $profile,
            'visitor' => $visitor,
            // Токен — кнопке «удалить мои данные»: действие необратимое.
            'csrf'    => Security::token(),
        ]);
    }
}
