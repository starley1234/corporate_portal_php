<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис уведомлений (prx597_notifications).
 *
 * Единый центр: события платформы → колокольчик в шапке + страница /prx597
 * + письмо через symfony/mailer (новая почтовая библиотека composer).
 *
 *  * notify() — создать уведомление (+письмо по настройкам пользователя);
 *  * job_update() — хук завершения задачи очереди prx120 (готово/ошибка);
 *  * настройка пользователя — prx_597_prefs (письма, типы событий);
 *  * управление — статистика, тест письма, повтор неудавшихся писем.
 *
 * Env: EMAIL_DSN (smtp://логин:пароль@хост:порт | sendmail://default |
 * null://null — письма выключены), EMAIL_FROM, EMAIL_FROM_NAME,
 * PRX597_EMAIL_ENABLED=0 — письма не отправлять вовсе (только колокольчик).
 */
class Service_Prx597_Notify extends Service_Prx597_Base
{
    protected static $_tables_ensured = FALSE;

    /** Почему получателей не осталось: пишется в результат emit. */
    protected static $_recipients_note = '';

    /** DDL (миграция 001 == ensure_tables()). */
    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        // Частые вызовы (колокольчик раз в 45с у каждого окна) не должны долбить DDL:
        // кэш на 5 минут держит схему "уже проверена" без блокировок БД.
        try
        {
            if (class_exists('Kohana') AND Kohana::$caching)
            {
                $hit = Kohana::cache('prx597.ensure_ok');
                if ($hit !== NULL)
                {
                    return;
                }
            }
        }
        catch (Throwable $e) {}

        // Полная схема центра (лента, настройки, реестр событий, шаблоны,
        // привязки, очередь доставки, журнал, счётчики) — одним вызовом,
        // из единственного источника DDL. Ошибку не прячем: без таблиц центр
        // молчит, а администратор должен об этом узнать.
        if (class_exists('Service_Prx597_Schema'))
        {
            $errors = Service_Prx597_Schema::ensure();

            if ($errors)
            {
                // Взаимоблокировка DDL — штатная ситуация при параллельных пинках
                $msg = implode('; ', array_slice($errors, 0, 3));
                $is_deadlock = (stripos($msg, 'взаимоблокировка') !== FALSE OR stripos($msg, 'deadlock') !== FALSE);
                $level = $is_deadlock ? Log::INFO : Log::ERROR;
                // Не шумим ERROR на каждый параллельный запрос — владелец получает 3 записи за 20 минут
                Kohana::$log->add($level, 'prx597: схема не поднялась: :e',
                    [':e' => $msg]);
                if ($is_deadlock)
                {
                    // Взаимоблокировка — не фатал: таблицы уже есть, просто другой процесс делал DDL
                    try { if (class_exists('Kohana') AND Kohana::$caching) Kohana::cache('prx597.ensure_ok', 1, 300); } catch (Throwable $e) {}
                    return;
                }
            }
            else
            {
                try { if (class_exists('Kohana') AND Kohana::$caching) Kohana::cache('prx597.ensure_ok', 1, 300); } catch (Throwable $e) {}
            }

            self::_defaults_off_once();

            // Разовая правка: событиям, которые модули завели сами, включаем
            // склейку в сводку — иначе режим «раз в сутки» у человека ничего
            // не менял (см. Registry::apply_digest_default_once).
            if (class_exists('Service_Prx597_Registry'))
            {
                Service_Prx597_Registry::apply_digest_default_once();
            }

            return;
        }

        try
        {
            DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "prx_597_notifications" (
    "id"           BIGSERIAL PRIMARY KEY,
    "user_id"      BIGINT       NOT NULL,
    "kind"         VARCHAR(32)  NOT NULL DEFAULT 'system',
    "title"        VARCHAR(300) NOT NULL DEFAULT '',
    "body"         TEXT         NOT NULL DEFAULT '',
    "href"         VARCHAR(300) NOT NULL DEFAULT '',
    "email_to"     VARCHAR(200) NOT NULL DEFAULT '',
    "email_status" VARCHAR(16)  NOT NULL DEFAULT 'none',
    "email_error"  TEXT         NULL,
    "read_at"      TIMESTAMPTZ  NULL,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "prx_597_notifications_user_idx" ON "prx_597_notifications" ("user_id", "read_at", "id" DESC);
CREATE INDEX IF NOT EXISTS "prx_597_notifications_email_idx" ON "prx_597_notifications" ("email_status", "id" DESC);
COMMENT ON TABLE  "prx_597_notifications" IS 'PRX-597 уведомления: job_done|job_failed|system|test; email none|pending|sent|failed';
COMMENT ON COLUMN "prx_597_notifications"."email_status" IS 'none — письмо не требовалось; pending/failed/sent — попытка письма';

CREATE TABLE IF NOT EXISTS "prx_597_prefs" (
    "user_id"       BIGINT PRIMARY KEY,
    "email_enabled" BOOLEAN      NOT NULL DEFAULT TRUE,
    "on_done"       BOOLEAN      NOT NULL DEFAULT TRUE,
    "on_failed"     BOOLEAN      NOT NULL DEFAULT TRUE,
    "updated_at"    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE "prx_597_prefs" IS 'PRX-597 настройки уведомлений пользователя';
SQL
            )->execute();
        }
        catch (Throwable $e)
        {
            self::$_tables_ensured = FALSE;
            Kohana::$log->add(Log::WARNING, 'prx597: ensure_tables: :e', [':e' => $e->getMessage()]);
        }
    }

    protected static function _db()
    {
        return Database::instance();
    }

    /**
     * OUTBOX-доставка (21.09): python-воркеры в режиме pg завершают задачи
     * ПРЯМО в БД, мимо PHP JobQueue::complete() — хук уведомлений там мёртв.
     * Поэтому: сгребаем завершённые задачи без маркера notified_at и шлём
     * уведомления. Вызывается из «Пиналки» (каждые 10 минут), Minion-задачи
     * Prx120:NotifyOutbox и страницы «Мои задачи».
     */
    /**
     * PostgreSQL возвращает BOOLEAN строками 't'/'f' — (bool)'f' в PHP это
     * TRUE (непустая строка!). Все булевы значения из БД прогоняем тут.
     */
    protected static function _bool($v): bool
    {
        return $v === TRUE OR $v === 't' OR $v === 'true' OR $v === 1 OR $v === '1';
    }

    public static function sweep_jobs($limit = 40)
    {
        try
        {
            // маркер доставки живёт в prx_120_jobs (ленивая миграция, идемпотентно)
            DB::query(NULL, 'ALTER TABLE "prx_120_jobs" ADD COLUMN IF NOT EXISTS "notified_at" TIMESTAMPTZ NULL')->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx597: sweep alter: :e', [':e' => $e->getMessage()]);

            return 0;
        }

        $db = self::_db();
        $rows = $db->query(Database::SELECT,
            "SELECT id, channel, status, payload, error, created_by, visitor_id
             FROM prx_120_jobs
             WHERE notified_at IS NULL AND status IN ('done', 'failed')
               AND (created_by IS NOT NULL OR visitor_id LIKE 'u:%')
             ORDER BY id DESC LIMIT ".(int) $limit, FALSE)->as_array();

        $sent = 0;

        foreach ($rows as $r)
        {
            $r = (array) $r;

            try
            {
                self::job_update($r, (string) $r['status']);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'prx597: sweep job :j: :e', [':j' => (int) $r['id'], ':e' => $e->getMessage()]);
            }

            try
            {
                $db->query(Database::INSERT,
                    'UPDATE prx_120_jobs SET notified_at = now() WHERE id = '.(int) $r['id'], FALSE);
                $sent++;
            }
            catch (Throwable $e)
            {
                break; // маркер не встал — не зацикливаемся
            }
        }

        if ($sent > 0)
        {
            Kohana::$log->add(Log::INFO, 'prx597: sweep: обработано задач: :n', [':n' => $sent]);
        }

        return $sent;
    }

    // --------------------------------------------------------------- prefs

    /** Поля настроек пользователя и их умолчания (тип: bool | int | str). */
    const PREF_FIELDS = [
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
    public static function prefs_default($user_id = 0)
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
    public static function prefs($user_id)
    {
        self::ensure_tables();
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM prx_597_prefs WHERE user_id = '.(int) $user_id, FALSE)->current();

        if ( ! $row)
        {
            return self::prefs_default($user_id);
        }

        $row  = (array) $row;
        $out  = self::prefs_default((int) $row['user_id']);

        // PG отдаёт BOOLEAN строками 't'/'f' — приводим к настоящему bool,
        // иначе (bool)'f' === TRUE и проверки «выключено» работают наоборот.
        foreach (self::PREF_FIELDS as $field => $type)
        {
            if ( ! array_key_exists($field, $row))
            {
                continue;   // колонки нет (база старее миграции 002) — берём умолчание
            }

            $value = $row[$field];

            if ($type === 'bool')
            {
                $out[$field] = self::_bool($value);
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
    public static function user_pref_fields(array $p)
    {
        $channels = Service_Prx597_Base::user_channels();
        $fields = [];
        foreach (self::PREF_FIELDS as $field => $type)
        {
            if ( ! array_key_exists($field, $p)) { continue; }
            if (substr($field, -8) === '_enabled' AND ! isset($channels[substr($field, 0, -8)])) { continue; }
            $fields[$field] = $type === 'bool' ? self::_bool($p[$field])
                : ($type === 'int' ? (int) $p[$field] : trim((string) $p[$field]));
        }
        return $fields;
    }

    /** Сохранить настройки (только известные ключи-булевы). Атомарный upsert. */
    public static function save_prefs($user_id, array $p)
    {
        self::ensure_tables();
        $db = self::_db();

        $values = [];

        foreach (self::PREF_FIELDS as $field => $type)
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
            return self::prefs($user_id);
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

        $saved = self::prefs($user_id);

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

    // -------------------------------------------------------------- notify

    /**
     * Заявить событие — главная точка входа для модулей.
     *
     *   Service_Prx597_Notify::emit('prx580.documents.ready', [
     *       'user_id' => $owner,
     *       'title'   => 'Пакет документов готов',
     *       'body'    => 'Файлов: 12, страниц: 84',
     *       'href'    => '/docs/'.$code,
     *       'data'    => ['code' => $code, 'pages' => 84],
     *   ]);
     *
     * Модуль не знает, есть ли у пользователя Telegram, какой у него план и не
     * спит ли он: это решает центр. Он же решает, писать ли строку в колокольчик
     * и ставить ли сообщения в очередь доставки.
     *
     * Ключи payload:
     *   user_id | user_ids — получатели (обязательно хотя бы один);
     *   title, body, href  — готовый текст (если шаблона нет);
     *   data               — подстановки для шаблона {{ключ}};
     *   priority           — 1 критично … 9 фоновое;
     *   dedup_key          — одинаковые события с одним ключом склеиваются;
     *   group_key          — склейка строки в колокольчике («Иван и ещё 14…»);
     *   initiator_id       — кто вызвал (по умолчанию Auth-пользователь),
     *                        его из получателей вычитаем;
     *   self               — TRUE: событие про действие самого получателя
     *                        (готовый обзор, собранная модель, выполненная
     *                        его задача). Если человек при этом на сайте —
     *                        колокольчик молчит, а признак едет дальше в
     *                        очередь: письмо тоже не уйдёт тому, кто зашёл
     *                        на сайт уже после постановки (Engine проверяет
     *                        присутствие в момент отправки).
     *                        Ставить только там, где событие — успешный итог
     *                        заказа человека: сбой и чужое событие будим всегда;
     *   channels           — явный список каналов (мимо настроек пользователя).
     *
     * @param  string $event_alias код события из реестра (объявляется в манифесте)
     * @param  array  $payload     данные события
     * @param  array  $options     служебное: event (для доучивания реестра),
     *                             force_self, force_channels, initiator_id, self
     * @return array ['ok', 'inbox' => n, 'outbox' => n, 'inbox_id' => id,
     *                'skipped' => [причины]]
     */
    public static function emit($event_alias, array $payload = [], array $options = [])
    {
        self::ensure_tables();

        $alias = self::_event_alias($event_alias);

        if ($alias === '')
        {
            return ['ok' => FALSE, 'error' => 'Пустой код события', 'inbox' => 0, 'outbox' => 0];
        }

        // Реестр доучивается на ходу: событие, о котором никто не объявил,
        // не должно потеряться (администратор увидит его в управлении).
        $event = Service_Prx597_Registry::event($alias);

        if ($event === NULL)
        {
            $defaults = (array) Arr::get($options, 'event', []);
            $defaults['title'] = (string) Arr::get($defaults, 'title', Arr::get($payload, 'title', $alias));
            $event = Service_Prx597_Registry::ensure($alias, $defaults, 'runtime');
        }

        if (empty($event['enabled']))
        {
            return ['ok' => FALSE, 'error' => 'Событие отключено администратором', 'inbox' => 0, 'outbox' => 0, 'skipped' => ['disabled']];
        }

        // «Своё» считаем один раз: этот же признак поедет в очередь, чтобы
        // движок повторил проверку перед отправкой наружу (человек мог зайти
        // на сайт уже после того, как событие встало в очередь).
        $self = self::_self_flag($payload, $options);

        $recipients = self::_recipients($payload, $options, $alias);

        if ( ! $recipients)
        {
            $note = self::$_recipients_note;

            // Штатная тишина: «себе не пишем» и «человек на сайте, он смотрит
            // результат». Событие обработано, отправлять было нечего — это не
            // ошибка модуля, поэтому ok = TRUE и причина в skipped.
            if ($note !== '')
            {
                return ['ok' => TRUE, 'inbox' => 0, 'outbox' => 0, 'inbox_id' => 0, 'skipped' => [$note]];
            }

            return ['ok' => FALSE, 'error' => 'Не указан получатель', 'inbox' => 0, 'outbox' => 0, 'skipped' => ['no_recipient']];
        }

        $data     = self::_template_data($payload);
        $title    = (string) Arr::get($payload, 'title', Arr::get($options, 'title', $event['title']));
        $body     = (string) Arr::get($payload, 'body', Arr::get($options, 'body', ''));
        $href     = self::_safe_href((string) Arr::get($payload, 'href', Arr::get($options, 'href', '/prx597')));
        // Важность: названную модулем не трогаем, иначе берём из категории
        // события (безопасность — 1, лайки — 7). См. Registry::priority_for.
        $explicit = Arr::get($payload, 'priority', Arr::get($options, 'priority', NULL));
        $priority = Service_Prx597_Registry::priority_for((array) $event, $explicit);

        $group_key = trim((string) Arr::get($payload, 'group_key', Arr::get($options, 'group_key', '')));

        $wanted = Arr::get($payload, 'channels', Arr::get($options, 'channels', NULL));

        if ($wanted !== NULL)
        {
            $wanted = is_array($wanted) ? $wanted : self::_csv($wanted);
        }

        $result = [
            'ok'       => TRUE,
            'inbox'    => 0,
            'outbox'   => 0,
            'inbox_id' => 0,
            'skipped'  => [],
        ];

        foreach ($recipients as $user_id)
        {
            $user_id = (int) $user_id;

            if ($user_id <= 0)
            {
                continue;
            }

            $prefs = self::prefs($user_id);

            if ($wanted === NULL)
            {
                $plan = Service_Prx597_Registry::plan_for($user_id, $event, $prefs);
            }
            else
            {
                // Модуль назвал каналы сам — это всё равно не разрешение
                // слать: выключатели человека («по умолчанию всё выключено»,
                // владелец 16.10.2026) сильнее пожелания модуля.
                $plan = array_values(array_filter((array) $wanted, function ($channel) use ($prefs) {
                    return Service_Prx597_Registry::channel_enabled($channel)
                        AND self::_truthy(Arr::get($prefs, $channel.'_enabled', FALSE));
                }));
            }

            if ( ! $plan)
            {
                $result['skipped'][] = 'нет каналов у пользователя '.$user_id;
                continue;
            }

            // --- колокольчик: показываем сразу, не дожидаясь движка ----------
            $inbox_id = 0;

            if (in_array('in_app', $plan, TRUE))
            {
                $inbox_id = self::inbox_add($user_id, [
                    'event_alias' => $alias,
                    'title'       => $title,
                    'body'        => $body,
                    'href'        => $href,
                    'category'    => $event['category'],
                    'icon'        => $event['icon'],
                    'priority'    => $priority,
                    'group_key'   => $group_key,
                    'data'        => $data,
                ]);

                $result['inbox']++;
                $result['inbox_id'] = $result['inbox_id'] ?: $inbox_id;
            }

            // --- внешние каналы: ставим в очередь доставки -------------------
            $dedup = ($group_key === '' AND $alias !== '')
                ? Service_Prx597_Limits::dedup_key($alias, $payload, (string) Arr::get($payload, 'dedup_key', ''))
                : '';

            foreach ($plan as $channel)
            {
                if ($channel === 'in_app')
                {
                    continue;
                }

                $out = self::_enqueue($user_id, $channel, [
                    'event_alias' => $alias,
                    'subject'     => $title,
                    'body'        => $body,
                    'href'        => $href,
                    'priority'    => $priority,
                    'data'        => $data,
                    'inbox_id'    => $inbox_id,
                    'dedup_key'   => $dedup,
                    'group_key'   => $group_key,
                    'self'        => $self,
                    'prefs'       => $prefs,
                    'event'       => $event,
                ]);

                if ($out['queued'])
                {
                    $result['outbox']++;
                }
                elseif ($out['reason'] !== '')
                {
                    $result['skipped'][] = $channel.': '.$out['reason'];
                }
            }
        }

        return $result;
    }

    /** Уникальность доставки: не даём событию одного получателя подавить другого. */
    protected static function _delivery_dedup($user_id, $channel, $key)
    {
        $key = trim((string) $key);
        return $key === '' ? '' : 'v2:'.hash('sha256', (int) $user_id."\0".$channel."\0".$key);
    }

    /**
     * Поставить сообщение в очередь доставки (outbox).
     *
     * Дедупликация — на уровне базы: уникальный индекс по dedup_key, поэтому
     * два одинаковых события (модуль дёрнул дважды, повтор вебхука…) не
     * превращаются в два письма.
     *
     * @return array ['queued' => bool, 'reason' => string, 'id' => int]
     */
    protected static function _enqueue($user_id, $channel, array $message)
    {
        $user_id = (int) $user_id;
        $channel = (string) $channel;
        $driver  = Service_Prx597_Base::channel_driver($channel);

        if ( ! class_exists($driver) OR ! call_user_func([$driver, 'enabled']))
        {
            return ['queued' => FALSE, 'reason' => 'канал недоступен', 'id' => 0];
        }

        $destination = call_user_func([$driver, 'destination'], $user_id);

        if ($destination === '')
        {
            return ['queued' => FALSE, 'reason' => 'нет привязки', 'id' => 0];
        }

        $prefs  = (array) Arr::get($message, 'prefs', []);
        $event  = (array) Arr::get($message, 'event', []);
        $data   = (array) Arr::get($message, 'data', []);

        $priority = (int) Arr::get($message, 'priority', 5);

        // Дайджест: событие не летит мгновенно, а ждёт своей сводки.
        $deadline = Service_Prx597_Limits::digest_deadline($prefs, $event, $priority);
        $group    = '';

        if ($deadline > 0)
        {
            $group = 'digest:'.$channel.':'.$deadline;
        }

        $db = self::_db();

        $payload = ['data' => $data];

        // Признак «своё событие» едет вместе со строкой очереди: между
        // постановкой в очередь и отправкой проходит время, и человек мог
        // зайти на сайт уже после неё. Проверку повторит движок
        // (Engine::_self_and_online) — и не станет слать письмо-отчёт о том,
        // что человек видит в колокольчике.
        if (self::_truthy(Arr::get($message, 'self', FALSE)))
        {
            $payload['self'] = TRUE;
        }

        $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $dedup = self::_delivery_dedup($user_id, $channel, Arr::get($message, 'dedup_key', ''));

        try
        {
            $row = $db->query(Database::SELECT,
                'INSERT INTO "prx_597_outbox"
                     ("user_id", "event_alias", "channel", "destination", "subject", "body", "href",
                      "payload", "priority", "status", "next_attempt_at", "dedup_key", "group_key", "inbox_id", "created_at")
                 VALUES ('.$user_id.',
                         '.$db->quote(self::_cut((string) Arr::get($message, 'event_alias', 'system'), 120)).',
                         '.$db->quote(self::_cut($channel, 20)).',
                         '.$db->quote(self::_cut($destination, 200)).',
                         '.$db->quote(self::_cut((string) Arr::get($message, 'subject', ''), 300)).',
                         '.$db->quote(self::_cut((string) Arr::get($message, 'body', ''), 4000)).',
                         '.$db->quote(self::_cut((string) Arr::get($message, 'href', ''), 300)).',
                         '.$db->quote($payload).'::jsonb,
                         '.$priority.',
                         \'pending\',
                         '.($deadline > 0 ? 'to_timestamp('.(int) $deadline.')' : 'now()').',
                         '.($dedup === '' ? 'NULL' : $db->quote($dedup)).',
                         '.($group === '' ? 'NULL' : $db->quote(self::_cut($group, 160))).',
                         '.((int) Arr::get($message, 'inbox_id', 0) ?: 'NULL').',
                         now())
                 ON CONFLICT DO NOTHING
                 RETURNING "id"', FALSE)->current();
        }
        catch (Throwable $e)
        {
            self::_log(Log::ERROR, 'не удалось поставить сообщение в очередь: :e', [':e' => $e->getMessage()]);

            return ['queued' => FALSE, 'reason' => 'ошибка записи', 'id' => 0];
        }

        $id = (int) Arr::get((array) $row, 'id', 0);

        if ($id <= 0)
        {
            // Сработала дедупликация или привязка отвалилась — молчим, это не ошибка.
            return ['queued' => FALSE, 'reason' => '', 'id' => 0, 'deduped' => TRUE];
        }

        // Строка колокольчика помечает, что письмо запланировано: в управлении
        // видно, что событие не «проигнорировано», а ждёт отправки.
        if ($channel === 'email' AND (int) Arr::get($message, 'inbox_id', 0) > 0)
        {
            $db->query(Database::UPDATE,
                'UPDATE "prx_597_notifications" SET "email_status" = \'pending\', "email_to" = '.$db->quote($destination).'
                  WHERE "id" = '.((int) $message['inbox_id']), FALSE);
        }

        return ['queued' => TRUE, 'reason' => '', 'id' => $id];
    }

    /**
     * Адресное сообщение от системы. Вызывает только админ-контроллер после
     * проверки prx597.admin и CSRF. Не создаёт внешнюю очередь и не зависит
     * от пользовательских подписок. Автор-администратор остаётся в аудите.
     */
    public static function system_message($recipient, $title, $body, $admin_id)
    {
        $recipient = trim((string) $recipient);
        $title = trim((string) $title);
        $body = trim((string) $body);
        $admin_id = (int) $admin_id;
        $user_id = 0;
        try
        {
            if ($admin_id <= 0 OR $recipient === '' OR mb_strlen($recipient) > 254)
            {
                throw new InvalidArgumentException('Укажите ID или точный email пользователя');
            }
            if ($title === '' OR mb_strlen($title) > 180 OR $body === '' OR mb_strlen($body) > 2000)
            {
                throw new InvalidArgumentException('Нужны заголовок (до 180 символов) и текст (до 2000 символов)');
            }
            if (ctype_digit($recipient))
            {
                $id = filter_var($recipient, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === FALSE) { throw new InvalidArgumentException('Некорректный ID пользователя'); }
                $user = ORM::factory('User', $id);
            }
            else
            {
                if ( ! filter_var($recipient, FILTER_VALIDATE_EMAIL))
                {
                    throw new InvalidArgumentException('Укажите ID или точный email пользователя');
                }
                $user = ORM::factory('User')->where('email', '=', $recipient)->find();
            }
            if ( ! $user->loaded()) { throw new InvalidArgumentException('Пользователь не найден'); }
            $user_id = (int) $user->id;
            $id = self::inbox_add($user_id, [
                'event_alias' => 'system.admin_message',
                'title' => $title,
                'body' => $body,
                'href' => '/prx597',
                'category' => 'system',
                'icon' => 'fas fa-bullhorn',
                'data' => ['sender' => 'system', 'admin_id' => $admin_id],
            ]);
            if ($id <= 0) { throw new RuntimeException('База не вернула ID уведомления'); }
            self::_log(Log::INFO, 'сообщение от системы: admin=:admin user=:user notification=:id',
                [':admin' => $admin_id, ':user' => $user_id, ':id' => $id]);
            return ['ok' => TRUE, 'user_id' => $user_id, 'id' => $id];
        }
        catch (InvalidArgumentException $e)
        {
            self::_log(Log::WARNING, 'отказ системного сообщения: admin=:admin, :reason',
                [':admin' => $admin_id, ':reason' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
        catch (Throwable $e)
        {
            self::_log(Log::ERROR, 'системное сообщение не сохранено: admin=:admin user=:user, :error',
                [':admin' => $admin_id, ':user' => $user_id, ':error' => get_class($e)]);
            return ['error' => 'Не удалось сохранить сообщение. Проверьте базу данных и журнал сайта'];
        }
    }

    /**
     * Создать строку в ленте «колокольчика».
     *
     * Склейка: если у события есть group_key и у пользователя уже есть
     * непрочитанная строка с тем же ключом — обновляем её (и считаем повторы в
     * data.count), а не плодим новые: «Иван и ещё 14 оценили ваш пост».
     *
     * @param  int   $user_id
     * @param  array $data event_alias, title, body, href, category, icon,
     *                     priority, group_key, data, email_status
     * @return int id строки (0 — не вышло)
     */
    public static function inbox_add($user_id, array $data)
    {
        self::ensure_tables();

        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return 0;
        }

        $title = trim((string) Arr::get($data, 'title', ''));

        if ($title === '')
        {
            return 0;
        }

        $db      = self::_db();
        $group   = trim((string) Arr::get($data, 'group_key', ''));
        $payload = json_encode((array) Arr::get($data, 'data', []), JSON_UNESCAPED_UNICODE);

        // Склейка: одна строка на группу, пока её не прочитали.
        if ($group !== '')
        {
            $exists = $db->query(Database::SELECT,
                'SELECT "id", "data" FROM "prx_597_notifications"
                  WHERE "user_id" = '.$user_id.' AND "group_key" = '.$db->quote(self::_cut($group, 160)).'
                    AND "read_at" IS NULL AND "archived_at" IS NULL
                  ORDER BY "id" DESC LIMIT 1', FALSE)->current();

            if ($exists)
            {
                $old   = json_decode((string) Arr::get((array) $exists, 'data', '{}'), TRUE);
                $count = (int) Arr::get((array) $old, 'count', 1) + 1;

                $merged = array_merge((array) $old, [
                    'count' => $count,
                    'last'  => Arr::get($data, 'data', []),
                ]);

                $db->query(Database::UPDATE,
                    'UPDATE "prx_597_notifications" SET
                         "title" = '.$db->quote(self::_cut($title, 300)).',
                         "body"  = '.$db->quote(self::_cut((string) Arr::get($data, 'body', ''), 2000)).',
                         "href"  = '.$db->quote(self::_cut((string) Arr::get($data, 'href', ''), 300)).',
                         "data"  = '.$db->quote(json_encode($merged, JSON_UNESCAPED_UNICODE)).'::jsonb,
                         "created_at" = now()
                      WHERE "id" = '.((int) $exists['id']), FALSE);

                return (int) $exists['id'];
            }
        }

        $row = $db->query(Database::SELECT,
            'INSERT INTO "prx_597_notifications"
                 ("user_id", "kind", "event_alias", "category", "icon", "title", "body", "href",
                  "priority", "data", "group_key", "email_status", "created_at")
             VALUES ('.$user_id.',
                     '.$db->quote(self::_cut((string) Arr::get($data, 'kind', 'system'), 32)).',
                     '.$db->quote(self::_cut((string) Arr::get($data, 'event_alias', 'system'), 120)).',
                     '.$db->quote(self::_cut((string) Arr::get($data, 'category', 'system'), 40)).',
                     '.$db->quote(self::_cut((string) Arr::get($data, 'icon', 'fas fa-bell'), 60)).',
                     '.$db->quote(self::_cut($title, 300)).',
                     '.$db->quote(self::_cut((string) Arr::get($data, 'body', ''), 2000)).',
                     '.$db->quote(self::_cut((string) Arr::get($data, 'href', ''), 300)).',
                     '.((int) Arr::get($data, 'priority', 5)).',
                     '.$db->quote($payload).'::jsonb,
                     '.($group === '' ? 'NULL' : $db->quote(self::_cut($group, 160))).',
                     '.$db->quote(self::_cut((string) Arr::get($data, 'email_status', 'none'), 16)).',
                     now())
             RETURNING "id"', FALSE)->current();

        return (int) Arr::get((array) $row, 'id', 0);
    }

    /**
     * Старый вызов (до реестра событий) — оставлен для совместимости:
     * модули, которые звали notify($user_id, 'job_done', …), продолжают
     * работать, но внутри это то же emit() с событием из реестра.
     *
     * @return int id строки колокольчика (0 — не создана)
     */
    public static function notify($user_id, $kind, $title, $body = '', $href = '', $allow_email = TRUE, $self = NULL)
    {
        $alias = self::kind_event($kind);

        // «Своё» событие угадывается по виду: job_done — это итог заказа
        // самого человека (задача очереди, сборка, отчёт), и если он на
        // сайте, колокольчик молчит (см. _recipients). Сбой (job_failed)
        // и всё остальное будим всегда: это просьба о действии или чужая
        // новость. Явный $self сильнее догадки.
        $self = ($self === NULL) ? ($kind === 'job_done') : self::_truthy($self);

        $options = [];

        if ($self)
        {
            $options['self'] = TRUE;
        }

        if ( ! $allow_email)
        {
            $options['channels'] = ['in_app'];
        }

        $res = self::emit($alias, [
            'user_id' => $user_id,
            'title'   => $title,
            'body'    => $body,
            'href'    => $href,
            'kind'    => $kind,
        ], $options);

        return (int) Arr::get($res, 'inbox_id', 0);
    }

    /** Старый тип события → код события в реестре. */
    public static function kind_event($kind)
    {
        $map = [
            'job_done'    => 'prx120.job.done',
            'job_failed'  => 'prx120.job.failed',
            'system'      => 'system.message',
            'test'        => 'system.test',
        ];

        $kind = strtolower(trim((string) $kind));

        if (isset($map[$kind]))
        {
            return (string) $map[$kind];
        }

        // Неизвестный тип: обрезаем до безопасного хвоста. Если от названия
        // ничего не осталось (кириллица, знаки) — это просто системное
        // сообщение, а не событие с пустым кодом.
        $tail = trim((string) preg_replace('~[^a-z0-9._-]+~', '', $kind), '._-');

        return $tail !== '' ? 'prx597.legacy.'.$tail : 'system.message';
    }

    // ----------------------------------------------------------- присутствие

    /**
     * Отметка присутствия: «человек сейчас на сайте».
     *
     * Владелец (16.10.2026): «запустил обзор, он сделался, я его смотрю — и мне
     * приходит внутреннее уведомление! Зачем?» Отвечает на это сама страница:
     * колокольчик в шапке и так опрашивает сервер раз в 45 секунд, когда
     * вкладка видима (/prx597/api/unread?visible=1). Отдельного «онлайн»
     * в системе не было, поэтому присутствие — одна строка на человека
     * (prx_597_presence), которую убирает presence_gc. В таблицу связи
     * (prx_597_channels) отметку не пишем: это журнал привязок, а не пульс.
     *
     * Запись не чаще раза в 20 секунд: несколько вкладок одного человека
     * не должны превращаться в поток UPDATE.
     */
    public static function touch_presence($user_id)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return FALSE;
        }

        try
        {
            self::_db()->query(Database::INSERT,
                'INSERT INTO "prx_597_presence" ("user_id", "seen_at") VALUES ('.$user_id.', now())
                 ON CONFLICT ("user_id") DO UPDATE SET "seen_at" = now()
                   WHERE "prx_597_presence"."seen_at" < now() - interval \'20 seconds\'', FALSE);
        }
        catch (Throwable $e)
        {
            // Таблицы нет (база старее миграции) — молчим: без присутствия
            // центр работает как раньше, уведомления не теряются.
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Был ли человек на сайте последние N секунд.
     *
     * Окно (presence.minutes, по умолчанию 3 минуты) длиннее шага опроса
     * колокольчика (45 секунд) с запасом: одна пропущенная вкладка из-за
     * сна ноутбука не должна превращать «он тут» в «его нет».
     */
    public static function seen_recently($user_id, $seconds = 0)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return FALSE;
        }

        $seconds = (int) $seconds;

        if ($seconds <= 0)
        {
            $seconds = (int) self::setting('presence.minutes', (int) self::cfg('presence.minutes', 3)) * 60;
        }

        $seconds = max(30, min(3600, $seconds));

        try
        {
            $row = self::_one('SELECT "seen_at" FROM "prx_597_presence"
                                  WHERE "user_id" = '.$user_id.'
                                    AND "seen_at" > now() - interval \''.$seconds.' seconds\'');
        }
        catch (Throwable $e)
        {
            // Нет таблицы — считаем, что не видели: правило тишины молчит,
            // а уведомление уходит. Лучше лишнее, чем потерянное.
            return FALSE;
        }

        return (bool) $row;
    }

    /** Уборка отметок присутствия: строка нужна на минуты, а не на годы. */
    public static function presence_gc()
    {
        try
        {
            self::_db()->query(Database::DELETE,
                'DELETE FROM "prx_597_presence" WHERE "seen_at" < now() - interval \'30 days\'', FALSE);
        }
        catch (Throwable $e)
        {
            // нет таблицы — и убирать нечего
        }
    }

    /**
     * Признак «событие про действие самого получателя» (self).
     *
     * Ставит модуль — угадывать по «один получатель без инициатора» нельзя:
     * под это правило попадёт чужое событие (заказ, ответ, приёмка), а его
     * терять нельзя. Считается один раз и едет дальше двумя путями: в отбор
     * получателей (_recipients, колокольчик молчит, если человек на сайте)
     * и в строку очереди (payload.self — движок повторит проверку перед
     * отправкой письма, см. Engine::_self_and_online).
     */
    protected static function _self_flag(array $payload, array $options)
    {
        return self::_truthy(Arr::get($options, 'self', Arr::get($payload, 'self', FALSE)));
    }

    /**
     * Кого уведомляем: из payload user_id/user_ids, инициатора вычитаем.
     * Массовых рассылок центр не делает — получателей всегда называет модуль.
     *
     * Здесь же «правило зеркала» (владелец, 16.10.2026): если событие —
     * следствие собственного действия человека и он прямо сейчас на сайте,
     * будить его нечем. Он и так смотрит результат, а мы отнимаем внимание,
     * пишем строку в колокольчик, которая тут же прочитается, и тратим лимиты.
     * Молчание только для «своих» событий (флаг self или он же инициатор):
     * чужое событие (вам ответили, вам назначили) уведомляем как обычно, даже
     * если человек на сайте.
     */
    protected static function _recipients(array $payload, array $options, $alias = '')
    {
        self::$_recipients_note = '';

        $ids = [];

        foreach ([Arr::get($options, 'user_id'), Arr::get($payload, 'user_id')] as $value)
        {
            if ($value !== NULL AND (int) $value > 0)
            {
                $ids[] = (int) $value;
            }
        }

        foreach ([Arr::get($options, 'user_ids'), Arr::get($payload, 'user_ids'), Arr::get($payload, 'recipients')] as $list)
        {
            foreach ((array) $list as $value)
            {
                if ((int) $value > 0)
                {
                    $ids[] = (int) $value;
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ( ! $ids)
        {
            return [];
        }

        $initiator = Arr::get($options, 'initiator_id', Arr::get($payload, 'initiator_id', NULL));

        if ($initiator === NULL)
        {
            try
            {
                $user      = Auth::instance()->get_user();
                $initiator = $user ? (int) $user->id : 0;
            }
            catch (Throwable $e)
            {
                $initiator = 0;
            }
        }

        $initiator = (int) $initiator;

        // «Своё» событие — то, что случилось от действия самого получателя
        // (человек запустил обзор, заказал сборку, поставил задачу). Признак
        // ставит модуль: угадывать по «один получатель без инициатора» нельзя
        // — под это правило попадёт и чужое событие (заказ, ответ, приёмка),
        // а его терять нельзя.
        $self = self::_self_flag($payload, $options);

        // Себе не пишем: «вы только что сохранили файл» никому не нужно.
        // force_self — осознанное исключение (например, тест из админки).
        if ($initiator > 0
            AND self::_truthy(self::cfg('exclude_initiator', TRUE))
            AND empty($options['force_self'])
            AND empty($payload['force_self']))
        {
            $ids = array_values(array_filter($ids, function ($id) use ($initiator) {
                return (int) $id !== $initiator;
            }));

            self::$_recipients_note = 'вы — инициатор';
        }

        // И не будим того, кто сам это сделал и прямо сейчас на сайте: он уже
        // смотрит результат. Присутствие метит колокольчик (?visible=1, он и
        // так опрашивает сервер раз в 45 секунд) — см. touch_presence.
        if ($self AND $ids AND self::_truthy(self::setting('presence.skip_self', TRUE)))
        {
            $online = [];

            foreach ($ids as $id)
            {
                if (self::seen_recently((int) $id))
                {
                    $online[] = (int) $id;
                }
            }

            if ($online)
            {
                self::$_recipients_note = 'вы на сайте';

                try
                {
                    Kohana::$log->add(Log::INFO, 'prx597: :a — получатель на сайте, колокольчик молчит (своё событие): :n чел.', [
                        ':a' => (string) $alias,
                        ':n' => count($online),
                    ]);
                }
                catch (Throwable $e)
                {
                    // журнал молчит — это не повод будить человека
                }

                $ids = array_values(array_diff($ids, $online));
            }
        }

        return $ids;
    }

    /**
     * Данные для шаблона: всё, что модуль положил в data, плюс скалярные ключи
     * самого payload (title/body/href туда не попадают — они уже текст).
     */
    protected static function _template_data(array $payload)
    {
        $data = (array) Arr::get($payload, 'data', []);

        // href НЕ пропускаем: манифесты объявляют его подстановкой ({{href}}),
        // и без него письмо про «открыть обзор» вело бы в никуда. title/body —
        // это уже готовый текст уведомления, а не данные шаблона.
        $skip = ['user_id', 'user_ids', 'recipients', 'data', 'channels', 'initiator_id',
            'dedup_key', 'group_key', 'priority', 'force_self', 'self', 'title', 'body'];

        foreach ($payload as $key => $value)
        {
            if (in_array((string) $key, $skip, TRUE) OR isset($data[$key]))
            {
                continue;
            }

            if (is_scalar($value) OR $value === NULL)
            {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** Код события в виде, с которым работает реестр. */
    protected static function _event_alias($alias)
    {
        $alias = strtolower(trim((string) $alias));

        return (string) preg_replace('/[^a-z0-9._-]+/', '', $alias);
    }

    // -------------------------------------------------------------- notify

    /**
     * Отправить сводные письма по pending-outbox.
     * Один пользователь получает максимум одно письмо за проход (до 30 событий).
     * Сначала строки переводятся в sending — параллельный пинок не продублирует их.
     */
    public static function send_digests($user_limit = 100, $per_user = 30)
    {
        self::ensure_tables();
        $db = self::_db();

        // Критично: два параллельных пинка не должны выбрать один pending-набор
        // до его перевода в sending. Без advisory lock один и тот же digest мог
        // уйти несколько раз при совпавших cron/worker-запросах.
        $lock = $db->query(Database::SELECT,
            'SELECT pg_try_advisory_lock(597597)', FALSE)->get('pg_try_advisory_lock');

        if ($lock !== TRUE AND $lock !== 't' AND $lock !== '1')
        {
            Kohana::$log->add(Log::INFO, 'prx597: digest пропущен — уже выполняется другой отправитель');

            return ['sent' => 0, 'failed' => 0, 'users' => 0, 'skipped' => TRUE];
        }

        try
        {
            // После аварийного завершения процесса sending не должен зависать
            // навсегда и не должен блокировать следующую сводку.
            // Драйвер возвращает для UPDATE число строк (int) ->execute() тут фатал.
            $db->query(Database::UPDATE,
                "UPDATE prx_597_notifications SET email_status = 'pending'
                 WHERE email_status = 'sending' AND created_at < now() - interval '30 minutes'", FALSE);

            // Важно: берём только те строки, для которых НЕТ сообщения в
            // prx_597_outbox — с появлением движка письмо уходит оттуда, и
            // сводка иначе отправила бы его второй раз.
            $users = $db->query(Database::SELECT,
            'SELECT user_id, count(*) AS total FROM prx_597_notifications n
             WHERE n.email_status = \'pending\'
               AND NOT EXISTS (SELECT 1 FROM prx_597_outbox o WHERE o.inbox_id = n.id)
             GROUP BY user_id ORDER BY min(id) LIMIT '.(int) $user_limit, FALSE)->as_array();
        $sent = 0;
        $failed = 0;

        foreach ($users as $u)
        {
            $uid = (int) $u['user_id'];
            $rows = $db->query(Database::SELECT,
                'SELECT * FROM prx_597_notifications n WHERE n.user_id = '.$uid.'
                 AND n.email_status = \'pending\'
                 AND NOT EXISTS (SELECT 1 FROM prx_597_outbox o WHERE o.inbox_id = n.id)
                 ORDER BY n.id ASC LIMIT '.(int) $per_user, FALSE)->as_array();
            $ids = array_map('intval', array_column($rows, 'id'));

            if ( ! $ids)
            {
                continue;
            }

            $db->query(Database::UPDATE,
                'UPDATE prx_597_notifications SET email_status = \'sending\'
                 WHERE user_id = '.$uid.' AND email_status = \'pending\'
                 AND id IN ('.implode(',', $ids).')', FALSE);
            $to = self::_email_of($uid);

            if ($to === '')
            {
                self::_email_batch_fix($ids, 'failed', 'У пользователя нет email');
                $failed++;
                continue;
            }

            // HTML-сводка (карточки) + аккуратный plaintext-фолбэк.
            // Было: implode("\\n") в двойных кавычках = литеральные \n — письмо
            // приходило одной строкой с «\n» внутри (та самая «каша»).
            $plain = ['В центре уведомлений PRX.ru накопились события:', ''];

            foreach ($rows as $r)
            {
                $plain[] = '• '.(string) $r['title'];
                if ((string) $r['body'] !== '') $plain[] = '  '.preg_replace('~\s+~u', ' ', (string) $r['body']);
                if ((string) $r['href'] !== '') $plain[] = '  Открыть: '.self::_base_url().self::_safe_href((string) $r['href']);
                $plain[] = '';
            }

            $extra = max(0, (int) $u['total'] - count($rows));
            if ($extra > 0) $plain[] = 'И ещё уведомлений: '.$extra.'. Открыть все: '.self::_base_url().'/prx597';
            $plain[] = ''; $plain[] = 'Настройки: '.self::_base_url().'/prx597';

            $res = self::send_email($to, 'PRX.ru: сводка уведомлений ('.(int) $u['total'].')',
                self::digest_html($rows, $extra), TRUE, implode("
", $plain));

            if (isset($res['ok']))
            {
                self::_email_batch_fix($ids, 'sent', NULL);
                $sent++;
            }
            else
            {
                self::_email_batch_fix($ids, 'failed', (string) ($res['error'] ?? 'ошибка доставки'));
                $failed++;
            }
        }

            return ['sent' => $sent, 'failed' => $failed, 'users' => count($users)];
        }
        finally
        {
            // SELECT возвращает Database_Result — у него нет ->execute().
            $db->query(Database::SELECT, 'SELECT pg_advisory_unlock(597597)', FALSE);
        }
    }

    protected static function _email_batch_fix(array $ids, $status, $error = NULL)
    {
        if ( ! $ids) return;
        $db = self::_db();
        $db->query(Database::UPDATE,
            'UPDATE prx_597_notifications SET email_status = '.$db->quote($status).',
             email_error = '.($error === NULL ? 'NULL' : $db->quote(mb_substr($error, 0, 300))).'
             WHERE id IN ('.implode(',', array_map('intval', $ids)).')', FALSE);
    }

    protected static function _email_fix($id, $status, $error)
    {
        $db = self::_db();
        $db->query(Database::UPDATE,
            'UPDATE prx_597_notifications SET email_status = '.$db->quote($status).',
             email_error = '.($error === NULL ? 'NULL' : $db->quote(mb_substr($error, 0, 300))).'
             WHERE id = '.(int) $id, FALSE);
    }

    protected static function _email_of($user_id)
    {
        try
        {
            $u = ORM::factory('User', (int) $user_id);

            return $u->loaded() ? trim((string) $u->email) : '';
        }
        catch (Throwable $e)
        {
            return '';
        }
    }

    protected static function _email_text($title, $body, $href)
    {
        $url = self::_base_url().self::_safe_href($href);

        return $title."\n"
            .($body !== '' ? "\n".$body."\n" : '')
            ."\nОткрыть: ".$url."\n\n"
            ."Вы получили это письмо, потому что подписаны на уведомления PRX.ru.\n"
            ."Настройки: ".self::_base_url()."/prx597";
    }

    /**
     * Обёртка письма: шапка с логотипом + карточка + подвал.
     * Таблично-инлайновая вёрстка — почтовые клиенты (Gmail, Mail.ru) режут
     * flex/grid и <style>. Тёмная тема не нужна: письма рендерим светлыми.
     */
    public static function email_shell($title, $inner_html, array $unsub = [])
    {
        $h = htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');

        // Подвал с отказом: у каждого письма должен быть способ выключить
        // именно его, иначе человек выключит всё и потеряет важное.
        $footer = 'Вы получили это письмо, потому что подписаны на уведомления PRX.ru.<br>';

        $unsub_user    = (int) Arr::get($unsub, 'user_id', 0);
        $unsub_channel = (string) Arr::get($unsub, 'channel', 'email');
        $unsub_alias   = (string) Arr::get($unsub, 'event_alias', '');

        if ($unsub_user > 0 AND $unsub_alias !== '' AND $unsub_alias !== 'digest')
        {
            $footer .= '<a href="'.htmlspecialchars(self::unsub_url($unsub_user, $unsub_channel, $unsub_alias, 'event'), ENT_QUOTES, 'UTF-8')
                .'" style="color:#2563eb;text-decoration:underline;">Больше не присылать это событие</a> · ';
        }

        if ($unsub_user > 0)
        {
            $footer .= '<a href="'.htmlspecialchars(self::unsub_url($unsub_user, $unsub_channel, $unsub_alias, 'channel'), ENT_QUOTES, 'UTF-8')
                .'" style="color:#2563eb;text-decoration:underline;">Отключить письма</a> · ';
        }

        $footer .= '<a href="'.self::_base_url().'/prx597" style="color:#2563eb;text-decoration:underline;">'
            .'Все уведомления и настройки</a> · '
            .'<a href="'.self::_base_url().'" style="color:#2563eb;text-decoration:underline;">на главную</a>';

        return '<!DOCTYPE html>'
            .'<html lang="ru"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            .'<body style="margin:0;padding:0;background:#f3f4f6;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">'
            .'<tr><td align="center">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            .'style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;'
            .'border:1px solid #e5e7eb;">'
            // шапка
            .'<tr><td style="background:#111827;padding:18px 28px;">'
            .'<table role="presentation" width="100%"><tr>'
            .'<td style="color:#ffffff;font:bold 18px Arial,Helvetica,sans-serif;">'
            .'<span style="color:#60a5fa;">PRX</span>.ru</td>'
            .'<td align="right" style="color:#9ca3af;font:12px Arial,Helvetica,sans-serif;">'
            .$h.'</td>'
            .'</tr></table></td></tr>'
            // тело
            .'<tr><td style="padding:24px 28px;font:15px/1.55 Arial,Helvetica,sans-serif;color:#111827;">'
            .(string) $inner_html
            .'</td></tr>'
            // подвал
            .'<tr><td style="padding:16px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;'
            .'color:#6b7280;font:12px/1.5 Arial,Helvetica,sans-serif;">'
            .$footer
            .'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    /** Экранированный короткий текст → HTML. */
    protected static function _esc($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------- отписка

    /**
     * Секрет подписи ссылок «больше не присылать».
     *
     * Ссылка живёт в чужом почтовом ящике, поэтому она сама себе пропуск:
     * подпись считается HMAC, ничего не храним. Ключ — отдельная переменная
     * окружения, а если её нет, годится уже имеющийся серверный секрет;
     * в крайнем случае — адрес площадки (тогда подпись хотя бы не пустая).
     */
    public static function unsub_secret()
    {
        foreach (['PRX597_NOTIFICATIONS_UNSUB_SECRET', 'WORKER_API_TOKEN', 'COOKIE_SALT'] as $key)
        {
            $value = trim((string) getenv($key));

            if ($value !== '')
            {
                return 'prx597|'.$value;
            }
        }

        throw new RuntimeException('Не настроен секрет подписи уведомлений');
    }

    /**
     * Подпись ссылки отказа.
     *
     * @param  int    $user_id
     * @param  string $channel канал, из которого пришло письмо
     * @param  string $alias   код события
     * @param  string $scope   'channel' — не присылать этим каналом; 'event' — не присылать это событие
     * @return string
     */
    public static function unsub_token($user_id, $channel, $alias, $scope)
    {
        $payload = implode('|', ['v1', (int) $user_id, self::_cut($channel, 20), self::_cut($alias, 120), $scope]);

        return substr(hash_hmac('sha256', $payload, self::unsub_secret()), 0, 40);
    }

    /** Проверить подпись ссылки отказа (сравнение постоянного времени). */
    public static function unsub_verify($user_id, $channel, $alias, $scope, $token)
    {
        if ( ! in_array((string) $scope, ['channel', 'event'], TRUE))
        {
            return FALSE;
        }

        $want = self::unsub_token($user_id, $channel, $alias, $scope);

        return $token !== '' AND hash_equals($want, (string) $token);
    }

    /**
     * Ссылка отказа для подвала письма и заголовка List-Unsubscribe.
     *
     * @param  int    $user_id
     * @param  string $channel
     * @param  string $alias
     * @param  string $scope 'channel' | 'event'
     * @return string
     */
    public static function unsub_url($user_id, $channel, $alias, $scope = 'event')
    {
        $url = self::_base_url().'/prx597/unsub'
            .'?u='.(int) $user_id
            .'&c='.urlencode(self::_cut($channel, 20))
            .'&e='.urlencode(self::_cut($alias, 120))
            .'&s='.urlencode((string) $scope)
            .'&t='.self::unsub_token($user_id, $channel, $alias, $scope);

        return $url;
    }

    /**
     * Выполнить отказ по подписанной ссылке.
     *
     * Ничего не удаляем и не выключаем «всё»: либо канал, либо одно событие.
     * Колокольчик на сайте остаётся (владелец, 16.10.2026) — отказ касается
     * только того, что уходит наружу.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function unsub_apply($user_id, $channel, $alias, $scope)
    {
        $user_id = (int) $user_id;
        $channel = self::_cut(trim((string) $channel), 20);
        $alias   = self::_cut(trim((string) $alias), 120);
        $scope   = (string) $scope;

        if ($user_id <= 0)
        {
            return ['ok' => FALSE, 'message' => 'Ссылка не подходит: не указан получатель.'];
        }

        if ($scope === 'channel')
        {
            if ($channel === '' OR $channel === 'in_app')
            {
                return ['ok' => FALSE, 'message' => 'Колокольчик на сайте выключается только в настройках уведомлений.'];
            }

            $gate = $channel === 'email' ? 'email_enabled' : $channel.'_enabled';

            try
            {
                self::save_prefs($user_id, [$gate => FALSE]);
            }
            catch (Throwable $e)
            {
                return ['ok' => FALSE, 'message' => 'Не удалось сохранить отказ, попробуйте в настройках уведомлений.'];
            }

            self::_log_unsub($user_id, $channel, 'все события', 'channel');

            return ['ok' => TRUE, 'message' => 'Готово: '.Service_Prx597_Registry::channel_label($channel).' больше не побеспокоит. Колокольчик на сайте остаётся.'];
        }

        if ($alias === '')
        {
            return ['ok' => FALSE, 'message' => 'Ссылка не подходит: не указано событие.'];
        }

        $event = Service_Prx597_Registry::event($alias);

        if ($event === NULL)
        {
            return ['ok' => FALSE, 'message' => 'Такого события уже нет — отключать нечего.'];
        }

        if ( ! empty($event['mandatory']))
        {
            return ['ok' => FALSE, 'message' => 'Это событие обязательное ('.self::_esc((string) $event['title']).') — его нельзя отключить. Оно важное для безопасности аккаунта.'];
        }

        // Отключаем только те внешние каналы, которые событие вообще
        // объявляет: отказ должен жить дольше сегодняшней настройки SMTP,
        // поэтому не смотрим на то, настроен ли транспорт прямо сейчас.
        $declared = (array) Arr::get($event, 'channels', []);

        foreach (['email', 'telegram', 'max'] as $one)
        {
            if (in_array($one, $declared, TRUE))
            {
                Service_Prx597_Registry::save_sub($user_id, $alias, $one, FALSE);
            }
        }

        self::_log_unsub($user_id, $channel, (string) $event['title'], 'event');

        return ['ok' => TRUE, 'message' => 'Готово: «'.self::_esc((string) $event['title']).'» больше не приходит письмом или в мессенджер. В колокольчике на сайте событие остаётся.'];
    }

    /** Отказ виден в журнале: администратор понимает, почему «тихо». */
    protected static function _log_unsub($user_id, $channel, $what, $scope)
    {
        try
        {
            Kohana::$log->add(Log::INFO, 'prx597: отказ от уведомлений (ссылка из письма): пользователь :u, канал :c, :w, вид :s',
                [':u' => (int) $user_id, ':c' => (string) $channel, ':w' => (string) $what, ':s' => (string) $scope]);
        }
        catch (Throwable $e)
        {
            // журнал не должен ломать отказ
        }
    }

    /**
     * Заголовки письма для почтовых правил (RFC 8058): одной ссылки в подвале
     * мало — почтовые клиенты умеют показывать свою кнопку «отписаться»,
     * и без этих заголовков такие письма чаще уезжают в спам.
     */
    public static function unsub_headers($url)
    {
        $url = trim((string) $url);

        // Перевода строки в заголовке быть не может: это уже не ссылка,
        // а попытка дописать письму свои заголовки.
        if ($url === '' OR strpos($url, "\n") !== FALSE OR strpos($url, "\r") !== FALSE)
        {
            return [];
        }

        return [
            'List-Unsubscribe'      => '<'.$url.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /** База ссылок в письмах: APP_URL из .env, по умолчанию — прод. */
    protected static function _base_url()
    {
        $u = rtrim(trim((string) getenv('APP_URL')), '/');

        // ВАЖНО: при пустом APP_URL раньше стоял `self::_base_url()` — самовызов
        // без изменения состояния = бесконечная рекурсия. Cron `Prx603:Kick` с
        // pending-уведомлениями вызывал `_base_url()` из digest/pdf-путей, PHP
        // растил VM-стек на куче, пока ядро не убивало процесс по OOM (~2.4 ГБ
        // total-vm на сервере с 1 ГБ RAM). Фолбэк — прод-адрес, как в .env.example.
        return ($u !== '') ? $u : 'http://localhost:8080';
    }

    /** Цвет маркера события: зелёный — успех, красный — ошибка, синий — прочее. */
    protected static function _kind_color($kind)
    {
        if (strpos((string) $kind, 'failed') !== FALSE OR strpos((string) $kind, 'error') !== FALSE)
        {
            return '#dc2626'; // красный
        }

        if (strpos((string) $kind, 'done') !== FALSE OR strpos((string) $kind, 'prx605') !== FALSE)
        {
            return '#059669'; // зелёный
        }

        return '#2563eb'; // синий
    }

    /**
     * HTML сводки уведомлений: карточки событий + кнопки «Открыть».
     * $rows — строки prx_597_notifications, $extra — сколько ещё не вошло.
     */
    public static function digest_html(array $rows, $extra = 0)
    {
        $cards = '';

        foreach ($rows as $r)
        {
            $title = (string) $r['title'];
            $body  = preg_replace('~\s+~u', ' ', (string) $r['body']);
            $url   = self::_base_url().self::_safe_href((string) $r['href']);
            $dot   = '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;'
                .'background:'.self::_kind_color($r['kind'] ?? '').';margin-right:7px;"></span>';
            $when  = '';

            if ( ! empty($r['created_at']))
            {
                try
                {
                    $when = Date::formatted_time((string) $r['created_at'], 'd.m.Y H:i');
                }
                catch (Throwable $e)
                {
                    $when = '';
                }
            }

            $title_html = self::_esc($title);

            // заголовок-ссылка: событие ведёт на страницу результата
            if (trim((string) $r['href']) !== '')
            {
                $title_html = '<a href="'.self::_esc($url).'" '
                    .'style="color:#111827;text-decoration:none;">'.$title_html.'</a>';
            }

            $cards .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
                .'style="margin:0 0 12px 0;border:1px solid #e5e7eb;border-radius:12px;">'
                .'<tr><td style="padding:14px 16px;">'
                .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                .'<td style="font:bold 15px/1.4 Arial,Helvetica,sans-serif;color:#111827;">'
                .$dot.$title_html.'</td>'
                .($when !== '' ? '<td align="right" style="color:#9ca3af;font:12px Arial,Helvetica,sans-serif;'
                    .'white-space:nowrap;">'.$when.'</td>' : '')
                .'</tr></table>'
                .($body !== '' ? '<div style="margin-top:6px;color:#4b5563;'
                    .'font:14px/1.55 Arial,Helvetica,sans-serif;">'.self::_esc($body).'</div>' : '')
                .(trim((string) $r['href']) !== ''
                    ? '<div style="margin-top:10px;"><a href="'.self::_esc($url).'" '
                        .'style="display:inline-block;background:#2563eb;color:#ffffff;'
                        .'font:bold 13px Arial,Helvetica,sans-serif;padding:8px 16px;'
                        .'border-radius:8px;text-decoration:none;">Открыть &rarr;</a></div>'
                    : '')
                .'</td></tr></table>';
        }

        if ($extra > 0)
        {
            $cards .= '<p style="margin:14px 0;color:#374151;font:14px Arial,Helvetica,sans-serif;">'
                .'И ещё уведомлений: <b>'.(int) $extra.'</b> — '
                .'<a href="'.self::_base_url().'/prx597" style="color:#2563eb;">смотреть все</a></p>';
        }

        return self::email_shell(
            'Сводка уведомлений',
            '<p style="margin:0 0 16px 0;font:15px Arial,Helvetica,sans-serif;color:#374151;">'
            .'В центре уведомлений накопились события:</p>'.$cards);
    }

    /**
     * Отправка письма symfony/mailer. Возвращает [ok] или [error].
     * DSN из EMAIL_DSN; PRX597_EMAIL_ENABLED=0 — письма отключены.
     */
    public static function send_email($to, $subject, $text, $is_html = FALSE)
    {
        // текстовая альтернатива для клиентов без HTML: из $text (если шлём HTML
        // — передаём готовый plaintext вторым аргументом-строкой ниже)
        $alt_text = NULL;

        if ($is_html AND is_string($text) AND func_num_args() > 4)
        {
            $alt_text = (string) func_get_arg(4);
        }

        if (trim((string) getenv('PRX597_EMAIL_ENABLED')) === '0')
        {
            return ['error' => 'Письма отключены настройкой PRX597_EMAIL_ENABLED=0'];
        }

        $dsn = trim((string) getenv('EMAIL_DSN'));

        if ($dsn === '')
        {
            return ['error' => 'EMAIL_DSN не задан (пример в .env.example: smtp://логин:пароль@хост:порт)'];
        }

        if (stripos($dsn, 'null:') === 0)
        {
            return ['error' => 'EMAIL_DSN использует пустой транспорт null: — письма не отправляются'];
        }

        if ( ! class_exists('Symfony\Component\Mailer\Transport'))
        {
            return ['error' => 'Библиотека symfony/mailer не установлена — выполните composer install'];
        }

        $from = trim((string) getenv('EMAIL_FROM'));
        $from_name = trim((string) getenv('EMAIL_FROM_NAME')) ?: 'PRX.ru';

        if ($from === '')
        {
            return ['error' => 'EMAIL_FROM не задан (адрес отправителя)'];
        }

        try
        {
            $mailer = new Symfony\Component\Mailer\Mailer(
                Symfony\Component\Mailer\Transport::fromDsn($dsn));

            $email = (new Symfony\Component\Mime\Email())
                ->from(new Symfony\Component\Mime\Address($from, $from_name))
                ->to($to)
                ->subject(mb_substr((string) $subject, 0, 200));

            if ($is_html)
            {
                $email->html((string) $text);

                // клиенты без HTML (и антиспам) любят text/plain часть
                $email->text($alt_text !== NULL ? $alt_text : trim(preg_replace('~\s+~u', ' ', strip_tags((string) $text))));
            }
            else
            {
                $email->text((string) $text);
            }

            // Шестой аргумент — заголовки письма (List-Unsubscribe и прочие).
            // Почтовые правила: без кнопки «отписаться» рассылка похожа на спам.
            if (func_num_args() > 5)
            {
                foreach ((array) func_get_arg(5) as $header => $value)
                {
                    $header = trim((string) $header);
                    $value  = trim((string) $value);

                    if ($header === '' OR $value === '' OR strpos($header, '\n') !== FALSE OR strpos($value, '\n') !== FALSE)
                    {
                        continue;   // заголовок с переводом строки — это инъекция
                    }

                    $email->getHeaders()->addTextHeader($header, $value);
                }
            }

            $mailer->send($email);

            return ['ok' => TRUE];
        }
        catch (Throwable $e)
        {
            return ['error' => mb_substr($e->getMessage(), 0, 300).' @ '.basename($e->getFile()).':'.$e->getLine()];
        }
    }

    // ------------------------------------------------------- триггер prx120

    /** Карта «канал очереди → подпись и база ссылки». */
    public static function channel_map()
    {
        $map = [];
        foreach (Manifest::channels() as $channel => $spec)
        {
            $map[$channel] = [(string) ($spec['label'] ?? $channel), '/tasks'];
        }
        return $map;
    }

    /**
     * Хук завершения задачи очереди prx120 (вызывается из JobQueue).
     * $status: 'done' | 'failed'.
     */
    public static function job_update(array $job, $status, $error = NULL)
    {
        $owner = self::_owner_of($job);

        if ($owner <= 0)
        {
            // задача анонима — колокольчик только у зарегистрированных;
            // лог причины: видно в админке логов/мониторе, почему «тихо»
            Kohana::$log->add(Log::INFO, 'prx597: skip: задача :j без владельца (аноним)',
                [':j' => (string) ($job['id'] ?? '?' )]);

            return;
        }

        $map   = self::channel_map();
        $ch    = (string) ($job['channel'] ?? '');
        $label = $map[$ch][0] ?? 'Задача';
        $base  = $map[$ch][1] ?? '/tasks';

        // код артефакта из payload — прямая ссылка на результат
        $code = NULL;
        $payload = json_decode((string) ($job['payload'] ?? ''), TRUE);

        if (is_array($payload) AND ! empty($payload['code']))
        {
            $candidate = trim((string) $payload['code']);

            // code — только короткий route-id. Не превращаем исходный текст,
            // OpenSCAD-код, URL или JSON в сегмент адреса.
            if (preg_match('/^[a-z0-9]{3,64}$/i', $candidate))
            {
                $code = $candidate;
            }
        }

        // Если payload не содержит route-id, ведём на раздел модуля, а не
        // подставляем произвольный текст после /3d/ или /tasks/.
        $href = '/tasks'; // Объектные ссылки и доступ к результатам определяет UI задач.

        if ($status === 'done')
        {
            $nid = self::notify($owner, 'job_done',
                'Готово: '.$label,
                $code !== NULL ? 'Результат собран — можно открывать.' : 'Задача завершилась — подробности на странице задач.',
                $href);

            Kohana::$log->add(Log::INFO, 'prx597: уведомление :n создано (job :j, :k, владелец :u)',
                [':n' => $nid, ':j' => (string) ($job['id'] ?? '?'), ':k' => $ch, ':u' => $owner]);
        }
        else
        {
            self::notify($owner, 'job_failed',
                'Ошибка: '.$label,
                'Не удалось выполнить задачу'.($error !== NULL ? ': '.mb_substr($error, 0, 200) : '').'. Можно попробовать ещё раз.',
                $href);
        }
    }

    /** Владелец задачи: created_by или visitor_id = 'u:<id>'. */
    protected static function _owner_of(array $job)
    {
        if ( ! empty($job['created_by']))
        {
            return (int) $job['created_by'];
        }

        $vid = (string) ($job['visitor_id'] ?? '');

        return (strpos($vid, 'u:') === 0) ? (int) substr($vid, 2) : 0;
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
    protected static function _defaults_off_once()
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

    /**
     * Кому можно слать системное сообщение: тем, у кого включён колокольчик.
     * Массовых рассылок центр не делает — это объявление администратора
     * платформы, и оно всё равно уважает выключатель канала.
     *
     * @param  int $limit
     * @return array список user_id
     */
    public static function recipient_ids($limit = 200)
    {
        self::ensure_tables();
        $limit = max(1, min(5000, (int) $limit));

        try
        {
            $rows = self::_all('SELECT "user_id", "in_app_enabled" FROM "prx_597_prefs"
                                 ORDER BY "user_id" LIMIT '.$limit);
        }
        catch (Throwable $e)
        {
            $rows = [];
        }

        // Настройки есть — уважаем выбор каждого, в том числе явное «не надо»:
        // пустой список честнее, чем рассылка тем, кто отказался.
        if ($rows)
        {
            $ids = [];

            foreach ($rows as $row)
            {
                if (self::_bool(Arr::get((array) $row, 'in_app_enabled', FALSE)))
                {
                    $ids[] = (int) $row['user_id'];
                }
            }

            return $ids;
        }

        // Настроек ещё нет ни у кого — берём всех вошедших, иначе объявление
        // администратора просто некому показать.
        try
        {
            $rows = self::_all('SELECT "id" FROM "users" ORDER BY "id" LIMIT '.$limit);
        }
        catch (Throwable $e)
        {
            return [];
        }

        return array_map('intval', array_column($rows, 'id'));
    }

    // ----------------------------------------------------------------- API

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
            $row = self::_db()->query(Database::SELECT,
                'SELECT "count" FROM "prx_597_unread" WHERE "user_id" = '.$user_id, FALSE)->current();

            if ($row)
            {
                return max(0, (int) (is_array($row) ? $row['count'] : $row->count));
            }
        }
        catch (Throwable $e)
        {
            // Таблицы счётчика нет — считаем по ленте ниже.
        }

        $count = (int) self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM prx_597_notifications
             WHERE user_id = '.$user_id.' AND read_at IS NULL AND archived_at IS NULL', FALSE)->get('c');

        try
        {
            self::_db()->query(Database::INSERT,
                'INSERT INTO "prx_597_unread" ("user_id", "count", "updated_at")
                 VALUES ('.$user_id.', '.$count.', now())
                 ON CONFLICT ("user_id") DO UPDATE SET "count" = EXCLUDED."count", "updated_at" = now()', FALSE);
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

        $count = (int) self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM prx_597_notifications
             WHERE user_id = '.$user_id.' AND read_at IS NULL AND archived_at IS NULL', FALSE)->get('c');

        self::_db()->query(Database::INSERT,
            'INSERT INTO "prx_597_unread" ("user_id", "count", "updated_at")
             VALUES ('.$user_id.', '.$count.', now())
             ON CONFLICT ("user_id") DO UPDATE SET "count" = EXCLUDED."count", "updated_at" = now()', FALSE);

        return $count;
    }

    /** Убрать прочитанное в архив (не удаляем: история нужна). */
    public static function archive($user_id, array $ids = NULL)
    {
        self::ensure_tables();
        $db = self::_db();

        if ($ids === NULL)
        {
            $db->query(Database::UPDATE,
                'UPDATE "prx_597_notifications" SET "archived_at" = now()
                  WHERE "user_id" = '.((int) $user_id).' AND "read_at" IS NOT NULL AND "archived_at" IS NULL', FALSE);

            return self::recount((int) $user_id);
        }

        $ids = array_filter(array_map('intval', $ids));

        if ($ids)
        {
            $db->query(Database::UPDATE,
                'UPDATE "prx_597_notifications" SET "archived_at" = now()
                  WHERE "user_id" = '.((int) $user_id).' AND "read_at" IS NOT NULL AND "id" IN ('.implode(',', $ids).')', FALSE);
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
        $rows = self::_db()->query(Database::SELECT,
            'SELECT * FROM prx_597_notifications WHERE user_id = '.(int) $user_id
            .($with_archived ? '' : ' AND archived_at IS NULL')
            .' ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

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
        $db = self::_db();

        if ($ids === NULL)
        {
            $db->query(Database::UPDATE,
                'UPDATE prx_597_notifications SET read_at = now()
                 WHERE user_id = '.(int) $user_id.' AND read_at IS NULL', FALSE);
            self::recount((int) $user_id);

            return;
        }

        $ids = array_filter(array_map('intval', $ids));

        if ($ids)
        {
            $db->query(Database::UPDATE,
                'UPDATE prx_597_notifications SET read_at = now()
                 WHERE user_id = '.(int) $user_id.' AND id IN ('.implode(',', $ids).')', FALSE);
        }
    }

    /** Удалить прочитанные (кнопка «очистить»). Возвращает сколько. */
    public static function clear_read($user_id)
    {
        self::ensure_tables();
        $c = (int) self::_db()->query(Database::SELECT,
            'WITH d AS (DELETE FROM prx_597_notifications
                WHERE user_id = '.(int) $user_id.' AND read_at IS NOT NULL RETURNING 1)
             SELECT count(*) AS c FROM d', FALSE)->get('c');

        return $c;
    }

    // --------------------------------------------------------------- admin

    public static function admin_stats()
    {
        self::ensure_tables();
        $db  = self::_db();

        $n = $db->query(Database::SELECT,
            'SELECT count(*) AS total,
                    count(*) FILTER (WHERE read_at IS NULL) AS unread,
                    count(*) FILTER (WHERE email_status = \'sent\') AS sent,
                    count(*) FILTER (WHERE email_status = \'failed\') AS failed,
                    count(*) FILTER (WHERE email_status = \'pending\') AS pending
             FROM prx_597_notifications', FALSE)->current();

        $u = (int) $db->query(Database::SELECT,
            'SELECT count(*) AS c FROM users', FALSE)->get('c');

        return [
            'total'  => (int) ($n['total'] ?? 0),
            'unread' => (int) ($n['unread'] ?? 0),
            'sent'   => (int) ($n['sent'] ?? 0),
            'failed' => (int) ($n['failed'] ?? 0),
            'pending' => (int) ($n['pending'] ?? 0),
            'users'  => $u,
        ];
    }

    /** Последние уведомления с именами пользователей (админка). */
    public static function admin_recent($limit = 100)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT n.*, u.username, u.email
             FROM prx_597_notifications n LEFT JOIN users u ON u.id = n.user_id
             ORDER BY n.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Повторить неудавшиеся письма: ставим их заново в очередь движка
     * (prx_597_outbox), а не просто перекрашиваем статус — иначе письмо
     * ждало бы старую сводку, которой больше нет.
     */
    public static function resend_failed($limit = 100)
    {
        self::ensure_tables();
        $db = self::_db();
        $rows = $db->query(Database::SELECT,
            'SELECT "id", "user_id", "title", "body", "href", "email_to" FROM "prx_597_notifications"
             WHERE "email_status" IN (\'failed\', \'dead\') ORDER BY "id" DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $queued = 0;

        foreach ($rows as $row)
        {
            $row = (array) $row;

            $out = self::_enqueue((int) $row['user_id'], 'email', [
                'event_alias' => 'system.retry',
                'subject'     => (string) $row['title'],
                'body'        => (string) $row['body'],
                'href'        => (string) $row['href'],
                'priority'    => 5,
                'data'        => [],
                'inbox_id'    => (int) $row['id'],
                'dedup_key'   => '',
                'group_key'   => '',
                'prefs'       => self::prefs((int) $row['user_id']),
                'event'       => ['category' => 'system', 'digest' => FALSE],
            ]);

            if ($out['queued'])
            {
                $db->query(Database::UPDATE,
                    'UPDATE "prx_597_notifications" SET "email_status" = \'pending\', "email_error" = NULL
                      WHERE "id" = '.((int) $row['id']), FALSE);
                $queued++;
            }
        }

        return ['ok' => TRUE, 'queued' => $queued, 'sent' => 0, 'failed' => 0];
    }

    /** Тестовое письмо администратору (или на указанный адрес). */
    public static function test_email($to = NULL)
    {
        $to = $to ?: trim((string) getenv('EMAIL_FROM'));

        if ($to === '')
        {
            return ['error' => 'Не указан адрес: и параметр пуст, и EMAIL_FROM не задан'];
        }

        $dsn = trim((string) getenv('EMAIL_DSN'));
        $host = '';

        if ($dsn !== '' AND preg_match('~@([^/:?]+)~', $dsn, $m))
        {
            $host = $m[1];
        }

        $res = self::send_email($to,
            'Тест письма PRX.ru',
            "Это тестовое письмо центра уведомлений PRX.ru (prx597).\n\n"
            ."Время: ".date('d.m.Y H:i:s')."\n"
            ."Транспорт: ".($dsn !== '' ? strtok($dsn, ':')." (хост: ".($host ?: '?').")" : 'EMAIL_DSN не задан')."\n\n"
            ."Если письмо дошло — почта настроена правильно.\nНастройки уведомлений: ".self::_base_url()."/prx597");

        if (isset($res['ok']))
        {
            return ['ok' => TRUE, 'to' => $to];
        }

        return $res;
    }
}
