<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-657 «Навыки Алисы»: таблицы навыков и журнала диалогов.
 *
 * nj_657_skills — настройки навыка (одна строка = один навык Яндекс.Диалогов):
 * промпт, модель, память, кнопки, тексты сценариев, привязка к skill_id Яндекса.
 * nj_657_dialogs — журнал «вопрос → ответ» с мета-данными ответа; он же —
 * память диалога (последние реплики уходят в контекст ИИ).
 *
 * Идемпотентность: CREATE TABLE IF NOT EXISTS + индексы IF NOT EXISTS +
 * посев навыка по умолчанию ON CONFLICT DO NOTHING.
 */
class pgMigration_Nj_657_001_Alice_CreateTables extends pgMigration
{
	public function up()
	{
		$this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_657_skills" (
	id                   BIGSERIAL PRIMARY KEY,
	code                 VARCHAR(12)  NOT NULL UNIQUE,
	title                VARCHAR(100) NOT NULL,
	yandex_skill_id      VARCHAR(64)  NOT NULL DEFAULT '',
	webhook_token        VARCHAR(64)  NOT NULL DEFAULT '',
	is_active            BOOLEAN      NOT NULL DEFAULT TRUE,
	is_default           BOOLEAN      NOT NULL DEFAULT FALSE,
	system_text          TEXT         NOT NULL DEFAULT '',
	activation_names     TEXT         NOT NULL DEFAULT '',
	welcome_text         TEXT         NOT NULL DEFAULT '',
	welcome_tts          TEXT         NOT NULL DEFAULT '',
	help_text            TEXT         NOT NULL DEFAULT '',
	farewell_text        TEXT         NOT NULL DEFAULT '',
	fallback_text        TEXT         NOT NULL DEFAULT '',
	model                VARCHAR(120) NOT NULL DEFAULT '',
	temperature          NUMERIC(4,3) NOT NULL DEFAULT 0.200,
	max_tokens           INT          NOT NULL DEFAULT 400,
	answer_max_chars     INT          NOT NULL DEFAULT 1024,
	history_depth        INT          NOT NULL DEFAULT 6,
	history_window_min   INT          NOT NULL DEFAULT 120,
	end_session          BOOLEAN      NOT NULL DEFAULT FALSE,
	buttons_text         TEXT         NOT NULL DEFAULT '',
	mqtt_topic           VARCHAR(200) NOT NULL DEFAULT '',
	created_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
	updated_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
	CHECK (temperature >= 0 AND temperature <= 2),
	CHECK (max_tokens > 0 AND max_tokens <= 4000),
	CHECK (answer_max_chars >= 100 AND answer_max_chars <= 4000),
	CHECK (history_depth >= 0 AND history_depth <= 30),
	CHECK (history_window_min >= 1 AND history_window_min <= 10080)
);

-- Один навык = один skill_id Яндекса (если привязка задана).
CREATE UNIQUE INDEX IF NOT EXISTS "nj_657_skills_yandex_idx"
	ON "nj_657_skills" (yandex_skill_id) WHERE yandex_skill_id <> '';

-- Токен вебхука уникален, если задан.
CREATE UNIQUE INDEX IF NOT EXISTS "nj_657_skills_token_idx"
	ON "nj_657_skills" (webhook_token) WHERE webhook_token <> '';

-- Навык «по умолчанию» может быть только один.
CREATE UNIQUE INDEX IF NOT EXISTS "nj_657_skills_default_idx"
	ON "nj_657_skills" (is_default) WHERE is_default;

CREATE TABLE IF NOT EXISTS "nj_657_dialogs" (
	id                 BIGSERIAL PRIMARY KEY,
	skill_id           BIGINT       NOT NULL REFERENCES "nj_657_skills"(id) ON DELETE CASCADE,
	user_key           VARCHAR(40)  NOT NULL DEFAULT '',
	yandex_session_id  VARCHAR(80)  NOT NULL DEFAULT '',
	message_id         INT          NOT NULL DEFAULT 0,
	question_text      TEXT         NOT NULL DEFAULT '',
	answer_text        TEXT         NOT NULL DEFAULT '',
	intent             VARCHAR(20)  NOT NULL,
	source             VARCHAR(10)  NOT NULL DEFAULT 'yandex',
	meta               JSONB        NOT NULL DEFAULT '{}'::jsonb,
	created_at         TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Память диалога: последние реплики пользователя навыка.
CREATE INDEX IF NOT EXISTS "nj_657_dialogs_context_idx"
	ON "nj_657_dialogs" (skill_id, user_key, id DESC);

-- Защита от повторной обработки одного и того же сообщения Яндекса
-- (Яндекс повторяет вебхук, если не дождался ответа).
CREATE UNIQUE INDEX IF NOT EXISTS "nj_657_dialogs_dedup_idx"
	ON "nj_657_dialogs" (yandex_session_id, message_id)
	WHERE yandex_session_id <> '';

CREATE INDEX IF NOT EXISTS "nj_657_dialogs_created_idx"
	ON "nj_657_dialogs" (created_at DESC);

-- Навык по умолчанию: чтобы вебхук работал сразу после миграции, как
-- «Мой диалог» из наработок source_data (принимает любой запрос).
INSERT INTO "nj_657_skills" (
	code, title, is_default, welcome_text, help_text
) VALUES (
	'maindialog1',
	'Мой диалог',
	TRUE,
	'Здравствуйте! Я голосовой навык с искусственным интеллектом. Спросите меня о чём угодно.',
	'Я отвечаю на любые вопросы с помощью искусственного интеллекта и помню последние реплики нашего разговора. Просто спросите. Скажите «выход», чтобы закончить.'
)
ON CONFLICT (code) DO NOTHING;
SQL);
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS "nj_657_dialogs", "nj_657_skills" CASCADE;');
	}
}
