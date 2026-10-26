<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API nj602: приём статьи от воркера и создание прогонов по расписанию.
 *  create          — POST (токен воркера/пиналки): topic? → черновик + задача;
 *  progress/<code> — POST: признак жизни (токен);
 *  item/<code>     — POST: статья целиком (токен);
 *  fail/<code>     — POST: ошибка воркера (токен);
 *  sitemap         — GET /sitemap.xml: публичная карта сайта для поисковиков.
 * Ошибки — json {error} с причиной.
 */
class Controller_Nj602_Api extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
    }

    /** POST /content/api/create — расписание (nj603) или управление. */
    public function action_create()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал (WORKER_API_TOKEN сайта)']);

            return;
        }

        $res = Service_Nj602_Content::create_draft(
            (string) $this->request->post('topic'), 'schedule');

        $this->_json(isset($res['error']) ? 400 : 200, $res);
    }

    /**
     * POST /content/api/brief — брифинг воркера перед стартом статьи:
     * работает ли конвейер, подсказка темы (ротация), интересы аудитории
     * (обезличенный агрегат nj600), последние заголовки (не повторяться).
     */
    public function action_brief()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);

            return;
        }

        $this->_json(200, Service_Nj602_Content::brief_for_worker());
    }

    public function action_progress()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);

            return;
        }

        $trace_raw = (string) $this->request->post('trace');
        $trace     = json_decode($trace_raw, TRUE);
        $trace     = is_array($trace) ? $trace : NULL;

        $this->_json(200, Service_Nj602_Content::api_progress(
            (string) $this->request->param('code'),
            (string) $this->request->post('phase'),
            (string) $this->request->post('note'),
            $trace));
    }

    public function action_item()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);

            return;
        }

        $code = (string) $this->request->param('code');
        $d = [
            'topic'        => (string) $this->request->post('topic'),
            'title'        => (string) $this->request->post('title'),
            'summary'      => (string) $this->request->post('summary'),
            'body_md'      => (string) $this->request->post('body_md'),
            'image_prompt' => (string) $this->request->post('image_prompt'),
            'mermaid'      => (string) $this->request->post('mermaid'),
            'graph'        => json_decode((string) $this->request->post('graph'), TRUE) ?: [],
            'tags'         => json_decode((string) $this->request->post('tags'), TRUE) ?: [],
            'sources'      => json_decode((string) $this->request->post('sources'), TRUE) ?: [],
            'facts'        => json_decode((string) $this->request->post('facts'), TRUE) ?: [],
            'quality'      => json_decode((string) $this->request->post('quality'), TRUE) ?: [],
            'model_used'   => (string) $this->request->post('model_used'),
            'usage'        => json_decode((string) $this->request->post('usage'), TRUE) ?: [],
            'progress'     => json_decode((string) $this->request->post('progress'), TRUE) ?: [],
        ];

        Kohana::$log->add(Log::INFO, 'nj602: статья :c принята от воркера', [':c' => $code]);
        $this->_json(200, Service_Nj602_Content::api_save($code, $d));
    }

    public function action_fail()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);

            return;
        }

        $code = (string) $this->request->param('code');
        $trace_raw = (string) $this->request->post('trace');
        $trace     = json_decode($trace_raw, TRUE);
        $trace     = is_array($trace) ? $trace : NULL;

        Kohana::$log->add(Log::WARNING, 'nj602: воркер сообщил ошибку по :c: :e',
            [':c' => $code, ':e' => mb_substr((string) $this->request->post('error'), 0, 300)]);

        $this->_json(200, Service_Nj602_Content::api_fail(
            $code, (string) $this->request->post('error'), $trace));
    }

    /**
     * GET /content/api/article/<code> — статья целиком для перегенерации схемы
     * (токен воркера). Отдаём ровно то, что нужно: тему, тело и текущую схему.
     */
    public function action_article()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);
            return;
        }

        $code = (string) $this->request->param('code');
        $a = Service_Nj602_Content::article_payload($code);

        if ( ! $a)
        {
            $this->_json(404, ['error' => 'Статья '.$code.' не найдена']);
            return;
        }

        $this->_json(200, [
            'ok'       => TRUE,
            'code'     => (string) $a['code'],
            'topic'    => (string) $a['topic'],
            'title'    => (string) $a['title'],
            'body_md'  => (string) $a['body_md'],
            'mermaid'  => (string) $a['mermaid'],
            'status'   => (string) $a['status'],
        ]);
    }

    /**
     * POST /content/api/mermaid/<code> — воркер вернул перегенерированную схему.
     * Меняем только mermaid: текст, статус и дата публикации не трогаются.
     */
    public function action_mermaid()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);
            return;
        }

        $code = (string) $this->request->param('code');
        $mmd  = (string) $this->request->post('mermaid');
        $res  = Service_Nj602_Content::save_mermaid($code, $mmd);

        if (isset($res['error']))
        {
            $this->_json(404, $res);
            return;
        }

        $trace = json_decode((string) $this->request->post('progress'), TRUE);
        Service_Nj602_Content::api_progress($code, 'Схема обновлена', '',
            is_array($trace) ? $trace : NULL);

        Kohana::$log->add(Log::INFO, 'nj602: схема статьи :c перегенерирована (:n знаков)',
            [':c' => $code, ':n' => mb_strlen(trim($mmd))]);

        $this->_json(200, ['ok' => TRUE, 'code' => $code, 'chars' => mb_strlen(trim($mmd))]);
    }

    /** POST /content/api/knowledge/search — RAG-контекст для воркера. */
    public function action_knowledge_search()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);
            return;
        }

        $q = trim((string) ($this->request->post('q') ?: $this->request->query('q')));
        if (mb_strlen($q) < 3)
        {
            $this->_json(400, ['error' => 'RAG-запрос слишком короткий']);
            return;
        }

        $this->_json(200, Service_Nj560_Knowledge::search($q, [
            'limit' => 8, 'semantic' => TRUE,
        ]));
    }

    /** POST /content/api/knowledge/remember — сохранить заметку/статью в RAG. */
    public function action_knowledge_remember()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);
            return;
        }

        $title = trim((string) $this->request->post('title'));
        $content = trim((string) $this->request->post('content'));
        if ($title === '' OR $content === '')
        {
            $this->_json(400, ['error' => 'Заметка без заголовка или текста']);
            return;
        }

        $key = trim((string) $this->request->post('key'));
        $key = $key !== '' ? $key : Service_Nj560_Knowledge::make_key($title.' '.$content);
        $meta = json_decode((string) $this->request->post('meta'), TRUE);
        $meta = is_array($meta) ? $meta : [];

        $this->_json(200, Service_Nj560_Knowledge::remember(
            'nj602', (string) ($this->request->post('kind') ?: 'article'), $key,
            mb_substr($title, 0, 300), mb_substr($content, 0, 30000), $meta,
            mb_substr((string) $this->request->post('summary'), 0, 2000)));
    }

    /** GET /sitemap.xml — quick-win SEO: статические страницы + сервисы + статьи. */
    public function action_sitemap()
    {
        $urls = [
            ['loc' => '/', 'pri' => '1.0'],
            ['loc' => '/services', 'pri' => '0.9'],
            ['loc' => '/content', 'pri' => '0.8'],
            ['loc' => '/news', 'pri' => '0.6'],
            ['loc' => '/pages/about', 'pri' => '0.5'],
            ['loc' => '/pages/terms', 'pri' => '0.3'],
            ['loc' => '/pages/privacy', 'pri' => '0.3'],
            ['loc' => '/orgs', 'pri' => '0.7'],
            ['loc' => '/trends', 'pri' => '0.6'],
            // Каталог опубликованных обзоров: читаемый список для людей и СЕО
            // (владелец, 08.10.2026). Сам /trends — рабочая страница сборки,
            // в карте сайта он остаётся входом в сервис.
            ['loc' => '/trends', 'pri' => '0.8'],
            // Каталог опубликованных отчётов «ИИ-радара» — того же рода: модуль
            // личный (audience admin), поэтому адрес живёт здесь, а не в extras
            // манифеста (module_urls пропускает непубличные модули целиком).
            ['loc' => '/airadar/catalog', 'pri' => '0.6'],
            // Каталог артефактов «Кузницы систем» (erp222_forge) — того же рода:
            // модуль владельца (audience admin), но выпущенное им читают люди и
            // поисковики, поэтому адрес объявляется здесь, а не в extras манифеста
            // (module_urls пропускает непубличные модули целиком).
            ['loc' => '/forge/artifacts', 'pri' => '0.6'],
            // Каталог опубликованных диалогов двух ИИ (nj662_dialog) — того же
            // рода: рабочий стол диалогов под замком (audience auth), а сами
            // разговоры читают люди и поисковики, поэтому адрес объявлен здесь.
            ['loc' => '/dialog/list', 'pri' => '0.6'],
            ['loc' => '/design', 'pri' => '0.6'],
            ['loc' => '/dossier', 'pri' => '0.6'],
            ['loc' => '/company', 'pri' => '0.6'],
            ['loc' => '/docs', 'pri' => '0.6'],
            ['loc' => '/jobs', 'pri' => '0.6'],
            ['loc' => '/3d', 'pri' => '0.5'],
            ['loc' => '/vat', 'pri' => '0.5'],
            ['loc' => '/qr', 'pri' => '0.6'],
            ['loc' => '/s', 'pri' => '0.6'],
        ];

        try
        {
            foreach (Service_Nj602_Content::latest_public(200) as $a)
            {
                $urls[] = ['loc' => '/content/'.$a['slug'], 'pri' => '0.7'];
            }
        }
        catch (Throwable $e)
        {
            // статьи могли ещё не применить таблицу — карта без них
        }

        // Отчёты ИИ-радара: опубликованные — самостоятельные страницы для людей
        // и поисковиков. Снятый с публикации отчёт исчезает и отсюда.
        if (class_exists('Service_Nj661_Airadar'))
        {
            try
            {
                foreach (Service_Nj661_Airadar::public_ids(200) as $rid)
                {
                    $urls[] = ['loc' => '/airadar/report/'.$rid, 'pri' => '0.6'];
                }
            }
            catch (Throwable $e)
            {
                // таблицы или колонки ещё нет — карта без отчётов
            }
        }

        // Артефакты «Кузницы систем» (erp222_forge): опубликованные и достаточно
        // ценные — самостоятельные страницы. Снятый с публикации исчезает и отсюда.
        if (class_exists('Service_Erp222_Forge'))
        {
            try
            {
                foreach (Service_Erp222_Forge::public_ids(200) as $aid)
                {
                    $urls[] = ['loc' => '/forge/artifact/'.$aid, 'pri' => '0.5'];
                }
            }
            catch (Throwable $e)
            {
                // таблицы ещё нет — карта без артефактов
            }
        }

        // Диалоги двух ИИ (nj662_dialog): опубликованные разговоры —
        // самостоятельные страницы для людей и поисковиков. Снятый с публикации
        // диалог исчезает и отсюда.
        if (class_exists('Service_Nj662_Dialog'))
        {
            try
            {
                foreach (Service_Nj662_Dialog::public_ids(200) as $did)
                {
                    $urls[] = ['loc' => '/dialog/'.$did, 'pri' => '0.5'];
                }
            }
            catch (Throwable $e)
            {
                // таблицы ещё нет — карта без диалогов
            }
        }

        // Каталог: /services + хабы групп (SSR, из реестра nj110_catalog).
        // Гость видит только public; сервисные href-ы отдельных landing-ов
        // уже индексируются, а группы каталога важны как SEO-объекты.
        if (class_exists('Service_Nj110_Catalog'))
        {
            try
            {
                foreach (Service_Nj110_Catalog::groups_summary() as $g)
                {
                    $urls[] = ['loc' => '/services/'.$g['slug'], 'pri' => '0.7'];
                }
            }
            catch (Throwable $e)
            {
                // каталог недоступен — карта без хабов групп
            }
        }

        // Лендинги групп /s/<slug> (nj611_landing)
        if (class_exists('Service_Nj611_Landing'))
        {
            try
            {
                foreach (Service_Nj611_Landing::groups() as $g)
                {
                    $urls[] = ['loc' => $g['href'], 'pri' => '0.7'];
                }
            }
            catch (Throwable $e)
            {
                // лендинги недоступны — карта без них
            }
        }

        // Страницы веществ «Разбора состава» (nj646_label) в карту НЕ отдаём:
        // владелец, 03.10.2026 — «Из карты сайта ingredientы убери!». Блок
        // удалён вместе с методом Store::ingredients_public_slugs(). Свои
        // страницы модуль в карту отдаёт манифестом (один адрес — /label).

        // Все публичные сервисы из манифестов (+ ключ `sitemap` — доп. адреса модуля).
        if (class_exists('Service_Nj110_Catalog'))
        {
            try
            {
                $urls = array_merge($urls, Service_Nj602_Sitemap::module_urls(Service_Nj110_Catalog::visible_modules()));
            }
            catch (Throwable $e)
            {
                // каталог недоступен — карта без сервисов
            }
        }

        $urls = Service_Nj602_Sitemap::merge($urls);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $u)
        {
            $xml .= '  <url><loc>https://n2j.ru'.HTML::chars((string) $u['loc']).'</loc>'
                .'<priority>'.$u['pri'].'</priority></url>'."\n";
        }

        $xml .= '</urlset>';

        $this->response->status(200);
        $this->response->headers('Content-Type', 'application/xml; charset=utf-8');
        $this->response->body($xml);
    }

    /** Токен воркера (WORKER_API_TOKEN сайта, заголовок X-Worker-Token). */
    protected function _token_ok()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return FALSE;
        }

        $given = (string) $this->request->headers('x-worker-token');

        return $given !== '' AND hash_equals($token, $given);
    }

    protected function _json($status, array $body)
    {
        $this->response->status($status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode($body, JSON_UNESCAPED_UNICODE));
    }
}
