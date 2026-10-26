<?php defined('SYSPATH') or die('No direct script access.');

/** Личная история показаний: адрес и имя владельца намеренно не хранятся. */
class pgMigration_Nj_656_001_Meters_CreateTables extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_656_meters" (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(10) NOT NULL UNIQUE,
    owner_key   VARCHAR(64) NOT NULL,
    title       VARCHAR(60) NOT NULL,
    unit        VARCHAR(8) NOT NULL,
    tariff      NUMERIC(14,4) NOT NULL,
    budget_rub  NUMERIC(14,2) NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (tariff > 0),
    CHECK (budget_rub >= 0)
);
CREATE INDEX IF NOT EXISTS "nj_656_meters_owner_updated_idx"
    ON "nj_656_meters" (owner_key, updated_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS "nj_656_readings" (
    id          BIGSERIAL PRIMARY KEY,
    meter_id    BIGINT NOT NULL REFERENCES "nj_656_meters"(id) ON DELETE CASCADE,
    reading_on  DATE NOT NULL,
    value       NUMERIC(16,3) NOT NULL,
    tariff      NUMERIC(14,4) NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (meter_id, reading_on),
    CHECK (value >= 0),
    CHECK (tariff > 0)
);
CREATE INDEX IF NOT EXISTS "nj_656_readings_meter_date_idx"
    ON "nj_656_readings" (meter_id, reading_on, id);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_656_readings", "nj_656_meters" CASCADE;');
    }
}
