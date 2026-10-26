<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-600/003: рекомендательный слой под надзором администратора.
 *
 * nj_600_reco_rules — правила администратора: «кому» (раздел интереса и/или
 *   конкретный владелец) → «что» (href сервиса) с весом; kind=block запрещает
 *   рекомендовать href (всем или одному владельцу). Правила правятся на
 *   /nj600/admin/reco и в карточке владельца /nj600/admin/owner/<key>.
 *
 * nj_600_reco_log — журнал показов: что, кому, по какой причине (источник:
 *   rule / covisit / group / manual), клик и отказ. Это обратная связь для
 *   администратора (CTR по источникам и сервисам) и память «не повторять
 *   отклонённое». Чистится реестром /nj100/cleanup (90 дней).
 */
class pgMigration_Nj_600_003_Reco_Tables extends pgMigration {

	public function up()
	{
		$this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_600_reco_rules" (
    "id"           BIGSERIAL    PRIMARY KEY,
    "kind"         VARCHAR(8)   NOT NULL DEFAULT 'boost',
    "when_section" VARCHAR(60)  NOT NULL DEFAULT '',
    "when_owner"   VARCHAR(40)  NOT NULL DEFAULT '',
    "href"         VARCHAR(200) NOT NULL,
    "title"        VARCHAR(120) NOT NULL DEFAULT '',
    "note"         VARCHAR(300) NOT NULL DEFAULT '',
    "weight"       INTEGER      NOT NULL DEFAULT 50,
    "enabled"      BOOLEAN      NOT NULL DEFAULT TRUE,
    "created_by"   BIGINT       NULL,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_600_reco_rules_owner_idx" ON "nj_600_reco_rules" ("when_owner");
COMMENT ON TABLE "nj_600_reco_rules" IS 'NJ-600 правила рекомендаций администратора: boost (советовать) / block (не советовать); when_section/when_owner пустые = всем';

CREATE TABLE IF NOT EXISTS "nj_600_reco_log" (
    "id"           BIGSERIAL    PRIMARY KEY,
    "owner_key"    VARCHAR(40)  NOT NULL,
    "href"         VARCHAR(200) NOT NULL,
    "source"       VARCHAR(12)  NOT NULL DEFAULT 'covisit',
    "rule_id"      BIGINT       NULL,
    "reason"       VARCHAR(200) NOT NULL DEFAULT '',
    "shown_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "clicked_at"   TIMESTAMPTZ  NULL,
    "dismissed_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_600_reco_log_owner_idx" ON "nj_600_reco_log" ("owner_key", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_600_reco_log_shown_idx" ON "nj_600_reco_log" ("shown_at");
COMMENT ON TABLE "nj_600_reco_log" IS 'NJ-600 журнал рекомендаций: показ/клик/отказ по владельцу (TTL 90 дней через /nj100/cleanup)';
SQL);
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS "nj_600_reco_log"');
		$this->run_query('DROP TABLE IF EXISTS "nj_600_reco_rules"');
	}
}
