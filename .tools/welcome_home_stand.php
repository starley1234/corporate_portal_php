<?php
/**
 * PHP-in-WASM стенд главной: подборка карточек, публичный GitHub-виджет,
 * приватность персональных обзоров и admin-only статус ИИ-радара.
 *
 *   N2J_ROOT=/home/user/n2j.ru node .tools/phpwasm_stand.mjs .tools/welcome_home_stand.php
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/');
}

class Controller_Base
{
    public $template;
    public $meta = [];
    public $menu = [];
    protected $airadar_allowed = FALSE;

    public function __construct($airadar_allowed = FALSE)
    {
        $this->template = (object) ['content' => NULL];
        $this->airadar_allowed = (bool) $airadar_allowed;
    }

    public function before() {}
    protected function can($ability) { return $ability === 'nj661.airadar' AND $this->airadar_allowed; }
    protected function set_menu($items) { $this->menu = $items; }
    protected function set_title($title) { $this->meta['title'] = $title; }
    protected function set_description($description) { $this->meta['description'] = $description; }
    protected function set_keywords($keywords) { $this->meta['keywords'] = $keywords; }
}

class HTML
{
    public static function chars($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

class URL
{
    public static function site($path = '', $protocol = FALSE)
    {
        return 'https://n2j.ru/'.ltrim((string) $path, '/');
    }
}

class Security
{
    public static function token()
    {
        return 'stand-csrf';
    }
}

class Service_Nj110_Catalog
{
    public static $requested_limit = NULL;

    public static function home_cards($ctx = NULL, $limit = NULL)
    {
        self::$requested_limit = $limit;
        $cards = [
            ['id' => 101, 'slug' => 'first', 'title' => 'Первая карточка', 'card_view' => 'card/first'],
            ['id' => 160, 'slug' => 'nj160_mcp', 'title' => 'MCP для ИИ-агентов', 'card_view' => 'nj160/card'],
        ];

        for ($i = 2; $i <= 7; $i++)
        {
            $cards[] = ['id' => 100 + $i, 'slug' => 'service-'.$i,
                'title' => 'Сервис '.$i, 'card_view' => 'card/service-'.$i];
        }

        return array_slice($cards, 0, (int) $limit);
    }

    public static function menu_items()
    {
        return [['title' => 'Меню каталога', 'href' => '/services']];
    }
}

class Log
{
    const WARNING = 3;
}

class Log_Plain
{
    public static $events = [];

    public static function event($level, $message, array $values = NULL, array $additional = NULL)
    {
        self::$events[] = [$level, $message, $values, $additional];
    }
}

class Service_Nj593_Trends
{
    public static $item_ids = [];
    public static $fail_list = FALSE;

    public static function first_admin_id()
    {
        return 1;
    }

    public static function list_user($user_id, $limit = NULL)
    {
        if (self::$fail_list)
        {
            throw new RuntimeException('mock database unavailable');
        }

        $now = date('c');

        return [
            ['id' => 2, 'code' => 'trdsecret2', 'status' => 'done', 'focus' => 'PRIVATE_CUSTOM_FOCUS',
                'language' => '', 'finished_at' => $now],
            ['id' => 1, 'code' => 'trdsafe01', 'status' => 'done', 'focus' => '',
                'language' => '', 'finished_at' => $now],
        ];
    }

    public static function items($review_id)
    {
        self::$item_ids[] = (int) $review_id;

        return [(int) $review_id === 1
            ? ['full_name' => 'n2j/open-project', 'title' => 'Полезный открытый проект',
                'why' => 'Короткое объяснение проекта.', 'stars' => 1234]
            : ['full_name' => 'private/project', 'title' => 'PRIVATE_CUSTOM_FOCUS',
                'why' => 'PRIVATE_CUSTOM_SUMMARY.', 'stars' => 9999]];
    }
}

class Service_Nj661_Airadar
{
    public static $report_reads = 0;

    public static function reports($limit = 30)
    {
        self::$report_reads++;

        return [[
            'created_at' => date('c'),
            'title'      => 'PRIVATE_RADAR_TITLE',
            'summary'    => 'PRIVATE_RADAR_SUMMARY',
            'stats'      => ['private' => 'PRIVATE_RADAR_IDEA'],
        ]];
    }
}

class View
{
    public static function factory($file)
    {
        return new View_Stub($file);
    }
}

class View_Stub
{
    public $file;
    public $vars = [];

    public function __construct($file)
    {
        $this->file = $file;
    }

    public function set($key, $value)
    {
        $this->vars[$key] = $value;

        return $this;
    }

    public function __toString()
    {
        return $this->render();
    }

    public function render()
    {
        if ($this->file === '/welcome/index' OR $this->file === 'welcome/parts/check_form')
        {
            ob_start();
            extract($this->vars, EXTR_SKIP);
            $path = $this->file === '/welcome/index'
                ? '/app/application/views/welcome/index.php'
                : '/app/application/views/welcome/parts/check_form.php';
            include $path;

            return ob_get_clean();
        }

        $module = (array) ($this->vars['module'] ?? []);

        return '<article data-service-card="'.HTML::chars($module['slug'] ?? '').'">'
            .HTML::chars($module['title'] ?? '').'</article>';
    }
}

require '/app/application/classes/Controller/Welcome.php';

function stand_check($label, $condition)
{
    if ( ! $condition)
    {
        echo 'ФЕЙЛ: '.$label."\n";

        return FALSE;
    }

    echo 'OK: '.$label."\n";

    return TRUE;
}

$checks = [];
$guest = new Controller_Welcome(FALSE);
$guest->action_index();
$guest_html = $guest->template->content->render();

$checks[] = stand_check('главная берёт запасную карточку до исключения MCP', Service_Nj110_Catalog::$requested_limit === 7);
$checks[] = stand_check('главная выводит ровно шесть карточек', substr_count($guest_html, 'data-service-card=') === 6);
$checks[] = stand_check('карточка MCP отсутствует на главной', strpos($guest_html, 'MCP для ИИ-агентов') === FALSE AND strpos($guest_html, '/mcp') === FALSE);
$checks[] = stand_check('публичный виджет берёт общий свежий GitHub-обзор', strpos($guest_html, 'n2j/open-project') !== FALSE AND strpos($guest_html, 'trdsafe01') !== FALSE);
$checks[] = stand_check('персональный фокус и его данные не попали на витрину', strpos($guest_html, 'PRIVATE_CUSTOM') === FALSE AND Service_Nj593_Trends::$item_ids === [1]);
$checks[] = stand_check('гостю не читается и не показывается личный ИИ-радар', Service_Nj661_Airadar::$report_reads === 0 AND strpos($guest_html, '/airadar') === FALSE);
$checks[] = stand_check('SEO главной позиционирует N2J как ИИ-сервисы для бизнеса', strpos($guest->meta['title'], 'ИИ-сервисы для бизнеса') !== FALSE AND strpos($guest->meta['description'], 'проверка контрагентов') !== FALSE AND strpos($guest_html, '<h1 id="home-title"') !== FALSE AND strpos($guest_html, 'ИИ-сервисы для бизнеса') !== FALSE);
$checks[] = stand_check('на главной есть ссылки на ключевые бизнес-инструменты', strpos($guest_html, 'href="/scout"') !== FALSE AND strpos($guest_html, 'href="/docs"') !== FALSE AND strpos($guest_html, 'href="/process"') !== FALSE AND strpos($guest_html, 'href="/coldmail"') !== FALSE);
$checks[] = stand_check('на странице ровно один SEO-заголовок H1', preg_match_all('~<h1\\b~i', $guest_html) === 1);
$checks[] = stand_check('форма доступна в первом экране с короткими действиями', strpos($guest_html, 'aria-describedby="home-check-help"') !== FALSE AND strpos($guest_html, 'Полный отчёт') !== FALSE);

preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $guest_html, $json_ld);
$schema = isset($json_ld[1]) ? json_decode($json_ld[1], TRUE) : NULL;
$checks[] = stand_check('главная отдаёт корректную WebSite JSON-LD разметку', is_array($schema) AND ($schema['@type'] ?? '') === 'WebSite' AND ($schema['inLanguage'] ?? '') === 'ru-RU');

$admin = new Controller_Welcome(TRUE);
$admin->action_index();
$admin_html = $admin->template->content->render();
$checks[] = stand_check('ИИ-радар доступен только пользователю с правом', strpos($admin_html, 'Последний отчёт:') !== FALSE AND strpos($admin_html, '/airadar') !== FALSE);
$checks[] = stand_check('заголовок, выводы и идеи радара не раскрываются на главной', strpos($admin_html, 'PRIVATE_RADAR') === FALSE);

Service_Nj593_Trends::$fail_list = TRUE;
$degraded = new Controller_Welcome(FALSE);
$degraded->action_index();
$degraded_html = $degraded->template->content->render();
$checks[] = stand_check('ошибка необязательного дайджеста не ломает главную', strpos($degraded_html, 'Тренды GitHub') !== FALSE AND strpos($degraded_html, 'Свежие открытые репозитории') !== FALSE);
$checks[] = stand_check('ошибка виджета записана в плоский лог', count(Log_Plain::$events) === 1 AND Log_Plain::$events[0][0] === Log::WARNING);

if (in_array(FALSE, $checks, TRUE))
{
    exit(1);
}

echo "ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";
