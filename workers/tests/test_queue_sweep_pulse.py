"""Свипер очереди: строка без пульса НЕ должна становиться бессмертной.

Повод (владелец, 28.09): «Почему монитор воркеров всегда показывает зависший
процесс?» — на мониторе задача «Семантический индекс (поиск)» висела в
«Выполняется» с попытками 3/3.

Разбор на локальном PostgreSQL показал дыру: все правила `PgQueue._sweep_stale`
сравнивали ГОЛЫЙ `heartbeat_at`, а `NULL < now() - interval` в SQL — это NULL,
то есть «не подходит». Строка с пустым пульсом (легаси-задача, ручная правка,
строка от старого воркера) не закрывалась НИКОГДА: сайтовый монитор считал её
застрявшей (`heartbeat_at IS NULL OR …`), а свиперы её не видели. Монитор
показывал «застряли: 1» вечно.

Теперь все проверки живости идут через PULSE_SQL (heartbeat_at → claimed_at →
started_at → created_at — created_at NOT NULL по схеме, поэтому без присмотра не
остаётся ни одна строка). Тесты держат это правило.

Функциональная проверка (необязательная): если задан PRX_TEST_PG_DSN, тест
поднимает таблицы и реально прогоняет свипер — так проверено 28.09 на локальном
PostgreSQL 16.
"""
import os
import secrets
import re

import pytest

from prx_workers.queue import PULSE_SQL, PgQueue


class _Conn:
    """Заглушка соединения: запоминает отправленный SQL."""

    def __init__(self):
        self.sql = []

    async def execute(self, sql, *args):
        self.sql.append(sql)


def _sweep_source() -> str:
    import inspect

    return inspect.getsource(PgQueue._sweep_stale)


def test_pulse_expression_has_all_fallbacks():
    assert PULSE_SQL == "coalesce(heartbeat_at, claimed_at, started_at, created_at)"


def test_all_liveness_rules_use_the_pulse_expression():
    src = _sweep_source()

    # Четыре правила: авто-стоп молчащих, «молчал дольше timeout», возврат в
    # очередь, отказ исчерпанных.
    assert src.count("PULSE_SQL") >= 4, src
    # Голого heartbeat_at в проверках живости быть не должно — именно он делал
    # строку с NULL-пульсом невидимой.
    assert not re.search(r"heartbeat_at\s*<\s*now\(\)", src), src


def test_release_own_orphans_uses_the_pulse_expression():
    import inspect

    src = inspect.getsource(PgQueue.release_own_orphans)
    assert src.count("PULSE_SQL") >= 3, src
    assert not re.search(r"coalesce\(heartbeat_at,\s*claimed_at\)", src), src


@pytest.mark.asyncio
async def test_sweep_writes_the_pulse_expression_into_sql():
    conn = _Conn()

    await PgQueue._sweep_stale(conn)

    # Шесть правил: авто-стоп молчащих, предел возраста, «молчал дольше
    # timeout», возврат в очередь, отказ исчерпанных processing и pending-тупик.
    assert len(conn.sql) == 6, "\n---\n".join(conn.sql)
    # Пульс нужен четырём правилам про processing; у предельньного возраста своя
    # шкала (started_at → claimed_at → created_at), у pending — available_at.
    with_pulse = [q for q in conn.sql if PULSE_SQL in q]
    assert len(with_pulse) == 4, "\n---\n".join(conn.sql)
    assert "coalesce(available_at, created_at)" in conn.sql[5], conn.sql[5]
    age_rule = [q for q in conn.sql if "Задача шла дольше" in q]
    assert len(age_rule) == 1 and "coalesce(started_at, claimed_at, created_at)" in age_rule[0], conn.sql
    # правило «возврат в очередь» обязано трогать только свои строки
    requeue = [q for q in conn.sql if "status = 'pending'" in q][0]
    assert "attempts < max_attempts" in requeue, requeue


# ---------------------------------------------------------------------------
# Функциональная проверка на живом PostgreSQL (локальная, необязательная)
# ---------------------------------------------------------------------------
DDL = """
CREATE TABLE prx_120_jobs (
  id bigserial PRIMARY KEY,
  channel varchar(128) NOT NULL DEFAULT '',
  payload jsonb NOT NULL DEFAULT '{}'::jsonb,
  status varchar(16) NOT NULL DEFAULT 'pending',
  priority integer NOT NULL DEFAULT 100,
  attempts integer NOT NULL DEFAULT 0,
  max_attempts integer NOT NULL DEFAULT 3,
  timeout_sec integer NOT NULL DEFAULT 600,
  error text, claimed_by varchar(128), claimed_at timestamptz, heartbeat_at timestamptz,
  available_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz NOT NULL DEFAULT now(),
  started_at timestamptz, finished_at timestamptz
);
CREATE TABLE prx_120_job_events (
  id bigserial PRIMARY KEY, job_id bigint NOT NULL, event varchar(32) NOT NULL,
  message text, meta jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now()
);
"""


@pytest.mark.asyncio
async def test_null_pulse_row_is_cancelled_on_real_postgres():
    dsn = os.environ.get("PRX_TEST_PG_DSN")

    if not dsn:
        pytest.skip("PRX_TEST_PG_DSN не задан — функциональная проверка пропущена")

    asyncpg = pytest.importorskip("asyncpg")
    conn = await asyncpg.connect(dsn)

    # Никогда не заменять настоящую очередь тестовыми таблицами.
    schema = "prx_test_" + secrets.token_hex(8)
    try:
        await conn.execute('CREATE SCHEMA "' + schema + '"')
        await conn.execute('SET search_path TO "' + schema + '"')
        await conn.execute(DDL)
        # 1) та самая строка: пульс NULL, захват 30 минут назад, попытки 3/3
        await conn.execute(
            """INSERT INTO prx_120_jobs (channel, status, attempts, max_attempts, timeout_sec,
                                        claimed_by, claimed_at, heartbeat_at)
               VALUES ('semantic.index', 'processing', 3, 3, 600, 'w1', now() - interval '30 minutes', NULL)""")
        # 2) зомби: пульс NULL, захвата нет, создана 3 часа назад
        await conn.execute(
            """INSERT INTO prx_120_jobs (channel, status, attempts, max_attempts, timeout_sec, created_at)
               VALUES ('semantic.index', 'processing', 3, 3, 600, now() - interval '3 hours')""")
        # 3) живая: только что захвачена, пульс ещё не пришёл — трогать нельзя
        await conn.execute(
            """INSERT INTO prx_120_jobs (channel, status, attempts, max_attempts, timeout_sec,
                                        claimed_by, claimed_at, heartbeat_at)
               VALUES ('semantic.index', 'processing', 1, 3, 600, 'w2', now() - interval '1 minute', NULL)""")
        # 4) анти-зомби: пульс ЖИВОЙ, но задача идёт дольше трёх таймаутов —
        #    закрывается по возрасту (иначе «вечный processing» с пульсом)
        await conn.execute(
            """INSERT INTO prx_120_jobs (channel, status, attempts, max_attempts, timeout_sec,
                                        claimed_by, claimed_at, heartbeat_at, started_at)
               VALUES ('semantic.index', 'processing', 1, 3, 600, 'w3', now(), now(),
                       now() - interval '2 hours')""")

        await PgQueue._sweep_stale(conn)

        rows = {r["id"]: r["status"] for r in await conn.fetch("SELECT id, status FROM prx_120_jobs")}
        assert rows[1] == "cancelled", "строка без пульса обязана закрываться свипером"
        assert rows[2] == "cancelled", "зомби без захвата обязан закрываться (фолбэк на created_at)"
        assert rows[3] == "processing", "свежую задачу трогать нельзя"
        assert rows[4] == "cancelled", "задачу старше трёх таймаутов закрываем по возрасту"
    finally:
        try:
            await conn.execute('SET search_path TO public')
            await conn.execute('DROP SCHEMA IF EXISTS "' + schema + '" CASCADE')
        finally:
            await conn.close()
