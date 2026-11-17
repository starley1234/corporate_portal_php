<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Схема центра уведомлений (prx597).
 *
 * Один источник правды по DDL: и миграция 002, и Service_Prx597_Service_Prx597_Notify::
 * ensure_tables() берут SQL отсюда. Иначе правка, внесённая «только в
 * миграцию», на живой базе без наката миграций не появится, а модуль
 * поднимает таблицы сам — расхождение находилось бы неделями.
 *
 * Все операторы идемпотентны (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS) —
 * их можно выполнять сколько угодно раз.
 */
class Service_Prx597_Schema extends Service_Prx597_Base
{
	/**
	 * Операторы создания таблиц ядра. Порядок важен: сначала справочники,
	 * потом очередь и журнал.
	 *
	 * @return array список SQL-строк
	 */
	public static function tables()
	{
		return [
<<<'SQL'
-- ---------------------------------------------------------------- реестр событий
CREATE TABLE IF NOT EXISTS "prx_597_events" (
    "alias"            VARCHAR(120) PRIMARY KEY,
    "module"           VARCHAR(80)  NOT NULL DEFAULT '',
    "title"            VARCHAR(200) NOT NULL DEFAULT '',
    "description"      TEXT         NOT NULL DEFAULT '',
    "category"         VARCHAR(40)  NOT NULL DEFAULT 'system',
    "icon"             VARCHAR(60)  NOT NULL DEFAULT 'fas fa-bell',
    "default_channels" VARCHAR(120) NOT NULL DEFAULT 'in_app',
    "allowed_roles"    VARCHAR(200) NOT NULL DEFAULT 'login',
    "ability"          VARCHAR(80)  NULL,
    "mandatory"        BOOLEAN      NOT NULL DEFAULT FALSE,
    "enabled"          BOOLEAN      NOT NULL DEFAULT TRUE,
    -- TRUE — событие можно копить в сводку. Не склеивают только срочные
    -- (в манифесте 'digest' => FALSE): так «не спамить» — это умолчание,
    -- а не то, что надо включать руками (владелец, 16.10.2026).
    "digest"           BOOLEAN      NOT NULL DEFAULT TRUE,
    "source"           VARCHAR(16)  NOT NULL DEFAULT 'db',
    "placeholders"     TEXT         NOT NULL DEFAULT '',
    "created_at"       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"       TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "prx_597_events_module_idx" ON "prx_597_events" ("module");
CREATE INDEX IF NOT EXISTS "prx_597_events_enabled_idx" ON "prx_597_events" ("enabled", "category");
COMMENT ON TABLE  "prx_597_events" IS 'PRX-597 реестр типов событий: откуда берутся уведомления';
COMMENT ON COLUMN "prx_597_events"."mandatory" IS 'TRUE — пользователь не может отключить событие';
COMMENT ON COLUMN "prx_597_events"."source" IS 'manifest — объявлено модулем; db — добавлено администратором';
COMMENT ON COLUMN "prx_597_events"."digest" IS 'TRUE — можно склеивать в сводку; FALSE — только немедленно';

-- -------------------------------------------------------------------- шаблоны
CREATE TABLE IF NOT EXISTS "prx_597_templates" (
    "id"          BIGSERIAL PRIMARY KEY,
    "event_alias" VARCHAR(120) NOT NULL,
    "channel"     VARCHAR(20)  NOT NULL,
    "subject"     VARCHAR(300) NOT NULL DEFAULT '',
    "body"        TEXT         NOT NULL DEFAULT '',
    "format"      VARCHAR(10)  NOT NULL DEFAULT 'text',
    "is_active"   BOOLEAN      NOT NULL DEFAULT TRUE,
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_by"  BIGINT       NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS "prx_597_templates_uq" ON "prx_597_templates" ("event_alias", "channel");
COMMENT ON TABLE "prx_597_templates" IS 'PRX-597 шаблоны уведомлений: событие × канал, подстановка {{плейсхолдер}}';

-- --------------------------------------------------------- привязки пользователя
CREATE TABLE IF NOT EXISTS "prx_597_channels" (
    "id"          BIGSERIAL PRIMARY KEY,
    "user_id"     BIGINT       NOT NULL,
    "channel"     VARCHAR(20)  NOT NULL,
    "destination" VARCHAR(200) NOT NULL DEFAULT '',
    "external_id" VARCHAR(80)  NULL,
    "status"      VARCHAR(16)  NOT NULL DEFAULT 'pending',
    "bind_code"   VARCHAR(24)  NULL,
    "title"       VARCHAR(120) NOT NULL DEFAULT '',
    "verified_at" TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "prx_597_channels_uq" ON "prx_597_channels" ("user_id", "channel");
CREATE UNIQUE INDEX IF NOT EXISTS "prx_597_channels_code_uq" ON "prx_597_channels" ("bind_code") WHERE "bind_code" IS NOT NULL;
CREATE INDEX IF NOT EXISTS "prx_597_channels_lookup_idx" ON "prx_597_channels" ("channel", "external_id");
COMMENT ON TABLE  "prx_597_channels" IS 'PRX-597 привязки каналов: почта, chat_id Telegram, пользователь MAX';
COMMENT ON COLUMN "prx_597_channels"."status" IS 'pending — ждёт подтверждения; active — доставка разрешена; blocked — отключено';

-- ------------------------------------------------- матрица настроек пользователя
CREATE TABLE IF NOT EXISTS "prx_597_subs" (
    "user_id"     BIGINT       NOT NULL,
    "event_alias" VARCHAR(120) NOT NULL,
    "channel"     VARCHAR(20)  NOT NULL,
    "enabled"     BOOLEAN      NOT NULL DEFAULT TRUE,
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY ("user_id", "event_alias", "channel")
);
COMMENT ON TABLE "prx_597_subs" IS 'PRX-597 настройки пользователя: что и каким каналом получать';

-- ------------------------------------------------------------------- outbox
CREATE TABLE IF NOT EXISTS "prx_597_outbox" (
    "id"              BIGSERIAL PRIMARY KEY,
    "user_id"         BIGINT       NOT NULL,
    "event_alias"     VARCHAR(120) NOT NULL DEFAULT 'system',
    "channel"         VARCHAR(20)  NOT NULL,
    "destination"     VARCHAR(200) NOT NULL DEFAULT '',
    "subject"         VARCHAR(300) NOT NULL DEFAULT '',
    "body"            TEXT         NOT NULL DEFAULT '',
    "href"            VARCHAR(300) NOT NULL DEFAULT '',
    "payload"         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "priority"        SMALLINT     NOT NULL DEFAULT 5,
    "status"          VARCHAR(16)  NOT NULL DEFAULT 'pending',
    "attempts"        SMALLINT     NOT NULL DEFAULT 0,
    "next_attempt_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "dedup_key"       VARCHAR(160) NULL,
    "group_key"       VARCHAR(160) NULL,
    "inbox_id"        BIGINT       NULL,
    "last_error"      TEXT         NULL,
    "created_at"      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "sent_at"         TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "prx_597_outbox_queue_idx" ON "prx_597_outbox" ("status", "next_attempt_at");
CREATE INDEX IF NOT EXISTS "prx_597_outbox_user_idx" ON "prx_597_outbox" ("user_id", "id" DESC);
CREATE UNIQUE INDEX IF NOT EXISTS "prx_597_outbox_dedup_uq" ON "prx_597_outbox" ("dedup_key") WHERE "dedup_key" IS NOT NULL;
COMMENT ON TABLE  "prx_597_outbox" IS 'PRX-597 очередь доставки: pending → sending → sent | failed | dead';
COMMENT ON COLUMN "prx_597_outbox"."status" IS 'pending — ждёт; sending — взято движком; sent — ушло; dead — DLQ после лимита попыток';

-- -------------------------------------------------------------------- журнал
CREATE TABLE IF NOT EXISTS "prx_597_log" (
    "id"            BIGSERIAL PRIMARY KEY,
    "outbox_id"     BIGINT       NULL,
    "user_id"       BIGINT       NOT NULL DEFAULT 0,
    "channel"       VARCHAR(20)  NOT NULL DEFAULT '',
    "attempt"       SMALLINT     NOT NULL DEFAULT 0,
    "ok"            BOOLEAN      NOT NULL DEFAULT FALSE,
    "response_code" VARCHAR(20)  NOT NULL DEFAULT '',
    "message"       TEXT         NOT NULL DEFAULT '',
    "created_at"    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "prx_597_log_outbox_idx" ON "prx_597_log" ("outbox_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "prx_597_log_created_idx" ON "prx_597_log" ("created_at" DESC);
COMMENT ON TABLE "prx_597_log" IS 'PRX-597 журнал доставки: попытка, ответ провайдера, причина';

-- ----------------------------------------------------------- счётчики лимитов
CREATE TABLE IF NOT EXISTS "prx_597_rate" (
    "bucket"     VARCHAR(160) PRIMARY KEY,
    "count"      INTEGER      NOT NULL DEFAULT 0,
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE "prx_597_rate" IS 'PRX-597 антиспам: ключ ведра (u:12:email:h:2026-10-09T15) → счётчик';

-- --------------------------------------------------- счётчик непрочитанных
CREATE TABLE IF NOT EXISTS "prx_597_unread" (
    "user_id"    BIGINT PRIMARY KEY,
    "count"      INTEGER      NOT NULL DEFAULT 0,
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE "prx_597_unread" IS 'PRX-597 счётчик непрочитанных: держит триггер, чтобы колокольчик не считал COUNT(*)';

-- --------------------------------------------------------------------- боты
CREATE TABLE IF NOT EXISTS "prx_597_bots" (
    "channel"       VARCHAR(20) PRIMARY KEY,
    "mode"          VARCHAR(12) NOT NULL DEFAULT 'webhook',
    "secret"        VARCHAR(80) NOT NULL DEFAULT '',
    "update_offset" BIGINT      NOT NULL DEFAULT 0,
    "last_poll_at"  TIMESTAMPTZ NULL,
    "last_error"    TEXT        NULL,
    "updated_at"    TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "prx_597_bots" IS 'PRX-597 боты: вебхук или опрос, курсор обновлений, последняя ошибка';

-- ---------------------------------------------------------------- настройки
CREATE TABLE IF NOT EXISTS "prx_597_settings" (
    "key"        VARCHAR(60) PRIMARY KEY,
    "value"      TEXT        NOT NULL DEFAULT '',
    "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "prx_597_settings" IS 'PRX-597 настройки администратора: лимиты, режимы, флаги (секреты — только в env)';
SQL,
		];
	}


	/**
	 * Самые старые таблицы модуля (миграция 001): лента уведомлений и
	 * настройки пользователя. Держим их здесь же, чтобы ensure_tables() одной
	 * командой поднимал всё, чем живет центр.
	 */
	public static function legacy()
	{
		return [
<<<'SQL'
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

-- Владелец (16.10.2026): «по умолчанию у пользователей должны быть отключены
-- уведомления» — наружу (почта, Telegram, MAX) DEFAULT FALSE. Колокольчик на
-- сайте остаётся включённым: он не беспокоит и виден только внутри. Умолчания
-- в коде (Service_Prx597_Notify::prefs_default) обязаны совпадать с этими: строка настроек
-- может появиться от любой частичной формы, и тогда её добирают колонки.
CREATE TABLE IF NOT EXISTS "prx_597_prefs" (
    "user_id"       BIGINT PRIMARY KEY,
    "email_enabled" BOOLEAN      NOT NULL DEFAULT FALSE,
    "on_done"       BOOLEAN      NOT NULL DEFAULT FALSE,
    "on_failed"     BOOLEAN      NOT NULL DEFAULT FALSE,
    "updated_at"    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Присутствие: «человек сейчас на сайте». Одна строка на человека, живёт
-- минуты — поэтому PRIMARY KEY по user_id и индекс только по времени, чтобы
-- уборка (Service_Prx597_Notify::presence_gc) не читала таблицу целиком.
-- Зачем: не будить уведомлением того, кто сам сделал событие и смотрит
-- результат (владелец, 16.10.2026). Метку ставит колокольчик — он и так
-- опрашивает сервер раз в 45 секунд (/prx597/api/unread?visible=1).
CREATE TABLE IF NOT EXISTS "prx_597_presence" (
    "user_id"  BIGINT      PRIMARY KEY,
    "seen_at"  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "prx_597_presence_seen_idx" ON "prx_597_presence" ("seen_at" DESC);
COMMENT ON TABLE "prx_597_presence" IS 'PRX-597 присутствие: user_id → когда человека видели на сайте (живёт минуты, чистит presence_gc)';
SQL,
		];
	}

	/** Добивка колонок к таблицам, которые жили до появления ядра. */
	public static function alters()
	{
		return [
<<<'SQL'
ALTER TABLE "prx_597_notifications" ADD COLUMN IF NOT EXISTS "event_alias"  VARCHAR(120) NOT NULL DEFAULT 'system';
ALTER TABLE "prx_597_notifications" ADD COLUMN IF NOT EXISTS "category"     VARCHAR(40)  NOT NULL DEFAULT 'system';
ALTER TABLE "prx_597_notifications" ADD COLUMN IF NOT EXISTS "icon"         VARCHAR(60)  NOT NULL DEFAULT 'fas fa-bell';
ALTER TABLE "prx_597_notifications" ADD COLUMN IF NOT EXISTS "priority"     SMALLINT     NOT NULL DEFAULT 5;
ALTER TABLE "prx_597_notifications" ADD COLUMN IF NOT EXISTS "data"         JSONB        NOT NULL DEFAULT '{}'::jsonb;
ALTER TABLE "prx_597_notifications" ADD COLUMN IF NOT EXISTS "group_key"    VARCHAR(160) NULL;
ALTER TABLE "prx_597_notifications" ADD COLUMN IF NOT EXISTS "archived_at"  TIMESTAMPTZ  NULL;
ALTER TABLE "prx_597_notifications" ADD COLUMN IF NOT EXISTS "expires_at"   TIMESTAMPTZ  NULL;
CREATE INDEX IF NOT EXISTS "prx_597_notifications_alias_idx" ON "prx_597_notifications" ("user_id", "event_alias", "id" DESC);
CREATE INDEX IF NOT EXISTS "prx_597_notifications_archive_idx" ON "prx_597_notifications" ("user_id", "archived_at");

ALTER TABLE "prx_597_prefs" ADD COLUMN IF NOT EXISTS "in_app_enabled"   BOOLEAN      NOT NULL DEFAULT TRUE;
ALTER TABLE "prx_597_prefs" ADD COLUMN IF NOT EXISTS "telegram_enabled" BOOLEAN      NOT NULL DEFAULT FALSE;
ALTER TABLE "prx_597_prefs" ADD COLUMN IF NOT EXISTS "max_enabled"      BOOLEAN      NOT NULL DEFAULT FALSE;

-- ADD COLUMN IF NOT EXISTS не меняет умолчание уже существующей колонки, а
-- базы, поднятые до 16.10.2026, держат там TRUE: без этих строк новая строка
-- настроек получила бы включённые каналы «сами собой». Данные не трогаем —
-- только умолчание для будущих вставок.
ALTER TABLE "prx_597_prefs" ALTER COLUMN "email_enabled"    SET DEFAULT FALSE;
ALTER TABLE "prx_597_prefs" ALTER COLUMN "on_done"          SET DEFAULT FALSE;
ALTER TABLE "prx_597_prefs" ALTER COLUMN "on_failed"        SET DEFAULT FALSE;
ALTER TABLE "prx_597_prefs" ALTER COLUMN "in_app_enabled"   SET DEFAULT TRUE;
ALTER TABLE "prx_597_prefs" ALTER COLUMN "telegram_enabled" SET DEFAULT FALSE;
ALTER TABLE "prx_597_prefs" ALTER COLUMN "max_enabled"      SET DEFAULT FALSE;
ALTER TABLE "prx_597_prefs" ADD COLUMN IF NOT EXISTS "digest_mode"      VARCHAR(10)  NOT NULL DEFAULT 'off';
ALTER TABLE "prx_597_prefs" ADD COLUMN IF NOT EXISTS "quiet_from"       SMALLINT     NOT NULL DEFAULT -1;
ALTER TABLE "prx_597_prefs" ADD COLUMN IF NOT EXISTS "quiet_to"         SMALLINT     NOT NULL DEFAULT -1;
ALTER TABLE "prx_597_prefs" ADD COLUMN IF NOT EXISTS "tz"               VARCHAR(40)  NOT NULL DEFAULT 'Europe/Moscow';
ALTER TABLE "prx_597_prefs" ADD COLUMN IF NOT EXISTS "locale"           VARCHAR(8)   NOT NULL DEFAULT 'ru';
SQL,
		];
	}

	/** Триггер счётчика непрочитанных + первичный пересчёт. */
	public static function triggers()
	{
		return [
<<<'SQL'
CREATE OR REPLACE FUNCTION prx_597_unread_sync() RETURNS trigger AS $$
BEGIN
    IF (TG_OP = 'INSERT') THEN
        IF NEW."read_at" IS NULL THEN
            INSERT INTO "prx_597_unread" ("user_id", "count", "updated_at")
            VALUES (NEW."user_id", 1, now())
            ON CONFLICT ("user_id") DO UPDATE
                SET "count" = "prx_597_unread"."count" + 1, "updated_at" = now();
        END IF;
        RETURN NULL;
    END IF;

    IF (TG_OP = 'DELETE') THEN
        IF OLD."read_at" IS NULL THEN
            UPDATE "prx_597_unread" SET "count" = GREATEST("count" - 1, 0), "updated_at" = now()
             WHERE "user_id" = OLD."user_id";
        END IF;
        RETURN NULL;
    END IF;

    IF (TG_OP = 'UPDATE') THEN
        IF OLD."read_at" IS NULL AND NEW."read_at" IS NOT NULL THEN
            UPDATE "prx_597_unread" SET "count" = GREATEST("count" - 1, 0), "updated_at" = now()
             WHERE "user_id" = NEW."user_id";
        ELSIF OLD."read_at" IS NOT NULL AND NEW."read_at" IS NULL THEN
            INSERT INTO "prx_597_unread" ("user_id", "count", "updated_at")
            VALUES (NEW."user_id", 1, now())
            ON CONFLICT ("user_id") DO UPDATE
                SET "count" = "prx_597_unread"."count" + 1, "updated_at" = now();
        END IF;
        RETURN NULL;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS "prx_597_notifications_unread_trg" ON "prx_597_notifications";
CREATE TRIGGER "prx_597_notifications_unread_trg"
AFTER INSERT OR UPDATE OR DELETE ON "prx_597_notifications"
FOR EACH ROW EXECUTE FUNCTION prx_597_unread_sync();
SQL,
		];
	}

	/** Всё вместе: создание и обновление в правильном порядке. */
	public static function statements()
	{
		return array_merge(self::legacy(), self::tables(), self::alters(), self::triggers());
	}

	/**
	 * Накатить схему. Ошибка одного оператора не роняет остальные, но
	 * возвращается списком: управление показывает, что именно не вышло.
	 *
	 * @return array список ошибок (пусто — всё хорошо)
	 */
	public static function ensure()
	{
		$errors = [];
		$db = NULL;
		$locked = FALSE;
		try
		{
			$db = Database::instance();
			$val = self::_val('SELECT pg_try_advisory_lock(597599)', FALSE);
			if (self::_truthy($val))
			{
				$locked = TRUE;
			}
			else
			{
				// Кто-то уже накатывает DDL — не мешаем, считаем что схема жива
				return [];
			}
		}
		catch (Throwable $e)
		{
			// Нет БД или нет advisory — продолжаем без блокировки
		}

		try
		{
			foreach (self::statements() as $sql)
			{
				try
				{
					self::_exec(Database::UPDATE, $sql);
				}
				catch (Throwable $e)
				{
					$msg = (string) $e->getMessage();
					// Взаимоблокировка при конкурентном DDL — не ошибка, а гонка
					if (stripos($msg, 'взаимоблокировка') !== FALSE OR stripos($msg, 'deadlock') !== FALSE)
					{
						continue;
					}
					$errors[] = mb_substr($msg, 0, 200);
				}
			}
		}
		finally
		{
			if ($locked AND $db !== NULL)
			{
				try { $db->query(Database::SELECT, 'SELECT pg_advisory_unlock(597599)', FALSE); } catch (Throwable $e) {}
			}
		}

		return $errors;
	}
}
