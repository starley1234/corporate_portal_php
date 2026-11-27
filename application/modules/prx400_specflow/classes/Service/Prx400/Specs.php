<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Жизненный цикл спецификации prx400 — общий фасад для UI (Prx_Actor) и
 * внешнего API (Prx150_Principal):
 *
 *   submit → prx212 + prx_400_specs + задача specflow.parse (одна транзакция
 *   с ключом идемпотентности) → воркер (Service_Prx400_Pipeline) → матчинг
 *   с НСИ → верификация оператором → экспорт JSON/CSV.
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

    // ------------------------------------------------------------------
    // Постановка разбора
    // ------------------------------------------------------------------

    /**
     * Принять PDF и поставить разбор. Повтор с тем же ключом и тем же файлом
     * (и режимом) возвращает ту же спецификацию; другой файл — конфликт.
     *
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
        [$file, $name] = self::validated_upload($upload);
        $sha = self::sha256($file['tmp_name']);

        $file_id = self::store($file);
        try {
            $idem = Prx_Idempotency::begin(self::SCOPE, (string) $owner, $key, hash('sha256', $sha.'|'.$mode.'|'.$template));
        } catch (Throwable $e) {
            self::discard($file_id);
            throw $e;
        }

        if ($idem['state'] !== Prx_Idempotency::STATE_NEW)
        {
            self::discard($file_id);
            if ($idem['state'] === Prx_Idempotency::STATE_REPLAY AND (int) ($idem['result']['spec_id'] ?? 0) > 0)
            {
                return (int) $idem['result']['spec_id'];
            }
            throw new Prx400_Error($idem['state'] === Prx_Idempotency::STATE_EXPIRED ? 'idempotency_key_expired' : 'idempotency_conflict', 409);
        }

        try {
            $spec = Model_Prx400_Spec::insert([
                'owner_id' => $owner, 'original_name' => $name, 'file_id' => $file_id,
                'file_sha256' => $sha, 'parse_mode' => $mode,
            ]);
            $spec_id = (int) $spec['id'];
            $job_id = self::push($principal, $spec_id, $mode, $template, $templates);
            Model_Prx400_Spec::queue($spec_id, $job_id, $mode);
            Prx_Idempotency::finish((int) $idem['id'], ['spec_id' => $spec_id]);
        } catch (Throwable $e) {
            Prx_Idempotency::abort();
            self::discard($file_id);
            throw self::queue_error($e);
        }

        Log_Plain::event(Log::INFO, 'prx400 spec=:s job_id=:j stage=queued mode=:m tpl=:t', [
            ':s' => $spec_id, ':j' => $job_id, ':m' => $mode, ':t' => $template !== '' ? $template : 'auto',
        ]);

        return $spec_id;
    }

    /**
     * Повторный разбор (failed или «застрявшая» задача): текущая задача
     * отменяется, новая ставится в той же транзакции. Режим — явный из
     * запроса, иначе сохранённый на спецификации.
     */
    public function launch(Prx_Principal $principal, int $id, array $options = []): void
    {
        Policy_Prx400_Spec::require_edit($principal);
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        self::require_worker();
        $mode = self::retry_mode($spec, $options);
        $template = Service_Prx400_Templates::resolve((string) ($options['template_id'] ?? ''));
        $templates = Service_Prx400_Templates::for_payload();
        if ((int) ($spec['file_id'] ?? 0) <= 0) throw new Prx400_Error('file_unavailable', 410);

        try {
            $job_id = Prx_Db::transaction(function () use ($principal, $id, $mode, $template, $templates): int {
                $spec = Model_Prx400_Spec::lock($id);
                if ($spec === NULL) throw new Prx400_Error('not_found', 404);
                if ( ! in_array($spec['status'], self::PIPELINE_STATUSES, TRUE)) throw new Prx400_Error('wrong_state', 409);
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
     * Перезалить PDF существующей спецификации (файл утерян/не тот):
     * ID и история карточки сохраняются, старый файл убирается после COMMIT.
     */
    public function reupload(Prx_Principal $principal, int $id, $upload, array $options = []): void
    {
        Policy_Prx400_Spec::require_edit($principal);
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        self::require_worker();
        $mode = self::retry_mode($spec, $options);
        $template = Service_Prx400_Templates::resolve((string) ($options['template_id'] ?? ''));
        $templates = Service_Prx400_Templates::for_payload();
        [$file, $name] = self::validated_upload($upload);
        $sha = self::sha256($file['tmp_name']);

        $file_id = self::store($file);
        try {
            $old_file = Prx_Db::transaction(function () use ($principal, $id, $mode, $template, $templates, $file_id, $name, $sha): int {
                $spec = Model_Prx400_Spec::lock($id);
                if ($spec === NULL) throw new Prx400_Error('not_found', 404);
                if ( ! in_array($spec['status'], self::PIPELINE_STATUSES, TRUE)) throw new Prx400_Error('wrong_state', 409);
                self::cancel_job((int) ($spec['job_id'] ?? 0));
                Model_Prx400_Spec::set_file($id, $file_id, $name, $sha);
                $job_id = self::push($principal, $id, $mode, $template, $templates);
                Model_Prx400_Spec::queue($id, $job_id, $mode);
                return (int) ($spec['file_id'] ?? 0);
            });
        } catch (Throwable $e) {
            self::discard($file_id);
            throw self::queue_error($e);
        }
        if ($old_file > 0) self::discard($old_file);
        Log_Plain::event(Log::INFO, 'prx400 spec=:s stage=reupload mode=:m', [':s' => $id, ':m' => $mode]);
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    /** Список: рабочее место видит всё, API-ключ — своё. Имена владельцев — через prx102. */
    public function listing(Prx_Principal $principal, int $limit = 100): array
    {
        $owner = Policy_Prx400_Spec::ownerId($principal);
        $rows = Model_Prx400_Spec::listing($principal instanceof Prx_Actor ? NULL : $owner, $limit);

        $user_ids = [];
        foreach ($rows as $r) if ((int) $r['owner_id'] < 0) $user_ids[] = -(int) $r['owner_id'];
        $names = Service_Prx102_Users::display_names($user_ids);

        $in_flight = array_filter($rows, static fn (array $r): bool => in_array($r['status'], ['queued', 'running'], TRUE));
        $jobs = self::jobs(array_map(static fn (array $r): int => (int) ($r['job_id'] ?? 0), $in_flight));
        foreach ($rows as &$r)
        {
            $owner_id = (int) $r['owner_id'];
            $r['owner_name'] = $owner_id < 0
                ? ($names[-$owner_id] ?? 'сотрудник #'.(-$owner_id))
                : ($owner_id > 0 ? 'API-ключ #'.$owner_id : 'API (до prx150)');
            [$r['status'], $r['error']] = self::effective($r, $jobs[(int) ($r['job_id'] ?? 0)] ?? NULL);
        }

        return $rows;
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

        return [
            'spec' => $spec,
            'rows' => Model_Prx400_Row::for_spec($id),
            'edits' => Model_Prx400_Edit::for_spec($id, 50),
            'templates' => Service_Prx400_Templates::options(),
            'parse_modes' => Service_Prx400_Limits::parse_modes(),
            'catalog_count' => Model_Prx400_Catalog::count(),
            'job_stage' => $job_stage,
            'math' => is_string($spec['math'] ?? NULL) ? json_decode($spec['math'], TRUE) : NULL,
            'file_ok' => (int) ($spec['file_id'] ?? 0) > 0 && Storage::metadata((int) $spec['file_id']) !== NULL,
            'can_edit' => $principal instanceof Prx_Actor && $principal->can(Policy_Prx400_Spec::EDIT_ACL),
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
        ];
        if ($status === 'failed')
        {
            $out['error'] = ['code' => self::public_code((string) $error)];
        }
        elseif (in_array($status, ['review', 'done'], TRUE))
        {
            $out['awaiting_operator'] = $status === 'review';
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

    /** Исходный PDF для сверки оператором. */
    public function pdf(Prx_Principal $principal, int $id): string
    {
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($id));
        $bytes = self::pdf_bytes($spec);
        if ($bytes === NULL) throw new Prx400_Error('file_unavailable', 410);

        return $bytes;
    }

    /**
     * Содержимое PDF спецификации с проверкой sha256 записи. Штатно —
     * read_owned (артефакт модуля без пользователя). Ранние загрузки (до
     * 26.11) привязывали файл к пользователю — их читает bytes(): file_id
     * взят из авторизованной записи (контракт фасада), подмену ловит хеш.
     */
    public static function pdf_bytes(array $spec): ?string
    {
        $file_id = (int) ($spec['file_id'] ?? 0);
        if ($file_id <= 0) return NULL;

        $max = Service_Prx400_Limits::get('max_pdf_bytes');
        $bytes = Storage::read_owned($file_id, self::MODULE, $max);
        if ($bytes === NULL)
        {
            $bytes = Storage::bytes($file_id);
            if ($bytes !== NULL) Log_Plain::event(Log::WARNING, 'prx400 spec=:s stage=pdf legacy_user_file file_id=:f', [':s' => (int) $spec['id'], ':f' => $file_id]);
        }
        if ($bytes === NULL OR strlen($bytes) > $max OR ! hash_equals((string) $spec['file_sha256'], hash('sha256', $bytes)))
        {
            return NULL;
        }

        return $bytes;
    }

    // ------------------------------------------------------------------
    // Работа оператора (HITL)
    // ------------------------------------------------------------------

    /** Подтвердить строку (переключатель) или назначить код НСИ вручную. */
    public function verify_row(Prx_Principal $principal, int $row_id, string $manual_code = ''): int
    {
        $actor_id = self::editor_id($principal);
        return Prx_Db::transaction(function () use ($principal, $actor_id, $row_id, $manual_code): int {
            [$row] = $this->editable_row($principal, $row_id);
            $manual_code = trim($manual_code);
            if ($manual_code !== '')
            {
                $item = Model_Prx400_Catalog::by_code($manual_code);
                if ($item === NULL) throw new Prx400_Error('catalog_code_not_found', 400);
                Model_Prx400_Row::set_manual_match($row_id, $actor_id, [
                    'catalog_id' => (int) $item['id'], 'code' => (string) $item['code'],
                    'name' => (string) $item['name'], 'unit' => (string) $item['unit'],
                    'okei_code' => (string) ($item['okei_code'] ?? ''), 'score' => 1.0, 'manual' => TRUE,
                    'alternatives' => (array) ($row['match']['alternatives'] ?? []),
                ]);
                return (int) $row['spec_id'];
            }

            // «Проверено» — переключатель; снятие возвращает статус из данных строки.
            $verified = ! $row['verified'];
            Model_Prx400_Row::set_verified($row_id, $actor_id, $verified);
            return (int) $row['spec_id'];
        });
    }

    /**
     * Правка полей строки: каждое изменённое поле — в журнал prx_400_edits;
     * затем пересчёт арифметики, повторный матчинг (если сопоставление не
     * ручное) и статуса. Возвращает spec_id.
     */
    public function row_edit(Prx_Principal $principal, int $row_id, array $post): int
    {
        $actor_id = self::editor_id($principal);
        $fields = self::edit_fields($post);

        return Prx_Db::transaction(function () use ($principal, $actor_id, $row_id, $fields): int {
            [$row] = $this->editable_row($principal, $row_id);
            $changed = [];
            foreach ($fields as $field => $value)
            {
                $old = $row[$field] === NULL ? NULL : (string) $row[$field];
                $new = $value === NULL ? NULL : (string) $value;
                if ($field === 'qty' AND $old !== NULL AND $new !== NULL AND (float) $old === (float) $new) continue;
                if ($old === $new) continue;
                Model_Prx400_Edit::add($row_id, (int) $row['spec_id'], $field, $old, $new, $actor_id);
                Model_Prx400_Row::set_field($row_id, $field, $value);
                $row[$field] = $value;
                $changed[] = $field;
            }
            if ($changed === []) return (int) $row['spec_id'];

            $flags = array_values(array_filter($row['flags'], static fn ($f): bool =>
                ! in_array(is_array($f) ? ($f['type'] ?? '') : '', ['math_row', 'unit_fractional'], TRUE)));
            $flags = array_merge($flags, Service_Prx400_Checks::row_flags($row));

            $match = $row['match'];
            if (empty($match['manual']) AND array_intersect($changed, ['raw_name', 'code_doc']))
            {
                $query = Service_Prx400_Match::query((string) $row['raw_name'], (string) $row['code_doc']);
                $match = Service_Prx400_Match::apply((string) $row['raw_name'], (string) $row['code_doc'],
                    (Service_Prx400_Match::catalog_source())($query))['match'];
            }
            $status = ! empty($match['manual']) && ! in_array('math_row', array_column($flags, 'type'), TRUE)
                ? 'ok' : Service_Prx400_Match::row_status($flags, $match);
            Model_Prx400_Row::set_assessment($row_id, $flags, $match, $status);

            return (int) $row['spec_id'];
        });
    }

    /** Завершение проверки оператором (HITL): review → done; расхождения — только осознанно. */
    public function finish(Prx_Principal $principal, int $spec_id, bool $accept_issues): void
    {
        self::editor_id($principal);
        Prx_Db::transaction(function () use ($principal, $spec_id, $accept_issues): void {
            $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::lock($spec_id));
            if ($spec['status'] !== 'review') throw new Prx400_Error('wrong_state', 409);
            $issues = Model_Prx400_Row::pending_count($spec_id);
            if ($issues > 0 AND ! $accept_issues) throw new Prx400_Error('issues_pending', 409, ['issues' => $issues]);
            Model_Prx400_Spec::set_status($spec_id, 'done');
        });
    }

    /**
     * Удалить спецификацию: живая задача отменяется, строки и правки — каскадом,
     * файл — после COMMIT (осиротевший файл добирает prx400:purge).
     */
    public function delete(Prx_Principal $principal, int $id): void
    {
        self::editor_id($principal);
        $file_id = Prx_Db::transaction(function () use ($principal, $id): int {
            $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::lock($id));
            self::cancel_job((int) ($spec['job_id'] ?? 0));
            Model_Prx400_Spec::delete($id);
            return (int) ($spec['file_id'] ?? 0);
        });
        if ($file_id > 0) self::discard($file_id);
        Log_Plain::event(Log::INFO, 'prx400 spec=:s stage=deleted', [':s' => $id]);
    }

    /** Добавить/обновить позицию справочника НСИ. */
    public function catalog_add(Prx_Principal $principal, array $post): void
    {
        self::editor_id($principal);
        $code = mb_substr(trim((string) ($post['code'] ?? '')), 0, 64);
        $name = mb_substr(trim((string) ($post['name'] ?? '')), 0, 4000);
        if ($code === '' OR $name === '') throw new Prx400_Error('catalog_fields_required', 400);

        Model_Prx400_Catalog::insert([
            'code' => $code,
            'name' => $name,
            'unit' => mb_substr(trim((string) ($post['unit'] ?? '')), 0, 16) ?: 'шт.',
            'okei_code' => mb_substr(trim((string) ($post['okei_code'] ?? '')), 0, 8),
            'keywords' => mb_substr(trim((string) ($post['keywords'] ?? '')), 0, 500),
        ]);
    }

    public function catalog_delete(Prx_Principal $principal, int $id): void
    {
        self::editor_id($principal);
        if (Model_Prx400_Catalog::delete($id) < 1) throw new Prx400_Error('not_found', 404);
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

    private static function editor_id(Prx_Principal $principal): int
    {
        Policy_Prx400_Spec::require_edit($principal);
        if ( ! $principal instanceof Prx_Actor) throw new Prx400_Error('forbidden', 403);

        return $principal->id();
    }

    /** Строка под блокировкой + её спецификация в статусе, где строки принадлежат оператору. */
    private function editable_row(Prx_Principal $principal, int $row_id): array
    {
        $row = Model_Prx400_Row::lock($row_id);
        if ($row === NULL) throw new Prx400_Error('not_found', 404);
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get((int) $row['spec_id']));
        if (in_array($spec['status'], self::PIPELINE_STATUSES, TRUE)) throw new Prx400_Error('wrong_state', 409);

        return [$row, $spec];
    }

    /** Поля правки из формы: только белый список, qty — число или пусто. */
    private static function edit_fields(array $post): array
    {
        $limits = ['raw_name' => 4000, 'code_doc' => 255, 'unit' => 16];
        $fields = [];
        foreach ($limits as $field => $max)
        {
            if (array_key_exists($field, $post)) $fields[$field] = mb_substr(trim((string) $post[$field]), 0, $max);
        }
        if (array_key_exists('qty', $post))
        {
            $qty = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $post['qty']));
            if ($qty === '') $fields['qty'] = NULL;
            elseif (preg_match('/\A-?\d{1,10}(?:\.\d{1,4})?\z/', $qty)) $fields['qty'] = (float) $qty;
            else throw new Prx400_Error('qty_invalid', 400);
        }

        return $fields;
    }
}
