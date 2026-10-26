<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страницы «Стенда эмбеддингов» (модуль NJ-671).
 *
 *   GET  /embedbench              форма: какой набор гонять, и история прогонов
 *   POST /embedbench/run          поставить прогон в очередь → 303 на результат
 *   GET  /embedbench/result/<id>  отчёт: два пути, метрики, решение
 *
 * Доступ — администратор: прогон занимает общую очередь и время
 * эмбеддинг-сервера, а в отчёте видны адреса внутренних сервисов. Гость видит
 * страницу с плашкой входа (общий `partials/auth_wall`), а не 403: так он
 * понимает, что делать дальше, вместо пустой страницы ошибки.
 *
 * Ничего не считается в контроллере: прогон ставит `Service_Nj120_JobQueue`,
 * метрики считает воркер, а слова подбирает `Service_Nj671_Bench`.
 */
class Controller_Nj671_Bench extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        $this->set_title('Стенд эмбеддингов: какой путь векторов брать');
        $this->set_description(
            'Один материал — два пути получить вектор: текстовый EMBEDDING_API_URL и '
            .'мультимодальный EMBEDDING_MM_API_URL. Сравнение качества поиска, цены входа, '
            .'повторяемости и связи «картинка ↔ слова» с готовым выводом для каждой задачи.');
        $this->set_keywords(
            'эмбеддинги сравнение recall@1 косинус мультимодальный текст embedding api стенд');

        $this->set_module_nav([
            ['title' => 'Стенд', 'href' => '/embedbench', 'icon' => 'fas fa-scale-balanced'],
        ]);
    }

    /** Форма запуска и история прогонов. */
    public function action_index()
    {
        // Гость — плашка входа: он должен понять, что это закрытый инструмент,
        // а не принять 403 за поломку сайта.
        if ( ! Gate::logged_in())
        {
            $this->template->content = Gate::wall('embedbench', [
                'icon'   => 'fas fa-scale-balanced',
                'title'  => 'Стенд эмбеддингов — инструмент владельца',
                'text'   => 'Прогон занимает очередь и время эмбеддинг-сервера, а в отчёте '
                           .'видны адреса внутренних сервисов. Вход — по аккаунту администратора.',
                'points' => [
                    'Что сравнивается: текстовый и мультимодальный пути на одном материале',
                    'Что получается: recall@1, MRR, цена входа, повторяемость, картинки',
                    'Зачем: выбрать инструмент по цифрам, а не по привычке',
                ],
            ]);

            return;
        }

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Стенд эмбеддингов доступен только администратору');
        }

        $this->template->content = View::factory('671/index', [
            'corpora' => Service_Nj671_Bench::corpora(),
            'limits'  => (array) Service_Nj671_Bench::cfg('limits', []),
            'history' => $this->_history(),
            'flash'   => mb_substr(trim((string) $this->request->query('flash')), 0, 400),
            'csrf'    => Security::token(),
            'site'    => $this->_site_env(),
        ]);
    }

    /** Поставить прогон в очередь. */
    public function action_run()
    {
        $this->_guard();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Стенд эмбеддингов доступен только администратору');
        }

        $corpus = mb_substr(trim((string) $this->request->post('corpus')), 0, 40);
        $quick  = (bool) $this->request->post('quick');
        $config = Service_Nj671_Bench::corpus($corpus);

        if ( ! $config)
        {
            $this->_back('Неизвестный набор материала: выберите один из списка.');
        }

        if ( ! Vision::table_exists('nj_671_runs'))
        {
            $this->_back('Хранилище прогонов ещё не создано: выполните миграции модуля.');
        }

        $ip       = class_exists('Service_Nj100_Ratelimit') ? Service_Nj100_Ratelimit::client_ip() : '';
        $per_hour = (int) (Service_Nj671_Bench::cfg('limits.per_hour', 6) ?: 6);

        if (Vision::runs_last_hour('nj_671_runs', $ip) >= $per_hour)
        {
            $this->_back('Лимит прогонов: не больше '.$per_hour.' в час с одного адреса.');
        }

        $run_id = Vision::run_create('nj_671_runs', [
            'user_id'   => class_exists('Visitor') ? Visitor::user_id() : NULL,
            'visitor'   => class_exists('Visitor') ? (string) Visitor::uid() : '',
            'client_ip' => $ip,
            'title'     => (string) $config['title'].($quick ? ' (быстрый)' : ''),
        ]);

        if ( ! $run_id)
        {
            $this->_back('Не удалось создать прогон — попробуйте ещё раз.');
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj671.bench', [
                'run_id' => (int) $run_id,
                'corpus' => $corpus,
                'quick'  => $quick,
            ], [
                'module'       => 'nj671_embedbench',
                // Больше предела задачи в очереди с запасом: стенд считает два
                // пути, и первый прогон может включать загрузку модели в память.
                'timeout_sec'  => (int) (Service_Nj671_Bench::cfg('limits.timeout_sec', 600) ?: 600),
                'max_attempts' => 2,
            ]);

            Vision::run_set_job('nj_671_runs', $run_id, $job_id);
        }
        catch (Throwable $e)
        {
            Vision::run_apply('nj_671_runs', $run_id, [
                'status' => 'error',
                'error'  => 'Не удалось поставить задачу в очередь: '.$e->getMessage(),
            ]);

            Log_Plain::event(Log::WARNING, 'nj671: очередь недоступна для прогона :run: :e',
                [':run' => $run_id, ':e' => $e->getMessage()]);

            $this->_back('Очередь недоступна: '.$e->getMessage());
        }

        Log_Plain::event(Log::INFO, 'nj671: прогон :run поставлен (задача :job, набор :c)',
            [':run' => $run_id, ':job' => $job_id, ':c' => $corpus]);

        HTTP::redirect('/embedbench/result/'.(int) $run_id, 303);
    }

    /** Отчёт по прогону. */
    public function action_result()
    {
        if ( ! Gate::logged_in())
        {
            HTTP::redirect('/embedbench', 303);
        }

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Стенд эмбеддингов доступен только администратору');
        }

        $id      = (int) $this->request->param('id');
        $initial = $id > 0 ? Vision::run_get('nj_671_runs', $id) : NULL;

        if ( ! $initial)
        {
            throw HTTP_Exception::factory(404, 'Прогон стенда не найден');
        }

        $watch = Vision::run_watch('nj_671_runs', $id, 'nj671.bench');
        $run   = $watch['run'];

        if ( ! $run)
        {
            throw HTTP_Exception::factory(404, 'Прогон стенда не найден');
        }

        $shape = Service_Nj671_Bench::shape((array) $run['result']);
        $live  = Vision::run_live($run);

        if ($shape['title'] !== '')
        {
            $this->set_title($shape['title'].' — стенд эмбеддингов');
        }

        $this->template->content = View::factory('671/result', [
            'run'   => $run,
            'data'  => $shape,
            'live'  => $live,
            'hint'  => (string) $watch['hint'],
        ]);
    }

    // ------------------------------------------------------------- помощники

    /** Прогоны: свежие сверху. Стенд админский, поэтому история общая. */
    protected function _history()
    {
        if ( ! Vision::table_exists('nj_671_runs'))
        {
            return [];
        }

        $limit = max(1, (int) (Service_Nj671_Bench::cfg('limits.history', 20) ?: 20));

        return Service_Nj671_Bench::history($limit);
    }

    /**
     * Что видит САЙТ про эмбеддинги. Показываем честно и с оговоркой: меряет
     * воркер, у него своё окружение (`workers/.env`), и адреса могут отличаться.
     */
    protected function _site_env()
    {
        $embedding = (array) Kohana::$config->load('llm.embedding');

        return [
            'api_url'    => (string) ($embedding['endpoint'] ?? ''),
            'model'      => (string) ($embedding['model'] ?? ''),
            'dimensions' => (int) ($embedding['dimensions'] ?? 0),
            'enabled'    => ! empty($embedding['enabled']),
            // Адрес мультимодального сервера сайту НЕ показываем: он живёт в
            // workers/.env, у сайта его может не быть вовсе, а в отчёте и так
            // написано, что реально ответило.
        ];
    }

    /** POST + CSRF: GET на этом сайте ничего не меняет. */
    protected function _guard()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }
    }

    /** Вернуться на форму с сообщением. */
    protected function _back($message)
    {
        HTTP::redirect('/embedbench?flash='.rawurlencode((string) $message), 303);
    }
}
