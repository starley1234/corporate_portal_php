<?php defined('SYSPATH') or die('No direct script access.');

/**
 * UI prx400_specflow. Просмотр — prx400.view, действия — prx400.run
 * (Policy_Prx400_Spec; ключи — application/config/acl.php). CSRF всех
 * POST проверяет Prx_PostGuard глобально; GET ничего не пишет.
 * Доменные ошибки: действия — flash и PRG-редирект, страницы — HTTP-статус.
 */
class Controller_Prx400_Ui extends Controller_Base
{
    private const FLASH = 'prx400_flash';
    private const IMPORT_REPORT = 'prx400_catalog_import';
    private const CATALOG_PER = [20, 50, 100, 200];
    private const BINARY = ['file', 'export', 'catalog_export', 'catalog_search'];

    public function before()
    {
        parent::before();
        if ( ! $this->can(Policy_Prx400_Spec::VIEW_ACL))
        {
            throw HTTP_Exception::factory(403, 'Оцифровка спецификаций доступна администратору');
        }
        $this->response->headers('Cache-Control', 'no-store');
        // Формы модуля шлют только скаляры (файлы — в $_FILES): массив в
        // параметре — подделка запроса; 400 вместо «Array to string» → 500.
        foreach ([(array) $this->request->query(), (array) $this->request->post()] as $params)
        {
            try {
                Prx400_Input::scalars($params);
            } catch (Prx400_Error $e) {
                throw HTTP_Exception::factory(400, 'Неверные параметры запроса');
            }
        }
        $this->set_title('Оцифровка спецификаций');
        if ( ! in_array($this->request->action(), self::BINARY, TRUE))
        {
            $this->set_module_nav([
                ['title' => 'Спецификации', 'href' => '/prx400_specflow', 'icon' => 'fas fa-file-pdf'],
                ['title' => 'Справочник НСИ', 'href' => '/prx400_specflow/catalog', 'icon' => 'fas fa-book'],
                ['title' => 'Память сопоставлений', 'href' => '/prx400_specflow/aliases', 'icon' => 'fas fa-brain'],
                ['title' => 'Шаблоны', 'href' => '/prx400_specflow/templates', 'icon' => 'fas fa-layer-group'],
                ['title' => 'Качество', 'href' => '/prx400_specflow/quality', 'icon' => 'fas fa-chart-line'],
                ['title' => 'API', 'href' => '/prx400_specflow/api-docs', 'icon' => 'fas fa-plug'],
            ]);
        }
    }

    public function after()
    {
        if (in_array($this->request->action(), self::BINARY, TRUE)) return; // бинарный ответ — без layout
        parent::after();
    }

    /** Список спецификаций (фильтры, страницы) + загрузка одного или нескольких PDF (POST). */
    public function action_index()
    {
        $this->allow(Request::GET, Request::POST);
        $service = new Service_Prx400_Specs;

        if ($this->request->method() === Request::POST)
        {
            $spec_id = $this->act('/prx400_specflow', function () use ($service): int {
                Service_Prx400_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
                return $service->submit($this->actor(), self::request_key((string) $this->request->post('request_key')),
                    $_FILES['pdf'] ?? NULL, (array) $this->request->post());
            });
            $dups = count($service->duplicates($this->actor(), $spec_id));
            $this->done('/prx400_specflow/spec/'.$spec_id, 'Спецификация загружена — разбор идёт в очереди воркеров.'
                .($dups > 0 ? ' Внимание: эти PDF уже загружались ('.$dups.') — см. карточку.' : ''));
            return;
        }

        try {
            $list = $service->listing($this->actor(), (array) $this->request->query());
        } catch (Prx400_Error $e) {
            if ($e->getMessage() !== 'invalid_filter') throw Prx400_Messages::http($e);
            // Ссылка с испорченным фильтром (дата руками, старая закладка) —
            // сбросить фильтр с пояснением, а не голая страница 400.
            Session::instance()->set(self::FLASH, ['type' => 'err', 'text' => Prx400_Messages::text('invalid_filter').' Фильтр сброшен.']);
            HTTP::redirect('/prx400_specflow', 303);
        }
        $this->template->content = View::factory('prx400/index', [
            'list' => $list,
            'statuses' => Service_Prx400_Specs::STATUSES,
            'per_options' => Service_Prx400_Specs::PER_PAGE,
            'max_files' => Service_Prx400_Limits::get('max_files'),
            'catalog_provider' => Service_Prx400_Catalog::provider()->name(),
            'templates' => Service_Prx400_Templates::options(),
            'catalog_count' => $this->page(fn () => Service_Prx400_Catalog::provider()->count()),
            'max_pdf' => Service_Prx400_Limits::pdf_label(),
            'can_edit' => $this->can(Policy_Prx400_Spec::EDIT_ACL),
            'request_key' => bin2hex(random_bytes(16)),
            'flash' => $this->flash(),
            'csrf' => Security::token(),
        ]);
    }

    /** Карточка спецификации: таблица, цвета качества, верификация, экспорт. */
    public function action_spec()
    {
        $this->allow(Request::GET);
        $id = (int) $this->request->param('id');
        $data = $this->page(fn () => (new Service_Prx400_Specs)->card($this->actor(), $id));
        $filter = (string) ($this->request->query('filter') ?: 'all');
        if ( ! in_array($filter, Service_Prx400_Rows::FILTERS, TRUE)) $filter = 'all';

        $this->template->content = View::factory('prx400/spec', $data + [
            'filter' => $filter,
            'filters' => Service_Prx400_Rows::FILTERS,
            'csrf' => Security::token(),
            'flash' => $this->flash(),
        ]);
    }

    /** Исходный PDF (сверка side-by-side). */
    public function action_file()
    {
        $this->allow(Request::GET);
        $id = (int) $this->request->param('id');
        $n = max(1, min(99, (int) ($this->request->query('n') ?: 1)));
        $pdf = $this->page(fn () => (new Service_Prx400_Specs)->pdf($this->actor(), $id, $n));
        $this->response->headers('Content-Type', 'application/pdf');
        $this->response->headers('Content-Length', (string) strlen($pdf));
        $this->response->headers('Content-Disposition', 'inline; filename="spec-'.$id.($n > 1 ? '-'.$n : '').'.pdf"');
        $this->response->headers('X-Content-Type-Options', 'nosniff');
        $this->response->body($pdf);
    }

    /** Экспорт: JSON для ERP (по умолчанию), csv, xlsx (позиции + закупка + сводка); bom=as_is|per_parent. */
    public function action_export()
    {
        $this->allow(Request::GET);
        $id = (int) $this->request->param('id');
        [$spec, $rows] = $this->page(fn () => (new Service_Prx400_Specs)->export($this->actor(), $id));
        $bom = (string) ($this->request->query('bom') ?: 'as_is');

        switch ((string) $this->request->query('format'))
        {
            case 'csv':
                $this->attachment('text/csv; charset=utf-8', 'spec-'.$id.'.csv', Service_Prx400_Export::csv($spec, $rows));
                return;
            case 'xlsx':
                $this->attachment('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'spec-'.$id.'.xlsx',
                    $this->page(fn () => Service_Prx400_Export::xlsx($spec, $rows, $bom)));
                return;
        }
        $this->attachment('application/json; charset=utf-8', 'spec-'.$id.'.json', json_encode(Service_Prx400_Export::json($spec, $rows),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** Повторный запуск разбора (режим/шаблон из формы). */
    public function action_launch()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/spec/'.$id, fn () => (new Service_Prx400_Specs)->launch($this->actor(), $id, (array) $this->request->post()));
        $this->done('/prx400_specflow/spec/'.$id, 'Разбор поставлен в очередь — подтверждения и ручные коды перенесутся на те же строки.');
    }

    /** Перезалить PDF существующей спецификации и перезапустить разбор. */
    public function action_reupload()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/spec/'.$id, function () use ($id): void {
            Service_Prx400_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
            (new Service_Prx400_Specs)->reupload($this->actor(), $id, $_FILES['pdf'] ?? NULL, (array) $this->request->post());
        });
        $this->done('/prx400_specflow/spec/'.$id, 'Файлы перезалиты — разбор запущен.');
    }

    /** Подтвердить строку / снять отметку (verified=1|0; без поля — переключить). */
    public function action_verify()
    {
        $row_id = $this->post_id();
        $raw = $this->request->post('verified');
        $this->row_action($row_id, fn () => (new Service_Prx400_Rows)->verify($this->actor(), $row_id, $raw === NULL ? NULL : (string) $raw === '1'), NULL);
    }

    /** Код НСИ строки: code — назначить (из поиска/похожих), clear=1 — снять ручной код. */
    public function action_code()
    {
        $row_id = $this->post_id();
        $rows = new Service_Prx400_Rows;
        if ((string) $this->request->post('clear') === '1')
        {
            $this->row_action($row_id, fn () => $rows->clear_code($this->actor(), $row_id), 'Ручной код снят — строка сопоставлена заново.');
            return;
        }
        $this->row_action($row_id, fn () => $rows->assign_code($this->actor(), $row_id, (string) $this->request->post('code')),
            'Код НСИ назначен и запомнен для следующих разборов.');
    }

    /** Правка полей строки — журнал в prx_400_edits, пересчёт статуса и матчинга. */
    public function action_edit()
    {
        $row_id = $this->post_id();
        $this->row_action($row_id, fn () => (new Service_Prx400_Rows)->edit($this->actor(), $row_id, (array) $this->request->post()),
            'Строка исправлена — правка в журнале, статус пересчитан.');
    }

    /** Новая позиция в справочник НСИ из строки (локальный справочник) + привязка. */
    public function action_catalog_item()
    {
        $row_id = $this->post_id();
        $this->row_action($row_id, fn () => (new Service_Prx400_Rows)->create_catalog_item($this->actor(), $row_id, (array) $this->request->post()),
            'Позиция добавлена в справочник НСИ и привязана к строке.');
    }

    /** Добавить строку, пропущенную распознаванием (после строки / внутрь сборки). */
    public function action_row_add()
    {
        $id = $this->post_id();
        $row = $this->act($this->spec_url($id), fn () => (new Service_Prx400_Rows)->add($this->actor(), $id, (array) $this->request->post()));
        $this->done($this->spec_url($id).'#row-'.(int) $row['row_id'], 'Строка добавлена — она отмечена как ручная в журнале и выгрузке.');
    }

    public function action_row_delete()
    {
        $row_id = $this->post_id();
        $back = (int) $this->request->post('back_spec');
        $spec_id = $this->act($this->spec_url($back), fn () => (new Service_Prx400_Rows)->delete($this->actor(), $row_id));
        $this->done($this->spec_url($spec_id), 'Строка удалена — запись осталась в журнале правок.');
    }

    /** Разделить строку по количествам («2; 3» или по строке на часть). */
    public function action_split()
    {
        $row_id = $this->post_id();
        $parts = preg_split('/[;\s]+/u', trim((string) $this->request->post('quantities')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->row_action($row_id, fn () => (new Service_Prx400_Rows)->split($this->actor(), $row_id, $parts)['spec_id'], 'Строка разделена на '.count($parts).' — части в журнале правок.');
    }

    /** Подтвердить пачкой все автосовпадения с уверенностью ≥ порога. */
    public function action_bulk_verify()
    {
        $id = $this->post_id();
        $result = $this->act($this->spec_url($id), fn () => (new Service_Prx400_Rows)->bulk_verify($this->actor(), $id, $this->request->post('min_score')));
        $this->done($this->spec_url($id), 'Подтверждено строк: '.(int) $result['verified'].'.');
    }

    /** Пересопоставить с НСИ строки без решения оператора (после обновления справочника/сбоя НСИ). */
    public function action_rematch()
    {
        $id = $this->post_id();
        $result = $this->act($this->spec_url($id), fn () => (new Service_Prx400_Rows)->rematch($this->actor(), $id));
        $this->done($this->spec_url($id), sprintf('Пересопоставлено строк: %d, изменилось: %d. Решения оператора не тронуты.', $result['checked'], $result['changed']));
    }

    /** Поиск в справочнике НСИ для выбора кода строки (JSON, GET). */
    public function action_catalog_search()
    {
        $this->allow(Request::GET);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        try {
            $body = ['items' => Service_Prx400_Catalog::search($this->actor(), (string) $this->request->query('q'), (int) ($this->request->query('limit') ?: 12))];
        } catch (Prx400_Error $e) {
            $this->response->status($e->getCode());
            $body = ['error' => ['code' => $e->getMessage(), 'message' => Prx400_Messages::text($e->getMessage())]];
        }
        $this->response->body(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Память сопоставлений: «как в документе» → код НСИ (решения операторов). */
    /** Качество разбора в бою: исходы, откуда коды НСИ, правки операторов, кандидаты в НСИ. */
    public function action_quality()
    {
        $this->allow(Request::GET);
        $days = (int) $this->request->query('days');
        if ( ! in_array($days, [7, 30, 90, 365], TRUE)) $days = 30;

        $this->template->content = View::factory('prx400/quality', [
            'days' => $days,
            'report' => Service_Prx400_Quality::report($days),
            'can_edit' => $this->can(Policy_Prx400_Spec::EDIT_ACL),
            'catalog_writable' => $this->page(static fn (): bool => Service_Prx400_Catalog::provider()->writable()),
        ]);
    }

    public function action_aliases()
    {
        $this->allow(Request::GET);
        $query = mb_substr(trim((string) $this->request->query('q')), 0, 200);
        $per = 100;
        $page_no = max(1, min(100000, (int) $this->request->query('page')));
        $page = Model_Prx400_Alias::page($query, $per, ($page_no - 1) * $per);

        $this->template->content = View::factory('prx400/aliases', [
            'aliases' => $page['rows'],
            'total' => $page['total'],
            'query' => $query,
            'page_no' => $page_no,
            'pages' => max(1, (int) ceil($page['total'] / $per)),
            'actor_names' => Service_Prx400_Specs::actor_names(array_column($page['rows'], 'actor_id')),
            'can_edit' => $this->can(Policy_Prx400_Spec::EDIT_ACL),
            'flash' => $this->flash(),
            'csrf' => Security::token(),
        ]);
    }

    public function action_alias_revoke()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/aliases', fn () => Service_Prx400_Catalog::revoke_alias($this->actor(), $id));
        $this->done('/prx400_specflow/aliases', 'Сопоставление забыто — следующие разборы сопоставят строку заново.');
    }

    /** Завершить проверку: review → done. */
    public function action_finish()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/spec/'.$id, fn () => (new Service_Prx400_Specs)
            ->finish($this->actor(), $id, (string) $this->request->post('accept_issues') === '1'));
        $this->done('/prx400_specflow/spec/'.$id, 'Проверка завершена — спецификация готова к выгрузке.');
    }

    /** Удалить спецификацию (позиции и правки каскадом, файл — из хранилища). */
    public function action_delete()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow', fn () => (new Service_Prx400_Specs)->delete($this->actor(), $id));
        $this->done('/prx400_specflow', 'Спецификация удалена: позиции и файл убраны.');
    }

    /** Справочник НСИ: список (поиск, страницы) + добавление (POST). */
    public function action_catalog()
    {
        $this->allow(Request::GET, Request::POST);
        if ($this->request->method() === Request::POST)
        {
            $this->act('/prx400_specflow/catalog', fn () => (new Service_Prx400_Specs)->catalog_add($this->actor(), (array) $this->request->post()));
            $this->done('/prx400_specflow/catalog', 'Позиция сохранена в справочнике НСИ — матчинг применится на следующем разборе.');
            return;
        }

        $query = mb_substr(trim((string) $this->request->query('q')), 0, 200);
        $per = (int) $this->request->query('per');
        if ( ! in_array($per, self::CATALOG_PER, TRUE)) $per = 100;
        $page_no = max(1, min(100000, (int) $this->request->query('page')));
        $provider = Service_Prx400_Catalog::provider();
        if ($provider->writable())
        {
            $page = Model_Prx400_Catalog::page(Service_Prx400_Catalog::words($query), $per, ($page_no - 1) * $per);
        }
        else
        {
            // Внешний НСИ не листается целиком — только поиск (тот же, что у оператора в карточке).
            $found = $query !== '' ? $this->page(fn () => $provider->search($query, $per)) : [];
            $page = ['rows' => $found, 'total' => count($found)];
            $page_no = 1;
        }

        $this->template->content = View::factory('prx400/catalog', [
            'catalog' => $page['rows'],
            'total' => $page['total'],
            'query' => $query,
            'page_no' => $page_no,
            'per' => $per,
            'per_options' => self::CATALOG_PER,
            'pages' => $provider->writable() ? max(1, (int) ceil($page['total'] / $per)) : 1,
            'catalog_provider' => $provider->name(),
            'catalog_writable' => $provider->writable(),
            'catalog_count' => $this->page(fn () => $provider->count()),
            'import_report' => Session::instance()->get_once(self::IMPORT_REPORT),
            'max_import' => Service_Prx400_Limits::pdf_label('catalog_import_max_bytes'),
            'max_import_rows' => Service_Prx400_Limits::get('catalog_import_max_rows'),
            'can_edit' => $this->can(Policy_Prx400_Spec::EDIT_ACL),
            'flash' => $this->flash(),
            'csrf' => Security::token(),
        ]);
    }

    /**
     * Импорт справочника из CSV/XLSX: «Проверить» (apply≠1) ничего не пишет,
     * «Импортировать» применяет файл целиком или не применяет при ошибках.
     * Отчёт (счётчики, строки с ошибками) — на странице справочника после PRG.
     */
    public function action_catalog_import()
    {
        $this->allow(Request::POST);
        $apply = (string) $this->request->post('apply') === '1';
        $report = $this->act('/prx400_specflow/catalog', function () use ($apply): array {
            Service_Prx400_Limits::assert_catalog_request_size((int) $this->request->headers('Content-Length'));
            return Service_Prx400_CatalogImport::from_upload($this->actor(), $_FILES['file'] ?? NULL, $apply);
        });
        Session::instance()->set(self::IMPORT_REPORT, $report);
        $this->done('/prx400_specflow/catalog', NULL);
    }

    /** Выгрузка справочника в CSV (тот же формат принимает импорт). */
    public function action_catalog_export()
    {
        $this->allow(Request::GET);
        $this->response->headers('Content-Type', 'text/csv; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="nsi-catalog-'.date('Ymd').'.csv"');
        $this->response->body(Service_Prx400_CatalogImport::export_csv());
    }

    public function action_catalog_delete()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/catalog', fn () => (new Service_Prx400_Specs)->catalog_delete($this->actor(), $id));
        $this->done('/prx400_specflow/catalog', 'Позиция убрана из справочника.');
    }

    /** Шаблоны: реестр + загрузка пользовательского JSON (POST). */
    public function action_templates()
    {
        $this->allow(Request::GET, Request::POST);
        if ($this->request->method() === Request::POST)
        {
            $title = $this->act('/prx400_specflow/templates', fn () => Service_Prx400_Templates::upload($this->actor(), $_FILES['tpl'] ?? NULL));
            $this->done('/prx400_specflow/templates', 'Шаблон «'.$title.'» добавлен — применяется с новой задачи разбора.');
            return;
        }

        $this->template->content = View::factory('prx400/templates', [
            'registry' => Service_Prx400_Templates::registry(),
            'can_edit' => $this->can(Policy_Prx400_Spec::EDIT_ACL),
            'flash' => $this->flash(),
            'csrf' => Security::token(),
        ]);
    }

    public function action_template_enable()
    {
        $this->allow(Request::POST);
        $enable = (string) $this->request->post('enable') === '1';
        $this->act('/prx400_specflow/templates', fn () => Service_Prx400_Templates::set_enabled(
            $this->actor(), (string) $this->request->post('code'), $enable));
        $this->done('/prx400_specflow/templates', 'Шаблон '.($enable ? 'включён' : 'отключён').' — применяется с новой задачи разбора.');
    }

    public function action_template_delete()
    {
        $this->allow(Request::POST);
        $this->act('/prx400_specflow/templates', fn () => Service_Prx400_Templates::delete($this->actor(), (string) $this->request->post('code')));
        $this->done('/prx400_specflow/templates', 'Пользовательский шаблон удалён.');
    }

    /** «API для интеграторов»: контракт prx150 (ключ + scope). */
    public function action_api_docs()
    {
        $this->allow(Request::GET);
        $this->template->content = View::factory('prx400/api_docs', [
            'scope' => Service_Prx400_Specs::SCOPE,
            'parse_modes' => Service_Prx400_Limits::parse_modes(),
            'templates' => Service_Prx400_Templates::options(),
            'max_pdf' => Service_Prx400_Limits::pdf_label(),
            'max_files' => Service_Prx400_Limits::get('max_files'),
            'max_total' => Service_Prx400_Limits::pdf_label('max_total_bytes'),
            'catalog_provider' => Service_Prx400_Catalog::provider()->name(),
            'can_keys' => $this->can('prx150.admin'),
        ]);
    }

    // ------------------------------------------------------------------

    /** Разрешённые методы действия; прочие — 405 с Allow. */
    private function allow(string ...$methods): void
    {
        if ( ! in_array($this->request->method(), $methods, TRUE))
        {
            throw (HTTP_Exception::factory(405))->allowed($methods);
        }
    }

    /** ID из маршрута для POST-действия. */
    private function post_id(): int
    {
        $this->allow(Request::POST);

        return (int) $this->request->param('id');
    }

    /** GET-страница: доменная ошибка → HTTP-статус с понятным текстом. */
    private function page(callable $read)
    {
        try {
            return $read();
        } catch (Prx400_Error $e) {
            throw Prx400_Messages::http($e);
        }
    }

    /** POST-действие: доменная ошибка → flash и PRG-редирект на $back. */
    private function act(string $back, callable $operation)
    {
        try {
            return $operation();
        } catch (Prx400_Error $e) {
            $text = Prx400_Messages::text($e->getMessage());
            if (isset($e->details()['issues'])) $text .= ' Осталось: '.(int) $e->details()['issues'].'.';
            Session::instance()->set(self::FLASH, ['type' => 'err', 'text' => $text]);
            HTTP::redirect($back, 303);
        }
    }

    private function done(string $to, ?string $text): void
    {
        if ($text !== NULL) Session::instance()->set(self::FLASH, ['type' => 'ok', 'text' => $text]);
        HTTP::redirect($to, 303);
    }

    private function flash(): array
    {
        return (array) Session::instance()->get_once(self::FLASH, ['text' => '']);
    }

    /** Действие со строкой: PRG на карточку к этой строке (сохраняя фильтр). */
    private function row_action(int $row_id, callable $operation, ?string $text): void
    {
        $back = (int) $this->request->post('back_spec');
        $spec_id = $this->act($this->spec_url($back), $operation);
        $this->done($this->spec_url((int) $spec_id).'#row-'.$row_id, $text);
    }

    /** Адрес карточки с текущим фильтром строк (поле back_filter формы). */
    private function spec_url(int $spec_id): string
    {
        $filter = (string) $this->request->post('back_filter');

        return '/prx400_specflow/spec/'.$spec_id.(in_array($filter, Service_Prx400_Rows::FILTERS, TRUE) && $filter !== 'all' ? '?filter='.$filter : '');
    }

    private function attachment(string $type, string $name, string $body): void
    {
        $this->response->headers('Content-Type', $type);
        $this->response->headers('Content-Disposition', 'attachment; filename="'.$name.'"');
        $this->response->headers('X-Content-Type-Options', 'nosniff');
        $this->response->body($body);
    }

    /** Ключ идемпотентности формы загрузки: из скрытого поля, иначе новый. */
    private static function request_key(string $posted): string
    {
        return preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', $posted) ? 'ui:'.substr($posted, 0, 120) : 'ui:'.bin2hex(random_bytes(16));
    }
}
