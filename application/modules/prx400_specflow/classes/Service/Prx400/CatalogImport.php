<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Импорт справочника НСИ из CSV/XLSX (выгрузки 1С, MDM, Excel).
 *
 * Два шага одной формой: «Проверить» (ничего не пишет, показывает, что будет
 * создано/обновлено и какие строки с ошибками) и «Импортировать». Правила:
 *
 *  - столбцы по заголовку, русские и латинские имена (ALIASES); обязательны
 *    код и наименование, лишние столбцы выгрузки игнорируются (видны в отчёте);
 *  - строка с ошибкой не обрезается молча (обрезанный код НСИ — чужой код):
 *    любая ошибка → импорт не применяется целиком, отчёт указывает номера строк;
 *  - повтор кода в файле — ошибка (непонятно, какая из строк верна);
 *  - применение — одна транзакция под advisory-блокировкой: upsert по коду,
 *    неизменённые позиции не трогаются; удалений нет (позиции, которых нет в
 *    файле, остаются — справочник может собираться из нескольких выгрузок);
 *  - ОКЕИ: Excel теряет ведущие нули («6» вместо «006») — цифры дополняются до 3.
 *
 * UI: Controller_Prx400_Ui::action_catalog_import, CLI: bin/console prx400:catalog.
 */
final class Service_Prx400_CatalogImport
{
    private const LOCK = 400001;
    private const CHUNK = 500;
    private const MAX_REPORTED_ERRORS = 200;

    /** Поле справочника => допустимые заголовки (в нижнем регистре, без точек и лишних пробелов). */
    public const ALIASES = [
        'code'      => ['code', 'код', 'код нси', 'код номенклатуры', 'артикул', 'nsi', 'nsi code'],
        'name'      => ['name', 'наименование', 'название', 'наименование номенклатуры', 'полное наименование'],
        'unit'      => ['unit', 'ед изм', 'единица измерения', 'единица', 'ед', 'еи'],
        'okei_code' => ['okei', 'okei_code', 'океи', 'код океи', 'код еи'],
        'keywords'  => ['keywords', 'ключевые слова', 'синонимы', 'теги'],
    ];

    private const MAX_LENGTH = ['code' => 64, 'name' => 4000, 'unit' => 16, 'okei_code' => 8, 'keywords' => 500];

    /** UI: загруженный файл + право prx400.run. */
    public static function from_upload(Prx_Principal $principal, $upload, bool $apply): array
    {
        Policy_Prx400_Spec::require_edit($principal);
        if ( ! $principal instanceof Prx_Actor) throw new Prx400_Error('forbidden', 403);
        Service_Prx400_Catalog::require_writable(); // внешний НСИ ведётся в своей системе

        $error = is_array($upload) ? ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE)) throw new Prx400_Error('catalog_file_too_large', 413);
        if ($error !== UPLOAD_ERR_OK OR ! is_string($upload['tmp_name'] ?? NULL) OR ! is_uploaded_file($upload['tmp_name']))
        {
            throw new Prx400_Error('catalog_file_required', 400);
        }

        $report = self::run($upload['tmp_name'], $apply);
        $report['file'] = mb_substr(basename((string) ($upload['name'] ?? 'файл')), 0, 120);
        if ($report['applied'])
        {
            Log_Plain::event(Log::INFO, 'prx400 stage=catalog_import user=:u created=:c updated=:up total=:t', [
                ':u' => $principal->id(), ':c' => $report['created'], ':up' => $report['updated'], ':t' => $report['total'],
            ]);
        }

        return $report;
    }

    /**
     * Разобрать файл и (при $apply и без ошибок) применить.
     *
     * @return array{total:int, created:int, updated:int, unchanged:int, errors:array, error_count:int,
     *               columns:array, ignored:array, applied:bool, applied_rows:int}
     */
    public static function run(string $path, bool $apply): array
    {
        $size = @filesize($path);
        if ($size === FALSE) throw new Prx400_Error('catalog_file_required', 400);
        if ($size > Service_Prx400_Limits::get('catalog_import_max_bytes')) throw new Prx400_Error('catalog_file_too_large', 413);

        try {
            $table = Prx_Spreadsheet::read($path, Service_Prx400_Limits::get('catalog_import_max_rows'));
        } catch (Prx_DomainError $e) {
            throw new Prx400_Error('catalog_'.$e->getMessage(), $e->getCode(), $e->details());
        }

        $header_line = (int) array_key_first($table);
        [$columns, $ignored] = self::map_header($table[$header_line]);
        unset($table[$header_line]);

        [$items, $errors] = self::validate($table, $columns);
        $report = [
            'total' => count($table), 'created' => 0, 'updated' => 0, 'unchanged' => 0,
            'errors' => array_slice($errors, 0, self::MAX_REPORTED_ERRORS), 'error_count' => count($errors),
            'columns' => array_keys($columns), 'ignored' => $ignored, 'applied' => FALSE, 'applied_rows' => 0,
        ];
        if ($items === [] AND $errors === []) throw new Prx400_Error('catalog_spreadsheet_empty', 400);

        if ( ! $apply OR $errors !== [])
        {
            $diff = self::diff($items);
            unset($diff['changed']);

            return $diff + $report;
        }

        return Prx_Db::transaction(static function () use ($items, $report): array {
            // Два импорта одновременно не пересчитают «создано/обновлено» друг поверх друга.
            Prx_Db::one('SELECT pg_advisory_xact_lock(:k) AS l', [':k' => self::LOCK]);
            $diff = self::diff($items);
            $changed = array_values(array_filter($items, static fn (array $item) => isset($diff['changed'][$item['code']])));
            foreach (array_chunk($changed, self::CHUNK) as $chunk) Model_Prx400_Catalog::upsert_many($chunk);
            unset($diff['changed']);

            return $diff + ['applied' => TRUE, 'applied_rows' => count($changed)] + $report;
        });
    }

    /**
     * Заголовок → [поле => индекс столбца, [проигнорированные заголовки]].
     */
    public static function map_header(array $header): array
    {
        $lookup = [];
        foreach (self::ALIASES as $field => $names) foreach ($names as $name) $lookup[$name] = $field;

        $columns = [];
        $ignored = [];
        foreach ($header as $index => $title)
        {
            $key = self::normalize_header((string) $title);
            if ($key === '') continue;
            $field = $lookup[$key] ?? NULL;
            if ($field === NULL) { $ignored[] = (string) $title; continue; }
            if (isset($columns[$field])) throw new Prx400_Error('catalog_columns_duplicate', 400, ['column' => (string) $title]);
            $columns[$field] = (int) $index;
        }
        $missing = array_values(array_diff(['code', 'name'], array_keys($columns)));
        if ($missing) throw new Prx400_Error('catalog_columns_missing', 400, ['missing' => $missing]);

        return [$columns, $ignored];
    }

    public static function normalize_header(string $title): string
    {
        $title = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $title)));
        $title = str_replace(['.', '*', '"', '«', '»'], ' ', $title);

        return trim(preg_replace('/\s+/u', ' ', $title));
    }

    /** @return array{0: array<int, array>, 1: array<int, array{line:int, code:string, field:string}>} */
    public static function validate(array $table, array $columns): array
    {
        $items = [];
        $errors = [];
        $seen = [];
        foreach ($table as $line => $cells)
        {
            $item = [];
            foreach (self::MAX_LENGTH as $field => $_)
            {
                $value = isset($columns[$field]) ? trim((string) ($cells[$columns[$field]] ?? '')) : '';
                $item[$field] = self::uncell($value);
            }
            $item['code'] = preg_replace('/\s+/u', ' ', $item['code']);
            $item['name'] = preg_replace('/\s+/u', ' ', $item['name']);
            if ($item['unit'] === '') $item['unit'] = 'шт.';
            if (preg_match('/^\d{1,2}$/', $item['okei_code'])) $item['okei_code'] = str_pad($item['okei_code'], 3, '0', STR_PAD_LEFT);

            $line_errors = [];
            if ($item['code'] === '') $line_errors[] = ['catalog_code_required', 'code'];
            if ($item['name'] === '') $line_errors[] = ['catalog_name_required', 'name'];
            foreach (self::MAX_LENGTH as $field => $max)
            {
                if (mb_strlen($item[$field]) > $max) $line_errors[] = ['catalog_value_too_long', $field];
            }
            if ($item['okei_code'] !== '' AND ! preg_match('/^\d{3,8}$/', $item['okei_code'])) $line_errors[] = ['catalog_okei_invalid', 'okei_code'];
            if ($item['code'] !== '' AND isset($seen[$item['code']])) $line_errors[] = ['catalog_code_duplicate', 'code'];

            if ($line_errors)
            {
                foreach ($line_errors as [$code, $field]) $errors[] = ['line' => (int) $line, 'code' => $code, 'field' => $field,
                    'value' => mb_substr($item[$field], 0, 60), 'first_line' => $code === 'catalog_code_duplicate' ? $seen[$item['code']] : NULL];
                continue;
            }
            $seen[$item['code']] = (int) $line;
            $items[] = $item;
        }

        return [$items, $errors];
    }

    /** Сравнение с текущим справочником: что создастся, обновится, не изменится. */
    private static function diff(array $items): array
    {
        $existing = [];
        foreach (array_chunk(array_column($items, 'code'), self::CHUNK) as $codes)
        {
            foreach (Model_Prx400_Catalog::by_codes($codes) as $row) $existing[$row['code']] = $row;
        }
        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'changed' => []];
        foreach ($items as $item)
        {
            $row = $existing[$item['code']] ?? NULL;
            if ($row === NULL) { $out['created']++; $out['changed'][$item['code']] = TRUE; continue; }
            $changed = FALSE;
            foreach (['name', 'unit', 'okei_code', 'keywords'] as $field) $changed = $changed || (string) $row[$field] !== $item[$field];
            if ( ! $changed) { $out['unchanged']++; continue; }
            $out['updated']++;
            $out['changed'][$item['code']] = TRUE;
        }

        return $out;
    }

    /** CSV справочника (UTF-8 с BOM, «;») — открывается в Excel и принимается обратно импортом. */
    public static function export_csv(): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Код', 'Наименование', 'Ед. изм.', 'ОКЕИ', 'Ключевые слова'], ';', '"', '');
        foreach (Model_Prx400_Catalog::all() as $row)
        {
            fputcsv($out, array_map([Service_Prx400_Export::class, 'cell'], [
                $row['code'], $row['name'], $row['unit'], (string) $row['okei_code'], $row['keywords'],
            ]), ';', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** Снять апостроф-экран формулы, который ставит Service_Prx400_Export::cell(). */
    public static function uncell(string $value): string
    {
        return preg_match("/\\A'[=+\\-@\\t\\r]/", $value) ? substr($value, 1) : $value;
    }
}
