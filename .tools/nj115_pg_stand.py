"""Стенд nj115_semantic на ЖИВОМ PostgreSQL: SQL, который построил PHP, исполняется по-настоящему.

Проверяем три вещи, которые нельзя проверить стабами:
  1) покрытие считает именно то, что нужно: свежий текст / изменившийся / удалённый;
  2) «Догнать» выбирает РОВНО отставшие строки (не свежие, без мусора);
  3) «Убрать мусор» удаляет только векторы исчезнувших записей.

Скрипт берёт SQL из /tmp/nj115_sql.json — его построил настоящий
Service_Nj115_Semantic на стенде phpwasm (t/nj115_coverage.php).
"""
import asyncio
import hashlib
import json

import asyncpg

import os

# Живой PostgreSQL. В песочнице — локальный сервер из /tmp/wvenv (pgserver).
DSN = os.environ.get("N2J_TEST_PG_DSN", "postgresql://postgres:@/postgres?host=/tmp/pgdata")
SQL_DUMP = os.environ.get("NJ115_SQL_DUMP", "/tmp/nj115_sql.json")


async def connect():
    try:
        return await asyncpg.connect(DSN)
    except Exception:
        import pgserver
        pgserver.get_server("/tmp/pgdata")
        return await asyncpg.connect(DSN)


SEED = """
DROP TABLE IF EXISTS nj_115_vectors, nj_115_collections, nj_560_docs, nj_115_searches CASCADE;

CREATE TABLE nj_115_collections (
    id serial PRIMARY KEY, name text UNIQUE, description text DEFAULT '',
    updated_at timestamptz DEFAULT now());

CREATE TABLE nj_115_vectors (
    id serial PRIMARY KEY,
    collection_id int REFERENCES nj_115_collections(id),
    entity text, entity_id text, content text, metadata jsonb DEFAULT '{}'::jsonb,
    embedding real[], model text, dims int, content_hash varchar(64) DEFAULT '',
    created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now(),
    UNIQUE (collection_id, entity, entity_id));

CREATE TABLE nj_560_docs (
    id bigserial PRIMARY KEY, title text, content text,
    source_module text DEFAULT 'nj560', kind text DEFAULT 'note');

INSERT INTO nj_560_docs (id, title, content) VALUES
    (1, 'Свежий',      'текст 1'),
    (2, 'Изменённый',  'новый текст 2'),
    (4, 'Без вектора', 'текст 4');

INSERT INTO nj_115_collections (name, description) VALUES ('nj560_knowledge', 'База знаний');

-- 1: вектор совпадает с текущим текстом (свежий)
INSERT INTO nj_115_vectors (collection_id, entity, entity_id, content, embedding, model, dims, content_hash)
SELECT c.id, 'doc', '1', 'Свежий' || E'\\n' || 'текст 1', ARRAY[0.1,0.2,0.3], 'stub', 3,
       md5('Свежий' || E'\\n' || 'текст 1')
FROM nj_115_collections c WHERE c.name = 'nj560_knowledge';

-- 2: текст с тех пор изменился (устаревший): хеш от старого текста
INSERT INTO nj_115_vectors (collection_id, entity, entity_id, content, embedding, model, dims, content_hash)
SELECT c.id, 'doc', '2', 'Изменённый' || E'\\n' || 'старый текст 2', ARRAY[0.2,0.2,0.3], 'stub', 3,
       md5('Изменённый' || E'\\n' || 'старый текст 2')
FROM nj_115_collections c WHERE c.name = 'nj560_knowledge';

-- 3: записи в источнике уже нет, вектор остался (мусор), обновлялся давно
INSERT INTO nj_115_vectors (collection_id, entity, entity_id, content, embedding, model, dims, content_hash, updated_at)
SELECT c.id, 'doc', '3', 'Удалённая запись', ARRAY[0.3,0.2,0.1], 'stub', 3,
       md5('Удалённая запись'), now() - interval '10 days'
FROM nj_115_collections c WHERE c.name = 'nj560_knowledge';
"""

FAILS = []


def check(label, got, want):
    ok = got == want
    if not ok:
        FAILS.append(label)
    print(("ОК   " if ok else "ПЛОХО") + f" {label}" + ("" if ok else f" :: получили {got!r}, ждали {want!r}"))


async def main():
    sql = json.load(open(SQL_DUMP))
    conn = await connect()

    await conn.execute(SEED)

    print("== Покрытие источников: свежий / изменённый / удалённый ==")
    row = await conn.fetchrow(sql["coverage"])
    total, have, fresh, orphans = row["total"], row["have"], row["fresh"], row["orphans"]
    check("всего записей в источнике (1, 2, 4)", total, 3)
    check("векторов в коллекции (1, 2, 3-мусор)", have, 3)
    check("актуальных (только id 1)", fresh, 1)
    check("мусорных (id 3 нет в источнике)", orphans, 1)
    check("без вектора: total − have", max(0, total - have), 0)
    check("устаревших: have − fresh", max(0, have - fresh), 2)
    check("покрытие, как посчитает PHP", round(fresh * 100 / total, 1), 33.3)

    print("\n== Догон: какие именно строки уходят в индексацию ==")
    rows = await conn.fetch(sql["reindex"])
    ids = [int(r["id"]) for r in rows]   # id приходит текстом: entity_id вектора тоже текст
    check("отобраны ровно отставшие: id 2 (устарел) и 4 (без вектора)", sorted(ids), [2, 4])
    check("свежий текст (id 1) в догон не попал", 1 in ids, False)
    check("мусорный вектор (id 3) источник не отдаёт", 3 in ids, False)
    texts = {int(r["id"]): r["content"] for r in rows}
    check("текст берётся тем же способом, что уходил в index_texts",
          texts.get(2), "Изменённый\nновый текст 2")
    check("хеш текста совпадает с python/PHP md5 (ключ общий)",
          hashlib.md5(texts[2].encode()).hexdigest(), hashlib.md5("Изменённый\nновый текст 2".encode()).hexdigest())
    meta = rows[0]["metadata"]
    check("метаданные приходят jsonb (не пустые)", meta is not None and '"source"' in str(meta) and '"kind"' in str(meta), True)

    print("\n== Уборка мусора ==")
    cnt = await conn.fetchrow(sql["prune_cnt"])
    check("счётчик мусора до уборки", cnt["cnt"], 1)
    await conn.execute(sql["prune_del"])
    left = await conn.fetchval("SELECT COUNT(*) FROM nj_115_vectors")
    check("после уборки осталось 2 вектора (только живые записи)", left, 2)
    gone = await conn.fetchval("SELECT COUNT(*) FROM nj_115_vectors WHERE entity_id = '3'")
    check("вектор удалённой записи удалён", gone, 0)
    row = await conn.fetchrow(sql["coverage"])
    check("после уборки мусора нет", row["orphans"], 0)
    check("после уборки отстают только реально отставшие (1 устарел, 1 без вектора)",
          (row["total"] - row["have"], row["have"] - row["fresh"]), (1, 1))

    cnt = await conn.fetchrow(sql["prune_cnt"])
    check("повторная уборка ничего не находит", cnt["cnt"], 0)

    print("\n== Предохранитель по времени ==")
    await conn.execute("UPDATE nj_115_vectors SET updated_at = now() WHERE entity_id = '2'")
    await conn.execute("DELETE FROM nj_560_docs WHERE id = 2")   # теперь и 2 — мусор, но свежий
    cnt = await conn.fetchrow(sql["prune_cnt"])
    check("свежий по updated_at вектор не считается мусором", cnt["cnt"], 0)
    await conn.execute("UPDATE nj_115_vectors SET updated_at = now() - interval '10 days' WHERE entity_id = '2'")
    cnt = await conn.fetchrow(sql["prune_cnt"])
    check("а старый — считается (защита от случайной потери)", cnt["cnt"], 1)

    print("\n== Журнал поисков: таблица из миграции 003 и реальные SQL сервиса ==")
    import re

    migration = open(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                  "../application/modules/nj115_semantic/migrations_pg/"
                                  "pgMigration_Nj_115_003_Semantic_Searches.php"), encoding="utf-8").read()
    m = re.search(r'CREATE TABLE IF NOT EXISTS nj_115_searches.*?\)"', migration, re.S)
    check("миграция 003 содержит CREATE TABLE журнала", m is not None, True)
    await conn.execute(m.group(0)[:m.group(0).rfind(")") + 1])

    cols = [r["column_name"] for r in await conn.fetch(
        "SELECT column_name FROM information_schema.columns WHERE table_name = 'nj_115_searches'")]
    check("таблица журнала создаётся на живом PostgreSQL",
          {"collection", "entity", "query", "results", "vector_hits", "text_hits",
           "cached", "ms", "error", "user_id", "created_at"} <= set(cols), True)

    # SQL, который построил PHP: успешный поиск с апострофом в запросе
    await conn.execute(sql["search_ins"])
    row = await conn.fetchrow("SELECT * FROM nj_115_searches ORDER BY id DESC LIMIT 1")
    check("журнал: запись успешного поиска принимается базой", (row["collection"], row["results"], row["vector_hits"]), ("nj560_knowledge", 2, 1))
    check("журнал: попадания по словам отделены от смысла", row["text_hits"], 1)
    check("журнал: время вызова и автор сохранены", (row["ms"], row["user_id"], row["cached"]), (42, 7, False))
    check("журнал: апостроф в запросе не сломал SQL и не потерялся", row["query"], "бюджет на \'квартал\'")

    # сводка за сутки: окно, пустые, ошибки, кэш и среднее без кэша
    await conn.execute("""INSERT INTO nj_115_searches
        (collection, entity, query, results, vector_hits, text_hits, cached, ms, error, user_id)
        VALUES ('nj560_knowledge', '', 'совсем не нашлось', 0, 0, 0, TRUE, 0, '', 1),
               ('nj560_knowledge', '', 'падение', 0, 0, 0, FALSE, 5, 'эмбеддинг-сервер: нет ответа', NULL),
               ('nj560_knowledge', '', 'повтор', 3, 3, 0, TRUE, 0, '', 1)""")

    await conn.execute("""INSERT INTO nj_115_searches
        (collection, entity, query, results, vector_hits, text_hits, cached, ms, error, user_id, created_at)
        VALUES ('nj560_knowledge', '', 'старый запрос', 1, 1, 0, FALSE, 10, '', 1, now() - interval '30 hours')""")
    st = await conn.fetchrow(sql["search_stats"])
    check("журнал: в окно суток попали 4 свежих записи (старая — нет)", st["total"], 4)
    check("журнал: «пусто» считается только без ошибки", st["empty"], 1)
    check("журнал: ошибки посчитаны отдельно", st["errors"], 1)
    check("журнал: попадания в кэш посчитаны", st["cached"], 2)
    check("журнал: среднее время — без записей из кэша", round(float(st["avg_ms"]), 1), 23.5)

    rows = await conn.fetch(sql["search_sel"])
    check("журнал: последние запросы идут в обратном порядке записи (ORDER BY id DESC)",
          [r["query"] for r in rows][:4], ["старый запрос", "повтор", "падение", "совсем не нашлось"])

    # чистка: 215 записей → остаются последние 200 (LIMIT берётся из конфига)
    await conn.execute("INSERT INTO nj_115_searches (collection, query, results) "
                       "SELECT 'nj560_knowledge', 'мусор ' || g, 0 FROM generate_series(1, 210) g")
    before = await conn.fetchval("SELECT COUNT(*) FROM nj_115_searches")
    ids_before = [r["id"] for r in await conn.fetch("SELECT id FROM nj_115_searches ORDER BY id")]
    await conn.execute(sql["search_sweep"])
    ids_after = [r["id"] for r in await conn.fetch("SELECT id FROM nj_115_searches ORDER BY id")]
    check("журнал: чистка срабатывает на 215 записях", before, 215)
    check("журнал: остаётся ровно searches.keep строк", len(ids_after), 200)
    check("журнал: выживают последние записи, старые уходят", ids_after, ids_before[-200:])

    await conn.close()

    print("\n" + ("ВСЁ ОК" if not FAILS else "ПРОВАЛОВ: %d — %s" % (len(FAILS), "; ".join(FAILS))))


asyncio.run(main())
