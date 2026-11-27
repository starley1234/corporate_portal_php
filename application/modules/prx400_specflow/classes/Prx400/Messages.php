<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Человекочитаемые тексты кодов prx400 для UI. Сервис и API оперируют
 * только кодами (Prx400_Error, prx_400_specs.error); текст — здесь.
 */
final class Prx400_Messages
{
    private const TEXT = [
        // Приёмка и действия оператора
        'file_required' => 'Выберите PDF-файл.',
        'pdf_required' => 'Файл не является PDF.',
        'file_too_large' => 'Файл больше допустимого размера.',
        'upload_unavailable' => 'Сервер не смог принять файл — попробуйте ещё раз.',
        'storage_unavailable' => 'Хранилище файлов недоступно — попробуйте позже.',
        'worker_not_configured' => 'Воркеры не настроены (WORKER_API_TOKEN) — разбор не запустится.',
        'template_library_empty' => 'Библиотека шаблонов пуста — включите или добавьте шаблон на странице «Шаблоны».',
        'template_not_found' => 'Выбранного шаблона нет в библиотеке — выберите другой или авто-детекцию.',
        'invalid_parse_mode' => 'Неизвестный режим разбора.',
        'invalid_idempotency_key' => 'Форма устарела — обновите страницу.',
        'idempotency_conflict' => 'Эта форма уже отправлена с другим файлом — обновите страницу.',
        'idempotency_key_expired' => 'Форма устарела — обновите страницу.',
        'budget_exceeded' => 'Исчерпан бюджет задач — обратитесь к администратору.',
        'wrong_state' => 'Действие недоступно в текущем статусе спецификации.',
        'issues_pending' => 'Остались неподтверждённые позиции — подтвердите их или отметьте «принимаю с расхождениями».',
        'qty_invalid' => 'Количество должно быть числом.',
        'invalid_field' => 'Неверный формат поля запроса.',
        'catalog_fields_required' => 'Код и наименование обязательны.',
        'catalog_code_not_found' => 'Такого кода нет в справочнике НСИ.',
        'catalog_unavailable' => 'Внешний справочник НСИ не отвечает — попробуйте позже.',
        'catalog_config' => 'Внешний справочник НСИ настроен неверно (PRX400_CATALOG_URL).',
        'catalog_readonly' => 'Справочник НСИ внешний — позиции меняются в самом справочнике.',
        'price_invalid' => 'Цена и сумма должны быть неотрицательными числами.',
        'row_name_required' => 'Укажите наименование позиции.',
        'pos_path_invalid' => 'Номер позиции — числа через точку, например 2.1.3.',
        'row_has_children' => 'У позиции есть вложенные строки — сначала удалите их.',
        'split_invalid' => 'Разделение: от 2 до 50 положительных количеств.',
        'split_sum_mismatch' => 'Сумма частей должна равняться исходному количеству.',
        'min_score_invalid' => 'Порог уверенности — от порога ручной проверки до 1.',
        'invalid_filter' => 'Неверный фильтр списка.',
        'too_many_files' => 'Слишком много файлов или их общий размер больше допустимого.',
        'invalid_bom_mode' => 'Режим количества BOM: as_is или per_parent.',
        'invalid_format' => 'Формат выгрузки: json, csv, xlsx или aggregate.',
        'invalid_json' => 'Тело запроса — JSON-объект.',
        'request_too_large' => 'Запрос больше допустимого размера.',
        'method_not_allowed' => 'Метод не поддерживается.',
        'unauthorized' => 'Требуется авторизация.',
        'webhook_url_invalid' => 'Адрес webhook — https на публичный хост.',
        'webhook_events_invalid' => 'Неизвестное событие webhook (spec.review, spec.done, spec.failed).',
        // Импорт справочника НСИ (CSV/XLSX)
        'catalog_file_required' => 'Выберите файл справочника (CSV или XLSX).',
        'catalog_file_too_large' => 'Файл справочника слишком большой — разделите его на части.',
        'catalog_spreadsheet_unreadable' => 'Файл не читается — загрузите его заново.',
        'catalog_spreadsheet_empty' => 'В файле нет строк с данными.',
        'catalog_spreadsheet_format' => 'Формат не поддерживается: нужен CSV (UTF-8 или Windows-1251) или XLSX. Старый .xls пересохраните в XLSX.',
        'catalog_spreadsheet_too_large' => 'Файл слишком велик после распаковки — разделите его на части.',
        'catalog_spreadsheet_too_many_columns' => 'Слишком много столбцов (до 64) — оставьте код, наименование, ед. изм., ОКЕИ, ключевые слова.',
        'catalog_spreadsheet_too_many_rows' => 'Слишком много строк в одном файле — разделите его на части (позиции из других частей не удаляются).',
        'catalog_columns_missing' => 'Не найдены столбцы «Код» и/или «Наименование» в первой строке файла.',
        'catalog_columns_duplicate' => 'Один и тот же столбец встречается в заголовке дважды.',
        'catalog_code_required' => 'пустой код',
        'catalog_name_required' => 'пустое наименование',
        'catalog_value_too_long' => 'значение длиннее допустимого',
        'catalog_okei_invalid' => 'код ОКЕИ — от 3 до 8 цифр',
        'catalog_code_duplicate' => 'код повторяется в файле',
        'template_file_required' => 'Файл шаблона не передан.',
        'template_too_large' => 'Файл шаблона слишком большой (до 64 КиБ).',
        'template_invalid_json' => 'Файл не является корректным JSON.',
        'template_invalid' => 'Шаблон неполный: нужны template_id (латиница), template_name, anchor_keywords и columns.',
        'template_invalid_column' => 'Колонки шаблона: допустимы pos, item_name, code_doc, qty, unit, unit_price, total_price; у каждой — непустой match; pos обязателен.',
        'template_invalid_regex' => 'Регулярное выражение шаблона некорректно или использует несовместимый синтаксис.',
        'template_code_reserved' => 'Код занят встроенным шаблоном — выберите другой template_id.',
        'not_found' => 'Запись не найдена.',
        'forbidden' => 'Недостаточно прав (нужно prx400.run).',
        // Разбор (воркер и очередь)
        'no_text' => 'Текст не извлечён — скан без распознаваемого слоя. Повторите в режиме «Vision» или «OCR + Vision».',
        'header_not_found' => 'Шапка таблицы не распознана по шаблонам. Попробуйте «OCR + Vision» или добавьте шаблон.',
        'no_rows' => 'Строки позиций не найдены. Попробуйте «OCR + Vision» или «Vision».',
        'vision_config' => 'Vision недоступен: у воркера не настроен LLM-шлюз (LOCAL_API_URL / LOCAL_API_KEY / DEFAULT_LLM_MODEL).',
        'vision_unavailable' => 'Vision-модель не ответила или не поддерживает изображения — проверьте LLM-шлюз воркера.',
        'template_missing' => 'Задача пришла без шаблонов — повторите разбор.',
        'file_unavailable' => 'Исходного PDF нет в хранилище — перезалейте файл.',
        'invalid_pdf' => 'PDF повреждён или не читается.',
        'ocr_config' => 'OCR не настроен у воркера — обратитесь к администратору.',
        'ocr_unavailable' => 'Сервис OCR недоступен — повторите разбор позже.',
        'ocr_auth' => 'Сервис OCR отклонил ключ доступа воркера.',
        'ocr_rejected' => 'Сервис OCR отклонил документ.',
        'ocr_invalid_response' => 'Сервис OCR вернул некорректный ответ.',
        'ocr_incomplete' => 'OCR распознал документ не полностью.',
        'ocr_page_failed' => 'OCR не смог распознать одну из страниц.',
        'bad_payload' => 'Задача разбора сформирована с ошибкой — повторите разбор.',
        'encrypted_pdf' => 'PDF защищён паролем.',
        'page_limit' => 'В документе больше страниц, чем разрешено (PRX400_MAX_PAGES).',
        'result_too_large' => 'Слишком много строк (PRX400_MAX_ROWS) или текста.',
        'invalid_result' => 'Воркер прислал некорректный результат — повторите разбор.',
        'job_timeout' => 'Разбор не уложился в таймаут задачи.',
        'job_unavailable' => 'Задача очереди не найдена — запустите разбор повторно.',
        'job_canceled' => 'Задача отменена — запустите разбор повторно.',
        'worker_lost' => 'Воркер перестал отвечать посреди разбора (упал или перезапущен). Автоповтор не помог — запустите разбор повторно.',
        'worker_crash_loop' => 'Воркер падал на этом файле несколько раз — проверьте PDF (размер, сканы) или перезалейте его.',
        'result_missing' => 'Задача завершилась, но результат не записан — запустите разбор повторно.',
        'job_failed' => 'Разбор завершился ошибкой — см. монитор очереди.',
    ];

    private const WARNINGS = [
        'vision_pages_truncated' => 'Vision разобрал не все страницы (лимит PRX400_MAX_VISION_PAGES) — сверьте хвост документа.',
        'vision_page_failed' => 'Часть страниц vision не распознал — сверьте позиции с PDF.',
        'empty_page' => 'В PDF есть страницы без текста.',
        'catalog_unavailable' => 'Справочник НСИ не ответил во время разбора — строки приняты без сопоставления. Нажмите «Пересопоставить с НСИ», когда он станет доступен.',
        'verified_rows_lost' => 'После перезапуска часть подтверждённых строк не нашлась в новом разборе — сверьте журнал правок.',
    ];

    public static function text(string $code): string
    {
        if (isset(self::TEXT[$code])) return self::TEXT[$code];
        // Легаси-записи (до 27.11) хранили «код: русский текст» или только текст.
        foreach (self::TEXT as $known => $text)
        {
            if (str_starts_with($code, $known.':')) return $text;
        }

        return $code !== '' ? $code : self::TEXT['job_failed'];
    }

    public static function warning(string $code): string
    {
        if (isset(self::WARNINGS[$code])) return self::WARNINGS[$code];
        if (preg_match('/\Avision_page_(\d+)_failed\z/', $code, $m)) return 'Vision не распознал страницу '.$m[1].' — сверьте её с PDF.';
        if (preg_match('/\Afile_(\d+)_([a-z_]+)\z/', $code, $m)) return 'Файл '.$m[1].' пропущен: '.self::text($m[2]);

        return $code;
    }

    /** HTTP-исключение страницы по доменной ошибке (GET-страницы UI). */
    public static function http(Prx_DomainError $e): HTTP_Exception
    {
        $status = $e->getCode();
        if ( ! class_exists('HTTP_Exception_'.$status)) $status = $status >= 500 ? 503 : 400;

        return HTTP_Exception::factory($status, self::text($e->getMessage()));
    }
}
