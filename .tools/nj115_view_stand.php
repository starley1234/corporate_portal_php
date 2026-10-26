<?php
/* ---------------------------------------------------------------------------
   Стенд страницы /nj115 (views/115/index.php): вид рисуется на РЕАЛЬНЫХ данных
   панели, и ни одного PHP-warning.

   Зачем: страницу видит владелец, а не только API. Ошибка в видах (необъявленная
   переменная, опечатка в ключе) даёт «страница открылась, но с мусором в разметке»
   — это ловится только рендером. Стенд рендерит вид дважды: с полной панелью
   (покрытие, отставания, мусор, проблемы, задачи) и с пустой.

   Запуск:
     R=/path/to/n2j.ru; H=/tmp/phpwasm
     cd $H && node harness.mjs \
       "{\"/115_index.php\":\"$R/application/modules/nj115_semantic/views/115/index.php\"}" \
       "$(cat $R/.tools/nj115_view_stand.php)"
   --------------------------------------------------------------------------- */
define('SYSPATH', '/app');

/* Все предупреждения PHP собираем и проверяем: страница обязана быть чистой. */
$GLOBALS['WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    $GLOBALS['WARN'][] = $str.' @ '.basename((string) $file).':'.$line;
    return TRUE;
});

class HTML {
    public static function chars($s){ return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
class Date {
    public static function formatted_time($ts, $fmt = 'd.m.y H:i'){ return substr((string) $ts, 0, 16); }
}
class View {
    public static $called = [];
    public static function factory($path, $data = []){ self::$called[] = $path; return ''; }
}

function render($panel)
{
    $csrf = 'csrf-токен';
    ob_start();
    include '/115_index.php';
    return ob_get_clean();
}

$FAIL = 0; $CHECKS = 0;
function check($label, $cond, $extra = '')
{
    global $FAIL, $CHECKS;
    $CHECKS++;
    if ( ! $cond) $FAIL++;
    echo ($cond ? 'ОК   ' : 'ПЛОХО')." $label".($extra !== '' ? " :: $extra" : '')."\n";
}

$healthy = [
    'collections' => [
        ['name' => 'nj560_knowledge', 'description' => 'База знаний', 'vectors' => 120, 'entities' => 118, 'model' => 'e5', 'dims' => 1024, 'updated_at' => '2026-09-28 09:00:00'],
    ],
    'totals'      => ['collections' => 1, 'vectors' => 120, 'entities' => 118],
    'queue'       => [
        'ok' => TRUE, 'pending' => 1, 'processing' => 1, 'bridge' => 2, 'queued_recent' => 2,
        'jobs' => [[
            'id' => 969, 'status' => 'failed', 'error' => 'эмбеддинг-сервер не ответил за 180 с',
            'collection' => 'nj560_knowledge', 'entity' => 'doc', 'items' => 32,
            'attempts' => 3, 'max' => 3, 'worker' => 'worker-1',
            'finished_at' => '2026-09-28 09:10:00', 'created_at' => '2026-09-28 09:00:00',
        ]],
    ],
    'searches'    => [
        ['collection' => 'nj560_knowledge', 'entity' => 'doc', 'query' => 'бюджет на квартал', 'results' => 3,
         'vector_hits' => 2, 'text_hits' => 1, 'cached' => FALSE, 'ms' => 120, 'error' => '', 'created_at' => '2026-09-28 10:00:00'],
        ['collection' => 'nj560_knowledge', 'entity' => '', 'query' => 'какой-то мусор', 'results' => 0,
         'vector_hits' => 0, 'text_hits' => 0, 'cached' => TRUE, 'ms' => 0, 'error' => '', 'created_at' => '2026-09-28 09:59:00'],
        ['collection' => 'nj560_knowledge', 'entity' => '', 'query' => 'падение', 'results' => 0,
         'vector_hits' => 0, 'text_hits' => 0, 'cached' => FALSE, 'ms' => 15, 'error' => 'эмбеддинг-сервер молчит', 'created_at' => '2026-09-28 09:58:00'],
    ],
    'search_stats' => ['total' => 5, 'empty' => 2, 'errors' => 1, 'cached' => 1, 'avg_ms' => 33, 'last_at' => '2026-09-28 10:00:00'],
    'health'      => [
        'ok' => FALSE, 'engine' => 'real[]', 'bridge_used' => 2,
        'embed_url' => 'https://embed.example/v1', 'embed_model' => 'e5', 'embed_dims' => 1024,
        'coverage_min' => 90.0, 'search' => ['max_distance' => 0.6, 'hybrid' => TRUE, 'fulltext_min_length' => 3],
        'coverage' => [
            ['key' => 'nj560_knowledge', 'title' => 'База знаний (nj560)', 'collection' => 'nj560_knowledge', 'entity' => 'doc',
             'total' => 100, 'have' => 97, 'fresh' => 90, 'missing' => 3, 'stale' => 7, 'orphans' => 3, 'coverage' => 90.0, 'error' => ''],
            ['key' => 'nj550_resumes', 'title' => 'Резюме (nj550)', 'collection' => 'nj550_resumes', 'entity' => 'resume',
             'total' => 50, 'have' => 50, 'fresh' => 50, 'missing' => 0, 'stale' => 0, 'orphans' => 0, 'coverage' => 100.0, 'error' => ''],
        ],
        'problems' => ['«База знаний (nj560)»: проиндексировано 90 % (без векторов 3, устарело 7) — кнопка «Догнать»', 'векторов без хеша текста: 4 — пересчитаются при «Догнать»'],
    ],
];

$html = render($healthy);
$warns = implode(' | ', $GLOBALS['WARN']);

echo "== Страница /nj115: полная панель ==\n";
check('вид отрисован без единого PHP-warning', $GLOBALS['WARN'] === [], $warns === '' ? '' : $warns);
check('общий статус-ряд и его скрипт подключены',
    View::$called === ['partials/live_status', 'partials/live_status_js'], implode(',', View::$called));
check('покрытие источников показано', strpos($html, 'Источники индексации') !== FALSE AND strpos($html, '90,0 %') !== FALSE);
check('худшее покрытие видно в шапке блока', strpos($html, 'покрытие 90,0 %') !== FALSE);
check('строки источников с числами', substr_count($html, 'nj560_knowledge') >= 2 AND strpos($html, 'nj550_resumes') !== FALSE);
check('кнопки «Догнать» и «Убрать мусор» есть', strpos($html, 'id="nj115-catch-all"') !== FALSE AND strpos($html, 'id="nj115-prune"') !== FALSE);
check('у отстающего источника — своя кнопка, у актуального — «актуально»',
    strpos($html, 'class="nj115-catch') !== FALSE AND strpos($html, 'актуально') !== FALSE);
check('мусор и отставания показаны числами', strpos($html, '3 / 7') !== FALSE, substr($html, 0, 0));
check('проблемы объяснены человеку', strpos($html, 'Что требует внимания') !== FALSE AND strpos($html, 'векторов без хеша текста: 4') !== FALSE);
check('задачи канала и кнопка «Повторить» на месте', strpos($html, '#969') !== FALSE AND strpos($html, 'nj115-retry') !== FALSE);
check('настройки поиска видны', strpos($html, 'Порог расстояния 0.6') !== FALSE AND strpos($html, 'фолбэк по словам') !== FALSE);
check('мост через сайт отмечен предупреждением', strpos($html, 'в HTTP-режиме') !== FALSE);
check('движок real[] подписан по-русски', strpos($html, 'real[] · косинус') !== FALSE);

echo "\n== Журнал поисков на странице ==\n";
check('карточка «Поисковые запросы» есть', strpos($html, 'Поисковые запросы') !== FALSE);
check('запрос виден как есть', strpos($html, 'бюджет на квартал') !== FALSE);
check('попадания разложены: смысл и слова', strpos($html, '2 по смыслу, 1 по словам') !== FALSE);
check('пустой результат помечен отдельно', strpos($html, '>пусто<') !== FALSE);
check('ошибка поиска помечена и раскрывается в подсказке',
    strpos($html, '>ошибка<') !== FALSE AND strpos($html, 'title="эмбеддинг-сервер молчит"') !== FALSE);
check('ответ из кэша подписан', strpos($html, 'из кэша') !== FALSE);
check('сводка за сутки с числами', strpos($html, 'за сутки: 5') !== FALSE AND strpos($html, 'среднее: <b>33</b> мс') !== FALSE);
check('тело журнала и подсказка пустого состояния помечены для живого обновления',
    strpos($html, 'id="nj115-searches"') !== FALSE AND strpos($html, 'id="nj115-searches-empty"') !== FALSE);
check('строки журнала перерисовываются из JS без вставки сырого HTML',
    strpos($html, 'function renderSearches(') !== FALSE AND strpos($html, 'esc(r.query)') !== FALSE);
check('после поиска журнал обновляется сам', strpos($html, 'reloadSearches();') !== FALSE);

echo "\n== Страница /nj115: пустая панель (свежая установка) ==\n";
$GLOBALS['WARN'] = [];
$html = render([]);
check('пустая панель рисуется без warning', $GLOBALS['WARN'] === [], implode(' | ', $GLOBALS['WARN']));
check('сказано, что источники не описаны', strpos($html, 'Источники не описаны') !== FALSE);
check('сказано, что векторов пока нет', strpos($html, 'Векторов пока нет') !== FALSE);
check('очереди нет — блок задач не рисуется', strpos($html, 'Последние задачи канала') === FALSE);
check('кнопки догона остались (источники могут появиться)', strpos($html, 'id="nj115-catch-all"') !== FALSE);
check('журнал пуст: подсказка вместо пустой таблицы', strpos($html, 'Запросов ещё не было') !== FALSE);
check('журнал пуст: чип «за сутки» скрыт',
    strpos($html, 'id="nj115-searches-total" class="hidden') !== FALSE);

echo "\n== Панель с ошибкой сервиса ==\n";
$GLOBALS['WARN'] = [];
$html = render(['error' => 'SQLSTATE[42P01]: relation "nj_115_vectors" does not exist', 'collections' => [], 'queue' => [], 'health' => [], 'totals' => []]);
check('ошибка сервиса не ломает страницу и не даёт warning', $GLOBALS['WARN'] === [], implode(' | ', $GLOBALS['WARN']));

echo "\n".($FAIL === 0 ? "ВСЁ ОК: страница /nj115" : "ПРОВАЛОВ: $FAIL")." (проверок: $CHECKS)\n";

restore_error_handler();
exit(0);
