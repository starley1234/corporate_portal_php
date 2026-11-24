"""Общий запуск подпроцессов для OCR."""

from __future__ import annotations

import asyncio


async def run(*args: str, timeout: float, env: dict | None = None) -> tuple[int, bytes]:
    proc = await asyncio.create_subprocess_exec(
        *args, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL, env=env,
    )
    try:
        output, _ = await asyncio.wait_for(proc.communicate(), timeout)
        return proc.returncode, output
    finally:
        if proc.returncode is None:
            try:
                proc.kill()
            except ProcessLookupError:
                pass
            await proc.communicate()
