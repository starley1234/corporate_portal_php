<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страницы «Сканера безопасности ИИ-моделей» (модуль NJ-672).
 *
 *   GET  /aiscan               форма: цель, наборы атак, стресс, свой датасет
 *   POST /aiscan/run           поставить аудит в очередь → 303 на результат
 *   GET  /aiscan/result/<id>   интерактивный отчёт
 *   GET  /aiscan/report/<id>   печатный отчёт для руководителя (PDF из браузера)
 *
 * Доступ — только администратор, и это часть модели угроз самого сканера:
 * он шлёт атакующие промпты и конкурентную нагрузку на указанный эндпоинт.
 * Гость видит плашку входа; не-админ — 403. Для внешних целей форма требует
 * подтверждение «у меня есть право тестировать этот эндпоинт».
 *
 * Контроллер ничего не считает: валидация формы и разбор датасета —
 * `Service_Nj672_Scan`, аудит — воркер канала nj672.scan, показ —
 * `Service_Nj672_Scan::shape()`.
 */
class Controller_Nj672_Scan extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        $this->set_title('Сканер безопасности ИИ-моделей: OWASP LLM аудит и стресс-тест');
        $this->set_description(
            'Автономный аудит OpenAI-совместимого эндпоинта: инъекции в промпт, джейлбрейк, '
            .'утечки системного промпта и данных, захват вывода по OWASP Top 10 for LLM, '
            .'стресс-профиль TTFT/ITL/TPS и отчёт для руководителя с матрицей рисков.');
        $this->set_keywords(
            'llm аудит безопасность owasp prompt injection сканер уязвимостей ttft kv-cache');

        $this->set_module_nav([
            ['title' => 'Сканер', 'href' => '/aiscan', 'icon' => 'fas fa-shield-halved'],
            ['title' => 'Простая проверка', 'href' => '/aiscan/simple', 'icon' => 'fas fa-bolt'],
            ['title' => 'Справочник', 'href' => '/aiscan/guide', 'icon' => 'fas fa-book-open'],
        ]);
    }

    /** Форма запуска и история аудитов. */
    public function action_index()
    {
        if ( ! Gate::logged_in())
        {
            $this->template->content = Gate::wall('aiscan', [
                'icon'   => 'fas fa-shield-halved',
                'title'  => 'Сканер безопасности ИИ-моделей — инструмент администратора',
                'text'   => 'Сканер отправляет атакующие промпты и нагрузочный трафик на указанный '
                           .'эндпоинт модели. Такой инструмент нельзя давать публично: он стал бы '
                           .'прокси для чужих атак. Вход — по аккаунту администратора.',
                'points' => [
                    'Что проверяется: инъекции, джейлбрейк, утечки, захват вывода — OWASP Top 10 for LLM',
                    'Что меряется: TTFT, ITL, токены/сек, порог деградации контекста (KV-Cache)',
                    'Что на выходе: баллы, матрица рисков, рекомендации архитектора, печатный отчёт',
                ],
            ]);

            return;
        }

        $this->_admin_only();

        $this->template->content = View::factory('672/index', [
            'profiles' => Service_Nj672_Scan::profiles(),
            'suites'   => Service_Nj672_Scan::suites(),
            'stress'   => Service_Nj672_Scan::stress_profiles(),
            'limits'   => (array) Service_Nj672_Scan::cfg('limits', []),
            'history'  => $this->_history(),
            'flash'    => mb_substr(trim((string) $this->request->query('flash')), 0, 400),
            'csrf'     => Security::token(),
        ]);
    }

    /**
     * Простой режим /aiscan/simple: одна цель, разумные умолчания, одна кнопка.
     *
     * Полная форма /aiscan честно показывает все ручки (наборы, стресс, судья,
     * свой датасет) — и этим перегружена для задачи «просто проверь вот эту
     * модель». Здесь умолчания фиксированы: все наборы атак, быстрый
     * стресс-профиль, судья включён; POST уходит в тот же /aiscan/run, отчёт —
     * тот же. Подтверждение права на тест работает одинаково в обоих режимах.
     */
    public function action_simple()
    {
        $this->_admin_or_index();

        $this->template->content = View::factory('672/simple', [
            'profiles' => Service_Nj672_Scan::profiles(),
            'suites'   => Service_Nj672_Scan::suites(),
            'history'  => array_slice($this->_history(), 0, 5),
            'flash'    => mb_substr(trim((string) $this->request->query('flash')), 0, 400),
            'csrf'     => Security::token(),
        ]);
    }

    /**
     * Справочник /aiscan/guide: что и как проверяется, как должно быть и как
     * не должно, методы устранения — теми же словами, что и отчёт.
     * Содержимое собирается из Service_Nj672_Scan::guide_sections() и текстов
     * рекомендаций, чтобы справочник не расходился с батареей воркера.
     */
    public function action_guide()
    {
        $this->_admin_or_index();

        $this->template->content = View::factory('672/guide', [
            'sections' => Service_Nj672_Scan::guide_sections(),
            'suites'   => Service_Nj672_Scan::suites(),
        ]);
    }

    /** Поставить аудит в очередь. */
    public function action_run()
    {
        $this->_guard();
        $this->_admin_only();

        $limits = (array) Service_Nj672_Scan::cfg('limits', []);
        $mode   = (string) $this->request->post('mode');          // profile | custom
        $stress = (string) $this->request->post('stress');
        $quick  = (bool) $this->request->post('quick');
        $judge  = (bool) $this->request->post('judge');
        $suites = Service_Nj672_Scan::known_suites(
            array_map('strval', (array) $this->request->post('suites')));
        $custom = Service_Nj672_Scan::split_dataset(
            (string) $this->request->post('dataset'),
            (int) ($limits['custom_max'] ?? 50), (int) ($limits['custom_chars'] ?? 2000));
        $custom_system = mb_substr(
            trim((string) $this->request->post('custom_system')),
            0, (int) ($limits['system_chars'] ?? 8000));

        if ( ! isset(Service_Nj672_Scan::stress_profiles()[$stress]))
        {
            $stress = 'off';
        }

        if ( ! $suites AND ! $custom AND $stress === 'off')
        {
            $this->_back('Нечего запускать: отметьте хотя бы один набор проверок, свой датасет или стресс-профиль.');
        }

        // --- цель ------------------------------------------------------------
        $target = NULL;
        $label    = '';
        $attested = FALSE;

        if ($mode === 'custom')
        {
            $url   = trim((string) $this->request->post('base_url'));
            $model = mb_substr(trim((string) $this->request->post('model')), 0, 160);
            $key   = mb_substr(trim((string) $this->request->post('api_key')), 0, 300);
            [$ok, $error] = Service_Nj672_Scan::validate_custom_url($url);

            if ( ! $ok)
            {
                $this->_back($error);
            }

            if ( ! $this->request->post('attest'))
            {
                $this->_back('Подтвердите, что у вас есть право тестировать этот эндпоинт: сканер будет атаковать его и нагружать. Подтверждение фиксируется в паспорте отчёта.');
            }

            $attested = TRUE;
            $target = ['kind' => 'custom', 'base_url' => $url, 'model' => $model, 'api_key' => $key];
            $label  = $model !== '' ? $model : 'свой эндпоинт';
        }
        else
        {
            $profile_key = (string) $this->request->post('profile');
            $profile     = Service_Nj672_Scan::profile($profile_key);

            if ( ! $profile)
            {
                $this->_back('Выберите цель из списка пресетов.');
            }

            // Песочница безвредна (фейковая модель внутри воркера — право на
            // неё не нужно). Любая другая цель — настоящий эндпоинт: сканер
            // будет его атаковать и нагружать, поэтому подтверждение права
            // требуется всегда, а не только для полного стресса. Кнопка
            // запуска в форме заблокирована тем же правилом; это — дублирующая
            // серверная проверка, подтверждение едет в паспорт отчёта.
            $sandbox = strpos($profile_key, 'sandbox_') === 0;

            if ( ! $sandbox AND ! $this->request->post('attest'))
            {
                $this->_back('Подтвердите, что у вас есть право тестировать этот эндпоинт: сканер будет атаковать его и нагружать. Подтверждение фиксируется в паспорте отчёта.');
            }

            $attested = ! $sandbox;
            $target = ['kind' => 'profile', 'profile' => $profile_key];
            $label  = (string) ($profile['title'] ?? $profile_key);
        }

        if ( ! Vision::table_exists('nj_672_runs'))
        {
            $this->_back('Хранилище аудитов ещё не создано: выполните миграции модуля.');
        }

        $ip       = class_exists('Service_Nj100_Ratelimit') ? Service_Nj100_Ratelimit::client_ip() : '';
        $per_hour = (int) ($limits['per_hour'] ?? 4);

        if (Vision::runs_last_hour('nj_672_runs', $ip) >= $per_hour)
        {
            $this->_back('Лимит аудитов: не больше '.$per_hour.' в час с одного адреса. Аудит — дорогая операция и для очереди, и для цели.');
        }

        $run_id = Vision::run_create('nj_672_runs', [
            'user_id'   => class_exists('Visitor') ? Visitor::user_id() : NULL,
            'visitor'   => class_exists('Visitor') ? (string) Visitor::uid() : '',
            'client_ip' => $ip,
            'title'     => 'Аудит · '.$label.($quick ? ' (быстрый)' : ''),
        ]);

        if ( ! $run_id)
        {
            $this->_back('Не удалось создать прогон — попробуйте ещё раз.');
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj672.scan', [
                'run_id'         => (int) $run_id,
                'target'         => $target,
                'suites'         => $suites,
                'stress'         => $stress,
                'quick'          => $quick,
                'judge'          => $judge,
                'custom_prompts' => $custom,
                'custom_system'  => $custom_system,
                // Зачем: воркер кладёт флаг в profile.attested, страница
                // показывает его в паспорте отчёта — подтверждение становится
                // частью аудируемой истории, а не формальностью формы.
                'attested'       => $attested,
            ], [
                'module'       => 'nj672_aiscan',
                'timeout_sec'  => (int) ($limits['timeout_sec'] ?? 1800),
                // Ретрай аудита — это повторная атака на цель и двойной счёт
                // за её токены. Честная ошибка лучше, человек перезапустит сам.
                'max_attempts' => 1,
            ]);

            Vision::run_set_job('nj_672_runs', $run_id, $job_id);
        }
        catch (Throwable $e)
        {
            Vision::run_apply('nj_672_runs', $run_id, [
                'status' => 'error',
                'error'  => 'Не удалось поставить задачу в очередь: '.$e->getMessage(),
            ]);

            Log_Plain::event(Log::WARNING, 'nj672: очередь недоступна для прогона :run: :e',
                [':run' => $run_id, ':e' => $e->getMessage()]);

            $this->_back('Очередь недоступна: '.$e->getMessage());
        }

        // Ключ цели в лог не пишем никогда — только режим и подписи.
        Log_Plain::event(Log::INFO, 'nj672: аудит :run поставлен (задача :job, цель :t, стресс :s)',
            [':run' => $run_id, ':job' => $job_id, ':t' => $label, ':s' => $stress]);

        HTTP::redirect('/aiscan/result/'.(int) $run_id, 303);
    }

    /** Интерактивный отчёт по прогону. */
    public function action_result()
    {
        [$run, $watch] = $this->_run_or_404();
        $shape = Service_Nj672_Scan::shape((array) $run['result']);

        if ($shape['title'] !== '')
        {
            $this->set_title($shape['title'].' — сканер безопасности ИИ');
        }

        $this->template->content = View::factory('672/result', [
            'run'  => $run,
            'data' => $shape,
            'live' => Vision::run_live($run),
            'hint' => (string) $watch['hint'],
        ]);
    }

    /** Печатный отчёт для руководителя (PDF — печатью из браузера). */
    public function action_report()
    {
        [$run, $watch] = $this->_run_or_404();

        if ((string) $run['status'] !== 'done')
        {
            HTTP::redirect('/aiscan/result/'.(int) $run['id'], 303);
        }

        $this->set_title('Отчёт аудита безопасности ИИ-модели #'.(int) $run['id']);

        $this->template->content = View::factory('672/report', [
            'run'  => $run,
            'data' => Service_Nj672_Scan::shape((array) $run['result']),
        ]);
    }

    // ------------------------------------------------------------- помощники

    /**
     * Прогон с дожимом из очереди или 404/403. Единая дверь для result/report.
     *
     * @return array [array $run, array $watch]
     */
    protected function _run_or_404()
    {
        if ( ! Gate::logged_in())
        {
            HTTP::redirect('/aiscan', 303);
        }

        $this->_admin_only();

        $id = (int) $this->request->param('id');

        if ($id <= 0 OR ! Vision::run_get('nj_672_runs', $id))
        {
            throw HTTP_Exception::factory(404, 'Аудит не найден');
        }

        $watch = Vision::run_watch('nj_672_runs', $id, 'nj672.scan');

        if ( ! $watch['run'])
        {
            throw HTTP_Exception::factory(404, 'Аудит не найден');
        }

        return [$watch['run'], $watch];
    }

    protected function _history()
    {
        if ( ! Vision::table_exists('nj_672_runs'))
        {
            return [];
        }

        $limit = max(1, (int) (Service_Nj672_Scan::cfg('limits.history', 20) ?: 20));

        return Service_Nj672_Scan::history($limit);
    }

    /** Сканером пользуется только администратор (модель угроз — в шапке). */
    protected function _admin_only()
    {
        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Сканер безопасности доступен только администратору');
        }
    }

    /** Гость со служебных страниц уходит на /aiscan (там плашка входа), не-админ — 403. */
    protected function _admin_or_index()
    {
        if ( ! Gate::logged_in())
        {
            HTTP::redirect('/aiscan', 303);
        }

        $this->_admin_only();
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
        // Из простого режима ошибка возвращает в простой же: кидать человека
        // в полную форму, от которой он ушёл, — плохой тон.
        $to = $this->request->post('ui') === 'simple' ? '/aiscan/simple' : '/aiscan';
        HTTP::redirect($to.'?flash='.rawurlencode((string) $message), 303);
    }
}
