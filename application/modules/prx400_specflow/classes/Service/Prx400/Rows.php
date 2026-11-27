<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Работа оператора с позициями (HITL) — общий фасад UI и API:
 * подтверждение, код НСИ (ручной выбор, снятие, «завести в НСИ из строки»),
 * правка полей и цен, добавление/удаление/разделение строк, массовое
 * подтверждение уверенных совпадений.
 *
 * Права — Policy_Prx400_Spec::require_review (UI prx400.run, API scope
 * prx400.review по своим записям). Строки правятся только в review/done.
 * Каждое действие — запись в prx_400_edits (actor_id со знаком), затем
 * пересчёт флагов и статуса единым правилом (Checks::refresh + Match::row_status).
 * Ручной код запоминается (prx_400_aliases) и применяется следующими разборами.
 */
final class Service_Prx400_Rows
{
    /** Фильтры списка строк (UI и API). */
    public const FILTERS = ['all', 'disputed', 'ok', 'review', 'new', 'error', 'verified'];

    private const TOLERANCE = 0.0001;

    /** Позиции спецификации для API (схема экспорта + служебные поля HITL). */
    public function listing(Prx_Principal $principal, int $spec_id, string $filter = 'all'): array
    {
        $spec = Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get($spec_id));
        if ( ! in_array($filter, self::FILTERS, TRUE)) throw new Prx400_Error('invalid_filter', 400);
        $rows = array_values(array_filter(Model_Prx400_Row::for_spec($spec_id), static fn (array $r): bool => self::passes($r, $filter)));

        return [
            'spec_id' => $spec_id,
            'status' => (string) $spec['status'],
            'filter' => $filter,
            'counts' => self::filter_counts(Model_Prx400_Row::counts($spec_id)),
            'rows' => array_map(static fn (array $r): array => Service_Prx400_Export::position($r, TRUE), $rows),
        ];
    }

    /** Строка в спецификации из URL; иначе 404 (как несуществующая). */
    public static function require_in_spec(int $spec_id, int $row_id): void
    {
        $row = Model_Prx400_Row::get($row_id);
        if ($row === NULL OR (int) $row['spec_id'] !== $spec_id) throw new Prx400_Error('not_found', 404);
    }

    /** Одна позиция для API (после действия — её новое состояние). */
    public static function position_of(Prx_Principal $principal, int $row_id): array
    {
        $row = Model_Prx400_Row::get($row_id);
        if ($row === NULL) throw new Prx400_Error('not_found', 404);
        Policy_Prx400_Spec::require_view($principal, Model_Prx400_Spec::get((int) $row['spec_id']));

        return Service_Prx400_Export::position($row, TRUE);
    }

    /** Счётчики по именам фильтров (вкладки UI = ?filter= в API). */
    public static function filter_counts(array $c): array
    {
        return [
            'all' => (int) ($c['total'] ?? 0), 'disputed' => (int) ($c['pending'] ?? 0),
            'ok' => (int) ($c['ok'] ?? 0), 'review' => (int) ($c['review'] ?? 0), 'new' => (int) ($c['new'] ?? 0),
            'error' => (int) ($c['error'] ?? 0), 'verified' => (int) ($c['verified'] ?? 0),
        ];
    }

    public static function passes(array $r, string $filter): bool
    {
        switch ($filter)
        {
            case 'disputed': return $r['status'] !== 'ok' && ! $r['verified'];
            case 'verified': return (bool) $r['verified'];
            case 'all': return TRUE;
            default: return $r['status'] === $filter;
        }
    }

    /** Подтвердить/снять подтверждение (NULL — переключить). */
    public function verify(Prx_Principal $principal, int $row_id, ?bool $verified = NULL): int
    {
        $actor = Policy_Prx400_Spec::require_review($principal);

        return Prx_Db::transaction(function () use ($principal, $actor, $row_id, $verified): int {
            [$row] = $this->editable_row($principal, $row_id);
            $target = $verified ?? ! $row['verified'];
            if ($target === $row['verified']) return (int) $row['spec_id'];
            Model_Prx400_Row::set_verified($row_id, $actor, $target);
            Model_Prx400_Edit::add($row_id, (int) $row['spec_id'], 'verified', $row['verified'] ? '1' : '0', $target ? '1' : '0', $actor, self::label($row));

            return (int) $row['spec_id'];
        });
    }

    /** Назначить код НСИ вручную: строка подтверждена, решение запомнено. */
    public function assign_code(Prx_Principal $principal, int $row_id, string $code): int
    {
        $actor = Policy_Prx400_Spec::require_review($principal);
        $code = trim($code);
        if ($code === '') throw new Prx400_Error('catalog_code_not_found', 400);
        $item = Service_Prx400_Catalog::by_code($code); // внешний источник — до транзакции
        if ($item === NULL) throw new Prx400_Error('catalog_code_not_found', 400);

        return Prx_Db::transaction(function () use ($principal, $actor, $row_id, $item): int {
            [$row] = $this->editable_row($principal, $row_id);
            $this->apply_code($row, $item, $actor, $principal instanceof Prx150_Principal ? 'api' : 'operator');

            return (int) $row['spec_id'];
        });
    }

    /** Снять ручной код: строка сопоставляется заново, решение забыто. */
    public function clear_code(Prx_Principal $principal, int $row_id): int
    {
        $actor = Policy_Prx400_Spec::require_review($principal);
        $row = Model_Prx400_Row::get($row_id);
        $assessed = $row !== NULL ? $this->fresh_match(array_merge($row, ['match' => NULL])) : NULL;

        return Prx_Db::transaction(function () use ($principal, $actor, $row_id, $assessed): int {
            [$row] = $this->editable_row($principal, $row_id);
            if (empty($row['match']['manual'])) return (int) $row['spec_id'];
            Service_Prx400_Catalog::forget($row);
            Model_Prx400_Edit::add($row_id, (int) $row['spec_id'], 'code', (string) ($row['match']['code'] ?? ''), NULL, $actor, self::label($row));
            Model_Prx400_Row::set_assessment($row_id, $assessed['flags'], $assessed['match'], $assessed['status']);
            Model_Prx400_Row::set_verified($row_id, $actor, FALSE);

            return (int) $row['spec_id'];
        });
    }

    /** Завести позицию в локальном НСИ из строки и сразу привязать её. */
    public function create_catalog_item(Prx_Principal $principal, int $row_id, array $post): int
    {
        $actor = Policy_Prx400_Spec::require_review($principal);
        Service_Prx400_Catalog::require_writable();

        return Prx_Db::transaction(function () use ($principal, $actor, $row_id, $post): int {
            [$row] = $this->editable_row($principal, $row_id);
            $fields = Service_Prx400_Catalog::fields($post, [
                'name' => (string) $row['raw_name'], 'unit' => (string) $row['unit'], 'keywords' => (string) $row['code_doc'],
            ]);
            $item = Service_Prx400_Catalog::save($fields);
            Model_Prx400_Edit::add($row_id, (int) $row['spec_id'], 'catalog_add', NULL, (string) $item['code'], $actor, self::label($row));
            $this->apply_code($row, $item, $actor, 'operator');

            return (int) $row['spec_id'];
        });
    }

    /**
     * Правка полей: каждое изменённое поле — в журнал; затем пересчёт
     * арифметики, повторный матчинг (если код не ручной) и статуса.
     */
    public function edit(Prx_Principal $principal, int $row_id, array $post): int
    {
        $actor = Policy_Prx400_Spec::require_review($principal);
        $fields = self::fields($post);
        // Матчинг (внешний НСИ — сеть) — до транзакции, по данным с правкой.
        $current = Model_Prx400_Row::get($row_id);
        $preview = $current !== NULL && array_intersect(array_keys($fields), ['raw_name', 'code_doc']) && empty($current['match']['manual'])
            ? $this->fresh_match(array_merge($current, $fields)) : NULL;

        return Prx_Db::transaction(function () use ($principal, $actor, $row_id, $fields, $preview): int {
            [$row] = $this->editable_row($principal, $row_id);
            $changed = [];
            foreach ($fields as $field => $value)
            {
                $old = $row[$field] === NULL ? NULL : (string) $row[$field];
                $new = $value === NULL ? NULL : (string) $value;
                if (in_array($field, ['qty', 'unit_price', 'total_price'], TRUE) AND $old !== NULL AND $new !== NULL AND abs((float) $old - (float) $new) < self::TOLERANCE) continue;
                if ($old === $new) continue;
                Model_Prx400_Edit::add($row_id, (int) $row['spec_id'], $field, $old, $new, $actor, self::label($row));
                Model_Prx400_Row::set_field($row_id, $field, $value);
                $row[$field] = $value;
                $changed[] = $field;
            }
            if ($changed === []) return (int) $row['spec_id'];

            $match = $row['match'];
            if (empty($match['manual']) AND array_intersect($changed, ['raw_name', 'code_doc']))
            {
                $match = $preview !== NULL && self::same_text($preview['row'], $row) ? $preview['match'] : $this->fresh_match($row)['match'];
            }
            $flags = Service_Prx400_Checks::refresh($row, $match);
            Model_Prx400_Row::set_assessment($row_id, $flags, $match, Service_Prx400_Match::row_status($flags, $match));

            return (int) $row['spec_id'];
        });
    }

    /**
     * Добавить строку, которой нет в разборе (пропуск OCR): после строки
     * $post['after_row_id'] (иначе в конец), потомком $post['parent_row_id'].
     *
     * @return array{spec_id: int, row_id: int}
     */
    public function add(Prx_Principal $principal, int $spec_id, array $post): array
    {
        $actor = Policy_Prx400_Spec::require_review($principal);
        $fields = self::fields($post);
        if (trim((string) ($fields['raw_name'] ?? '')) === '') throw new Prx400_Error('row_name_required', 400);
        $path = trim(Prx400_Input::text($post['pos_path'] ?? ''));
        if ($path !== '' AND ! preg_match(Service_Prx400_Pipeline::POS_PATH_PATTERN, $path)) throw new Prx400_Error('pos_path_invalid', 400);
        $draft = array_merge(['raw_name' => '', 'code_doc' => '', 'qty' => NULL, 'unit' => '', 'unit_price' => NULL,
            'total_price' => NULL, 'section' => '', 'flags' => []], $fields);
        $assessed = $this->fresh_match($draft);

        return Prx_Db::transaction(function () use ($principal, $actor, $spec_id, $post, $draft, $path, $assessed): array {
            $this->editable_spec($principal, $spec_id);
            $rows = Model_Prx400_Row::for_spec($spec_id);
            $after = self::find($rows, (int) ($post['after_row_id'] ?? 0));
            $parent = self::find($rows, (int) ($post['parent_row_id'] ?? 0));
            if ((int) ($post['after_row_id'] ?? 0) > 0 AND $after === NULL) throw new Prx400_Error('not_found', 404);
            if ((int) ($post['parent_row_id'] ?? 0) > 0 AND $parent === NULL) throw new Prx400_Error('not_found', 404);
            // После строки — за всем её поддеревом; в сборку — последним потомком.
            $anchor = $after ?? $parent;
            $seq = $anchor !== NULL ? self::subtree_end($rows, $anchor) + 1 : (count($rows) > 0 ? max(array_column($rows, 'seq')) + 1 : 0);
            Model_Prx400_Row::shift($spec_id, $seq - 1);
            $row_id = Model_Prx400_Row::insert($spec_id, array_merge($draft, [
                'seq' => $seq,
                'pos' => $path !== '' ? (int) substr(strrchr('.'.$path, '.'), 1) : NULL,
                'pos_path' => $path,
                'level' => $parent !== NULL ? (int) $parent['level'] + 1 : ($after !== NULL ? (int) $after['level'] : 0),
                'file_no' => (int) ($anchor['file_no'] ?? 1),
                'page' => $anchor['page'] ?? NULL,
                'origin' => 'manual',
                'match' => $assessed['match'],
                'flags' => $assessed['flags'],
                'status' => $assessed['status'],
            ]), $parent !== NULL ? (int) $parent['id'] : ($after['parent_id'] ?? NULL));
            Model_Prx400_Edit::add($row_id, $spec_id, 'row_add', NULL, mb_substr((string) $draft['raw_name'], 0, 500), $actor, self::label($draft + ['seq' => $seq, 'pos_path' => $path]));

            return ['spec_id' => $spec_id, 'row_id' => $row_id];
        });
    }

    /** Удалить строку (мусор OCR, дубль). Строку с потомками — нельзя. */
    public function delete(Prx_Principal $principal, int $row_id): int
    {
        $actor = Policy_Prx400_Spec::require_review($principal);

        return Prx_Db::transaction(function () use ($principal, $actor, $row_id): int {
            [$row] = $this->editable_row($principal, $row_id);
            if (Model_Prx400_Row::children_count($row_id) > 0) throw new Prx400_Error('row_has_children', 409);
            Model_Prx400_Edit::add($row_id, (int) $row['spec_id'], 'row_delete', mb_substr((string) $row['raw_name'], 0, 500), NULL, $actor, self::label($row));
            Model_Prx400_Row::delete($row_id);

            return (int) $row['spec_id'];
        });
    }

    /**
     * Разделить строку по количествам (одна позиция документа → несколько
     * позиций учёта): сумма частей равна исходному количеству. Первая часть
     * остаётся в строке, остальные — новыми строками ниже (origin split).
     *
     * @param array $parts список количеств (число или строка «2,5»)
     * @return array{spec_id: int, row_ids: int[]}
     */
    public function split(Prx_Principal $principal, int $row_id, array $parts): array
    {
        $actor = Policy_Prx400_Spec::require_review($principal);
        $qtys = [];
        foreach (array_values($parts) as $p)
        {
            $q = self::number_field($p, 'qty_invalid');
            if ($q === NULL OR $q <= 0) throw new Prx400_Error('split_invalid', 400);
            $qtys[] = $q;
        }
        if (count($qtys) < 2 OR count($qtys) > 50) throw new Prx400_Error('split_invalid', 400);

        return Prx_Db::transaction(function () use ($principal, $actor, $row_id, $qtys): array {
            [$row] = $this->editable_row($principal, $row_id);
            if ($row['qty'] === NULL OR abs(array_sum($qtys) - (float) $row['qty']) > 0.0001) throw new Prx400_Error('split_sum_mismatch', 409);
            $price = $row['unit_price'] !== NULL ? (float) $row['unit_price'] : NULL;
            $total = static fn (float $q): ?float => $price !== NULL ? round($q * $price, 2) : NULL;

            Model_Prx400_Edit::add($row_id, (int) $row['spec_id'], 'split', (string) (float) $row['qty'], implode(' + ', $qtys), $actor, self::label($row));
            Model_Prx400_Row::set_field($row_id, 'qty', $qtys[0]);
            if ($row['total_price'] !== NULL) Model_Prx400_Row::set_field($row_id, 'total_price', $total($qtys[0]));
            $first = array_merge($row, ['qty' => $qtys[0], 'total_price' => $row['total_price'] !== NULL ? $total($qtys[0]) : NULL]);
            $flags = Service_Prx400_Checks::refresh($first, $row['match']);
            Model_Prx400_Row::set_assessment($row_id, $flags, $row['match'], Service_Prx400_Match::row_status($flags, $row['match']));

            $ids = [$row_id];
            Model_Prx400_Row::shift((int) $row['spec_id'], (int) $row['seq'], count($qtys) - 1);
            foreach (array_slice($qtys, 1) as $i => $q)
            {
                $part = array_merge($row, [
                    'seq' => (int) $row['seq'] + $i + 1, 'qty' => $q,
                    'total_price' => $row['total_price'] !== NULL ? $total($q) : NULL,
                    'origin' => 'split', 'verified' => FALSE,
                ]);
                $part['flags'] = Service_Prx400_Checks::refresh($part, $row['match']);
                $part['status'] = Service_Prx400_Match::row_status($part['flags'], $row['match']);
                $ids[] = Model_Prx400_Row::insert((int) $row['spec_id'], $part, $row['parent_id']);
            }

            return ['spec_id' => (int) $row['spec_id'], 'row_ids' => $ids];
        });
    }

    /**
     * Подтвердить разом уверенные совпадения: код НСИ есть, скор ≥ порога
     * (по умолчанию bulk_verify_min), арифметика сошлась.
     *
     * @return array{spec_id: int, verified: int}
     */
    public function bulk_verify(Prx_Principal $principal, int $spec_id, $min_score = NULL): array
    {
        $actor = Policy_Prx400_Spec::require_review($principal);
        $min = $min_score === NULL || $min_score === '' ? Service_Prx400_Limits::bulk_verify_min() : self::number_field($min_score, 'min_score_invalid');
        if ($min === NULL OR $min < Service_Prx400_Limits::match()['manual_review'] OR $min > 1) throw new Prx400_Error('min_score_invalid', 400);

        return Prx_Db::transaction(function () use ($principal, $actor, $spec_id, $min): array {
            $this->editable_spec($principal, $spec_id);
            $ids = Model_Prx400_Row::verify_confident($spec_id, $min, $actor);
            if ($ids !== []) Model_Prx400_Edit::add(NULL, $spec_id, 'bulk_verify', NULL, count($ids).' ≥ '.$min, $actor, 'строк: '.count($ids));

            return ['spec_id' => $spec_id, 'verified' => count($ids)];
        });
    }

    // ------------------------------------------------------------------

    private function apply_code(array $row, array $item, int $actor, string $source): void
    {
        $match = Service_Prx400_Match::from_item($item, 'manual', TRUE, (array) ($row['match']['alternatives'] ?? []));
        $flags = Service_Prx400_Checks::refresh($row, $match);
        Model_Prx400_Edit::add((int) $row['id'], (int) $row['spec_id'], 'code', (string) ($row['match']['code'] ?? '') ?: NULL, (string) $item['code'], $actor, self::label($row));
        Model_Prx400_Row::set_manual_match((int) $row['id'], $actor, $match, $flags, Service_Prx400_Match::row_status($flags, $match));
        Service_Prx400_Catalog::remember($row, (string) $item['code'], $source, $actor);
    }

    /** @return array{row: array, match: array, flags: array, status: string} */
    private function fresh_match(array $row): array
    {
        $matcher = new Service_Prx400_Matcher;
        $matcher->prime([$row]);

        return ['row' => $row] + $matcher->assess($row);
    }

    private static function same_text(array $a, array $b): bool
    {
        return (string) $a['raw_name'] === (string) $b['raw_name'] && (string) $a['code_doc'] === (string) $b['code_doc'];
    }

    /** seq последней строки поддерева $root (строки — по seq, связи — parent_id). */
    public static function subtree_end(array $rows, array $root): int
    {
        $parent = array_column($rows, 'parent_id', 'id');
        $end = (int) $root['seq'];
        foreach ($rows as $r)
        {
            if ((int) $r['seq'] <= $end) continue;
            $seen = [];
            $inside = FALSE;
            for ($p = $parent[(int) $r['id']] ?? NULL; $p !== NULL AND ! isset($seen[$p]); $p = $parent[$p] ?? NULL)
            {
                if ((int) $p === (int) $root['id']) { $inside = TRUE; break; }
                $seen[$p] = TRUE;
            }
            if ( ! $inside) break;
            $end = (int) $r['seq'];
        }

        return $end;
    }

    private static function find(array $rows, int $id): ?array
    {
        if ($id <= 0) return NULL;
        foreach ($rows as $r) if ((int) $r['id'] === $id) return $r;

        return NULL;
    }

    /** Подпись строки в журнале: «1.2 · Болт М16…». */
    public static function label(array $row): string
    {
        $pos = (string) ($row['pos_path'] ?? '') !== '' ? (string) $row['pos_path']
            : (isset($row['pos']) && $row['pos'] !== NULL ? (string) $row['pos'] : '#'.((int) ($row['seq'] ?? 0) + 1));

        return mb_substr($pos.' · '.trim((string) ($row['raw_name'] ?? '')), 0, 80);
    }

    /** Строка под блокировкой + её спецификация в статусе, где строки принадлежат оператору. */
    private function editable_row(Prx_Principal $principal, int $row_id): array
    {
        $row = Model_Prx400_Row::lock($row_id);
        if ($row === NULL) throw new Prx400_Error('not_found', 404);
        $spec = $this->editable_spec($principal, (int) $row['spec_id'], FALSE);

        return [$row, $spec];
    }

    private function editable_spec(Prx_Principal $principal, int $spec_id, bool $lock = TRUE): array
    {
        $spec = Policy_Prx400_Spec::require_view($principal, $lock ? Model_Prx400_Spec::lock($spec_id) : Model_Prx400_Spec::get($spec_id));
        if ( ! in_array($spec['status'], ['review', 'done'], TRUE)) throw new Prx400_Error('wrong_state', 409);

        return $spec;
    }

    /** Поля правки из формы/API: белый список, числа — RU/EN запись или пусто. */
    public static function fields(array $post): array
    {
        $limits = ['raw_name' => 4000, 'code_doc' => 255, 'unit' => 16, 'section' => 255];
        $fields = [];
        foreach ($limits as $field => $max)
        {
            if (array_key_exists($field, $post)) $fields[$field] = mb_substr(trim(Prx400_Input::text($post[$field])), 0, $max);
        }
        foreach (['qty' => 'qty_invalid', 'unit_price' => 'price_invalid', 'total_price' => 'price_invalid'] as $field => $error)
        {
            if (array_key_exists($field, $post)) $fields[$field] = self::number_field($post[$field], $error);
        }

        return $fields;
    }

    private static function number_field($raw, string $error): ?float
    {
        if (is_int($raw) OR is_float($raw)) return is_finite((float) $raw) && abs((float) $raw) < 1e12 ? (float) $raw : throw new Prx400_Error($error, 400);
        if ($raw === NULL) return NULL;
        if ( ! is_string($raw)) throw new Prx400_Error($error, 400); // true, [], {} — не число
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($raw));
        if ($value === '') return NULL;
        if ( ! preg_match('/\A-?\d{1,12}(?:\.\d{1,4})?\z/', $value)) throw new Prx400_Error($error, 400);

        return (float) $value;
    }
}
