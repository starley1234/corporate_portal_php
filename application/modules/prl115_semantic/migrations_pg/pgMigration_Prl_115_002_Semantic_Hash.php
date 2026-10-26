<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция PRL-115 (2): хеш содержимого вектора и кэш эмбеддингов запросов.
 *
 * content_hash (md5 текста) решает три задачи, без которых модуль «работает,
 * но непонятно как»:
 *   1. повторная индексация НЕ пересчитывает векторы у неизменившихся текстов
 *      (каждый вызов эмбеддинг-сервера — деньги владельца);
 *   2. «догоняющая» индексация (кнопка «Догнать» на /prl115) находит именно
 *      отставшие/новые записи источников;
 *   3. покрытие в процентах считается ОДНИМ SQL-запросом: PostgreSQL умеет
 *      md5(), поэтому «свежий ли вектор» сравнивается в базе, без выгрузки
 *      текстов в PHP.
 *
 * Почему md5, а не sha256: он есть и в PHP (md5()), и в python (hashlib.md5),
 * и в самом PostgreSQL (md5(text)) — значит сравнение возможно на всех трёх
 * сторонах. Это ключ кэша, а не защита: коллизии здесь безобидны.
 *
 * prl_115_query_cache — кэш векторов поисковых запросов (jsonb, без зависимости
 * от pgvector, чтобы таблица одинаково работала в обоих режимах).
 */
class pgMigration_Prl_115_002_Semantic_Hash extends pgMigration {

    public function up()
    {
        // 1. Хеш текста у вектора. ADD COLUMN IF NOT EXISTS + DEFAULT '' —
        //    на существующих строках пусто, поэтому первый же проход «Догнать»
        //    переиндексирует их и заполнит хеши (старые векторы не теряются).
        $this->run_query("ALTER TABLE prl_115_vectors
            ADD COLUMN IF NOT EXISTS content_hash varchar(64) NOT NULL DEFAULT ''");

        $this->run_query("CREATE INDEX IF NOT EXISTS prl_115_vectors_hash_idx
            ON prl_115_vectors (collection_id, entity, content_hash)");

        $this->run_query("COMMENT ON COLUMN prl_115_vectors.content_hash IS
            'md5 текста вектора: неизменившиеся тексты не пересчитываем'");

        // 2. Кэш эмбеддингов поисковых запросов.
        $this->run_query("CREATE TABLE IF NOT EXISTS prl_115_query_cache
        (
          qhash      varchar(64) PRIMARY KEY,
          query      text NOT NULL DEFAULT '',
          model      varchar(128) NOT NULL DEFAULT '',
          vector     jsonb NOT NULL,
          dims       integer NOT NULL DEFAULT 0,
          hits       integer NOT NULL DEFAULT 0,
          created_at timestamptz NOT NULL DEFAULT now(),
          used_at    timestamptz NOT NULL DEFAULT now()
        )");

        $this->run_query("CREATE INDEX IF NOT EXISTS prl_115_query_cache_used_idx
            ON prl_115_query_cache (used_at)");

        $this->run_query("COMMENT ON TABLE prl_115_query_cache IS
            'PRL-115: кэш векторов поисковых запросов (один запрос — один вызов эмбеддинг-сервера)'");
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prl_115_query_cache;");
        $this->run_query("DROP INDEX IF EXISTS prl_115_vectors_hash_idx;");
        $this->run_query("ALTER TABLE prl_115_vectors DROP COLUMN IF EXISTS content_hash;");
    }
}
