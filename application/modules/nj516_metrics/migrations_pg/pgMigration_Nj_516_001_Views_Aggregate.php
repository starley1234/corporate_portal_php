<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj516: ежедневные агрегаты просмотров. Ни одного идентификатора посетителя,
 * IP, user-agent, исходного URL или поисковой фразы здесь нет.
 */
class pgMigration_Nj_516_001_Views_Aggregate extends pgMigration {

    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS nj_516_views (
    day        date        NOT NULL,
    path       varchar(200) NOT NULL,
    group_name varchar(80)  NOT NULL,
    source     varchar(16)  NOT NULL CHECK (source IN ('direct', 'internal', 'search', 'external')),
    audience   varchar(8)   NOT NULL CHECK (audience IN ('guest', 'logged')),
    views      bigint       NOT NULL DEFAULT 0 CHECK (views >= 0),
    updated_at timestamptz  NOT NULL DEFAULT now(),
    PRIMARY KEY (day, path, group_name, source, audience)
);
CREATE INDEX IF NOT EXISTS nj_516_views_day_idx ON nj_516_views (day DESC);
CREATE INDEX IF NOT EXISTS nj_516_views_path_day_idx ON nj_516_views (path, day DESC);
COMMENT ON TABLE nj_516_views IS 'nj516: обезличенные дневные агрегаты просмотров публичных сервисов; идентификаторов посетителя нет';
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS nj_516_views;');
    }
}
