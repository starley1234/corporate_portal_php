<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Документы организации» (nj580): /docs — создание и мои пакеты,
 * /docs/<code> — пакет (статусы документов, файлы DOCX/PDF/XLSX/ZIP).
 */
class Controller_Nj580_Orgdocs extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('Документы организации — Word, PDF, Excel по ГОСТ');
        $this->set_description('Пакет административных и кадровых документов под вашу организацию: положения, приказы, инструкции, штатное расписание. Word и PDF с оформлением по ГОСТ Р 7.0.97-2016, таблицы в Excel.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

        // верхнее меню модуля (чипы md+, на малых экранах — в левой панели)
        $this->set_module_nav([
            ['title' => 'Мастер пакета',   'href' => '/docs/wizard', 'icon' => 'fas fa-list-check'],
            ['title' => 'Подробная форма', 'href' => '/docs/custom', 'icon' => 'fas fa-sliders'],
        ]);

        // Страницы не кэшируем: инлайновый JS поллинга, кэш = сломанный интерфейс
        $this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
        $this->response->headers('Pragma', 'no-cache');
    }

    /** /docs: GET — конструктор + мои пакеты; POST — создание пакета. */
    public function action_index()
    {
        // Маршрут 'docs' объявлен без <action> — POST приходит в index (как в nj570)
        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        $this->template->content = View::factory('580/index', [
            'my'       => $this->_user_id ? Service_Nj580_Orgdocs::list_user($this->_user_id) : [],
            'groups'   => Service_Nj580_Orgdocs::doc_groups(),
            'logged'   => $this->_user_id !== NULL,
            'csrf'     => Security::token(),
            'error'    => NULL,
        ]);
    }

    /** POST /docs/<code>/feedback — уточнение участника (пойдёт в следующую сборку). */
    public function action_feedback()
    {
        $code = (string) $this->request->param('code');
        $pkg  = Service_Nj580_Orgdocs::by_code($code);
        $is_owner = ($pkg AND $this->_user_id !== NULL AND (int) $pkg['user_id'] === $this->_user_id);

        if ( ! $is_owner AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Уточнения добавляет владелец пакета');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('docs/'.$code.'?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

            return;
        }

        Service_Nj580_Orgdocs::add_feedback((int) $pkg['id'], $this->_user_id,
            (string) Arr::get($_POST, 'text', ''));

        $this->redirect('docs/'.$code.'?flash='
            .urlencode('Уточнение сохранено — нажмите «Пересобрать пакет», чтобы воркер его учёл'));
    }

    /** POST /docs/<code>/delete — удалить СВОЙ пакет (жёстко: строки БД и файлы). */
    public function action_delete()
    {
        $code = (string) $this->request->param('code');
        $pkg  = Service_Nj580_Orgdocs::by_code($code);
        $is_owner = ($pkg AND $this->_user_id !== NULL AND (int) $pkg['user_id'] === $this->_user_id);

        if ( ! $is_owner AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Пакет удаляет владелец');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('docs?flash='.urlencode('Сессия устарела — пакет не удалён'));

            return;
        }

        $res = Service_Nj580_Orgdocs::admin_delete((int) $pkg['id'], TRUE);
        $msg = isset($res['ok']) ? 'Пакет '.$code.' удалён полностью (данные и файлы)' : (string) ($res['error'] ?? 'Не удалось удалить пакет');

        $this->redirect('docs?flash='.urlencode($msg));
    }

    /**
     * GET /docs/custom — подробная форма пакета (поля, каталог типов).
     * ?ogrn=<номер> — предзаполняем организацию из реестра (название, ОКВЭД → сфера).
     * POST приходит сюда же и уходит в общий _create().
     */
    public function action_custom()
    {
        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }

        $ogrn = preg_replace('/\D+/', '', (string) $this->request->query('ogrn'));
        $org  = NULL;

        if ($ogrn !== '' AND Service_Nj580_Orgdocs::ogrn_valid($ogrn))
        {
            $org = Service_Nj580_Orgdocs::org_by_ogrn($ogrn); // карточка из кэша реестра или NULL
        }

        $this->set_title('Пакет документов подробно — Документы организации');

        $this->template->content = View::factory('580/custom', [
            'org'    => $org,
            'ogrn'   => $ogrn,
            'groups' => Service_Nj580_Orgdocs::doc_groups(),
            'logged' => $this->_user_id !== NULL,
            'csrf'   => Security::token(),
        ]);
    }

    /** GET /docs/wizard — мастер-опросник; POST — собрать сведения и создать пакет. */
    public function action_wizard()
    {
        if ($this->request->method() === Request::POST)
        {
            $this->_wizard_create();

            return;
        }

        $this->set_title('Мастер пакета документов — 6 вопросов');

        $this->template->content = View::factory('580/wizard', [
            'logged' => $this->_user_id !== NULL,
            'csrf'   => Security::token(),
            'groups' => Service_Nj580_Orgdocs::doc_groups(),
            'error'  => isset($_GET['error']) ? (string) $_GET['error'] : '',
        ]);
    }

    /** POST /docs/wizard — ответы мастера → поля формы создания пакета. */
    protected function _wizard_create()
    {
        if ($this->_user_id === NULL)
        {
            throw HTTP_Exception::factory(403, 'Создание пакетов — только для зарегистрированных');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            $this->redirect('docs/wizard?error='.urlencode('Сессия устарела — ответьте ещё раз (это быстро)'));

            return;
        }

        $post = $this->request->post();

        $pick = function ($name) use ($post) {
            $chip  = trim((string) Arr::get($post, $name, ''));
            $other = trim((string) Arr::get($post, $name.'_other', ''));

            return $other !== '' ? $other : $chip;
        };

        $industry  = $pick('industry');
        $size      = $pick('size');
        $structure = trim((string) Arr::get($post, 'structure', ''));
        $schedule  = $pick('schedule');

        $needs_parts = (array) Arr::get($post, 'needs', []);
        $needs = implode('; ', array_filter(array_map(
            function ($v) { return trim((string) $v); }, $needs_parts)));

        $needs_extra = trim((string) Arr::get($post, 'needs_extra', ''));

        if ($needs_extra !== '')
        {
            $needs = trim($needs.'; '.$needs_extra, '; ');
        }

        if ($schedule !== '')
        {
            $needs = trim('режим работы: '.$schedule.($needs !== '' ? '; '.$needs : ''), '; ');
        }

        $set = (string) Arr::get($post, 'set', 'auto');

        if ($set === 'minimal')
        {
            $types = ['ptr', 'oplata', 'pdn', 'ohrana', 'reestr'];
        }
        elseif ($set === 'custom')
        {
            $types = (array) Arr::get($post, 'types', []);
        }
        else
        {
            $types = ['auto'];
        }

        $res = Service_Nj580_Orgdocs::create(
            $this->_user_id,
            trim((string) Arr::get($post, 'org_name', '')),
            [
                'industry'  => $industry,
                'size'      => $size,
                'structure' => $structure,
                'needs'     => $needs,
            ],
            $types,
            ! empty($post['use_search']),
            '',
            trim((string) Arr::get($post, 'ogrn', ''))
        );

        if (isset($res['error']))
        {
            $this->redirect('docs/wizard?error='.urlencode($res['error']));

            return;
        }

        $this->redirect('docs/'.$res['code'].'?flash='
            .urlencode('Пакет поставлен в очередь — мастер передал воркеру все ответы'));
    }

    /** Создание пакета (POST /docs обрабатывается в action_index). */
    protected function _create()
    {
        if ($this->_user_id === NULL)
        {
            throw HTTP_Exception::factory(403, 'Создание пакетов — только для зарегистрированных');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        $types = (array) Arr::get($_POST, 'types', []);

        if ( ! empty($_POST['auto']))
        {
            $types = ['auto'];
        }

        $res = Service_Nj580_Orgdocs::create(
            $this->_user_id,
            trim((string) Arr::get($_POST, 'org_name', '')),
            [
                'industry' => trim((string) Arr::get($_POST, 'industry', '')),
                'size'     => trim((string) Arr::get($_POST, 'size', '')),
                'structure'=> trim((string) Arr::get($_POST, 'structure', '')),
                'needs'    => trim((string) Arr::get($_POST, 'needs', '')),
            ],
            $types,
            ! empty($_POST['use_search']),
            trim((string) Arr::get($_POST, 'raw_text', '')),
            trim((string) Arr::get($_POST, 'ogrn', ''))
        );

        if (isset($res['error']))
        {
            $this->template->content = View::factory('580/index', [
                'my'     => Service_Nj580_Orgdocs::list_user($this->_user_id),
                'groups' => Service_Nj580_Orgdocs::doc_groups(),
                'logged' => TRUE,
                'csrf'   => Security::token(),
                'error'  => $res['error'],
            ]);

            return;
        }

        HTTP::redirect('/docs/'.$res['code'], 303);
    }

    /** GET /docs/<code> — страница пакета. */
    public function action_view()
    {
        $code = (string) $this->request->param('code');
        $pkg  = Service_Nj580_Orgdocs::by_code($code);

        if ($pkg === NULL)
        {
            throw HTTP_Exception::factory(404, 'Пакет не найден');
        }

        $is_owner = ($this->_user_id !== NULL AND (int) $pkg['user_id'] === $this->_user_id);
        $pkg      = Service_Nj580_Orgdocs::reconcile($pkg);


        // Онтология (nj607): организация из пакета документов попадает в общий
        // словарь сервисов. Здесь, а не при создании: пакет открывают тогда, когда
        // организация реально существует; лишних записей не появляется.
        if (class_exists('Service_Nj607_Ontology'))
        {
            try
            {
                Service_Nj607_Ontology::remember((string) $pkg['org_name'], [
                    'kind'   => 'org',
                    'ogrn'   => (string) ($pkg['ogrn'] ?? ''),
                    'module' => 'orgdocs',
                    'code'   => (string) $pkg['code'],
                    'path'   => '/docs/'.rawurlencode((string) $pkg['code']),
                    'label'  => 'Пакет документов',
                    'score'  => 75,
                    'source' => 'nj580',
                ]);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj580: онтология пакета: :e', [':e' => $e->getMessage()]);
            }
        }
        $this->set_title('Пакет документов «'.($pkg['org_name'] ?: $pkg['code']).'» — Документы организации');

        $this->template->content = View::factory('580/view', [
            'pkg'      => $pkg,
            'docs'     => Service_Nj580_Orgdocs::documents((int) $pkg['id']),
            'pkgfiles' => Service_Nj580_Orgdocs::package_files((int) $pkg['id']),
            'is_owner' => $is_owner,
            'catalog'  => Service_Nj580_Orgdocs::doc_catalog(),
            'feedback' => Service_Nj580_Orgdocs::list_feedback((int) $pkg['id']),
            'csrf'     => Security::token(),
        ]);
    }

    /** POST /docs/<code>/regen — пересборка пакета. */
    public function action_regen()
    {
        if ($this->_user_id === NULL)
        {
            throw HTTP_Exception::factory(403, 'Нужна авторизация');
        }

        if ($this->request->method() !== Request::POST OR ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        $code = (string) $this->request->param('code');
        $res  = Service_Nj580_Orgdocs::regen($this->_user_id, $code);
        $msg  = isset($res['error']) ? $res['error'] : 'Пакет поставлен в очередь на пересборку';

        HTTP::redirect('/docs/'.$code.'?flash='.urlencode($msg), 303);
    }

    /** GET /docs/<code>/file/<id> и /docs/file/<id> — скачивание файла пакета. */
    public function action_file()
    {
        $file = Service_Nj580_Orgdocs::file((int) $this->request->param('id'));

        if ($file === NULL)
        {
            throw HTTP_Exception::factory(404, 'Файл не найден');
        }

        $pkg      = Service_Nj580_Orgdocs::by_id((int) $file['package_id']);
        $is_owner = ($this->_user_id !== NULL AND $pkg AND (int) $pkg['user_id'] === $this->_user_id);
        $is_admin = Auth::instance()->logged_in('admin');
        $by_code  = ($pkg !== NULL AND (string) $this->request->param('code') === (string) $pkg['code']);

        if ($pkg === NULL OR ( ! $by_code AND ! $is_owner AND ! $is_admin))
        {
            throw HTTP_Exception::factory(403, 'Нет доступа к файлу');
        }

        $path = Service_Nj580_Orgdocs::file_path($file['path']);

        if ( ! is_file($path))
        {
            throw HTTP_Exception::factory(404, 'Файл отсутствует на диске');
        }

        $kind = (string) $file['kind'];
        $mime = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pdf'  => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip'  => 'application/zip',
        ];

        $this->auto_render = FALSE;

        $this->response->headers('Content-Type', isset($mime[$kind]) ? $mime[$kind] : 'application/octet-stream')
            ->headers('Content-Length', (string) filesize($path))
            ->headers('Content-Disposition', 'attachment; filename="document.'.$kind.'"')
            // крупные файлы (ZIP > 100 КБ) стримим мимо fastcgi-буферов nginx,
            // иначе возможен пустой HTTP 500 «upstream sent too big file»
            ->headers('X-Accel-Buffering', 'no')
            ->headers('Cache-Control', 'private, max-age=3600')
            ->body(file_get_contents($path));
    }

}
