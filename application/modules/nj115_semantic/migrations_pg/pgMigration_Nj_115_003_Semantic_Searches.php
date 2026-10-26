<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция NJ-115 (3): журнал поисковых запросов.
 *
 * Зачем журнал, а не только счётчики кэша: счётчики говорят «сколько запросов
 * в кэше», но не говорят, С КАКИМ РЕЗУЛЬТАТОМ искали. Панель /nj115 должна
 * отвечать на вопрос владельца «поиск реально что-то находит?» — для этого
 * храним последние запросы: сколько нашлось, что нашлось впервые (вектор или
 * текст), сколько занял вызов эмбеддинг-сервера (0 — ответ из кэша) и ошибку,
 * если поиск не удался.
 *
 * Таблица маленькая и чистится сама (Service_Nj115_Semantic::log_search()):
 * держим не больше searches.keep строк — журнал нужен «на посмотреть», а не
 * как архив.
 */
class pgMigration_Nj_115_003_Semantic_Searches extends pgMigration {

    public function up()
    {
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_115_searches
        (
          id          bigserial PRIMARY KEY,
          collection  varchar(64)  NOT NULL,
          entity      varchar(128) NOT NULL DEFAULT '',
          query       varchar(500) NOT NULL DEFAULT '',
          results     integer      NOT NULL DEFAULT 0,
          vector_hits integer      NOT NULL DEFAULT 0,
          text_hits   integer      NOT NULL DEFAULT 0,
          cached      boolean      NOT NULL DEFAULT FALSE,
          ms          integer      NOT NULL DEFAULT 0,
          error       varchar(500) NOT NULL DEFAULT '',
          user_id     integer      NULL,
          created_at  timestamptz  NOT NULL DEFAULT now()
        )");

        $this->run_query("CREATE INDEX IF NOT EXISTS nj_115_searches_created_idx
            ON nj_115_searches (created_at DESC)");

        $this->run_query("COMMENT ON TABLE nj_115_searches IS
            'NJ-115: журнал поисковых запросов — видно, что искали и что нашлось'");
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS nj_115_searches;");
    }
}
