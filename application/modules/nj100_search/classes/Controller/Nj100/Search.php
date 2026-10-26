<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj100_search: /search — СТРАНИЦА УДАЛЕНА, отвечаем 410 Gone.
 *
 * Почему: страница не работала как сервис (полноценная выдача живёт в шапке
 * каждой страницы: мгновенный дропдаун + Ctrl+K, «Enter — перейти»), а краулеры
 * и роботы-долбилы ходили по `/search?q=…&t=…` миллионами вариаций: каждый такой
 * заход — девять запросов ILIKE по девяти таблицам. Владелец: «эту страницу
 * удали, она не работает и её долбят роботы».
 *
 * Что осталось:
 *   * GET /search/api?q=… — машинная выдача (её и дёргает поиск в шапке),
 *     контроллер Controller_Nj100_Api и Service_Nj100_Search НЕ тронуты;
 *   * сама /search — отвечает 410 Gone без шаблона, сессии и обращений к БД.
 *
 * Почему именно 410, а не 404 и не редирект: 410 — единственный ответ, по
 * которому поисковики выкидывают URL из индекса (404 «попробуем позже»,
 * редирект «переехало, запомним»). Ответ при этом статический и не читает БД,
 * поэтому роботам он ничего не стоит.
 *
 * Класс отвечает НЕ через Controller_Erp100_Base: у него в before() авторизация,
 * меню каталога и трекинг визитов — всё это для «страницы, которой нет» лишняя
 * работа с БД ровно того вида, от которого избавляемся.
 */
class Controller_Nj100_Search extends Controller {

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
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<title>Страница удалена — n2j.ru</title>
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head><body style="font:16px/1.5 system-ui,sans-serif;color:#111;margin:12vh auto;max-width:34rem;padding:0 1.25rem">
<h1 style="font-size:1.25rem;margin:0 0 .75rem">Страница удалена</h1>
<p style="color:#374151">Отдельная страница поиска больше не поддерживается — ей пользовались только роботы.</p>
<p style="color:#374151">Поиск по материалам, базе знаний, организациям и вакансиям — в шапке любой страницы
(значок лупы или Ctrl&nbsp;+&nbsp;K); Enter открывает выбранный результат.</p>
<p><a href="/" style="color:#1d4ed8">На главную</a></p>
</body></html>
HTML
        );
    }
}
