<?php defined('SYSPATH') or die('No direct script access.');

/** Внутренние настройки prx597. Схему подготавливает публичный фасад Notify. */
final class Service_Prx597_Preferences extends Service_Prx597_Base
{
    /** Поля настроек пользователя и их умолчания (тип: bool | int | str). */
    const FIELDS = [
        'email_enabled'    => 'bool',
        'on_done'          => 'bool',
        'on_failed'        => 'bool',
        'in_app_enabled'   => 'bool',
        'telegram_enabled' => 'bool',
        'max_enabled'      => 'bool',
        'digest_mode'      => 'str',
        'quiet_from'       => 'int',
        'quiet_to'         => 'int',
        'tz'               => 'str',
        'locale'           => 'str',
    ];

    /**
     * Умолчания настроек: если человек ничего не настраивал.
     *
     * Владелец (16.10.2026): «По умолчанию у пользователей должны быть
     * отключены уведомления», и он же уточнил (выбор из вариантов): колокольчик
     * на сайте остаётся включённым. Поэтому наружу центр не шлёт НИЧЕГО, пока
     * человек сам не отметит нужное на /prx597?tab=settings, а лента на сайте
     * работает всегда — она не беспокоит и не требует адреса.
     *
     * Исключения, которые не «умолчания», а правила платформы: обязательные
     * события (безопасность, деньги) всегда оставляют след в колокольчике
     * (см. Registry::plan_for) и адресное сообщение администратора
     * (Notify::system_message) — оно уходит ровно получателю.
     */
    public static function defaults($user_id = 0)
    {
        return [
            'user_id'          => (int) $user_id,
            'email_enabled'    => FALSE,
            'on_done'          => FALSE,
            'on_failed'        => FALSE,
            'in_app_enabled'   => TRUE,
            'telegram_enabled' => FALSE,
            'max_enabled'      => FALSE,
            'digest_mode'      => (string) self::cfg('digest.default', 'off'),
            'quiet_from'       => -1,
            'quiet_to'         => -1,
            'tz'               => (string) self::cfg('quiet.tz', 'Europe/Moscow'),
            'locale'           => 'ru',
        ];
    }

    /** Настройки пользователя (defaults, если не менял). */
    public static function get($user_id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM prx_597_prefs WHERE user_id = '.(int) $user_id, FALSE)->current();

        if ( ! $row)
        {
            return self::defaults($user_id);
        }

        $row  = (array) $row;
        $out  = self::defaults((int) $row['user_id']);

        // PG отдаёт BOOLEAN строками 't'/'f' — приводим к настоящему bool,
        // иначе (bool)'f' === TRUE и проверки «выключено» работают наоборот.
        foreach (self::FIELDS as $field => $type)
        {
            if ( ! array_key_exists($field, $row))
            {
                continue;   // колонки нет (база старее миграции 002) — берём умолчание
            }

            $value = $row[$field];

            if ($type === 'bool')
            {
                $out[$field] = self::_truthy($value);
            }
            elseif ($type === 'int')
            {
                $out[$field] = (int) $value;
            }
            else
            {
                $out[$field] = (string) $value;
            }
        }

        return $out;
    }

    /** Поля пользовательской формы/API: скрытые каналы и отсутствующие поля не меняем. */
    public static function form_fields(array $p)
    {
        $channels = Service_Prx597_Base::user_channels();
        $fields = [];
        foreach (self::FIELDS as $field => $type)
        {
            if ( ! array_key_exists($field, $p)) { continue; }
            if (substr($field, -8) === '_enabled' AND ! isset($channels[substr($field, 0, -8)])) { continue; }
            $fields[$field] = $type === 'bool' ? self::_truthy($p[$field])
                : ($type === 'int' ? (int) $p[$field] : trim((string) $p[$field]));
        }
        return $fields;
    }

    /** Сохранить настройки (только известные ключи-булевы). Атомарный upsert. */
    public static function save($user_id, array $p)
    {
        $db = self::_db();

        $values = [];

        foreach (self::FIELDS as $field => $type)
        {
            if ( ! array_key_exists($field, $p))
            {
                continue;   // не пришло — не трогаем (форма могла выслать часть полей)
            }

            if ($type === 'bool')
            {
                $values[$field] = ! empty($p[$field]);
            }
            elseif ($type === 'int')
            {
                $values[$field] = (int) $p[$field];
            }
            else
            {
                $values[$field] = trim((string) $p[$field]);
            }
        }

        if ( ! $values)
        {
            return self::get($user_id);
        }

        // Режим дайджеста — из белого списка: что не знаем, то off.
        if (isset($values['digest_mode']) AND ! in_array($values['digest_mode'], ['off', 'hourly', 'daily'], TRUE))
        {
            $values['digest_mode'] = 'off';
        }

        $columns = array_keys($values);
        $insert  = [];
        $update  = [];

        foreach ($values as $field => $value)
        {
            $literal = is_bool($value) ? ($value ? 'TRUE' : 'FALSE')
                : (is_int($value) ? (string) $value : $db->quote((string) $value));

            $insert[] = $literal;
            $update[] = $field.' = EXCLUDED.'.$field;
        }

        // Один атомарный запрос вместо UPDATE-then-INSERT (гонки двух вкладок).
        // ВАЖНО: у этого драйвера query(Database::INSERT) возвращает массив —
        // никакого ->execute() поверх.
        $db->query(Database::INSERT,
            'INSERT INTO prx_597_prefs (user_id, '.implode(', ', $columns).', updated_at)
             VALUES ('.(int) $user_id.', '.implode(', ', $insert).', now())
             ON CONFLICT (user_id) DO UPDATE SET '.implode(', ', $update).', updated_at = now()', FALSE);

        $saved = self::get($user_id);

        foreach ($values as $key => $value)
        {
            $got    = $saved[$key] ?? NULL;
            $equals = is_bool($value) ? ((bool) $got === $value) : ((string) $got === (string) $value);

            if ( ! $equals)
            {
                Kohana::$log->add(Log::ERROR, 'prx597: настройки пользователя :u не подтвердились после записи (:k)', [':u' => (int) $user_id, ':k' => (string) $key]);
                throw new RuntimeException('Настройки уведомлений не подтвердились в базе данных');
            }
        }

        return $saved;
    }

    /**
     * Разовая уборка «включений по умолчанию».
     *
     * До 16.10.2026 внешние каналы были включены сами собой, поэтому TRUE в
     * настройках чаще всего означал не выбор человека, а значение по умолчанию
     * из формы. Правило узкое и безопасное: гасим строки только тех людей, у
     * кого НЕТ ни одной явной клетки матрицы (prx_597_subs) — тот, кто сам
     * что-то отмечал, неприкосновенен. Колокольчик (in_app) не трогаем: он и
     * должен быть включён всегда. Отметка в настройках закрывает уборку
     * навсегда: второй раз она не сработает и чужих решений не тронет.
     */
    public static function disable_legacy_defaults_once()
    {
        if (self::setting('prefs.defaults_off', '') === '1')
        {
            return 0;
        }

        $db = self::_db();

        try
        {
            $db->query(Database::UPDATE,
                'UPDATE "prx_597_prefs" p
                    SET "email_enabled" = FALSE, "telegram_enabled" = FALSE,
                        "max_enabled" = FALSE, "on_done" = FALSE, "on_failed" = FALSE,
                        "updated_at" = now()
                  WHERE ("email_enabled" IS TRUE OR "telegram_enabled" IS TRUE
                         OR "max_enabled" IS TRUE OR "on_done" IS TRUE OR "on_failed" IS TRUE)
                    AND NOT EXISTS (SELECT 1 FROM "prx_597_subs" s WHERE s."user_id" = p."user_id")',
                FALSE);   // драйвер отдаёт число, а не объект результата

            self::set_setting('prefs.defaults_off', '1');
        }
        catch (Throwable $e)
        {
            // Нет таблицы настроек или база старее правки — не мешаем работать.
            return 0;
        }

        return 0;
    }

}
