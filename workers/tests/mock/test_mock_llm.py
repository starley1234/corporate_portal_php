"""Мок LLM-шлюза (workers/mock/mock_llm_server.py) — пригодность для vision.

Зачем тест: мок разбирал content сообщения как СТРОКУ, а у vision-каналов
(prx639/prx640/prx646) content — СПИСОК частей ([{"type": "text"}, {"type":
"image_url"}]). На `hash(list)` мок падал с TypeError, клиент получал
«Server disconnected without sending a response», и ЛЮБОЙ прогон фото в
песочнице заканчивался ошибкой канала — то есть отлаживать фото-конвейер
локально было нельзя (нашли 03.10.2026).
"""

from __future__ import annotations

import importlib.util
from pathlib import Path

MOCK = Path(__file__).resolve().parents[2] / "mock" / "mock_llm_server.py"


def _mock():
    spec = importlib.util.spec_from_file_location("mock_llm_server", MOCK)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def test_multimodal_content_is_understood():
    mod = _mock()

    messages = [
        {"role": "system", "content": "ты OCR"},
        {"role": "user", "content": [
            {"type": "text", "text": "Прочитай состав"},
            {"type": "image_url", "image_url": {"url": "data:image/jpeg;base64,AAAA"}},
        ]},
    ]

    text = mod._last_user_text(messages)

    assert "Прочитай состав" in text
    assert "картинка" in text


def test_plain_string_content_still_works():
    mod = _mock()

    assert mod._last_user_text([{"role": "user", "content": "привет"}]) == "привет"


def test_reply_queue_serves_answers_in_order(tmp_path, monkeypatch):
    """MOCK_LLM_REPLY_FILES — очередь ответов (двухпроходные каналы).

    prx646.label ходит к модели дважды: строки этикетки, потом состав из них.
    С одним REPLY_FILE обе просьбы получали один и тот же ответ, и такой прогон
    в песочнице было не проверить.
    """
    first = tmp_path / "first.json"
    second = tmp_path / "second.json"
    first.write_text('{"lines": ["Состав: Вода"]}', encoding="utf-8")
    second.write_text('{"composition": "Вода"}', encoding="utf-8")

    monkeypatch.setenv("MOCK_LLM_REPLY_FILES", f"{first},{second}")
    mod = _mock()

    assert mod._next_reply() == '{"lines": ["Состав: Вода"]}'
    assert mod._next_reply() == '{"composition": "Вода"}'
    # очередь кончилась — последний ответ повторяется, мок не падает
    assert mod._next_reply() == '{"composition": "Вода"}'


def test_single_reply_file_still_works(tmp_path, monkeypatch):
    only = tmp_path / "reply.json"
    only.write_text('{"ok": true}', encoding="utf-8")

    monkeypatch.setenv("MOCK_LLM_REPLY_FILES", "")
    monkeypatch.setenv("MOCK_LLM_REPLY_FILE", str(only))
    mod = _mock()

    assert mod._next_reply() == '{"ok": true}'
    assert mod._next_reply() == '{"ok": true}'
