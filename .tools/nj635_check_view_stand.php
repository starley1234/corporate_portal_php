<?php
/**
 * Стенд рендера /check (nj635_scout) на PHP-in-WASM: собираем страницу по
 * фикстуре карточки и проверяем, что в HTML попали ключевые блоки, а сам
 * шаблон не падает ни в полном, ни в пустом варианте.
 *
 *   N2J_ROOT=/home/user/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj635_check_view_stand.php
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/');
}

class HTML
{
    public static function chars($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

class Arr
{
    public static function get($a, $k, $d = NULL)
    {
        return is_array($a) && array_key_exists($k, $a) ? $a[$k] : $d;
    }

    public static function path($array, $path, $default = NULL)
    {
        return $default;
    }
}

class Log
{
    const WARNING = 3;
    const ERROR   = 4;
}

class Date
{
    public static function formatted_time($time, $format = 'Y-m-d H:i:s', $timezone = NULL)
    {
        $ts = is_numeric($time) ? (int) $time : strtotime((string) $time);

        return $ts > 0 ? date($format, $ts) : '';
    }
}

class Security
{
    public static function token()
    {
        return 'tok';
    }
}

class View
{
    public static function factory($file, array $vars = [])
    {
        return new View_Stub($file, $vars);
    }
}

class View_Stub
{
    public $file;
    public $vars;

    public function __construct($file, array $vars)
    {
        $this->file = $file;
        $this->vars = $vars;
    }

    /** Как Kohana: вид ищется в application/views и в views модулей. */
    public static function resolve($file)
    {
        $tries = ['/app/application/views/'.$file.'.php'];

        foreach (glob('/app/application/modules/*/views/'.$file.'.php') as $hit)
        {
            $tries[] = $hit;
        }

        foreach ($tries as $t)
        {
            if (file_exists($t))
            {
                return $t;
            }
        }

        return NULL;
    }

    public function __toString()
    {
        return $this->render();
    }

    public function render()
    {
        $path = self::resolve($this->file);

        if ($path === NULL)
        {
            return '';
        }

        extract($this->vars);
        ob_start();
        include $path;

        return (string) ob_get_clean();
    }
}

class Kohana
{
    public static $config;
    public static $log;

    public static function find_file($type, $file)
    {
        if ($type === 'views')
        {
            return View_Stub::resolve($file) ?: FALSE;
        }

        return file_exists('/app/application/'.$type.'/'.$file.'.php')
            ? '/app/application/'.$type.'/'.$file.'.php'
            : FALSE;
    }
}

Kohana::$log = new class { public function add($l, $m, $v = []) { } };

require '/app/application/modules/nj594_orgregistry/classes/Service/Nj594/Orgs.php';
require '/app/application/modules/nj635_scout/classes/Service/Nj635/Report.php';

$ok  = 0;
$bad = 0;

function check($label, $got, $want)
{
    global $ok, $bad;
    $g = is_bool($got) ? ($got ? 'true' : 'false') : (string) $got;
    $w = is_bool($want) ? ($want ? 'true' : 'false') : (string) $want;

    if ($g === $w) { $ok++; echo "  ок   {$label}\n"; }
    else          { $bad++; echo "  ФЕЙЛ {$label}: получено «{$g}», ждали «{$w}»\n"; }
}

function render($view_file, array $vars)
{
    extract($vars);
    ob_start();
    include $view_file;
    return (string) ob_get_clean();
}

$view = '/app/application/modules/nj635_scout/views/635/check.php';

$card = [
    'ok'         => TRUE,
    'kind'       => 'inn',
    'query'      => '7707083893',
    'org'        => [
        'id' => 7, 'ogrn' => '1027700132195', 'inn' => '7707083893', 'kpp' => '773601001',
        'form' => 'ЮЛ', 'short_name' => 'ООО «Ромашка»', 'full_name' => 'Общество с ограниченной ответственностью «Ромашка»',
        'address' => 'Москва, ул. Пример, 1', 'okved' => '62.01', 'okved_name' => 'Разработка ПО',
        'status' => 'Действующее', 'reg_date' => '2010-05-01', 'director' => 'Иванов Иван Иванович',
        'source' => 'fns', 'fetched_at' => '2026-09-27 10:00:00', 'url' => '/orgs/1027700132195',
    ],
    'candidates' => [],
    'vacancies'  => [
        'total' => 4, 'active' => 2,
        'items' => [[
            'id' => 1, 'company' => 'ООО «Ромашка»', 'company_key' => 'ромашка',
            'title' => 'PHP-разработчик', 'city' => 'Москва', 'direction' => 'Разработка',
            'salary' => 'от 200 000 ₽', 'url' => 'https://example.com/vacancy/1',
            'first_seen_at' => '2026-09-20 10:00:00', 'last_seen_at' => '2026-09-26 10:00:00',
        ]],
    ],
    'vacancy'    => NULL,
    'links'      => [[
        'module' => 'nj598', 'item_code' => 'dsr1234', 'title' => 'AI-досье: ООО «Ромашка»',
        'item_url' => '/dossier/dsr1234', 'kind' => 'review',
    ]],
    'signals'    => [
        ['level' => 'ok', 'title' => 'Есть активные вакансии — 2', 'text' => 'Компания набирает людей.'],
    ],
    'verdict'    => ['level' => 'ok', 'title' => 'Критичных сигналов в реестре нет', 'text' => 'Организация действует.'],
    'notes'      => [],
    'actions'    => [
        ['label' => 'Карточка в справочнике', 'hint' => 'Реквизиты и связи', 'icon' => 'fas fa-landmark', 'href' => '/orgs/1027700132195'],
        ['label' => 'Собрать AI-досье сейчас', 'hint' => 'Запуск одним нажатием', 'icon' => 'fas fa-bolt',
         'href' => '/dossier?company='.rawurlencode('ООО «Ромашка»'), 'action' => 'dossier', 'query' => 'ООО «Ромашка»'],
    ],
    'online'     => TRUE,
    'checked_at' => '27.09.2026 12:00',
];

echo "1. Полная карточка\n";
$card['flash'] = '';
$html = render($view, ['q' => '7707083893', 'card' => $card, 'csrf' => 'tok', 'logged' => TRUE]);

check('название компании', (bool) strpos($html, 'ООО «Ромашка»'), TRUE);
check('ИНН в метриках', (bool) strpos($html, '7707083893'), TRUE);
check('статус-бейдж', (bool) strpos($html, 'Действующее'), TRUE);
check('руководитель', (bool) strpos($html, 'Иванов Иван Иванович'), TRUE);
check('сигнал о вакансиях', (bool) strpos($html, 'Есть активные вакансии — 2'), TRUE);
check('вакансия в списке', (bool) strpos($html, 'PHP-разработчик'), TRUE);
check('счётчик активных', (bool) strpos($html, 'активных: 2'), TRUE);
check('связь с досье', (bool) strpos($html, '/dossier/dsr1234'), TRUE);
check('вход в досье остался в «уже собрано»', (bool) strpos($html, 'href="/dossier/dsr1234"'), TRUE);
check('старой кнопки «полная разведка» нет', (bool) strpos($html, 'action="/scout"'), FALSE);
check('дата проверена', (bool) strpos($html, '27.09.2026 12:00'), TRUE);
check('дата вакансии отформатирована', (bool) strpos($html, '26.09.2026'), TRUE);
check('одна кнопка запускает полный отчёт', (bool) strpos($html, 'Собрать полный отчёт'), TRUE);
check('запуск уходит на /check методом POST', (bool) preg_match('~<form method="post" action="/check"~', $html), TRUE);
check('форма запускает именно run', (bool) strpos($html, 'value="run"'), TRUE);
check('в форме запуска есть компания', (bool) strpos($html, 'value="ООО «Ромашка»"'), TRUE);
check('сказано, что появится на этой же странице', (bool) strpos($html, 'появятся здесь же'), TRUE);
check('перечислены три сервиса отчёта', (bool) preg_match('~AI-досье~', $html) && (bool) strpos($html, 'Сайт компании') && (bool) strpos($html, 'Риски'), TRUE);
check('досье не дублируется отдельной плиткой', (bool) strpos($html, '/dossier?company='), FALSE);
check('обычные плитки остались ссылками', (bool) strpos($html, 'href="/orgs/1027700132195"'), TRUE);

echo "\n1б. Сообщение после действия (flash)\n";
$html = render($view, ['q' => '7707083893', 'card' => $card, 'csrf' => 'tok', 'logged' => TRUE,
    'flash' => 'По этой компании досье уже есть — открыли его.']);
check('flash виден', (bool) strpos($html, 'досье уже есть'), TRUE);

echo "\n1в. Лимит ФНС исчерпан — подсказка у формы\n";
$card4 = $card;
$card4['fns'] = ['limited' => TRUE, 'message' => 'Запросов к ФНС сейчас слишком много (лимит: 5 запросов в минуту, 30 в час).'];
$html = render($view, ['q' => '7707083893', 'card' => $card4, 'csrf' => 'tok', 'logged' => TRUE, 'flash' => '']);
check('подсказка про паузу реестра', (bool) strpos($html, 'Реестр сейчас на паузе'), TRUE);
check('текст лимита показан', (bool) strpos($html, '5 запросов в минуту'), TRUE);

echo "\n1б. Отчёт на той же странице\n";
$step_done = ['key' => 'dossier', 'label' => 'AI-досье на работодателя', 'icon' => 'fas fa-user-secret', 'hint' => '', 'status' => 'done',
    'stage' => '', 'elapsed_sec' => 12, 'silent_sec' => NULL, 'error' => '', 'code' => 'dsr1234', 'page' => '/dossier/dsr1234',
    'data' => ['summary' => 'Итог досье', 'red_flags' => [], 'pros' => [], 'cheatsheet' => [], 'sources' => []]];
$step_run = ['key' => 'profile', 'label' => 'Сайт компании', 'icon' => 'fas fa-building', 'hint' => '', 'status' => 'running',
    'stage' => 'обходим страницы', 'elapsed_sec' => 8, 'silent_sec' => NULL, 'error' => '', 'code' => 'prf1', 'page' => '/company/prf1', 'data' => []];

$html = render($view, ['q' => '7707083893', 'card' => $card, 'csrf' => 'tok', 'logged' => TRUE, 'flash' => '',
    'report' => ['blocks' => [
        ['key' => 'dossier', 'status' => 'done', 'html' => Service_Nj635_Report::block('dossier', $step_done, ['query' => 'Ромашка'])],
        ['key' => 'profile', 'status' => 'running', 'html' => Service_Nj635_Report::block('profile', $step_run, ['query' => 'Ромашка'])],
    ], 'letters' => TRUE, 'ref_q' => 'q=Ромашка&d=dsr1234&pr=prf1', 'query' => 'Ромашка', 'ref' => [], 'done' => FALSE]]);

check('карточка и отчёт на одной странице', (bool) strpos($html, 'ООО «Ромашка»') && (bool) strpos($html, 'Полный отчёт'), TRUE);
check('блок досье отрисован', (bool) strpos($html, 'data-nj635-step="dossier"'), TRUE);
check('блок профиля отрисован', (bool) strpos($html, 'data-nj635-step="profile"'), TRUE);
check('результат досье виден сразу', (bool) strpos($html, 'Итог досье'), TRUE);
check('ход второго шага виден', (bool) strpos($html, 'обходим страницы'), TRUE);
check('опрос настроен на api статуса', (bool) strpos($html, 'data-api="/check/api/status?'), TRUE);
check('скрипт живого отчёта подключён', (bool) strpos($html, 'data-nj635-blocks'), TRUE);
check('счётчик готовности', (bool) strpos($html, 'готово 1 из 2'), TRUE);
check('блока запуска внизу больше нет', (bool) strpos($html, 'появятся здесь же'), FALSE);
check('отчёт можно обновить на месте', (bool) strpos($html, 'Обновить отчёт'), TRUE);
check('подсказка про письмо', (bool) strpos($html, '/coldmail'), TRUE);

// отчёт открывают и по ссылке вида /check?p=<код> — без строки запроса
$html = render($view, ['q' => '', 'card' => NULL, 'csrf' => 'tok', 'logged' => FALSE, 'flash' => '',
    'report' => ['blocks' => [
        ['key' => 'dossier', 'status' => 'done', 'html' => Service_Nj635_Report::block('dossier', $step_done, ['query' => 'Ромашка'])],
    ], 'letters' => TRUE, 'ref_q' => 'q=Ромашка&d=dsr1234', 'query' => 'Ромашка', 'ref' => [], 'done' => TRUE]]);
check('отчёт виден и без карточки', (bool) strpos($html, 'data-nj635-step="dossier"'), TRUE);
check('пустое состояние не мешает отчёту', (bool) strpos($html, 'Одна кнопка — весь отчёт'), FALSE);

echo "\n2. Пустой ввод (нет карточки)\n";
$html = render($view, ['q' => '', 'card' => NULL, 'csrf' => 'tok', 'logged' => FALSE, 'flash' => '']);
check('вход — одна строка поиска', (bool) strpos($html, 'placeholder="напр.'), TRUE);
check('объяснено, что всё на одной странице', (bool) strpos($html, 'никуда переходить не нужно'), TRUE);
check('показаны примеры', (bool) strpos($html, '/check?q=%D0%A1%D0%B1%D0%B5%D1%80%D0%B1%D0%B0%D0%BD%D0%BA'), TRUE);
check('нет блока действий', (bool) strpos($html, 'Запустить полную разведку'), FALSE);

echo "\n3. Организация не найдена (есть заметка)\n";
$card2 = $card;
$card2['org']     = NULL;
$card2['signals'] = [['level' => 'info', 'title' => 'Организация не найдена в справочнике', 'text' => 'Уточните ИНН/ОГРН.']];
$card2['verdict'] = ['level' => 'info', 'title' => 'Данных для вывода мало', 'text' => 'Проверьте реквизиты.'];
$card2['notes']   = ['В справочнике нет организации с таким названием — уточните ИНН/ОГРН.'];
$html = render($view, ['q' => 'Ромашка', 'card' => $card2, 'csrf' => 'tok', 'logged' => FALSE, 'flash' => '']);

check('заметка видна', (bool) strpos($html, 'В справочнике нет организации'), TRUE);
check('вывод info', (bool) strpos($html, 'Данных для вывода мало'), TRUE);
check('ссылка на вход', (bool) strpos($html, '/auth/login?return_url=check'), TRUE);

echo "\n4. Кандидаты (неоднозначное название)\n";
$card3 = $card2;
$card3['candidates'] = [
    ['name' => 'ООО «Ромашка»', 'inn' => '7707083893', 'ogrn' => '1027700132195', 'url' => '/check?q=7707083893'],
];
$html = render($view, ['q' => 'Ромашка', 'card' => $card3, 'csrf' => 'tok', 'logged' => FALSE, 'flash' => '']);
check('список кандидатов', (bool) strpos($html, 'Нашли несколько похожих'), TRUE);
check('ссылка-уточнение', (bool) strpos($html, '/check?q=7707083893'), TRUE);

echo "\n5. Ошибка ввода\n";
$html = render($view, ['q' => 'ab', 'card' => ['error' => 'Слишком короткий запрос — минимум 3 символа.'], 'csrf' => 'tok', 'logged' => FALSE, 'flash' => '']);
check('ошибка показана', (bool) strpos($html, 'Слишком короткий запрос'), TRUE);

echo "\n";
echo $bad === 0 ? "ВСЁ ПРОШЛО: {$ok} проверок\n" : "ПРОВАЛОВ: {$bad} из ".($ok + $bad)."\n";

exit($bad === 0 ? 0 : 1);
