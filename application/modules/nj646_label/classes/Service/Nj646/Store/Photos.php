<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Photos`: кадры прогонов: сохранение файлом, чтение для воркера, чистка.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Photos
{
    //
    // ПОЧЕМУ ФОТО ЖИВЁТ ФАЙЛОМ, А НЕ В PAYLOAD ЗАДАЧИ. Раньше base64 кадра
    // (8 МБ → ~10,7 МБ) ехал внутри payload задачи: строка ложилась в jsonb
    // очереди, её тянул каждый опрос воркера, а мёртвая задача с фото навсегда
    // оставалась в базе. Очередь nj120 — ОБЩАЯ на весь проект, и один
    // «Разбор состава» бил по всем модулям. Воркер работает по HTTPS с другого
    // хоста, поэтому путь к файлу ему не поможет: он забирает кадр отдельным
    // запросом (Api::action_photo_file, тот же X-Worker-Token).
    //
    // Каталог — application/cache/nj646_photo (в .gitignore, не публичный).
    // Имя файла — только id прогона: в путь не попадает ничего из запроса.

    /** Каталог приватных фото прогонов. */
    public static function photo_dir()
    {
        $dir = APPPATH.'cache/nj646_photo';

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        return $dir;
    }

    /**
     * Положить кадр прогона на диск. Возвращает расширение файла или '' (не смогли).
     *
     * Расширение берём из сигнатуры: воркеру важно, что это image/jpeg|png|webp,
     * а не то, как файл назвал браузер.
     */
    public static function photo_save($run_id, $bytes)
    {
        $run_id = (int) $run_id;

        if ($run_id <= 0 OR $bytes === '')
        {
            return '';
        }

        $ext = 'jpg';

        if (strncmp($bytes, "\x89PNG", 4) === 0)
        {
            $ext = 'png';
        }
        elseif (strncmp($bytes, 'RIFF', 4) === 0 AND substr($bytes, 8, 4) === 'WEBP')
        {
            $ext = 'webp';
        }

        self::photo_drop($run_id);

        return @file_put_contents(self::photo_dir().'/'.$run_id.'.'.$ext, $bytes) !== FALSE ? $ext : '';
    }

    /** Кадр прогона: [байты, mime] или [NULL, ''] — файла нет. */
    public static function photo_bytes($run_id)
    {
        $run_id = (int) $run_id;
        $found  = glob(self::photo_dir().'/'.$run_id.'.*');

        if ($run_id <= 0 OR ! $found)
        {
            return [NULL, ''];
        }

        $bytes = @file_get_contents($found[0]);

        if ($bytes === FALSE OR $bytes === '')
        {
            return [NULL, ''];
        }

        $ext = strtolower((string) pathinfo($found[0], PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';

        return [$bytes, $mime];
    }

    /** Убрать кадр прогона: результат уже на сайте, само фото больше не нужно. */
    public static function photo_drop($run_id)
    {
        $run_id = (int) $run_id;

        if ($run_id <= 0)
        {
            return;
        }

        foreach ((array) glob(self::photo_dir().'/'.$run_id.'.*') as $file)
        {
            @unlink($file);
        }
    }

    /**
     * Подчистить давние кадры: задача могла умереть вместе с воркером.
     *
     * Зовётся при приёме нового фото (не по крону: лишняя ручка на каждый час
     * ради файлов, которых обычно нет, — это работа впустую). Работа ограничена
     * `$limit` файлами, чтобы приём фото не ждал каталог в тысячи записей.
     */
    public static function photo_gc($max_age_sec = 21600, $limit = 40)
    {
        $dir  = self::photo_dir();
        $gone = 0;

        foreach ((array) glob($dir.'/*') as $file)
        {
            if ($gone >= $limit)
            {
                break;
            }

            if (is_file($file) AND (time() - (int) @filemtime($file)) > (int) $max_age_sec)
            {
                @unlink($file);
                $gone++;
            }
        }

        return $gone;
    }

    public static function runs_last_hour($client_ip, $mode = 'photo')
    {
        self::ensure_tables();

        $row = self::_one('SELECT COUNT(*) AS c FROM nj_646_runs
                           WHERE client_ip = '.self::db()->quote((string) $client_ip).'
                             AND mode = '.self::db()->quote((string) $mode).'
                             AND created_at > now() - interval \'1 hour\'');

        return (int) ($row['c'] ?? 0);
    }
}
