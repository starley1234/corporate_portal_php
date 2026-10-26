"""Разбор ответа OmniVoice: поток байт, JSON base64, пустой JSON."""

from n2j_workers import omnivoice


def test_sniff_format_magic_bytes():
    assert omnivoice.sniff_format(b"ID3....mp3") == "mp3"
    assert omnivoice.sniff_format(b"RIFF....WAVE") == "wav"
    assert omnivoice.sniff_format(b"fLaC....") == "flac"
    assert omnivoice.sniff_format(b"OggS....OpusHead....") == "opus"
    assert omnivoice.sniff_format(b"") is None
    assert omnivoice.sniff_format(b"abcd") is None
    assert omnivoice.sniff_format(bytes([0xFF, 0xFB, 0x90, 0x00])) == "mp3"


def test_audio_from_response_raw_mp3():
    body = b"ID3" + b"\x00" * 20
    got = omnivoice.audio_from_response(body, "audio/mpeg", "wav")
    assert got["bytes"] == body and got["format"] == "mp3" and not got["error"]


def test_audio_from_response_json_base64():
    raw = b"ID3" + b"\x00" * 80
    import base64
    payload = '{"audio_base64": "%s"}' % base64.b64encode(raw).decode("ascii")
    got = omnivoice.audio_from_response(payload.encode("ascii"), "application/json")
    assert got["bytes"] == raw and got["format"] == "mp3"


def test_audio_from_response_json_url():
    body = b'{"audio_url": "https://voice.example/audio/file.mp3"}'
    got = omnivoice.audio_from_response(body, "application/json")
    assert got["url"].endswith("/audio/file.mp3") and not got["bytes"]


def test_audio_from_response_json_without_audio():
    got = omnivoice.audio_from_response(b'{"ok": true}', "application/json")
    assert got["error"] and not got["bytes"]


def test_enabled_needs_both(monkeypatch):
    monkeypatch.delenv("NJ665_API_URL", raising=False)
    monkeypatch.delenv("NJ665_API_KEY", raising=False)
    assert not omnivoice.enabled()
    monkeypatch.setenv("NJ665_API_URL", "https://voice.example")
    assert not omnivoice.enabled()
    monkeypatch.setenv("NJ665_API_KEY", "k")
    assert omnivoice.enabled()
    assert omnivoice.api_base() == "https://voice.example"


def test_default_model(monkeypatch):
    monkeypatch.delenv("NJ665_MODEL", raising=False)
    assert omnivoice.default_model() == "k2-fsa/omnivoice"
    monkeypatch.setenv("NJ665_MODEL", "acme/voice")
    assert omnivoice.default_model() == "acme/voice"


def test_timeout_prefers_nj666(monkeypatch):
    monkeypatch.delenv("NJ666_SYNTH_TIMEOUT", raising=False)
    monkeypatch.delenv("NJ665_TIMEOUT", raising=False)
    assert omnivoice.timeout() == 600.0
    monkeypatch.setenv("NJ665_TIMEOUT", "120")
    assert omnivoice.timeout() == 120.0
    monkeypatch.setenv("NJ666_SYNTH_TIMEOUT", "900")
    assert omnivoice.timeout() == 900.0
