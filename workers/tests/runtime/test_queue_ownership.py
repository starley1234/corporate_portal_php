"""Прямой PG-транспорт: запоздавший fail не присваивает pending/чужой захват."""
import asyncio

import pytest

from prx_workers.queue import PgQueue


class Context:
    def __init__(self, value):
        self.value = value

    async def __aenter__(self):
        return self.value

    async def __aexit__(self, *args):
        return False


class Connection:
    def __init__(self, row):
        self.row = row
        self.queries = []

    def transaction(self):
        return Context(self)

    async def fetchrow(self, sql, *args):
        self.queries.append((sql, args))
        assert "claimed_by = $2 AND status = 'processing' FOR UPDATE" in sql
        return self.row

    async def execute(self, sql, *args):
        self.queries.append((sql, args))
        return "UPDATE 1"


class Pool:
    def __init__(self, connection):
        self.connection = connection

    def acquire(self, **kwargs):
        return Context(self.connection)


@pytest.mark.parametrize("retry", [True, False])
def test_failure_without_current_claim_is_rejected_without_mutations(retry):
    conn = Connection(None)  # WHERE отверг pending/terminal/чужой processing
    queue = PgQueue("unused")
    queue._pool = Pool(conn)
    assert asyncio.run(queue.fail(7, "old-worker", "late error", retry=retry)) is False
    assert len(conn.queries) == 1


@pytest.mark.parametrize("attempts,maximum,retry,pending", [
    (1, 3, True, True), (2, 3, True, True), (3, 3, True, False), (1, 3, False, False),
])
def test_failure_of_current_claim_keeps_backoff_and_terminal_rules(attempts, maximum, retry, pending):
    conn = Connection({"attempts": attempts, "max_attempts": maximum, "status": "processing"})
    queue = PgQueue("unused")
    queue._pool = Pool(conn)
    assert asyncio.run(queue.fail(7, "owner", "error", retry=retry)) is True
    update, args = conn.queries[1]
    if pending:
        assert "status = 'pending'" in update
        assert "claimed_by = NULL, claimed_at = NULL" in update
        assert args == (7, "error", str(15 * attempts ** 2))
    else:
        assert "status = 'failed'" in update
        assert args == (7, "error")
    assert "'failed'" in conn.queries[2][0]
