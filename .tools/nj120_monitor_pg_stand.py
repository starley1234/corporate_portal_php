"""Стенд монитора воркеров (nj120) на ЖИВОМ PostgreSQL.

Поводом стала прод-ошибка: /nj120/monitor отдавал
`Database_Exception [ 0 ]: ОШИБКА: столбец ev.created_at не существует`.
PHP собрал SQL, который линтерам не виден как ошибка (запрос синтаксически
корректен), а база его отвергла: в LATERAL-подзапросе выбирали только
`e.message`, а снаружи читали `ev.created_at`. Поэтому здесь SQL, собранный
НАСТОЯЩИМ Service_Nj120_JobQueue (стенд .tools/nj120_monitor_stand.php),
исполняется на живом PostgreSQL — «монитор открывается» становится
проверяемым фактом, а не надеждой.

Таблицы берутся ИЗ МИГРАЦИЙ модуля (001/002/004) — стенд не разъедется с
схемой при её изменении. Дополнительно есть контроль: старый (сломанный)
вариант запроса обязан падать — иначе стенд «зелёный» ничего не значит.

Запуск:
    cd /home/user/n2j.ru
    N2J_TEST_PG_DSN='postgresql://postgres:@/postgres?host=/tmp/pgdata' \
    /tmp/wvenv/bin/python .tools/nj120_monitor_pg_stand.py
"""
import asyncio
import json
import os
import re

import asyncpg

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DSN = os.environ.get("N2J_TEST_PG_DSN", "postgresql://postgres:@/postgres?host=/tmp/pgdata")
SQL_DUMP = os.environ.get("NJ120_SQL_DUMP", "/tmp/nj120_sql.json")

DDL_FILES = [
    "application/modules/nj120_jobs/migrations_pg/pgMigration_Nj_120_001_Jobs_CreateTables.php",
    "application/modules/nj120_jobs/migrations_pg/pgMigration_Nj_120_002_Jobs_Visitor.php",
    "application/modules/nj120_jobs/migrations_pg/pgMigration_Nj_120_004_Usage_Accounting.php",
]

FAILS = []
CHECKS = 0


def check(label, got, want):
    global CHECKS
    CHECKS += 1
    ok = got == want
    if not ok:
        FAILS.append(label)
    print("%-5s %s" % ("ОК" if ok else "ПЛОХО", label if ok else "%s :: получено %r, ожидалось %r" % (label, got, want)))


async def connect():
    try:
        return await asyncpg.connect(DSN)
    except Exception:
        import pgserver
        pgserver.get_server("/tmp/pgdata")
        return await asyncpg.connect(DSN)


def schema_sql():
    """CREATE/ALTER из миграций модуля — как есть, без переписывания руками."""
    out = []
    for rel in DDL_FILES:
        text = open(os.path.join(REPO, rel), encoding="utf-8").read()
        blocks = re.findall(r"<<<SQL\n(.*?)\n\s*SQL\b", text, re.S)
        blocks += re.findall(r'run_query\("(.*?)"\)', text, re.S)
        blocks += re.findall(r"DB::query\(NULL,\s*(?:\"|')(.*?)(?:\"|')\s*,?\s*\)", text, re.S)
        for block in blocks:
            for stmt in [s.strip() for s in block.split(";") if s.strip()]:
                head = stmt.split(None, 1)[0].upper()
                if head in ("CREATE", "ALTER", "COMMENT"):
                    out.append(stmt)
    return out


SEED_SCHEMA = """
DROP TABLE IF EXISTS nj_120_usage, nj_120_job_events, nj_120_jobs, users CASCADE;

-- users — приложение (erp100_base/001), для JOIN в мониторе нужен минимум
CREATE TABLE users (id bigint PRIMARY KEY, username text);
"""

SEED_ROWS = """
INSERT INTO users (id, username) VALUES (7, 'admin');

INSERT INTO nj_120_jobs
    (id, channel, payload, status, attempts, max_attempts, created_by, claimed_by,
     claimed_at, heartbeat_at, started_at, finished_at, visitor_id, created_at, available_at)
VALUES
    (969, 'semantic.index', '{"collection":"nj560_knowledge"}', 'processing', 1, 3, 7, 'worker-1',
     now() - interval '10 minutes', now() - interval '30 seconds', now() - interval '10 minutes',
     NULL, 'u:7', now() - interval '11 minutes', now() - interval '11 minutes'),
    (970, 'semantic.index', '{"collection":"nj560_knowledge"}', 'done', 1, 3, 7, 'worker-1',
     now() - interval '2 hours', now() - interval '110 minutes', now() - interval '2 hours',
     now() - interval '105 minutes', 'u:7', now() - interval '121 minutes', now() - interval '121 minutes'),
    (971, 'nj560.digest', '{"days":7}', 'failed', 3, 3, NULL, 'worker-2',
     now() - interval '3 hours', now() - interval '160 minutes', now() - interval '3 hours',
     now() - interval '2 hours', NULL, now() - interval '181 minutes', now() - interval '181 minutes');

INSERT INTO nj_120_job_events (job_id, event, message, created_at) VALUES
    (969, 'claimed', 'взял воркер worker-1', now() - interval '10 minutes'),
    (969, 'progress', 'Считаю векторы 2/48', now() - interval '55 seconds'),
    (969, 'progress', 'Считаю векторы 3/48', now() - interval '25 seconds');
"""


def old_buggy(sql):
    """Тот самый запрос, что уронил прод: LATERAL без created_at."""
    return sql.replace("SELECT e.message, e.created_at FROM nj_120_job_events e",
                       "SELECT e.message FROM nj_120_job_events e")


async def main():
    queries = json.load(open(SQL_DUMP, encoding="utf-8"))["queries"]
    print("SQL собрано PHP: %d\n" % len(queries))

    conn = await connect()

    await conn.execute(SEED_SCHEMA)
    for stmt in schema_sql():
        await conn.execute(stmt)

    await conn.execute(SEED_ROWS)

    cols = [r["column_name"] for r in await conn.fetch(
        "SELECT column_name FROM information_schema.columns WHERE table_name = 'nj_120_job_events'")]
    check("схема: журнал событий имеет created_at (его читает монитор)", "created_at" in cols, True)

    print("\n== Монитор открывается: SQL, собранный PHP, исполняется на живом PostgreSQL ==")
    stage_ok = None
    broken = []
    for q in queries:
        try:
            rows = await conn.fetch(q["sql"])
        except Exception as e:                       # noqa: BLE001 — нужен любой отказ базы
            broken.append("%s … %s" % (q["sql"].strip().splitlines()[0][:60], str(e).strip().splitlines()[0]))
            continue
        if "ev.message AS stage" in q["sql"] and "j.status = 'processing'" in q["sql"]:
            stage_ok = rows

    check("все запросы монитора прошли (нет «столбец не существует»)", broken, [])

    check("«чем занят воркер»: этап пришёл из журнала", stage_ok[0]["stage"] if stage_ok else None,
          "Считаю векторы 3/48")
    check("«чем занят воркер»: время этапа — свежее событие, а не пусто",
          stage_ok[0]["stage_at"] is not None if stage_ok else False, True)
    check("«чем занят воркер»: в списке только processing", len(stage_ok or []), 1)

    print("\n== Уборка очереди: живого не трогает, зомби закрывает ==")
    updates = [q["sql"] for q in queries if q["sql"].strip().startswith("UPDATE nj_120_jobs")]
    check("правила уборки собраны (UPDATE-ов не меньше пяти)", len(updates) >= 5, True)

    alive = await conn.fetchval("SELECT status FROM nj_120_jobs WHERE id = 969")
    check("живая задача с пульсом после уборки осталась в работе", alive, "processing")

    # Зомби: пульса нет, захвачена давно — та самая строка, что висела вечно.
    await conn.execute("""INSERT INTO nj_120_jobs
        (id, channel, payload, status, attempts, max_attempts, timeout_sec, claimed_by,
         claimed_at, heartbeat_at, started_at, created_at, available_at)
        VALUES (972, 'semantic.index', '{}'::jsonb, 'processing', 1, 3, 600, 'worker-3',
                now() - interval '3 days', NULL, now() - interval '3 days',
                now() - interval '3 days', now() - interval '3 days')""")

    for sql in updates:
        try:
            await conn.fetch(sql)
        except Exception as e:                       # noqa: BLE001
            FAILS.append("уборка: %s" % str(e).strip().splitlines()[0])

    zombie = await conn.fetchrow("SELECT status, error FROM nj_120_jobs WHERE id = 972")
    check("зомби закрыт уборкой (не остался в «Выполняется»)", zombie["status"], "cancelled")
    # Первым срабатывает самое раннее правило (пульс молчит — авто-стоп), оно и
    # подписывает причину: «остановлен автоматически».
    check("причина закрытия записана человеческим языком",
          "автоматически" in (zombie["error"] or ""), True)

    still = await conn.fetchval("SELECT status FROM nj_120_jobs WHERE id = 969")
    check("после уборки живая задача по-прежнему в работе", still, "processing")

    # Контроль: если из подзапроса убрать created_at, база обязана отказать —
    # именно так упал прод, и стенд это ловит.
    bug_sql = next(q["sql"] for q in queries
                   if "ev.message AS stage" in q["sql"] and "j.status = 'processing'" in q["sql"])
    try:
        await conn.fetch(old_buggy(bug_sql))
        check("контроль: старый запрос без created_at не проходит", "прошёл (стенд бесполезен)", "ошибка базы")
    except asyncpg.exceptions.UndefinedColumnError as e:
        check("контроль: старый запрос без created_at ловится базой", "created_at" in str(e), True)

    await conn.close()

    print("\n" + ("ВСЁ ОК" if not FAILS else "ПРОВАЛОВ: %d — %s" % (len(FAILS), "; ".join(FAILS))))
    print("проверок: %d" % CHECKS)


asyncio.run(main())
