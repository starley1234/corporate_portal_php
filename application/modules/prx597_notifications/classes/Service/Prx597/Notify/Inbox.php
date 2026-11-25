<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Чтение ленты prx597: счётчик, архив, последние, отметки прочтения.
 *
 * Трейт Service_Prx597_Notify. Работает с prx_597_notifications и
 * prx_597_unread через _db(); побочные эффекты — UPDATE read_at/archived_at
 * и пересчёт счётчика. Публичные методы не менялись.
 */
trait Service_Prx597_Notify_Inbox
{
    /**
     * Счётчик непрочитанных.
     *
     * Держим его в prx_597_unread (триггер), а не считаем COUNT(*) на каждый
     * запрос страницы: колокольчик опрашивается каждые 45 секунд у каждого
     * открытого окна, и на большой ленте COUNT(*) упирается в диск. Если
     * строки счётчика нет (например, до миграции 002) — считаем честно и
     * заодно заводим строку.
     */
    public static function unread_count($user_id)
    {
        self::ensure_tables();
        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return 0;
        }

        try
        {
            $row = self::_one('SELECT "count" FROM "prx_597_unread" WHERE "user_id" = '.$user_id);

            if ($row)
            {
                return max(0, (int) (is_array($row) ? $row['count'] : $row->count));
            }
        }
        catch (Throwable $e)
        {
            // Таблицы счётчика нет — считаем по ленте ниже.
        }

        $count = (int) self::_val('SELECT count(*) AS c FROM prx_597_notifications
             WHERE user_id = '.$user_id.' AND read_at IS NULL AND archived_at IS NULL', 0);

        try
        {
            self::_exec('INSERT INTO "prx_597_unread" ("user_id", "count", "updated_at")
                 VALUES ('.$user_id.', '.$count.', now())
                 ON CONFLICT ("user_id") DO UPDATE SET "count" = EXCLUDED."count", "updated_at" = now()');
        }
        catch (Throwable $e)
        {
            // нет таблицы — просто отдаём посчитанное
        }

        return $count;
    }

    /** Пересчитать счётчик непрочитанных (после правок в обход центра). */
    public static function recount($user_id)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return 0;
        }

        $count = (int) self::_val('SELECT count(*) AS c FROM prx_597_notifications
             WHERE user_id = '.$user_id.' AND read_at IS NULL AND archived_at IS NULL', 0);

        self::_exec('INSERT INTO "prx_597_unread" ("user_id", "count", "updated_at")
             VALUES ('.$user_id.', '.$count.', now())
             ON CONFLICT ("user_id") DO UPDATE SET "count" = EXCLUDED."count", "updated_at" = now()');

        return $count;
    }

    /** Убрать прочитанное в архив (не удаляем: история нужна). */
    public static function archive($user_id, array $ids = NULL)
    {
        self::ensure_tables();

        if ($ids === NULL)
        {
            self::_exec('UPDATE "prx_597_notifications" SET "archived_at" = now()
                  WHERE "user_id" = '.((int) $user_id).' AND "read_at" IS NOT NULL AND "archived_at" IS NULL');

            return self::recount((int) $user_id);
        }

        $ids = array_filter(array_map('intval', $ids));

        if ($ids)
        {
            self::_exec('UPDATE "prx_597_notifications" SET "archived_at" = now()
                  WHERE "user_id" = '.((int) $user_id).' AND "read_at" IS NOT NULL AND "id" IN ('.implode(',', $ids).')');
        }

        return self::recount((int) $user_id);
    }

    /**
     * Лента уведомлений (колокольчик и страница).
     * Архив по умолчанию не показываем — он не удалён, но из виду убран.
     */
    public static function latest($user_id, $limit = 8, $with_archived = FALSE)
    {
        self::ensure_tables();
        $rows = self::_all('SELECT * FROM prx_597_notifications WHERE user_id = '.(int) $user_id
            .($with_archived ? '' : ' AND archived_at IS NULL')
            .' ORDER BY id DESC LIMIT '.(int) $limit);

        foreach ($rows as &$row)
        {
            // Исправляет также старые строки, созданные до проверки route-id.
            $row['href']      = self::_safe_href((string) $row['href']);
            $row['event_alias'] = (string) Arr::get((array) $row, 'event_alias', 'system');
            $row['category']  = (string) Arr::get((array) $row, 'category', 'system');
            $row['icon']      = (string) Arr::get((array) $row, 'icon', 'fas fa-bell');
            $row['priority']  = (int) Arr::get((array) $row, 'priority', 5);
            $row['archived']  = trim((string) Arr::get((array) $row, 'archived_at', '')) !== '';
        }
        unset($row);

        return $rows;
    }

    /**
     * Разрешённая внутренняя ссылка уведомления: наружу не пускаем, мусор не
     * подставляем. Публичная обёртка — каналы доставки и вьюхи зовут именно её.
     */
    public static function safe_href($href)
    {
        return self::_safe_href($href);
    }

    /** Разрешённая внутренняя ссылка уведомления без произвольного текста. */
    protected static function _safe_href($href)
    {
        $href = trim((string) $href);

        if (preg_match('~^/(tasks|docs|company|jobs|3d|trends|review|dossier|content)(?:/([a-z0-9]{3,64}))?/?$~i', $href, $m))
        {
            return '/'.strtolower($m[1]).(!empty($m[2]) ? '/'.strtolower($m[2]) : '');
        }

        return '/prx597';
    }

    /** Отметить прочитанным: NULL — все, иначе список id. */
    public static function mark_read($user_id, array $ids = NULL)
    {
        self::ensure_tables();

        if ($ids === NULL)
        {
            self::_exec('UPDATE prx_597_notifications SET read_at = now()
                 WHERE user_id = '.(int) $user_id.' AND read_at IS NULL');
            self::recount((int) $user_id);

            return;
        }

        $ids = array_filter(array_map('intval', $ids));

        if ($ids)
        {
            self::_exec('UPDATE prx_597_notifications SET read_at = now()
                 WHERE user_id = '.(int) $user_id.' AND id IN ('.implode(',', $ids).')');
        }
    }

    /** Удалить прочитанные (кнопка «очистить»). Возвращает сколько. */
    public static function clear_read($user_id)
    {
        self::ensure_tables();
        $c = (int) self::_val('WITH d AS (DELETE FROM prx_597_notifications
                WHERE user_id = '.(int) $user_id.' AND read_at IS NOT NULL RETURNING 1)
             SELECT count(*) AS c FROM d', 0);

        return $c;
    }
}
