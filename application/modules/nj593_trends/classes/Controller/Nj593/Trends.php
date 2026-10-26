<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Тренды GitHub» (nj593): /trends — ОТКРЫТЫЙ КАТАЛОГ опубликованных обзоров
 * (карточками), /trends/mine — МОИ обзоры, /trends/new — ФОРМА нового обзора,
 * /trends/<code> — СТРАНИЦА ОБЗОРА (публичный дайджест).
 *
 * Владелец (15.10.2026): «/trends — пускай будет перечень обзоров хорошо
 * оформленный, а страницу — форму для нового обзора сделай другую». Форма
 * переехала на свой адрес /trends/new; POST на /trends сохранён для старых
 * закладок и внешних форм и делает то же самое.
 * Владелец (16.10.2026): «вместо /trends показывай содержимое /trends/catalog,
 * /trends/catalog — убери». Каталог стал главной страницей модуля, личный
 * перечень переехал на /trends/mine, отдельного адреса каталога больше нет.
 * Дизайн — по эталону /pages/required_design_sample: контейнер
 * px-3.5 pt-4 pb-16, карточки rounded-xl p-4→sm:p-6, поля rounded-lg
 * min-h-[44px], сетка 2/3+1/3 (компаньон hidden md:block), статус-ряд
 * «спиннер+статус+секундомер» неразрывный, статусы словами.
 */
class Controller_Nj593_Trends extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Тренды GitHub — ежедневный обзор новинок от ИИ');
        $this->set_description('Ежедневный обзор трендов и новинок GitHub: ИИ отбирает самые интересные свежие репозитории, читает их README и объясняет по-русски, что это, почему в обзоре и кому пригодится.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // Чипы раздела — в ВЕРХНЕМ header шаблона. Владелец (26.09): «в чипах
        // оставь Тренды GitHub, /nj593/admin — остальное только дублирует»:
        // «Ежедневный обзор» и «Мои обзоры» повторяли заголовок и переход к
        // списку, поэтому из шапки убраны. Активный пункт Base помечает сам.
        $nav = [
            ['title' => 'Тренды GitHub', 'href' => '/trends', 'icon' => 'fas fa-fire'],
        ];

        if ($this->can('nj593.admin'))
        {
            $nav[] = ['title' => 'Управление', 'href' => '/nj593/admin', 'icon' => 'fas fa-lock'];
        }

        $this->set_module_nav($nav);

        // страницы не кэшируем: инлайновый JS поллинга, кэш = сломанный интерфейс
        $this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
        $this->response->headers('Pragma', 'no-cache');
    }

    /**
     * /trends: GET — открытый каталог опубликованных обзоров (главная модуля);
     * POST — создать обзор (совместимость: форма живёт на /trends/new, но
     * старая закладка или внешняя форма с POST на /trends создаёт обзор тем же
     * путём).
     *
     * Для людей и СЕО (владелец, 08.10.2026): читаемый список, свежие сверху,
     * пагинация и поиск — без входа и без служебных колонок. В каталог попадают
     * только ГОТОВЫЕ и ОПУБЛИКОВАННЫЕ обзоры: снятый обзор остаётся у владельца
     * на /trends/mine, но людям и поисковикам его не показываем.
     */
    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        Service_Nj593_Trends::ensure_tables();

        $q     = trim((string) Arr::get($_GET, 'q', ''));
        $per   = (int) Arr::get($_GET, 'per', 10);
        $per   = in_array($per, [10, 20, 50, 100], TRUE) ? $per : 10;
        $total = Service_Nj593_Trends::count_public($q);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($pages, max(1, (int) Arr::get($_GET, 'page', 1)));

        $this->set_title($q === ''
            ? 'Тренды GitHub — ежедневный обзор новинок open source'
            : 'Поиск по обзорам трендов GitHub: '.$q);
        $this->set_description('Все опубликованные обзоры трендов GitHub одним списком: свежие сверху, с датой, числом позиций и названиями репозиториев. Ищите по теме, репозиторию или коду обзора.');
        $this->set_keywords('тренды github, обзоры, каталог обзоров, новинки open source, свежие репозитории, дайджест github');

        $this->template->content = View::factory('593/index', [
            'rows'        => Service_Nj593_Trends::search_public($q, $page, $per),
            'q'           => $q,
            'page'        => $page,
            'pages'       => $pages,
            'total'       => $total,
            'per'         => $per,
            'per_options' => [10, 20, 50, 100],
            'is_admin'    => $this->can('nj593.admin'),
            'flash'       => (string) Arr::get($_GET, 'flash', ''),
            'mine_url'    => '/trends/mine',
        ]);
    }

    /**
     * /trends/mine: GET — перечень МОИХ обзоров (управление своими сборками).
     *
     * Поиск и постраничный вывод, статус каждой сборки, публикация в каталог,
     * повтор с теми же настройками и удаление. Появился 16.10.2026, когда
     * /trends отдали открытому каталогу.
     */
    public function action_mine()
    {
        $this->_require_admin();

        Service_Nj593_Trends::ensure_tables();

        $q    = trim((string) Arr::get($_GET, 'q', ''));
        // Сколько строк на странице выбирает пользователь (10/20/50/100);
        // выбор запоминается браузером (partials/prefs_js) и приходит в ?per=
        $per  = (int) Arr::get($_GET, 'per', 20);
        $per  = in_array($per, [10, 20, 50, 100], TRUE) ? $per : 20;
        $total = $this->_user_id ? Service_Nj593_Trends::count_user($this->_user_id, $q) : 0;
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($pages, max(1, (int) Arr::get($_GET, 'page', 1)));

        // «Мои обзоры» показывают честный статус: незавершённые строки сверяются
        // с очередью (молчащий воркер гасится, задача без воркера становится
        // ошибкой) — иначе в списке навсегда остаётся «в работе».
        $my = $this->_user_id ? Service_Nj593_Trends::search_user($this->_user_id, $q, $page, $per) : [];
        $my = Service_Nj593_Trends::reconcile_list($my, $per);

        // Дайджест последней публикации и счётчик каталога: страница ссылается
        // на открытый каталог (/trends) — иначе читаемый список для людей и
        // поисковиков живёт без входа.
        $latest = Service_Nj593_Trends::latest_public();

        $this->set_title('Мои обзоры трендов GitHub');
        $this->set_description('Собственные обзоры трендов GitHub: статус сборки, публикация в открытом каталоге, повтор с теми же настройками и удаление.');

        $this->template->content = View::factory('593/mine', [
            'latest'    => $latest ? (array) $latest : NULL,
            'catalog_total' => Service_Nj593_Trends::count_public(),
            'my'        => $my,
            'logged'    => $this->_user_id !== NULL,
            'csrf'      => Security::token(),
            'flash'     => (string) Arr::get($_GET, 'flash', ''),
            'q'         => $q,
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
            'per'       => $per,
            'per_options' => [10, 20, 50, 100],
            'new_url'   => '/trends/new',
        ]);
    }

    /**
     * /trends/new: GET — форма нового обзора; POST — создать обзор.
     *
     * Параметры можно передать в адресе (?period=3&count=10&min_stars=30&
     * language=rust&focus=...): значения проверяются как в create() и
     * подставляются в поля. Так «Собрать заново» со страницы обзора открывает
     * форму уже заполненной — повтор не нужно настраивать руками.
     */
    public function action_new()
    {
        $this->_require_admin();

        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        Service_Nj593_Trends::ensure_tables();

        $this->set_title('Новый обзор трендов GitHub — собрать обзор');
        $this->set_description('Соберите обзор трендов GitHub: дата, период, число позиций, минимум звёзд, язык и пожелание «что считать интересным». ИИ отберёт свежие репозитории и напишет разбор по-русски.');

        $this->template->content = View::factory('593/new', [
            'form'      => $this->_form_defaults(),
            'languages' => (array) Kohana::$config->load('nj593_trends.languages'),
            'logged'    => $this->_user_id !== NULL,
            'csrf'      => Security::token(),
            'flash'     => (string) Arr::get($_GET, 'flash', ''),
        ]);
    }

    /** Общая серверная защита формы, старого POST и действий управления. */
    protected function _require_admin()
    {
        if ( ! $this->can('nj593.admin'))
        {
            Log_Plain::event(Log::WARNING, 'nj593: отказ в управлении обзорами, user_id=:uid', [':uid' => (int) $this->_user_id]);
            throw HTTP_Exception::factory(403, 'Собирать обзоры и управлять ими может только администратор');
        }
    }

    /** Значения формы нового обзора (с проверкой параметров адреса). */
    protected function _form_defaults()
    {
        $cfg = Kohana::$config->load('nj593_trends');

        $rdate = trim((string) Arr::get($_GET, 'rdate', date('Y-m-d')));

        if ( ! preg_match('~^\d{4}-\d{2}-\d{2}$~', $rdate))
        {
            $rdate = date('Y-m-d');
        }

        $period_values = [1, 2, 3, 7];
        $period = (int) Arr::get($_GET, 'period', (int) $cfg->get('default_period', 1));

        if ( ! in_array($period, $period_values, TRUE))
        {
            $period = (int) $cfg->get('default_period', 1);
        }

        $count = (int) Arr::get($_GET, 'count', (int) $cfg->get('default_items', 8));
        $count = max(3, min((int) $cfg->get('max_items', 12), $count ?: 8));

        $min_stars = (int) Arr::get($_GET, 'min_stars', 50);
        $min_stars = max((int) $cfg->get('min_stars_min', 10), min(100000, $min_stars ?: 50));

        $language = trim((string) Arr::get($_GET, 'language', ''));

        if ( ! in_array($language, (array) $cfg->get('languages', ['']), TRUE))
        {
            $language = '';
        }

        $focus = trim((string) Arr::get($_GET, 'focus', ''));

        if (mb_strlen($focus) > (int) $cfg->get('max_focus_len', 500))
        {
            $focus = mb_substr($focus, 0, (int) $cfg->get('max_focus_len', 500));
        }

        return [
            'rdate'     => $rdate,
            'period'    => $period,
            'count'     => $count,
            'min_stars' => $min_stars,
            'language'  => $language,
            'focus'     => $focus,
        ];
    }

    /** POST /trends/new (и /trends) — создать обзор. Отказ возвращает на форму. */
    protected function _create()
    {
        $this->_require_admin();

        if ($this->_user_id === NULL)
        {
            $this->redirect('auth/login?return_url=trends/new');

            return;
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('trends/new?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        $res = Service_Nj593_Trends::create(
            $this->_user_id,
            (string) Arr::get($_POST, 'rdate', ''),
            (int) Arr::get($_POST, 'period', 1),
            (int) Arr::get($_POST, 'count', 8),
            (string) Arr::get($_POST, 'language', ''),
            (int) Arr::get($_POST, 'min_stars', 50),
            (string) Arr::get($_POST, 'focus', '')
        );

        if (isset($res['error']))
        {
            $this->redirect('trends/new?flash='.urlencode((string) $res['error']));

            return;
        }

        $this->redirect('trends/'.$res['code']);
    }

    /** GET /trends/<code> — страница обзора. ПУБЛИЧНАЯ (дайджест для всех). */
    public function action_view()
    {
        $code = (string) $this->request->param('code');
        $review = Service_Nj593_Trends::by_code($code);

        if ($review === NULL)
        {
            throw HTTP_Exception::factory(404, 'Обзор не найден');
        }

        $is_admin = $this->can('nj593.admin');

        // Читатели видят только готовые опубликованные обзоры, даже прежний
        // владелец. Администратор видит также черновики и ход сборки.
        if ( ! Service_Nj593_Trends::can_read($review, $is_admin))
        {
            throw HTTP_Exception::factory(404, 'Обзор не найден');
        }

        $review = Service_Nj593_Trends::reconcile($review);

        $this->template->content = View::factory('593/view', [
            'r'        => $review,
            'items'    => Service_Nj593_Trends::items((int) $review['id']),
            // Модель генерации — служебная информация: видна только администратору
            // (владелец, 26.09: «Модель — показываем только админу»).
            'is_admin' => $is_admin,
            // Публикация: готовый обзор виден в каталоге, пока администратор не снял его.
            'published' => Service_Nj593_Trends::is_published($review),
            // Фактическое время текущей задачи: таймер стартует не с нуля
            'elapsed'  => Service_Nj593_Trends::elapsed_sec($review),
            // Модель молчала → обзор собран по данным GitHub: это видно в
            // шапке обзора (не служебная информация, а честность данных).
            'ai_offline' => Service_Nj593_Trends::is_ai_offline($review),
            'csrf'     => Security::token(),
            'flash'    => (string) Arr::get($_GET, 'flash', ''),
        ]);
    }

    /**
     * POST /trends/<code>/publish — снять обзор с публикации или вернуть его.
     *
     * Менять публикацию может только администратор; действие
     * защищено CSRF и отправляется только POST-ом (в интерфейсе — в два клика).
     */
    public function action_publish()
    {
        $this->_require_admin();

        $code  = (string) $this->request->param('code');
        $r     = Service_Nj593_Trends::by_code($code);
        if ($r === NULL)
        {
            throw HTTP_Exception::factory(404, 'Обзор не найден');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('trends/'.$code.'?flash='.urlencode('Сессия устарела — публикация не изменена'));

            return;
        }

        // published=1 — вернуть в каталог, всё остальное — снять с публикации.
        $want = (string) Arr::get($_POST, 'published', '') === '1';

        if (Service_Nj593_Trends::set_published((int) $r['id'], $want))
        {
            $this->redirect('trends/'.$code.'?flash='.urlencode($want
                ? 'Обзор снова опубликован — он в каталоге'
                : 'Обзор снят с публикации — в каталоге его нет, страница работает только у вас'));

            return;
        }

        $this->redirect('trends/'.$code.'?flash='.urlencode('Не удалось изменить публикацию — попробуйте ещё раз'));
    }

    /** POST /trends/<code>/delete — удалить обзор полностью (только администратор). */
    public function action_delete()
    {
        $this->_require_admin();

        $code = (string) $this->request->param('code');
        $r = Service_Nj593_Trends::by_code($code);
        if ($r === NULL)
        {
            throw HTTP_Exception::factory(404, 'Обзор не найден');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('trends?flash='.urlencode('Сессия устарела — обзор не удалён'));

            return;
        }

        Service_Nj593_Trends::hard_delete((int) $r['id']);
        $this->redirect('trends?flash='.urlencode('Обзор '.$code.' удалён полностью'));
    }
}
