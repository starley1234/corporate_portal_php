<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Жизненный цикл спецификации prx400 — общий фасад для UI (Prx_Actor) и
 * внешнего API (Prx150_Principal):
 *
 *   submit (1..N PDF) → prx212 + prx_400_specs/spec_files + задача
 *   specflow.parse (одна транзакция с ключом идемпотентности) → воркер
 *   (Service_Prx400_Pipeline) → матчинг с НСИ → проверка оператором
 *   (Service_Prx400_Rows) → экспорт JSON/CSV/XLSX, webhook и уведомления.
 *
 * Права решает Policy_Prx400_Spec; SQL — модели Model_Prx400_*; файлы —
 * только фасад Storage. Ошибки — Prx400_Error с машинным кодом.
 */
final class Service_Prx400_Specs
{
    const CHANNEL = 'specflow.parse';
    const MODULE = 'prx400';
    const SCOPE = 'prx400.extract';

    /**
     * Терминальные коды разбора (воркер → PHP, очередь → статус). Всё, что не
     * в списке, отображается как job_failed: произвольная строка из очереди
     * не становится «кодом ошибки» для интегратора.
     */
    const FAILED_CODES = [
        'invalid_pdf', 'encrypted_pdf', 'page_limit', 'result_too_large', 'no_text',
        'ocr_config', 'ocr_unavailable', 'ocr_auth', 'ocr_rejected', 'ocr_invalid_response',
        'ocr_incomplete', 'ocr_page_failed', 'file_unavailable', 'invalid_result', 'pdf_required',
        'header_not_found', 'template_missing', 'template_not_found', 'template_invalid',
        'no_rows', 'vision_config', 'vision_unavailable', 'bad_payload',
        'job_failed', 'job_timeout', 'job_unavailable', 'job_canceled', 'result_missing',
        'worker_lost', 'worker_crash_loop',
    ];

    /** Статусы, в которых строки ещё не принадлежат оператору. */
    const PIPELINE_STATUSES = ['queued', 'running', 'failed'];

    /** Все статусы записи (фильтр списка). */
    const STATUSES = ['queued', 'running', 'review', 'done', 'failed'];

    // ------------------------------------------------------------------
    // Постановка разбора
    // ------------------------------------------------------------------

    /**
     * Принять PDF (один или несколько — части одной спецификации, по порядку)
     * и поставить разбор. Повтор с тем же ключом и теми же файлами (и режимом)
     * возвращает ту же спецификацию; другие файлы — конфликт.
     *
     * @param mixed $upload элемент $_FILES (одиночный или multiple) либо список элементов
     * @param array{parse_mode?: mixed, template_id?: mixed} $options
     * @return int ID спецификации
     */
    public function submit(Prx_Principal $principal, string $key, $upload, array $options = []): int
    {
        $owner = Policy_Prx400_Spec::require_edit($principal);
        if ( ! preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', $key)) throw new Prx400_Error('invalid_idempotency_key', 400);
        self::require_worker();
        $mode = self::mode($options['parse_mode'] ?? NULL);
        $template = Service_Prx400_Templates::resolve((string) ($options['template_id'] ?? ''));
        $templates = Service_Prx400_Templates::for_payload();
        $files = self::validated_uploads($upload);

        $stored = self::store_all($files);
        try {
            $idem = Prx_Idempotency::begin(self::SCOPE, (string) $owner, $key,
                hash('sha256', implode(',', array_column($stored, 'sha')).'|'.$mode.'|'.$template));
        } catch (Throwable $e) {
            self::discard_all($stored);
            throw $e;
        }

        if ($idem['state'] !== Prx_Idempotency::STATE_NEW)
        {
            self::discard_all($stored);
            if ($idem['state'] === Prx_Idempotency::STATE_REPLAY AND (int) ($idem['result']['spec_id'] ?? 0) > 0)
            {
                return (int) $idem['result']['spec_id'];
            }
            throw new Prx400_Error($idem['state'] === Prx_Idempotency::STATE_EXPIRED ? 'idempotency_key_expired' : 'idempotency_conflict', 409);
        }

        try {
            $spec = Model_Prx400_Spec::insert([
                'owner_id' => $owner, 'original_name' => self::title($stored), 'file_id' => $stored[0]['file_id'],
                'file_sha256' => $stored[0]['sha'], 'parse_mode' => $mode,
            ]);
            $spec_id = (int) $spec['id'];
            foreach ($stored as $i => $f) Model_Prx400_File::add($spec_id, $i + 1, $f['file_id'], $f['sha'], $f['name'], $f['size']);
            $job_id = self::push($principal, $spec_id, $mode, $template, $templates);
            Model_Prx400_Spec::queue($spec_id, $job_id, $mode);
            Prx_Idempotency::finish((int) $idem['id'], ['spec_id' => $spec_id]);
        } catch (Throwable $e) {
            Prx_Idempotency::abort();
            self::discard_all($stored);
            throw self::queue_error($e);
        }

        Log_Plain::event(Log::INFO, 'prx400 spec=:s job_id=:j stage=queued mode=:m tpl=:t files=:f', [
            ':s' => $spec_id, ':j' => $job_id, ':m' => $mode, ':t' => $template !== '' ? $template : 'auto', ':f' => count($stored),
        ]);

        return $spec_id;
    }

    /**
     * Те же PDF (sha256) уже загружались: предупреждение, не запрет (повторная
     * закупка по той же спецификации — штатный случай). Ключ видит только свои.
     */
    public function duplicates(Prx_Principal $principal, int $spec_id): array
    {
        Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($spec_id));
        $shas = array_column(Model_Prx400_File::for_spec($spec_id), 'file_sha256');
        $owner = $principal instanceof Prx_Actor ? NULL : Policy_Prx400_Spec::ownerId($principal);

        return array_map(static fn (array $d): array => [
            'spec_id' => (int) $d['spec_id'], 'file_no' => (int) $d['file_no'], 'original_name' => (string) $d['original_name'],
            'status' => (string) $d['status'], 'created_at' => (string) $d['created_at'],
        ], Model_Prx400_File::duplicates($shas, $spec_id, $owner));
    }

    /**
     * Повторный разбор: failed, «застрявшая» задача или уже разобранная
     * спецификация (review/done — другой режим/шаблон; решения оператора
     * переносятся на те же строки нового разбора, Pipeline::merge_decisions).
     * Текущая задача отменяется, новая ставится в той же транзакции. Режим —
     * явный из запроса, иначе сохранённый на спецификации.
     */
    public function launch(Prx_Principal $principal, int $id, array $options = []): void
    {
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        in_array($spec['status'], self::PIPELINE_STATUSES, TRUE)
            ? Policy_Prx400_Spec::require_edit($principal) : Policy_Prx400_Spec::require_review($principal);
        self::require_worker();
        $mode = self::retry_mode($spec, $options);
        $template = Service_Prx400_Templates::resolve((string) ($options['template_id'] ?? ''));
        $templates = Service_Prx400_Templates::for_payload();
        if ((int) ($spec['file_id'] ?? 0) <= 0) throw new Prx400_Error('file_unavailable', 410);

        try {
            $job_id = Prx_Db::transaction(function () use ($principal, $id, $mode, $template, $templates): int {
                $spec = Model_Prx400_Spec::lock($id);
                if ($spec === NULL) throw new Prx400_Error('not_found', 404);
                if ( ! in_array($spec['status'], self::STATUSES, TRUE)) throw new Prx400_Error('wrong_state', 409);
                self::cancel_job((int) ($spec['job_id'] ?? 0));
                $job_id = self::push($principal, $id, $mode, $template, $templates);
                Model_Prx400_Spec::queue($id, $job_id, $mode);
                return $job_id;
            });
        } catch (Throwable $e) {
            throw self::queue_error($e);
        }
        Log_Plain::event(Log::INFO, 'prx400 spec=:s job_id=:j stage=relaunch mode=:m', [':s' => $id, ':j' => $job_id, ':m' => $mode]);
    }

    /**
     * Системный перезапуск после падения воркера (prx400:purge → Pipeline::maintain).
     *
     * Только если запись всё ещё ждёт ИМЕННО эту задачу, её остановило
     * обслуживание очереди с причиной «воркер замолчал» и лимит автоповторов
     * не исчерпан. Режим и шаблон — из упавшей задачи: оператор их не менял.
     *
     * @return bool TRUE — поставлена новая задача
     */
    public function recover(int $id, int $lost_job_id): bool
    {
        $lost = self::job($lost_job_id);
        if (Service_Prx120_JobQueue::stop_reason($lost) !== Service_Prx120_JobQueue::STOP_WORKER_LOST) return FALSE;
        $payload = is_array($lost['payload'] ?? NULL) ? $lost['payload'] : (array) json_decode((string) ($lost['payload'] ?? ''), TRUE);
        $templates = Service_Prx400_Templates::for_payload();

        try {
            $job_id = Prx_Db::transaction(function () use ($id, $lost_job_id, $payload, $templates): int {
                $spec = Model_Prx400_Spec::lock($id);
                if ($spec === NULL OR (int) ($spec['job_id'] ?? 0) !== $lost_job_id
                    OR ! in_array($spec['status'], ['queued', 'running'], TRUE)
                    OR (int) ($spec['recoveries'] ?? 0) >= Service_Prx400_Limits::get('crash_retries')
                    OR (int) ($spec['file_id'] ?? 0) <= 0) return 0;
                $mode = in_array((string) ($payload['parse_mode'] ?? ''), Service_Prx400_Limits::parse_modes(), TRUE)
                    ? (string) $payload['parse_mode'] : self::retry_mode($spec, []);
                $template = is_string($payload['template_id'] ?? NULL) ? $payload['template_id'] : '';
                $job_id = self::push(NULL, $id, $mode, $template, $templates, (int) $spec['owner_id']);
                Model_Prx400_Spec::requeue_recovered($id, $lost_job_id, $job_id);

                return $job_id;
            });
        } catch (Prx120_Budget_Exception $e) {
            return FALSE; // бюджет очереди исчерпан — запись упадёт с worker_lost
        }
        if ($job_id <= 0) return FALSE;
        Log_Plain::event(Log::WARNING, 'prx400 spec=:s lost_job=:l job_id=:j stage=recover', [':s' => $id, ':l' => $lost_job_id, ':j' => $job_id]);

        return TRUE;
    }

    /**
     * Перезалить PDF существующей спецификации (файл утерян/не тот): все
     * файлы заменяются новым набором, ID и история карточки сохраняются,
     * старые файлы убираются после COMMIT.
     */
    public function reupload(Prx_Principal $principal, int $id, $upload, array $options = []): void
    {
        Policy_Prx400_Spec::require_edit($principal);
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        self::require_worker();
        $mode = self::retry_mode($spec, $options);
        $template = Service_Prx400_Templates::resolve((string) ($options['template_id'] ?? ''));
        $templates = Service_Prx400_Templates::for_payload();
        $files = self::validated_uploads($upload);

        $stored = self::store_all($files);
        try {
            $old_files = Prx_Db::transaction(function () use ($principal, $id, $mode, $template, $templates, $stored): array {
                $spec = Model_Prx400_Spec::lock($id);
                if ($spec === NULL) throw new Prx400_Error('not_found', 404);
                if ( ! in_array($spec['status'], self::PIPELINE_STATUSES, TRUE)) throw new Prx400_Error('wrong_state', 409);
                $old = self::file_ids($spec);
                self::cancel_job((int) ($spec['job_id'] ?? 0));
                Model_Prx400_Spec::set_file($id, $stored[0]['file_id'], self::title($stored), $stored[0]['sha']);
                Model_Prx400_File::delete_for_spec($id);
                foreach ($stored as $i => $f) Model_Prx400_File::add($id, $i + 1, $f['file_id'], $f['sha'], $f['name'], $f['size']);
                $job_id = self::push($principal, $id, $mode, $template, $templates);
                Model_Prx400_Spec::queue($id, $job_id, $mode);
                return $old;
            });
        } catch (Throwable $e) {
            self::discard_all($stored);
            throw self::queue_error($e);
        }
        foreach ($old_files as $file_id) self::discard($file_id);
        Log_Plain::event(Log::INFO, 'prx400 spec=:s stage=reupload mode=:m files=:f', [':s' => $id, ':m' => $mode, ':f' => count($stored)]);
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    /** Размеры страницы списка (UI и API). */
    const PER_PAGE = [20, 50, 100, 200];

    /**
     * Список: рабочее место видит всё, API-ключ — своё. Фильтры: status,
     * q (имя файла, шифр, подразделение, шаблон), from/to (YYYY-MM-DD),
     * page/per. Имена владельцев — через prx102.
     *
     * @return array{rows: array, total: int, page: int, per: int, pages: int, filter: array}
     */
    public function listing(Prx_Principal $principal, array $query = []): array
    {
        $owner = Policy_Prx400_Spec::ownerId($principal);
        $filter = self::list_filter($query);
        $per = in_array((int) ($query['per'] ?? 0), self::PER_PAGE, TRUE) ? (int) $query['per'] : 50;
        $page = max(1, min(100000, (int) ($query['page'] ?? 1)));
        $result = Model_Prx400_Spec::listing($principal instanceof Prx_Actor ? NULL : $owner, $per, ($page - 1) * $per, $filter);
        $rows = $result['rows'];

        $user_ids = [];
        foreach ($rows as $r) if ((int) $r['owner_id'] < 0) $user_ids[] = -(int) $r['owner_id'];
        $names = Service_Prx102_Users::display_names($user_ids);
        $files = Model_Prx400_File::counts(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $in_flight = array_filter($rows, static fn (array $r): bool => in_array($r['status'], ['queued', 'running'], TRUE));
        $jobs = self::jobs(array_map(static fn (array $r): int => (int) ($r['job_id'] ?? 0), $in_flight));
        foreach ($rows as &$r)
        {
            $owner_id = (int) $r['owner_id'];
            $r['owner_name'] = $owner_id < 0
                ? ($names[-$owner_id] ?? 'сотрудник #'.(-$owner_id))
                : ($owner_id > 0 ? 'API-ключ #'.$owner_id : 'API (до prx150)');
            [$r['status'], $r['error']] = self::effective($r, $jobs[(int) ($r['job_id'] ?? 0)] ?? NULL);
            $r['files_count'] = $files[(int) $r['id']] ?? 1;
        }
        unset($r);

        return [
            'rows' => $rows, 'total' => $result['total'], 'page' => $page, 'per' => $per,
            'pages' => max(1, (int) ceil($result['total'] / $per)), 'filter' => $filter,
        ];
    }

    /** Строка списка для API (без служебных полей таблицы). */
    public static function list_item(array $r): array
    {
        return [
            'spec_id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'error' => $r['status'] === 'failed' ? ['code' => self::public_code((string) $r['error'])] : NULL,
            'original_name' => (string) $r['original_name'],
            'spec_number' => (string) ($r['spec_number'] ?? ''),
            'template_id' => (string) ($r['template_id'] ?? ''),
            'parse_mode' => (string) $r['parse_mode'],
            'files' => (int) ($r['files_count'] ?? 1),
            'rows_total' => (int) $r['rows_total'],
            'rows_pending' => (int) $r['rows_issue'],
            'created_at' => date('c', strtotime((string) $r['created_at'])),
            'updated_at' => date('c', strtotime((string) $r['updated_at'])),
            'status_url' => '/api/v1/spec-extractions/'.(int) $r['id'],
        ];
    }

    /** Фильтр списка из запроса: неизвестный статус/дата — ошибка, а не «показать всё». */
    private static function list_filter(array $query): array
    {
        $status = trim((string) ($query['status'] ?? ''));
        if ($status !== '' AND ! in_array($status, self::STATUSES, TRUE)) throw new Prx400_Error('invalid_filter', 400);
        $filter = ['status' => $status, 'q' => mb_substr(trim((string) ($query['q'] ?? '')), 0, 200)];
        foreach (['from', 'to'] as $k)
        {
            $v = trim((string) ($query[$k] ?? ''));
            if ($v !== '' AND ! (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) AND checkdate((int) $m[2], (int) $m[3], (int) $m[1])))
            {
                throw new Prx400_Error('invalid_filter', 400);
            }
            $filter[$k] = $v;
        }

        return $filter;
    }

    /** Карточка: спецификация (действующий статус), позиции, этап воркера, журнал правок. */
    public function card(Prx_Principal $principal, int $id): array
    {
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        $job = self::job((int) ($spec['job_id'] ?? 0));
        [$spec['status'], $spec['error']] = self::effective($spec, $job);

        $job_stage = NULL;
        if (in_array($spec['status'], ['queued', 'running'], TRUE))
        {
            foreach (Service_Prx120_JobQueue::instance()->payload_jobs(self::MODULE, 'spec_id', $id, 1) as $row)
            {
                $job_stage = (array) $row;
            }
        }

        $provider = Service_Prx400_Catalog::provider();
        $rows = Model_Prx400_Row::for_spec($id);
        $edits = Model_Prx400_Edit::for_spec($id, 100);

        return [
            'spec' => $spec,
            'rows' => $rows,
            'files' => Model_Prx400_File::for_spec($id),
            'duplicates' => $this->duplicates($principal, $id),
            'edits' => $edits,
            'catalog_provider' => $provider->name(),
            'catalog_writable' => $provider->writable(),
            'bulk_verify_min' => Service_Prx400_Limits::bulk_verify_min(),
            'templates' => Service_Prx400_Templates::options(),
            'parse_modes' => Service_Prx400_Limits::parse_modes(),
            'catalog_count' => $provider->count(),
            'job_stage' => $job_stage,
            'math' => is_string($spec['math'] ?? NULL) ? json_decode($spec['math'], TRUE) : NULL,
            'file_ok' => (int) ($spec['file_id'] ?? 0) > 0 && Storage::metadata((int) $spec['file_id']) !== NULL,
            'can_edit' => $principal instanceof Prx_Actor && $principal->can(Policy_Prx400_Spec::EDIT_ACL),
            'actor_names' => self::actor_names(array_merge(array_column($edits, 'actor_id'), array_column($rows, 'verified_by'))),
        ];
    }

    /**
     * Статус для внешнего API: queued/running/failed/canceled; review/done —
     * результат по схеме экспорта ERP (review помечен awaiting_operator).
     */
    public function status(Prx_Principal $principal, int $id): array
    {
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        $job = self::job((int) ($spec['job_id'] ?? 0));
        [$status, $error] = self::effective($spec, $job);

        $out = [
            'spec_id' => $id,
            'status' => $status === 'failed' && $error === 'job_canceled' ? 'canceled' : $status,
            'parse_mode' => (string) $spec['parse_mode'],
            'attempt' => (int) ($job['attempts'] ?? 0),
            'status_url' => '/api/v1/spec-extractions/'.$id,
            'files' => array_map(static fn (array $f): array => [
                'file_no' => $f['file_no'], 'original_name' => $f['original_name'], 'sha256' => $f['file_sha256'],
            ], Model_Prx400_File::for_spec($id)),
        ];
        if ($status === 'failed')
        {
            $out['error'] = ['code' => self::public_code((string) $error)];
        }
        elseif (in_array($status, ['review', 'done'], TRUE))
        {
            $out['awaiting_operator'] = $status === 'review';
            $out['counts'] = Service_Prx400_Rows::filter_counts(Model_Prx400_Row::counts($id));
            $out['result'] = Service_Prx400_Export::json($spec, Model_Prx400_Row::for_spec($id));
        }

        return $out;
    }

    /** Данные экспорта (UI): только разобранные спецификации. */
    public function export(Prx_Principal $principal, int $id): array
    {
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        if ( ! in_array($spec['status'], ['review', 'done'], TRUE)) throw new Prx400_Error('wrong_state', 409);

        return [$spec, Model_Prx400_Row::for_spec($id)];
    }

    /** Исходный PDF (файл $file_no спецификации) для сверки оператором. */
    public function pdf(Prx_Principal $principal, int $id, int $file_no = 1): string
    {
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        $files = Model_Prx400_File::for_spec($id);
        // Нет такого номера — 404; номер есть, но файл удалён сроком хранения — 410.
        if ($file_no !== 1 AND ! in_array($file_no, array_column($files, 'file_no'), TRUE)) throw new Prx400_Error('not_found', 404);
        $bytes = self::pdf_bytes($spec, $file_no);
        if ($bytes === NULL) throw new Prx400_Error('file_unavailable', 410);

        return $bytes;
    }

    /**
     * Содержимое PDF спецификации с проверкой sha256 записи. Штатно —
     * read_owned (артефакт модуля без пользователя). Ранние загрузки (до
     * 26.11) привязывали файл к пользователю — их читает bytes(): file_id
     * взят из авторизованной записи (контракт фасада), подмену ловит хеш.
     */
    public static function pdf_bytes(array $spec, int $file_no = 1): ?string
    {
        $files = Model_Prx400_File::for_spec((int) $spec['id']);
        [$file_id, $sha] = $files === [] && $file_no === 1 ? [(int) ($spec['file_id'] ?? 0), (string) $spec['file_sha256']] : [0, ''];
        foreach ($files as $f)
        {
            if ($f['file_no'] === $file_no) [$file_id, $sha] = [$f['file_id'], $f['file_sha256']];
        }
        if ($file_id <= 0) return NULL;

        $max = Service_Prx400_Limits::get('max_pdf_bytes');
        $bytes = Storage::read_owned($file_id, self::MODULE, $max);
        if ($bytes === NULL)
        {
            $bytes = Storage::bytes($file_id);
            if ($bytes !== NULL) Log_Plain::event(Log::WARNING, 'prx400 spec=:s stage=pdf legacy_user_file file_id=:f', [':s' => (int) $spec['id'], ':f' => $file_id]);
        }
        if ($bytes === NULL OR strlen($bytes) > $max OR ! hash_equals($sha, hash('sha256', $bytes)))
        {
            return NULL;
        }

        return $bytes;
    }

    // ------------------------------------------------------------------
    // Работа оператора (HITL)
    // ------------------------------------------------------------------

    /** Подтвердить строку (переключатель) или назначить код НСИ (Service_Prx400_Rows). */
    public function verify_row(Prx_Principal $principal, int $row_id, string $manual_code = ''): int
    {
        $rows = new Service_Prx400_Rows;

        return trim($manual_code) !== '' ? $rows->assign_code($principal, $row_id, $manual_code) : $rows->verify($principal, $row_id);
    }

    /** Правка полей строки (Service_Prx400_Rows::edit). */
    public function row_edit(Prx_Principal $principal, int $row_id, array $post): int
    {
        return (new Service_Prx400_Rows)->edit($principal, $row_id, $post);
    }

    /** Завершение проверки (HITL): review → done; расхождения — только осознанно. */
    public function finish(Prx_Principal $principal, int $spec_id, bool $accept_issues): void
    {
        $actor = Policy_Prx400_Spec::require_review($principal);
        Prx_Db::transaction(function () use ($principal, $spec_id, $accept_issues, $actor): void {
            $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::lock($spec_id));
            if ($spec['status'] !== 'review') throw new Prx400_Error('wrong_state', 409);
            $issues = Model_Prx400_Row::pending_count($spec_id);
            if ($issues > 0 AND ! $accept_issues) throw new Prx400_Error('issues_pending', 409, ['issues' => $issues]);
            Model_Prx400_Spec::set_status($spec_id, 'done');
            Model_Prx400_Edit::add(NULL, $spec_id, 'finish', 'review', $issues > 0 ? 'done (расхождений: '.$issues.')' : 'done', $actor);
            Service_Prx400_Notify::queue($spec_id, (int) $spec['owner_id'], 'done');
        });
        Service_Prx400_Notify::publish($spec_id, 'done', NULL, $actor < 0 ? -$actor : NULL);
    }

    /**
     * Удалить спецификацию: живая задача отменяется, строки и правки — каскадом,
     * файлы — после COMMIT (осиротевший файл добирает prx400:purge).
     */
    public function delete(Prx_Principal $principal, int $id): void
    {
        Policy_Prx400_Spec::require_review($principal);
        $file_ids = Prx_Db::transaction(function () use ($principal, $id): array {
            $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::lock($id));
            $files = self::file_ids($spec);
            self::cancel_job((int) ($spec['job_id'] ?? 0));
            Model_Prx400_Spec::delete($id);
            return $files;
        });
        foreach ($file_ids as $file_id) self::discard($file_id);
        Log_Plain::event(Log::INFO, 'prx400 spec=:s stage=deleted', [':s' => $id]);
    }

    /** Добавить/обновить позицию локального справочника НСИ. */
    public function catalog_add(Prx_Principal $principal, array $post): void
    {
        Policy_Prx400_Spec::require_review($principal);
        Service_Prx400_Catalog::save(Service_Prx400_Catalog::fields($post));
    }

    public function catalog_delete(Prx_Principal $principal, int $id): void
    {
        Policy_Prx400_Spec::require_review($principal);
        Service_Prx400_Catalog::delete($id);
    }

    /** Все артефакты prx212 спецификации (spec_files + совместимый file_id). */
    public static function file_ids(array $spec): array
    {
        $ids = array_column(Model_Prx400_File::for_spec((int) $spec['id']), 'file_id');
        if ((int) ($spec['file_id'] ?? 0) > 0) $ids[] = (int) $spec['file_id'];

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Имена действующих лиц журнала: сотрудники (< 0) через prx102, ключи — «API-ключ #N». */
    public static function actor_names(array $actor_ids): array
    {
        $users = [];
        foreach ($actor_ids as $id) if ((int) $id < 0) $users[] = -(int) $id;
        $names = $users !== [] ? Service_Prx102_Users::display_names(array_values(array_unique($users))) : [];
        $out = [];
        foreach ($actor_ids as $id)
        {
            $id = (int) $id;
            $out[$id] = $id < 0 ? ($names[-$id] ?? 'сотрудник #'.(-$id)) : ($id > 0 ? 'API-ключ #'.$id : 'система');
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Статусы и коды
    // ------------------------------------------------------------------

    /**
     * Действующий статус записи с учётом очереди (только чтение — запись
     * делает prx400:purge через Pipeline::maintain). [status, error_code].
     */
    public static function effective(array $spec, ?array $job): array
    {
        $status = (string) $spec['status'];
        $error = $spec['error'] ?? NULL;
        if ( ! in_array($status, ['queued', 'running'], TRUE)) return [$status, $error];

        $job_id = (int) ($spec['job_id'] ?? 0);
        if ($job_id <= 0 OR $job === NULL) return ['failed', 'job_unavailable'];
        // Остановило обслуживание очереди, а не человек: «отменено» здесь — ложь.
        $stop = Service_Prx120_JobQueue::stop_reason($job);
        if ($stop !== NULL) return ['failed', $stop];
        switch ((string) $job['status'])
        {
            case 'pending': return ['queued', NULL];
            case 'processing': return ['running', NULL];
            case 'cancelled': return ['failed', 'job_canceled'];
            case 'failed': return ['failed', self::error_code($job)];
            default: return ['failed', 'result_missing']; // done без записанного результата
        }
    }

    /** Код отказа задачи: только из белого списка; разбор текстов очереди — у prx120. */
    public static function error_code(?array $job): string
    {
        return Prx120_JobOutcome::failure_code($job, self::FAILED_CODES);
    }

    /** Код для API: легаси-записи хранили русский текст — наружу только код. */
    public static function public_code(string $error): string
    {
        if (in_array($error, self::FAILED_CODES, TRUE)) return $error;
        foreach (self::FAILED_CODES as $code)
        {
            if (str_starts_with($error, $code.':')) return $code;
        }

        return 'job_failed';
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    private static function require_worker(): void
    {
        if (trim((string) getenv('WORKER_API_TOKEN')) === '') throw new Prx400_Error('worker_not_configured', 503);
    }

    /** Режим разбора: пусто → по умолчанию; неизвестный — ошибка (без тихого отката в ocr). */
    private static function mode($raw): string
    {
        $mode = trim((string) $raw);
        if ($mode === '') return Service_Prx400_Limits::default_mode();
        if ( ! in_array($mode, Service_Prx400_Limits::parse_modes(), TRUE)) throw new Prx400_Error('invalid_parse_mode', 400);

        return $mode;
    }

    private static function retry_mode(array $spec, array $options): string
    {
        if (trim((string) ($options['parse_mode'] ?? '')) !== '') return self::mode($options['parse_mode']);
        $stored = (string) ($spec['parse_mode'] ?? '');

        return in_array($stored, Service_Prx400_Limits::parse_modes(), TRUE) ? $stored : Service_Prx400_Limits::default_mode();
    }

    /**
     * Загрузка → список проверенных файлов: одиночный элемент $_FILES,
     * multiple (name[]/tmp_name[]) или список элементов. Число файлов —
     * max_files, сумма — max_total_bytes, каждый — max_pdf_bytes.
     *
     * @return array<int, array{0: array, 1: string}>
     */
    private static function validated_uploads($upload): array
    {
        $list = [];
        if (is_array($upload) AND isset($upload['tmp_name']) AND is_array($upload['tmp_name']))
        {
            foreach (array_keys($upload['tmp_name']) as $k)
            {
                $item = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $f) $item[$f] = $upload[$f][$k] ?? NULL;
                if (($item['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $list[] = $item;
            }
        }
        elseif (is_array($upload) AND array_is_list($upload))
        {
            $list = array_values(array_filter($upload, static fn ($f): bool => is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        }
        elseif (is_array($upload))
        {
            $list = [$upload];
        }
        if ($list === []) throw new Prx400_Error('file_required', 400);
        if (count($list) > Service_Prx400_Limits::get('max_files')) throw new Prx400_Error('too_many_files', 413);

        $out = array_map([self::class, 'validated_upload'], $list);
        $total = array_sum(array_map(static fn (array $f): int => (int) $f[0]['size'], $out));
        if ($total > Service_Prx400_Limits::get('max_total_bytes')) throw new Prx400_Error('file_too_large', 413);

        return $out;
    }

    /** @return array{0: array, 1: string} [файл для Storage::save, отображаемое имя] */
    private static function validated_upload($file): array
    {
        $error = is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE)) throw new Prx400_Error('file_too_large', 413);
        if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], TRUE))
            throw new Prx400_Error('upload_unavailable', 503);
        if ($error !== UPLOAD_ERR_OK OR ! is_string($file['tmp_name'] ?? NULL) OR ! is_uploaded_file($file['tmp_name']))
            throw new Prx400_Error('file_required', 400);

        $path = $file['tmp_name'];
        $size = filesize($path);
        if ($size === FALSE) throw new Prx400_Error('upload_unavailable', 503);
        if ($size > Service_Prx400_Limits::get('max_pdf_bytes')) throw new Prx400_Error('file_too_large', 413);
        if ($size < 5 OR file_get_contents($path, FALSE, NULL, 0, 5) !== '%PDF-'
            OR (new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf')
            throw new Prx400_Error('pdf_required', 415);

        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', basename((string) ($file['name'] ?? ''))) ?? '');
        $name = mb_substr($name !== '' ? $name : 'specification.pdf', 0, 255);

        return [['tmp_name' => $path, 'name' => 'specification.pdf', 'size' => $size, 'type' => 'application/pdf'], $name];
    }

    private static function sha256(string $path): string
    {
        $sha = hash_file('sha256', $path);
        if ($sha === FALSE) throw new Prx400_Error('upload_unavailable', 503);

        return $sha;
    }

    /**
     * Сохранить все файлы; сбой на середине — уже сохранённые убираются.
     *
     * @return array<int, array{file_id: int, sha: string, name: string, size: int}>
     */
    private static function store_all(array $files): array
    {
        $stored = [];
        try {
            foreach ($files as [$file, $name])
            {
                $sha = self::sha256($file['tmp_name']);
                $stored[] = ['file_id' => self::store($file), 'sha' => $sha, 'name' => $name, 'size' => (int) $file['size']];
            }
        } catch (Throwable $e) {
            self::discard_all($stored);
            throw $e;
        }

        return $stored;
    }

    private static function discard_all(array $stored): void
    {
        foreach ($stored as $f) self::discard((int) $f['file_id']);
    }

    /** Отображаемое имя спецификации: первый файл (+N). */
    private static function title(array $stored): string
    {
        $name = (string) $stored[0]['name'];

        return count($stored) > 1 ? mb_substr($name, 0, 240).' (+'.(count($stored) - 1).')' : $name;
    }

    /** Артефакт модуля (user_id NULL): read_owned/delete_owned/owned_ids_before. */
    private static function store(array $file): int
    {
        try {
            return Storage::save($file, self::MODULE, NULL, FALSE, Service_Prx400_Limits::get('max_pdf_bytes'));
        } catch (Storage_Exception $e) {
            Log_Plain::event(Log::ERROR, 'prx400 stage=storage_save error_type=:t', [':t' => get_class($e)]);
            throw new Prx400_Error('storage_unavailable', 503);
        }
    }

    /** Убрать артефакт; неудача — в журнал (prx400:purge доберёт сироту). */
    public static function discard(int $file_id): void
    {
        try {
            Storage::delete_owned($file_id, self::MODULE);
            return;
        } catch (Throwable $e) {
            Log_Plain::event(Log::WARNING, 'prx400 stage=discard file_id=:f error_type=:t', [':f' => $file_id, ':t' => get_class($e)]);
        }
    }

    /**
     * @param Prx_Principal|NULL $principal NULL — системный перезапуск (prx400:purge):
     *        задача остаётся за сотрудником-владельцем, если запись из UI.
     */
    private static function push(?Prx_Principal $principal, int $spec_id, string $mode, string $template, array $templates, int $owner_id = 0): int
    {
        $options = [
            'module' => self::MODULE,
            'priority' => 40,
            'max_attempts' => Service_Prx400_Limits::get('max_attempts'),
            'timeout_sec' => Service_Prx400_Limits::get('task_timeout_sec'),
        ];
        if ($principal instanceof Prx_Actor)
        {
            $options['created_by'] = $principal->id();
            // Правило prx120: без visitor_id задача не попадает в «Мои задачи».
            $options['visitor_id'] = Visitor::uid();
        }
        elseif ($principal === NULL AND $owner_id < 0)
        {
            $options['created_by'] = -$owner_id;
        }
        $job_id = (int) Service_Prx120_JobQueue::push(self::CHANNEL, [
            'spec_id' => $spec_id,
            'templates' => $templates,
            'parse_mode' => $mode,
            'template_id' => $template, // '' = авто-детекция по якорям
            // Части спецификации по порядку: воркер скачивает /file/<file_no>.
            'files' => array_map(static fn (array $f): array => [
                'file_no' => $f['file_no'], 'sha256' => $f['file_sha256'], 'name' => $f['original_name'],
            ], Model_Prx400_File::for_spec($spec_id)),
        ], $options);
        if ($job_id < 1) throw new RuntimeException('prx400: очередь не вернула ID задачи');

        return $job_id;
    }

    /** Отменить живую задачу (force): её воркер получит lease_lost и не запишет результат. */
    private static function cancel_job(int $job_id): void
    {
        if ($job_id <= 0) return;
        $job = self::job($job_id);
        if ($job !== NULL AND in_array((string) $job['status'], ['pending', 'processing'], TRUE))
        {
            Service_Prx120_JobQueue::instance()->cancel($job_id, TRUE);
        }
    }

    /** Бюджет prx120 — доменная ошибка 429, остальное — как есть. */
    private static function queue_error(Throwable $e): Throwable
    {
        return $e instanceof Prx120_Budget_Exception ? new Prx400_Error('budget_exceeded', 429) : $e;
    }

    public static function job(int $job_id): ?array
    {
        return $job_id > 0 ? Service_Prx120_JobQueue::instance()->get($job_id) : NULL;
    }

    /** @return array<int, array> задачи по ID (только для записей в работе) */
    private static function jobs(array $job_ids): array
    {
        $out = [];
        foreach (array_unique(array_filter($job_ids)) as $job_id)
        {
            $job = self::job((int) $job_id);
            if ($job !== NULL) $out[(int) $job_id] = $job;
        }

        return $out;
    }
}
