<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100_search: /search — СТРАНИЦА УДАЛЕНА, отвечаем 410 Gone.
 *
 * Почему: страница не работала как сервис (полноценная выдача живёт в шапке
 * каждой страницы: мгновенный дропдаун + Ctrl+K, «Enter — перейти»), а краулеры
 * и роботы-долбилы ходили по `/search?q=…&t=…` миллионами вариаций: каждый такой
 * заход — девять запросов ILIKE по девяти таблицам. Владелец: «эту страницу
 * удали, она не работает и её долбят роботы».
 *
 * Что осталось:
 *   * GET /search/api?q=… — машинная выдача (её и дёргает поиск в шапке),
 *     контроллер Controller_Prx100_Api и Service_Prx100_Search НЕ тронуты;
 *   * сама /search — отвечает 410 Gone без шаблона, сессии и обращений к БД.
 *
 * Почему именно 410, а не 404 и не редирект: 410 — единственный ответ, по
 * которому поисковики выкидывают URL из индекса (404 «попробуем позже»,
 * редирект «переехало, запомним»). Ответ при этом статический и не читает БД,
 * поэтому роботам он ничего не стоит.
 *
 * Класс отвечает НЕ через Controller_Prx100_Base: у него в before() авторизация,
 * меню каталога и трекинг визитов — всё это для «страницы, которой нет» лишняя
 * работа с БД ровно того вида, от которого избавляемся.
 */
class Controller_Prx100_Search extends Controller {

    /** @var bool страница не рендерит шаблоны */
    public $auto_render = FALSE;

    /**
     * GET /search — 410 Gone.
     */
    public function action_index()
    {
        $this->response->status(410);
        $this->response->headers('Content-Type', 'text/html; charset=utf-8');
        // Тот же смысл, что у HTML-мета: даже если робот пришёл за ссылкой,
        // индексировать тут нечего.
        $this->response->headers('X-Robots-Tag', 'noindex, nofollow, noarchive');
        // Ответ всегда одинаковый — пусть краулеры кэшируют его, а не переспрашивают.
        $this->response->headers('Cache-Control', 'public, max-age=3600');

        $this->response->body(<<<'HTML'
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Страница удалена — PRX</title>
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f9fafb;color:#111827;font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
main{width:min(100% - 2rem,35rem);margin:12vh auto;padding:2rem;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 2px rgb(17 24 39/.06)}
h1{margin:0 0 .75rem;font-size:30px;line-height:1.2;letter-spacing:-.02em}
p{margin:.75rem 0;color:#4b5563}
a.button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;margin-top:1rem;padding:.625rem 1rem;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;color:#1f2937;font-weight:600;text-decoration:none}
a.button:hover{background:#f3f4f6}
@media(prefers-color-scheme:dark){
body{background:#111827;color:#f9fafb}
main{background:#1f2937;border-color:#374151;box-shadow:0 1px 2px rgb(0 0 0/.25)}
p{color:#d1d5db}
a.button{background:#1f2937;border-color:#4b5563;color:#f9fafb}
a.button:hover{background:#374151}
}
</style>
</head>
<body>
<main>
<h1>Страница удалена</h1>
<p>Отдельная страница поиска больше не поддерживается — ей пользовались только роботы.</p>
<p>Поиск по материалам, базе знаний, организациям и вакансиям — в шапке любой страницы
(значок лупы или Ctrl&nbsp;+&nbsp;K); Enter открывает выбранный результат.</p>
<a href="/" class="button">На главную</a>
</main>
</body>
</html>
HTML
        );
    }
}
