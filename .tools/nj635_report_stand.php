<?php
/**
 * Стенд единого отчёта (nj635_scout, /check) на PHP-in-WASM.
 *
 * Повод: владелец — «слишком много кликов по интерфейсам, нужен один экран,
 * который всё делает и показывает результат». Здесь проверяются части, от
 * которых зависит именно это: ссылки отчёта, доступ (чужое не показываем),
 * шкала состояний и рендер блоков «досье / сайт / риски» во всех состояниях.
 *
 *   N2J_ROOT=/home/user/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj635_report_stand.php
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

require '/app/application/modules/nj635_scout/classes/Service/Nj635/Report.php';

$ok  = 0;
$bad = 0;

function check($label, $got, $want)
{
    global $ok, $bad;
    $g = is_bool($got) ? ($got ? 'true' : 'false') : (is_array($got) ? json_encode($got, JSON_UNESCAPED_UNICODE) : (string) $got);
    $w = is_bool($want) ? ($want ? 'true' : 'false') : (is_array($want) ? json_encode($want, JSON_UNESCAPED_UNICODE) : (string) $want);

    if ($g === $w) { $ok++; echo "  ок   {$label} = {$g}\n"; }
    else          { $bad++; echo "  ФЕЙЛ {$label}: получено «{$g}», ждали «{$w}»\n"; }
}

echo "1. Шаги отчёта\n";
$steps = Service_Nj635_Report::steps();
check('шагов в отчёте', count($steps), 3);
check('порядок: досье первым', array_keys($steps)[0], 'dossier');
check('досье доступно без входа', (bool) $steps['dossier']['login'], FALSE);
check('профиль сайта требует входа', (bool) $steps['profile']['login'], TRUE);
check('риски требуют входа', (bool) $steps['watch']['login'], TRUE);

echo "\n2. Ссылки отчёта из адреса\n";
$ref = Service_Nj635_Report::ref(['p' => 'sct1234', 'd' => 'dosabcd', 'pr' => 'prf99', 'w' => 'wdg1', 'q' => 'Ромашка']);
check('код маршрута', $ref['project'], 'sct1234');
check('код досье', $ref['dossier'], 'dosabcd');
check('запрос сохранён', $ref['query'], 'Ромашка');

$ref = Service_Nj635_Report::ref(['d' => 'dos/x?y=<script>_ok', 'p' => '']);
check('мусор в коде вычищен', $ref['dossier'], 'dosxyscript_ok');
check('спецсимволов не осталось', (bool) preg_match('~[^0-9a-z_]~', $ref['dossier']), FALSE);
check('пустой p остаётся пустым', $ref['project'], '');
check('нет ссылок — нечего показывать', Service_Nj635_Report::has_ref($ref), TRUE);
check('полностью пусто — нечего показывать', Service_Nj635_Report::has_ref(Service_Nj635_Report::ref([])), FALSE);

echo "\n3. Ссылки отчёта в адрес\n";
check('полный набор', Service_Nj635_Report::query(['project' => 'sct1', 'dossier' => '', 'profile' => 'prf1', 'watch' => 'wdg1', 'query' => 'Сбер']),
    'q='.rawurlencode('Сбер').'&p=sct1&pr=prf1&w=wdg1');
check('только досье', Service_Nj635_Report::query(['project' => '', 'dossier' => 'dsr9', 'profile' => '', 'watch' => '', 'query' => '']), 'd=dsr9');

echo "\n4. Шкала состояний владельцев\n";
check('new → в очереди', Service_Nj635_Report::map_status('new'), 'queued');
check('running → идёт', Service_Nj635_Report::map_status('running'), 'running');
check('done → готово', Service_Nj635_Report::map_status('done'), 'done');
check('error → ошибка', Service_Nj635_Report::map_status('error'), 'failed');
check('failed → ошибка', Service_Nj635_Report::map_status('failed'), 'failed');
check('cancelled → остановлено', Service_Nj635_Report::map_status('cancelled'), 'cancelled');
check('неизвестное → идёт', Service_Nj635_Report::map_status('чтото'), 'running');

echo "\n5. Доступ к чужому\n";
$me = ['user_id' => 7, 'uid' => 'g:abc', 'ubid' => 'abc'];
check('свой аккаунт — видно', Service_Nj635_Report::mine($me, 7, '', ''), TRUE);
check('чужой аккаунт — закрыто', Service_Nj635_Report::mine($me, 8, '', ''), FALSE);
check('своя кука — видно', Service_Nj635_Report::mine($me, 0, 'g:abc', ''), TRUE);
check('чужой артефакт без владельца — закрыто', Service_Nj635_Report::mine($me, 0, '', ''), FALSE);
check('гость→логин по ubid — видно', Service_Nj635_Report::mine($me, 0, 'g:abc', 'g:'), TRUE);
check('чужой ubid — закрыто', Service_Nj635_Report::mine($me, 0, 'g:zzz', 'g:'), FALSE);
check('гость без куки — закрыто', Service_Nj635_Report::mine(['user_id' => NULL, 'uid' => '', 'ubid' => NULL], 0, 'g:x', 'g:'), FALSE);

echo "\n6. Блок «AI-досье»\n";
$step = ['key' => 'dossier', 'label' => 'AI-досье на работодателя', 'icon' => 'fas fa-user-secret', 'hint' => 'Отзывы', 'status' => 'done',
    'stage' => '', 'elapsed_sec' => 0, 'silent_sec' => NULL, 'error' => '', 'code' => 'dos1', 'page' => '/dossier/dos1', 'rev' => 'deadbeef',
    'data' => [
        'summary' => 'Компания делает промышленное ПО.',
        'red_flags' => [['text' => 'Текучка в отделе поддержки', 'source' => 'отзывы'], 'Задержки зарплаты в 2024'],
        'pros' => ['Сильный инженерный состав'],
        'cheatsheet' => ['questions_to_ask' => ['Как устроен онбординг?']],
        'sources' => [['url' => 'https://a', 'title' => 'A'], ['url' => 'https://b', 'title' => 'B']],
    ]];
$html = Service_Nj635_Report::block('dossier', $step, ['query' => 'Ромашка']);
check('блок отрисован', (bool) strpos($html, 'data-nj635-step="dossier"'), TRUE);
check('красный флаг показан', (bool) strpos($html, 'Текучка в отделе поддержки'), TRUE);
check('плюсы показаны', (bool) strpos($html, 'Сильный инженерный состав'), TRUE);
check('вопросы к собеседованию', (bool) strpos($html, 'Как устроен онбординг?'), TRUE);
check('счётчик источников', (bool) strpos($html, '2 источников'), TRUE);
check('ссылка на полное досье', (bool) strpos($html, '/dossier/dos1'), TRUE);
check('на блоке есть отпечаток состояния', (bool) preg_match('~data-nj635-step="dossier" data-rev="deadbeef"~', $html), TRUE);

$run = $step;
$run['status'] = 'running';
$run['stage'] = 'читаем отзывы сотрудников (3/8)';
$run['elapsed_sec'] = 42;
$html = Service_Nj635_Report::block('dossier', $run, ['query' => 'Ромашка']);
check('ход работы виден', (bool) strpos($html, 'читаем отзывы сотрудников (3/8)'), TRUE);
check('секундомер отдан скрипту', (bool) strpos($html, 'data-elapsed="42"'), TRUE);
check('есть кнопка остановить', (bool) strpos($html, '/dossier/stop/dos1'), TRUE);

$fail = $step;
$fail['status'] = 'failed';
$fail['error'] = 'Воркер не смог собрать досье';
$html = Service_Nj635_Report::block('dossier', $fail, ['query' => 'Ромашка']);
check('ошибка показана честно', (bool) strpos($html, 'Воркер не смог собрать досье'), TRUE);

$locked = $step;
$locked['status'] = 'locked';
$html = Service_Nj635_Report::block('dossier', $locked, ['query' => 'Ромашка']);
check('чужое не раскрываем', (bool) strpos($html, 'другой учётной записью'), TRUE);
check('данных чужого досье нет', (bool) strpos($html, 'Текучка'), FALSE);

echo "\n7. Блок «Сайт компании»\n";
$prof = ['key' => 'profile', 'label' => 'Сайт компании', 'icon' => 'fas fa-building', 'hint' => '', 'status' => 'done', 'stage' => '',
    'elapsed_sec' => 0, 'silent_sec' => NULL, 'error' => '', 'code' => 'prf1', 'page' => '/company/prf1', 'needs_login' => FALSE,
    'data' => ['sphere' => 'Разработка ПО', 'size_estimate' => '120 человек', 'confidence' => 'высокая', 'summary' => 'Делает АСУ ТП.',
        'contacts' => ['emails' => ['hr@example.ru'], 'phones' => ['+7 495 000-00-00'], 'address' => 'Москва'],
        'decision_makers' => [['name' => 'Пётр Иванов', 'role' => 'CTO', 'source' => 'сайт']],
        'tech_stack' => ['PHP', 'PostgreSQL']]];
$html = Service_Nj635_Report::block('profile', $prof, ['query' => 'Ромашка']);
check('сфера показана', (bool) strpos($html, 'Разработка ПО'), TRUE);
check('почта показана', (bool) strpos($html, 'hr@example.ru'), TRUE);
check('ЛПР показано', (bool) strpos($html, 'Пётр Иванов'), TRUE);
check('стек показан', (bool) strpos($html, 'PostgreSQL'), TRUE);

$need = $prof;
$need['status'] = 'missing';
$need['needs_login'] = TRUE;
$html = Service_Nj635_Report::block('profile', $need, ['query' => 'Ромашка']);
check('зовём войти без ругани', (bool) strpos($html, 'Войдите'), TRUE);
check('в ссылке входа — запуск отчёта', (bool) strpos($html, 'run%3D1'), TRUE);

echo "\n8. Блок «Наблюдение за рисками»\n";
$watch = ['key' => 'watch', 'label' => 'Наблюдение за рисками', 'icon' => 'fas fa-shield-halved', 'hint' => '', 'status' => 'watching',
    'stage' => 'ООО «Ромашка» · ИНН 7707083893', 'elapsed_sec' => 0, 'silent_sec' => NULL, 'error' => '', 'code' => 'wdg1',
    'page' => '/watchdog#wdg1', 'needs_login' => FALSE,
    'events' => [
        ['severity' => 'risk', 'title' => 'Судебный иск на 5 млн ₽', 'url' => 'https://sud', 'summary' => 'Арбитраж', 'created_at' => '2026-09-20 10:00:00'],
        ['severity' => 'info', 'title' => 'Сменился ОКВЭД', 'url' => '', 'summary' => '', 'created_at' => '2026-08-01 10:00:00'],
    ]];
$html = Service_Nj635_Report::block('watch', $watch, ['query' => 'Ромашка']);
check('риск виден', (bool) strpos($html, 'Судебный иск на 5 млн ₽'), TRUE);
check('метка риска', (bool) strpos($html, 'Риск'), TRUE);
check('ссылка на все сигналы', (bool) strpos($html, '/watchdog#wdg1'), TRUE);

$clean = $watch;
$clean['events'] = [];
$html = Service_Nj635_Report::block('watch', $clean, ['query' => 'Ромашка']);
check('пусто — это хорошая новость', (bool) strpos($html, 'Сигналов пока нет'), TRUE);

$locked = $watch;
$locked['status'] = 'locked';
$locked['events'] = [];
$html = Service_Nj635_Report::block('watch', $locked, ['query' => 'Ромашка']);
check('чужое наблюдение закрыто', (bool) strpos($html, 'другой учётной записью'), TRUE);
check('чужих сигналов не показали', (bool) strpos($html, 'Судебный иск'), FALSE);

$need = $watch;
$need['status'] = 'missing';
$need['needs_login'] = TRUE;
$need['events'] = [];
$html = Service_Nj635_Report::block('watch', $need, ['query' => 'Ромашка']);
check('гостю предлагают войти в наблюдение', (bool) strpos($html, 'включить наблюдение'), TRUE);
check('в ссылке входа — запуск отчёта', (bool) strpos($html, 'run%3D1'), TRUE);

echo "\n9. Форма на главной (тот же вид, что и на странице)\n";
$html = View::factory('welcome/parts/check_form')->render();
check('отправка на /check', (bool) strpos($html, 'action="/check"'), TRUE);
check('метод POST (запуск ровно один раз)', (bool) preg_match('~<form method="post" action="/check"~', $html), TRUE);
check('есть защита от подделки', (bool) strpos($html, 'name="csrf"'), TRUE);
check('кнопка «Проверить» — только карточка', (bool) preg_match('~name="act" value="card"~', $html), TRUE);
check('кнопка «Полный отчёт» — всё сразу', (bool) preg_match('~name="act" value="run"~', $html), TRUE);
check('подсказка про «без переходов»', (bool) strpos($html, 'без переходов по сервисам'), TRUE);
check('в главной подставлен тот же вид', (bool) strpos((string) file_get_contents('/app/application/views/welcome/index.php'), "welcome/parts/check_form"), TRUE);

echo "\n10. Вход из карты разведки (/scout/<code>)\n";
$scout_view = (string) file_get_contents('/app/application/modules/nj635_scout/views/635/view.php');
check('есть ссылка на единый отчёт', (bool) strpos($scout_view, 'href="/check?'), TRUE);
check('адрес строится общим методом', (bool) strpos($scout_view, 'Service_Nj635_Report::query('), TRUE);
check('адрес совпадает с тем, что понимает отчёт', Service_Nj635_Report::query([
        'project' => 'sct1234', 'dossier' => '', 'profile' => '', 'watch' => '', 'query' => 'Ромашка']),
    'q='.rawurlencode('Ромашка').'&p=sct1234');

echo "\n";
echo $bad === 0 ? "ВСЁ ПРОШЛО: {$ok} проверок\n" : "ПРОВАЛОВ: {$bad} из ".($ok + $bad)."\n";

exit($bad === 0 ? 0 : 1);
