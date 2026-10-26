<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: страницы сервиса «CAD-детали».
 *
 *   /cad            — описание детали и мои детали (карточки с превью);
 *   /cad/<code>     — одна деталь: ракурсы, параметры, файлы, ревизии, правки;
 *   /cad/file/<id>  — отдача файла модели (STEP/STL/GLB/ракурс);
 *   /cad/archive/<code>[/<rev>] — деталь одним ZIP (файлы, код, README).
 *
 * Доступ: деталь открывается по коду-ссылке (как /3d/<code> в nj570) — так
 * ссылкой можно поделиться с коллегой; менять и пересобирать может только
 * владелец или управление.
 */
class Controller_Nj643_Cad extends Controller_Erp100_Base {

    /** @var int|NULL */
    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('CAD-детали — 3D-модель по описанию');
        $this->set_description('Опишите деталь словами: ИИ напишет параметрическую модель, headless CAD-движок проверит геометрию и границы параметров, а вы получите STEP, STL, GLB и ракурсы для расчётов, печати и заказа.');

        $this->set_module_nav([
            ['title' => 'Управление', 'href' => '/cad/admin', 'icon' => 'fas fa-lock', 'acl' => 'nj643.admin'],
        ]);

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
    }

    /** /cad — форма и список деталей. */
    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            $act = (string) $this->request->post('act');

            if ($act === 'create')
            {
                if ( ! Security::check((string) $this->request->post('csrf')))
                {
                    return $this->redirect_flash('/cad', 'Сессия устарела — попробуйте ещё раз');
                }

                $res = Service_Nj643_Cad::create_part($this->_user_id, [
                    'prompt'     => (string) $this->request->post('prompt'),
                    'exports'    => (array) $this->request->post('exports'),
                    'stress'     => (string) $this->request->post('stress') === '1',
                    'deflection' => (string) $this->request->post('deflection'),
                ]);

                if (isset($res['error']))
                {
                    return $this->redirect_flash('/cad', (string) $res['error']);
                }

                return $this->redirect_flash('/cad/'.$res['code'], 'Модель поставлена в работу — страница покажет этапы');
            }

            if ($act === 'stop_run')
            {
                if ( ! Security::check((string) $this->request->post('csrf')))
                {
                    return $this->redirect_flash('/cad', 'Сессия устарела — попробуйте ещё раз');
                }

                $res = Service_Nj643_Cad::cancel_part((string) $this->request->post('code'),
                    $this->_user_id, $this->_is_admin());

                return $this->redirect_flash('/cad', isset($res['error']) ? (string) $res['error'] : 'Сборка остановлена');
            }

            if ($act === 'delete')
            {
                if ( ! Security::check((string) $this->request->post('csrf')))
                {
                    return $this->redirect_flash('/cad', 'Сессия устарела — попробуйте ещё раз');
                }

                $res = Service_Nj643_Cad::delete_part((string) $this->request->post('code'),
                    $this->_user_id, $this->_is_admin());

                return $this->redirect_flash('/cad', isset($res['error']) ? (string) $res['error'] : 'Деталь удалена');
            }

            if ($act === 'copy')
            {
                if ( ! Security::check((string) $this->request->post('csrf')))
                {
                    return $this->redirect_flash('/cad', 'Сессия устарела — попробуйте ещё раз');
                }

                $res = Service_Nj643_Cad::copy_part((string) $this->request->post('code'),
                    $this->_user_id, $this->_is_admin());

                if (isset($res['error']))
                {
                    return $this->redirect_flash('/cad', (string) $res['error']);
                }

                return $this->redirect_flash('/cad/'.$res['code'],
                    'Копия создана — собираю её из того же кода модели');
            }
        }

        $f = [
            'status' => (string) $this->request->query('status'),
            'q'      => trim((string) $this->request->query('q')),
        ];

        $per   = $this->_per();
        $page  = max(1, (int) $this->request->query('page'));
        $total = 0;
        $items = [];
        $live  = [];
        $last  = [];

        if ($this->_user_id)
        {
            $total = Service_Nj643_Cad::count_parts($this->_user_id, $f);
            $items = Service_Nj643_Cad::parts_of($this->_user_id, $f, $per, ($page - 1) * $per);

            foreach ($items as $item)
            {
                $code = (string) $item['code'];
                $st   = Service_Nj643_Cad::live_for($code);

                if ($st AND in_array((string) $st['status'], ['queued', 'running'], TRUE))
                {
                    $live[$code] = $st;

                    continue;
                }

                $done = Service_Nj643_Cad::last_for($code);

                if ($done)
                {
                    $last[$code] = $done;
                }
            }
        }

        $this->template->content = View::factory('643/index', [
            'items'   => $items,
            'live'    => $live,
            'last'    => $last,
            'counts'  => Service_Nj643_Cad::counts_of((int) $this->_user_id),
            'f'       => $f + ['page' => $page, 'per' => $per, 'total' => $total,
                               'pages' => max(1, (int) ceil($total / max(1, $per)))],
            'signed'   => $this->_user_id !== NULL,
            'is_admin' => $this->_is_admin(),
            'csrf'     => Security::token(),
            'flash'   => $this->_flash(),
        ]);
    }

    /** /cad/<code> — деталь. */
    public function action_part()
    {
        $code = (string) $this->request->param('code');
        $p    = Service_Nj643_Cad::by_code($code);

        if ($p === NULL)
        {
            throw HTTP_Exception::factory(404, 'Деталь не найдена — возможно, её удалили');
        }

        $is_admin = $this->_is_admin();
        $can_edit = Service_Nj643_Cad::can_edit($p, $this->_user_id, $is_admin);

        if ($this->request->method() === Request::POST)
        {
            $act = (string) $this->request->post('act');

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                return $this->redirect_flash('/cad/'.$code, 'Сессия устарела — попробуйте ещё раз');
            }

            if ( ! $can_edit)
            {
                return $this->redirect_flash('/cad/'.$code, 'Это не ваша деталь — менять может только владелец');
            }

            if ($act === 'revise')
            {
                $res = Service_Nj643_Cad::revise_part($code, (string) $this->request->post('instruction'),
                    $this->_user_id, $is_admin);

                return $this->redirect_flash('/cad/'.$code,
                    isset($res['error']) ? (string) $res['error'] : 'Правка запущена — модель пересобирается');
            }

            if ($act === 'rebuild')
            {
                $res = Service_Nj643_Cad::rebuild_part($code, (array) $this->request->post('params'),
                    $this->_user_id, $is_admin);

                return $this->redirect_flash('/cad/'.$code,
                    isset($res['error']) ? (string) $res['error'] : 'Пересобираю с новыми размерами');
            }

            if ($act === 'copy')
            {
                // «Сделать по образцу»: новая деталь из того же кода модели.
                // ИИ не участвует — правки копии не задевают рабочую деталь.
                $res = Service_Nj643_Cad::copy_part($code, $this->_user_id, $is_admin);

                if (isset($res['error']))
                {
                    return $this->redirect_flash('/cad/'.$code, (string) $res['error']);
                }

                return $this->redirect_flash('/cad/'.$res['code'],
                    'Копия создана — собираю её из того же кода модели');
            }

            if ($act === 'again')
            {
                $res = Service_Nj643_Cad::run_again($code, $this->_user_id, $is_admin);

                return $this->redirect_flash('/cad/'.$code,
                    isset($res['error']) ? (string) $res['error'] : 'Запустил сборку заново');
            }

            if ($act === 'fix_limits')
            {
                $res = Service_Nj643_Cad::revise_part($code, $this->_limits_instruction($p), $this->_user_id, $is_admin);

                return $this->redirect_flash('/cad/'.$code,
                    isset($res['error']) ? (string) $res['error'] : 'Прошу модель переживать крайние значения параметров');
            }

            if ($act === 'stop_run')
            {
                $res = Service_Nj643_Cad::cancel_part($code, $this->_user_id, $is_admin);

                return $this->redirect_flash('/cad/'.$code, isset($res['error']) ? (string) $res['error'] : 'Сборка остановлена');
            }

            if ($act === 'delete_rev')
            {
                // Удаляем ОДНУ ревизию (владелец, 27.09.2026). Остаёмся на странице
                // детали в «Истории» — там видно, что ревизии больше нет.
                $rev = (int) $this->request->post('rev');
                $res = Service_Nj643_Cad::delete_revision($code, $rev, $this->_user_id, $is_admin);

                return $this->redirect_flash('/cad/'.$code,
                    isset($res['error']) ? (string) $res['error'] : 'Ревизия '.$rev.' удалена');
            }

            if ($act === 'delete')
            {
                $res = Service_Nj643_Cad::delete_part($code, $this->_user_id, $is_admin);

                return $this->redirect_flash('/cad', isset($res['error']) ? (string) $res['error'] : 'Деталь удалена');
            }
        }

        $live = Service_Nj643_Cad::live_for($code);
        $last = NULL;

        if ($live AND ! in_array((string) $live['status'], ['queued', 'running'], TRUE))
        {
            $last = Service_Nj643_Cad::last_for($code);
            $live = NULL;
        }

        // --- Выбор ревизии для просмотра (?rev=N) -------------------------
        // Владелец (27.09): «делаю ревизии, а смотрю только на ревизию 1; в
        // истории тоже нельзя переключиться». По умолчанию показываем
        // последнюю, ?rev=N — выбранную: файлы, ракурсы и 3D берутся ИМЕННО её.
        $rev_list   = Service_Nj643_Cad::revisions_of((int) $p['id'],
            (int) Kohana::$config->load('nj643_cad.revisions_shown', 12));
        $rev_param  = (int) $this->request->query('rev');
        $latest_rev = $rev_list ? (int) $rev_list[0]['rev'] : (int) $p['revision'];
        $sel_rev    = $rev_param > 0 ? $rev_param : $latest_rev;
        $sel_row    = Service_Nj643_Cad::revision_by_rev((int) $p['id'], $sel_rev);

        if ($sel_row === NULL AND $rev_param > 0)
        {
            // Такой ревизии нет — не молчим, а показываем последнюю
            $sel_rev = $latest_rev;
            $sel_row = Service_Nj643_Cad::revision_by_rev((int) $p['id'], $sel_rev);
        }

        // Ракурсы и файлы считаем по выбранной ревизии (у последней — её же id).
        $sel_rev_id = $sel_row ? (int) $sel_row['id'] : NULL;
        $is_latest  = ((int) $sel_rev === (int) $latest_rev);

        // --- Сравнение ревизий (?rev=N&cmp=M) ------------------------------
        // Владелец (27.09.2026): «что я менял три ревизии назад» не отвечал никто.
        // Сравниваем выбранную с указанной: метрики и построчный diff кода — без ИИ.
        $cmp_param = (int) $this->request->query('cmp');
        $cmp_row   = NULL;

        if ($cmp_param > 0 AND $cmp_param !== (int) $sel_rev)
        {
            $cmp_row = Service_Nj643_Cad::revision_by_rev((int) $p['id'], $cmp_param);

            if ($cmp_row === NULL)
            {
                $cmp_row = NULL;
            }
        }

        $cmp_models = $cmp_row ? Service_Nj643_Cad::model_files_of($p, (int) $cmp_row['id']) : [];

        $this->set_title(mb_substr((string) $p['title'], 0, 80).' — CAD-деталь');

        $this->template->content = View::factory('643/part', [
            'p'         => $p,
            // Ракурсы движка на странице детали больше не показываем (владелец,
            // 27.09.2026) — смотреть нужно в 3D; PNG остались превью в списках.
            'models'    => Service_Nj643_Cad::model_files_of($p, $is_latest ? NULL : $sel_rev_id),
            'files'     => $is_latest
                ? Service_Nj643_Cad::files_of((int) $p['id'])
                : Service_Nj643_Cad::files_of((int) $p['id'], $sel_rev_id),
            'params'    => Service_Nj643_Cad::params_of($p),
            'revisions' => $rev_list,
            'sel_rev'   => $sel_rev,
            'sel_row'   => $sel_row,
            'latest_rev'=> $latest_rev,
            'is_latest' => $is_latest,
            'usage'     => ($is_admin OR $can_edit) ? Service_Nj643_Cad::usage_of((int) $p['id']) : [],
            'report'    => is_array($p['report']) ? $p['report'] : json_decode((string) $p['report'], TRUE),
            'live'      => $live,
            'last'      => $last,
            'cmp_rev'   => $cmp_row ? (int) $cmp_row['rev'] : 0,
            'cmp_row'   => $cmp_row,
            'cmp_models'=> $cmp_models,
            'can_edit'  => $can_edit,
            'is_admin'  => $is_admin,
            'csrf'      => Security::token(),
            'flash'     => $this->_flash(),
        ]);
    }

    /** /cad/file/<id> — файл модели. */
    public function action_file()
    {
        $file = Service_Nj643_Cad::file((int) $this->request->param('id'));

        if ($file === NULL)
        {
            throw HTTP_Exception::factory(404, 'Файл не найден');
        }

        $p = Service_Nj643_Cad::part_by_id((int) $file['part_id']);

        if ($p === NULL)
        {
            throw HTTP_Exception::factory(404, 'Деталь удалена');
        }

        $path = Service_Nj643_Cad::file_path((string) $file['path']);

        if ( ! is_file($path))
        {
            throw HTTP_Exception::factory(404, 'Файл отсутствует на диске — пересоберите деталь');
        }

        $rev     = NULL;
        $rev_row = Service_Nj643_Cad::revision((int) $file['revision_id']);

        if ($rev_row)
        {
            $rev = (int) $rev_row['rev'];
        }

        $kind = (string) $file['kind'];
        $name = Service_Nj643_Cad::download_name($p, $file, $rev);
        $dl   = (string) $this->request->query('download') !== '';

        $this->auto_render = FALSE;

        $this->response->headers('Content-Type', Service_Nj643_Cad::mime_of($kind))
            ->headers('Content-Length', (string) filesize($path))
            ->headers('Content-Disposition', ($dl ? 'attachment' : 'inline').'; filename="'.$name.'"')
            ->headers('Cache-Control', 'private, max-age=3600')
            ->headers('X-Robots-Tag', 'noindex')
            ->body(file_get_contents($path));
    }

    /**
     * /cad/archive/<code>[/<rev>] — деталь одним ZIP.
     *
     * Владелец (28.09.2026): «Когда я качаю деталь, то нифига не понятно» и
     * «надо чтобы детали не удалялись просто так (для этого надо сделать архив
     * и запись или что-то в этом духе)». Отдаём одним файлом: STEP, STL, GLB, код
     * модели и README с размерами — этого достаточно, чтобы отдать деталь
     * подрядчику без сайта. Менять деталь по-прежнему может только владелец
     * (см. /cad/admin), а скачать по код-ссылке — любой, как и файлы.
     */
    public function action_archive()
    {
        $p = Service_Nj643_Cad::by_code((string) $this->request->param('code'));

        if ($p === NULL)
        {
            throw HTTP_Exception::factory(404, 'Деталь не найдена — возможно, её удалили');
        }

        $rev = (int) $this->request->param('rev');

        if ($rev <= 0)
        {
            $rev = (int) $this->request->query('rev');
        }

        $res = Service_Nj643_Cad::archive_of($p, $rev);

        if (isset($res['error']))
        {
            throw HTTP_Exception::factory(404, (string) $res['error']);
        }

        if ((string) $res['bytes'] === '')
        {
            throw HTTP_Exception::factory(500, 'Не удалось собрать архив');
        }

        $this->auto_render = FALSE;

        $this->response->headers('Content-Type', 'application/zip')
            ->headers('Content-Length', (string) strlen($res['bytes']))
            ->headers('Content-Disposition', 'attachment; filename="'.$res['name'].'"')
            ->headers('Cache-Control', 'private, no-store')
            ->headers('X-Robots-Tag', 'noindex')
            ->body($res['bytes']);
    }

    /** Флеш-сообщение через сессию: перезагрузка ничего не повторяет. */
    protected function redirect_flash($url, $message)
    {
        Session::instance()->set('nj643_flash', (string) $message);

        HTTP::redirect($url, 303);
    }

    protected function _flash()
    {
        $flash = (string) Session::instance()->get_once('nj643_flash', '');

        if ($flash === '')
        {
            $flash = (string) Arr::get($_GET, 'flash', '');
        }

        return $flash;
    }

    /** Инструкция для LLM по упавшим крайним значениям (кнопка «Починить границы»). */
    protected function _limits_instruction(array $p)
    {
        $report = is_array($p['report']) ? $p['report'] : json_decode((string) $p['report'], TRUE);
        $cases  = (is_array($report) AND ! empty($report['stress']['cases']))
            ? (array) $report['stress']['cases'] : [];

        $bad = [];

        foreach ($cases as $c)
        {
            if (is_array($c) AND empty($c['ok']))
            {
                $bad[] = 'параметры '.json_encode($c['params'] ?? [], JSON_UNESCAPED_UNICODE)
                    .' → '.mb_substr((string) ($c['error'] ?? 'ошибка'), 0, 200);
            }
        }

        $head = 'Сделай так, чтобы модель строилась при ЛЮБЫХ значениях параметров из схемы, '
            .'включая минимальные и максимальные: связи между размерами не должны ломаться.';

        if ($bad !== [])
        {
            $head .= ' Сейчас падает: '.implode('; ', array_slice($bad, 0, 4)).'.';
        }

        return $head;
    }

    protected function _per()
    {
        $per = (int) $this->request->query('per');
        $max = (int) Kohana::$config->load('nj643_cad.per_page_max', 60);

        if ($per <= 0)
        {
            $per = (int) Kohana::$config->load('nj643_cad.per_page', 12);
        }

        return max(1, min($per, $max));
    }

    protected function _is_admin()
    {
        return ($this->_current_user !== NULL AND $this->can('nj643.admin'));
    }
}
