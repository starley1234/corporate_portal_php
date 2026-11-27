"""Лимиты каналов prx_120_channel_limits в python-захвате (PgQueue.claim).

PHP (Service_Prx120_JobQueue::limit_blocked_sql) и python обязаны читать
лимиты одинаково: иначе HTTP-воркеры соблюдают лимит, а pg-воркеры — нет.
"""
import asyncio
import re
from pathlib import Path

from prx_workers.queue import PgQueue, limit_blocked_sql

ROOT = Path(__file__).resolve().parents[3]
PHP = ROOT / "application/modules/prx120_jobs/classes/Service/Prx120/JobQueue.php"


def _norm(sql: str) -> str:
    return re.sub(r"\s+", " ", sql).replace("( ", "(").replace(" )", ")").strip()


def test_python_and_php_share_the_same_limit_condition():
    src = PHP.read_text(encoding="utf-8")
    body = re.search(r"function limit_blocked_sql\(\$alias\).*?return \"(.*?)\";", src, re.S)
    assert body, "PHP limit_blocked_sql не найден"
    php_sql = body.group(1).replace("$a", "j")
    assert _norm(php_sql) == _norm(limit_blocked_sql("j"))


def test_alias_is_sanitized():
    assert "j; drop" not in limit_blocked_sql("j; drop table x")


class Tx:
    async def __aenter__(self):
        return self

    async def __aexit__(self, *args):
        return False


class Conn:
    """Кандидаты по очереди; канал «slow» упирается в лимит при перепроверке."""

    def __init__(self, candidates, blocked):
        self.candidates = list(candidates)
        self.blocked = set(blocked)
        self.calls = []

    def transaction(self):
        return Tx()

    async def fetchrow(self, sql, *args):
        self.calls.append(("fetchrow", sql, args))
        if sql.lstrip().startswith("UPDATE"):
            return {"id": args[1], "channel": "x", "payload": "{}", "status": "processing"}
        assert "prx_120_channel_limits" in sql, "фильтр лимитов в выборе кандидата"
        return self.candidates.pop(0) if self.candidates else None

    async def execute(self, sql, *args):
        self.calls.append(("execute", sql, args))
        return "SELECT 1"

    async def fetchval(self, sql, *args):
        self.calls.append(("fetchval", sql, args))
        return args[0] in self.blocked


class Pool:
    def __init__(self, conn):
        self.conn = conn

    def acquire(self, **kwargs):
        conn = self.conn

        class Ctx:
            async def __aenter__(self):
                return conn

            async def __aexit__(self, *a):
                return False

        return Ctx()


def _queue(conn):
    q = PgQueue("unused")
    q._pool = Pool(conn)

    async def no_sweep(_conn):
        return None

    q._sweep_stale = no_sweep
    return q


def _rows(job):
    return job


def test_limited_candidate_is_rechecked_under_channel_lock_and_skipped(monkeypatch):
    import prx_workers.queue as qmod

    monkeypatch.setattr(qmod, "_row_to_job", _rows)
    conn = Conn(
        candidates=[{"id": 1, "channel": "slow", "limited": True},
                    {"id": 2, "channel": "free", "limited": False}],
        blocked={1},
    )
    job = asyncio.run(_queue(conn).claim(["slow", "free"], "w1"))
    assert job["id"] == 2
    locks = [c for c in conn.calls if c[0] == "execute" and "pg_advisory_xact_lock" in c[1]]
    assert locks and locks[0][2] == ("prx120.channel:slow",), "та же блокировка, что в PHP"
    second = [c for c in conn.calls if c[0] == "fetchrow" and "SELECT id, channel" in c[1]][1]
    assert ["slow"] in [a for a in second[2] if isinstance(a, list)], "упёршийся канал исключён"


def test_unlimited_candidate_takes_no_lock(monkeypatch):
    import prx_workers.queue as qmod

    monkeypatch.setattr(qmod, "_row_to_job", _rows)
    conn = Conn(candidates=[{"id": 5, "channel": "free", "limited": False}], blocked=set())
    job = asyncio.run(_queue(conn).claim([], "w1"))
    assert job["id"] == 5
    assert not [c for c in conn.calls if c[0] == "execute"]


def test_all_candidates_blocked_returns_none(monkeypatch):
    import prx_workers.queue as qmod

    monkeypatch.setattr(qmod, "_row_to_job", _rows)
    conn = Conn(candidates=[{"id": 1, "channel": "slow", "limited": True}], blocked={1})
    assert asyncio.run(_queue(conn).claim([], "w1")) is None
    assert not [c for c in conn.calls if c[1].lstrip().startswith("UPDATE")]
