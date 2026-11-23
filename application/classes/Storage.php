<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Канонический фасад файлового хранилища — единственная точка доступа модулей
 * к файлам. Проводник — Service_Prx212_Storage (prx212_file_storage); замена
 * модуля не затрагивает вызывающий код.
 *
 *   $file_id = Storage::save($_FILES['doc'], 'prx312_claims', $actor->id());
 *   $file_id = Storage::put($text, 'result.txt', 'prx395_document_extract', NULL, FALSE, 'text/plain');
 *   $bytes   = Storage::bytes($file_id);           // содержимое, локальный диск или S3
 *   $url     = Storage::url($file_id, $actor->id());
 *   Storage::delete($file_id, $actor->id());
 *
 * Контракт:
 *  - сессия не читается: кто действует — явный $user_id, NULL — гость;
 *  - фасад не решает права на объект (документ, письмо, задание): file_id
 *    вызывающий берёт из своей авторизованной записи; файловые правила
 *    (владелец/публичность) применяет только delete();
 *  - prx212 не подключён: записи бросают Storage_Exception, чтение возвращает
 *    NULL/FALSE/[]; любая ошибка хранилища при записи — Storage_Exception;
 *  - наружу только int, string, bool и массивы фиксированных ключей — без ORM.
 */
final class Storage {

    /** Ключи metadata(): стабильный контракт без внутренностей хранилища. */
    const META_KEYS = ['id', 'filename', 'extension', 'mime_type', 'size', 'hash',
        'is_public', 'module', 'created_at', 'url'];

    /** @var bool Только тесты (через Reflection): считать prx212 не подключённым. */
    private static bool $module_missing = FALSE;

    /**
     * Сохранить загруженный файл: элемент $_FILES или ['tmp_name', 'name', 'size', 'type'].
     *
     * @param array      $file
     * @param string     $module    префикс модуля (буквы, цифры, `_`, `-`, до 50)
     * @param int|null   $user_id   владелец; NULL — системный файл без пользователя
     * @param bool       $is_public
     * @return int ID файла
     * @throws Storage_Exception недопустимый префикс, prx212 не подключён или сбой
     */
    public static function save(array $file, string $module, ?int $user_id = NULL, bool $is_public = FALSE): int
    {
        return self::write('save', fn ($service) => (int) $service->save($file, [
            'module'    => $module,
            'user_id'   => $user_id,
            'is_public' => $is_public ? 1 : 0,
        ]));
    }

    /**
     * Сохранить строку как файл.
     *
     * @throws Storage_Exception
     */
    public static function put(string $content, string $filename, string $module, ?int $user_id = NULL, bool $is_public = FALSE, ?string $mime_type = NULL): int
    {
        return self::write('put', fn ($service) => (int) $service->saveRaw($content, $filename, [
            'module'    => $module,
            'user_id'   => $user_id,
            'is_public' => $is_public ? 1 : 0,
        ], $mime_type));
    }

    /**
     * Содержимое файла целиком. NULL — файла нет или он недоступен.
     * Права на объект не проверяет: file_id берётся из своей авторизованной записи.
     */
    public static function bytes(int $file_id): ?string
    {
        $bytes = self::read('bytes '.$file_id, fn ($service) => $service->read_bytes($file_id));
        return is_string($bytes) ? $bytes : NULL;
    }

    /**
     * Метаданные файла — только ключи META_KEYS (без storage_path, backend_id, user).
     * $user_id — инициатор запроса (NULL — гость); права на объект не проверяет.
     */
    public static function metadata(int $file_id, ?int $user_id = NULL): ?array
    {
        $meta = self::read('metadata '.$file_id, fn ($service) => $service->get_extended_metadata($file_id));
        if ( ! is_array($meta)) return NULL;

        $out = [];
        foreach (self::META_KEYS as $key)
        {
            $out[$key] = $meta[$key] ?? NULL;
        }
        $out['id'] = (int) $out['id'];
        $out['size'] = (int) $out['size'];
        $out['is_public'] = (bool) $out['is_public'];
        $out['url'] = is_string($out['url']) ? $out['url'] : NULL;

        return $out;
    }

    /**
     * Ссылка на файл на $ttl секунд; NULL — файла нет. Для приватного файла
     * ссылка открывает доступ по токену: вызывающий сам решил, что файл отдаётся.
     * $user_id — инициатор (NULL — гость); права на объект не проверяет.
     */
    public static function url(int $file_id, ?int $user_id = NULL, int $ttl = 3600): ?string
    {
        $url = self::read('url '.$file_id, fn ($service) => $service->generateUrl($file_id, $ttl));
        return is_string($url) ? $url : NULL;
    }

    /**
     * Удалить файл; FALSE — файла нет. Права файловые: владелец или (в DEVELOPMENT)
     * любой; гость — отказ. Права на объект проверяет вызывающий.
     *
     * @throws Storage_Exception доступ запрещён, prx212 не подключён или сбой
     */
    public static function delete(int $file_id, ?int $user_id = NULL): bool
    {
        return (bool) self::write('delete', fn ($service) => $service->delete($file_id, $user_id));
    }

    /**
     * Прочитать приватный артефакт внутреннего пайплайна: файл без пользователя,
     * не публичный, префикс $module. NULL — файла нет.
     */
    public static function read_owned(int $file_id, string $module, int $max_bytes): ?string
    {
        $bytes = self::read('read_owned '.$file_id, fn ($service) => $service->read_owned($file_id, $module, $max_bytes));
        return is_string($bytes) ? $bytes : NULL;
    }

    /**
     * Удалить свой приватный артефакт (см. read_owned); повтор безопасен, FALSE — файла нет.
     *
     * @throws Storage_Exception чужой артефакт, prx212 не подключён или сбой
     */
    public static function delete_owned(int $file_id, string $module): bool
    {
        return (bool) self::write('delete_owned', fn ($service) => $service->delete_owned($file_id, $module));
    }

    /**
     * ID своих приватных артефактов старше $hours часов — уборка после сбоев.
     * @return int[]
     */
    public static function owned_ids_before(string $module, int $hours, int $limit = 100): array
    {
        $ids = self::read('owned_ids_before '.$module, fn ($service) => $service->owned_file_ids_before($module, $hours, $limit));
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    // ------------------------------------------------------------------

    /** Сервис prx212 или NULL, если модуль не подключён. */
    private static function service(): ?object
    {
        if (self::$module_missing OR ! class_exists('Service_Prx212_Storage'))
        {
            return NULL;
        }
        return Service_Prx212_Storage::instance();
    }

    /** Запись: модуль не подключён или любая ошибка хранилища → Storage_Exception. */
    private static function write(string $operation, callable $fn): mixed
    {
        $service = self::service();
        if ($service === NULL)
        {
            throw new Storage_Exception('Файловое хранилище prx212 не подключено');
        }

        try
        {
            return $fn($service);
        }
        catch (Throwable $e)
        {
            throw new Storage_Exception('Storage::'.$operation.': '.$e->getMessage(), 0, $e);
        }
    }

    /** Чтение: модуль не подключён, файла нет или сбой → NULL; сбой — в журнал. */
    private static function read(string $context, callable $fn): mixed
    {
        $service = self::service();
        if ($service === NULL)
        {
            return NULL;
        }

        try
        {
            return $fn($service);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'Storage: :ctx — :type', [
                ':ctx' => $context, ':type' => get_class($e),
            ]);
            return NULL;
        }
    }
}
