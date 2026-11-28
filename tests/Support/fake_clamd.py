#!/usr/bin/env python3
"""Фейковый clamd для тестов prx212: протокол zINSTREAM на unix-сокете.

Ответ «FOUND», если в потоке есть строка EICAR, иначе «OK». Пишет «ready»
в stdout, когда слушает. Только для tests/Smoke/SecurityPackageIntegrationTest.
"""
import os
import socket
import struct
import sys

path = sys.argv[1]
if os.path.exists(path):
    os.unlink(path)
srv = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
srv.bind(path)
srv.listen(5)
print("ready", flush=True)

while True:
    conn, _ = srv.accept()
    with conn:
        f = conn.makefile("rb")
        cmd = b""
        while not cmd.endswith(b"\0"):
            ch = f.read(1)
            if not ch:
                break
            cmd += ch
        data = b""
        while True:
            head = f.read(4)
            if len(head) < 4:
                break
            (n,) = struct.unpack(">I", head)
            if n == 0:
                break
            data += f.read(n)
        if cmd != b"zINSTREAM\0":
            conn.sendall(b"UNKNOWN COMMAND\0")
        elif b"EICAR" in data:
            conn.sendall(b"stream: Eicar-Test-Signature FOUND\0")
        else:
            conn.sendall(b"stream: OK\0")
