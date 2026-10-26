"""Общий резолвер модели VLM-сервисов: одна настройка на все сервисы.

Повод: у каждого сервиса «сними фото — получи разбор» был свой ключ
`NJ<ID>_VISION_MODEL`, и после подключения нового сервиса он молча уезжал на
модель по умолчанию у провайдера — владелец узнавал об этом по странному
качеству ответов, а не из настроек. Теперь всем хватает одной строки
`VLM_MODEL` в workers/.env, а свой ключ сервиса остаётся как исключение.

Тест держит этот порядок (свой ключ → соседний → общий) и проверяет, что на
него переведены все vision-обработчики.

Запуск из каталога workers/:
    python3 -m pytest tests/test_vlm_defaults.py -q
"""

import re
from pathlib import Path

import pytest

from n2j_workers import vlm
from n2j_workers.handlers import (nj570_build, nj639_qc, nj640_cameras,
                                  nj646_label, nj650_receipt, nj651_meal,
                                  nj652_plant)

HANDLERS = Path(__file__).resolve().parents[1] / "n2j_workers" / "handlers"

#: Обработчики, у которых есть кадр и vision-модель, и их префиксы.
VISION_HANDLERS = {
    "nj570_build": nj570_build,
    "nj639_qc": nj639_qc,
    "nj640_cameras": nj640_cameras,
    "nj646_label": nj646_label,
}


@pytest.fixture(autouse=True)
def _clean_env(monkeypatch):
    """Чистое окружение: иначе тест зависит от .env разработчика."""
    for name in ("VLM_MODEL", "VLM_MAX_TOKENS", "VLM_TIMEOUT",
                 "NJ570_VISION_MODEL", "NJ639_VISION_MODEL",
                 "NJ640_VISION_MODEL", "NJ646_VISION_MODEL",
                 "NJ650_VISION_MODEL", "NJ651_VISION_MODEL",
                 "NJ652_VISION_MODEL", "NJ650_MAX_TOKENS",
                 "NJ650_LLM_TIMEOUT"):
        monkeypatch.delenv(name, raising=False)


def test_no_settings_means_provider_default():
    """Сервис обязан работать сразу после установки, без единой настройки."""
    assert vlm.model("NJ650") == ""


def test_shared_model_is_used_by_every_service(monkeypatch):
    monkeypatch.setenv("VLM_MODEL", "openai::gpt-4o-mini")

    for prefix in ("NJ650", "NJ651", "NJ652", "NJ646", "NJ639", "NJ640", "NJ570"):
        assert vlm.model(prefix) == "openai::gpt-4o-mini", prefix


def test_own_key_wins_over_shared(monkeypatch):
    monkeypatch.setenv("VLM_MODEL", "общая")
    monkeypatch.setenv("NJ650_VISION_MODEL", "своя")

    assert vlm.model("NJ650") == "своя"
    assert vlm.model("NJ651") == "общая", "Чужой ключ не влияет на соседний сервис"


def test_fallback_prefix_is_checked_before_shared(monkeypatch):
    monkeypatch.setenv("VLM_MODEL", "общая")
    monkeypatch.setenv("NJ639_VISION_MODEL", "соседняя")

    # nj646 исторически наследует настройку nj639 — порядок сохраняем.
    assert vlm.model("NJ646", "NJ639") == "соседняя"


def test_blank_value_does_not_shadow_shared(monkeypatch):
    """`NJ650_VISION_MODEL=` в .env — это «не задано», а не «пустая модель»."""
    monkeypatch.setenv("VLM_MODEL", "общая")
    monkeypatch.setenv("NJ650_VISION_MODEL", "   ")

    assert vlm.model("NJ650") == "общая"


def test_tokens_and_timeout_have_shared_defaults(monkeypatch):
    assert vlm.max_tokens("NJ650", 1600) == 1600
    assert vlm.timeout("NJ650", 180.0) == 180.0

    monkeypatch.setenv("VLM_MAX_TOKENS", "2500")
    monkeypatch.setenv("VLM_TIMEOUT", "240")
    assert vlm.max_tokens("NJ650", 1600) == 2500
    assert vlm.timeout("NJ650", 180.0) == 240.0

    monkeypatch.setenv("NJ650_MAX_TOKENS", "900")
    monkeypatch.setenv("NJ650_LLM_TIMEOUT", "120")
    assert vlm.max_tokens("NJ650", 1600) == 900
    assert vlm.timeout("NJ650", 180.0) == 120.0


def test_broken_values_do_not_break_the_worker(monkeypatch):
    monkeypatch.setenv("VLM_MAX_TOKENS", "много")
    monkeypatch.setenv("VLM_TIMEOUT", "скоро")

    assert vlm.max_tokens("NJ650", 1600) == 1600
    assert vlm.timeout("NJ650", 180.0) == 180.0


def test_timeout_stays_in_sane_range(monkeypatch):
    """Таймаут модели не должен уезжать за пределы, где ломается очередь."""
    monkeypatch.setenv("VLM_TIMEOUT", "1")
    assert vlm.timeout("NJ650") == 30.0

    monkeypatch.setenv("VLM_TIMEOUT", "99999")
    assert vlm.timeout("NJ650") == 1800.0


@pytest.mark.parametrize("name", sorted(VISION_HANDLERS))
def test_old_vision_handlers_use_shared_resolver(name):
    """Старые обработчики читают модель через vlm.model, а не os.getenv сами."""
    source = (HANDLERS / f"{name}.py").read_text(encoding="utf-8")

    assert "vlm.model(" in source, f"{name}: модель должна резолвиться через vlm.model"
    assert not re.search(r'os\.getenv\(\s*"NJ\d+_VISION_MODEL"', source), (
        f"{name}: остался прямой os.getenv вместо общего резолвера")


@pytest.mark.parametrize("module", [nj650_receipt, nj651_meal, nj652_plant])
def test_new_services_share_the_pipeline(module):
    """Новые сервисы не копируют конвейер, а зовут общий vlm.run_photo_job."""
    source = Path(module.__file__).read_text(encoding="utf-8")

    assert "vlm.run_photo_job(" in source
    assert module.CHANNEL.startswith("nj6")
    assert module.PREFIX.startswith("NJ6")


def test_new_services_declare_their_env_in_example():
    """Ключи сервиса должны быть в workers/.env.example, иначе их не настроить."""
    example = (HANDLERS.parents[1] / ".env.example").read_text(encoding="utf-8")

    for prefix in ("NJ650", "NJ651", "NJ652"):
        assert f"#{prefix}_API_BASE=" in example, prefix
        assert f"#{prefix}_SITE_TOKEN=" in example, prefix

    assert "#VLM_MODEL=" in example


def test_photo_size_resolution(monkeypatch):
    """Размер фото для модели: своя ручка → общий VLM_PHOTO_MAX_PX → дефолт.

    Запрос владельца 06.10.2026: у каждого модуля анализа изображений —
    настройка, до какого размера уменьшать кадр (экономим время и токены).
    """
    for name in ("NJ650_PHOTO_MAX_PX", "VLM_PHOTO_MAX_PX"):
        monkeypatch.delenv(name, raising=False)

    assert vlm.photo_max_w("NJ650") == vlm.IMAGE_MAX_W       # 1400 по умолчанию
    assert vlm.photo_max_w("NJ640_CAMERAS", 512) == 512      # ТЗ-дефолт модуля

    monkeypatch.setenv("VLM_PHOTO_MAX_PX", "900")
    assert vlm.photo_max_w("NJ650") == 900
    assert vlm.photo_max_w("NJ640_CAMERAS", 512) == 900      # глобальную берут и «ТЗ»-модули

    monkeypatch.setenv("NJ650_PHOTO_MAX_PX", "640")
    assert vlm.photo_max_w("NJ650") == 640                   # своя перебивает всё
    assert vlm.photo_max_w("NJ651") == 900                   # сосед живёт глобальной


def test_photo_size_stays_sane(monkeypatch):
    """Кривые значения не ломают подготовку кадра."""
    for raw, want in (("50", 200), ("99999", 4000), ("огромное", vlm.IMAGE_MAX_W)):
        monkeypatch.setenv("VLM_PHOTO_MAX_PX", raw)
        assert vlm.photo_max_w("NJ650") == want, raw


@pytest.mark.parametrize("module,prefix", [
    (nj646_label, "NJ646"), (nj650_receipt, "NJ650"), (nj651_meal, "NJ651"),
    (nj652_plant, "NJ652"), (nj639_qc, "NJ639"),
    (nj640_cameras, "NJ640_CAMERAS"), (nj570_build, "NJ570"),
])
def test_photo_knob_declared_in_example(module, prefix):
    """Ручка размера кадра объявлена в workers/.env.example у каждого модуля."""
    source = Path(module.__file__).read_text(encoding="utf-8")
    example = (HANDLERS.parents[1] / ".env.example").read_text(encoding="utf-8")

    if module in (nj650_receipt, nj651_meal, nj652_plant):
        assert "vlm.run_photo_job(" in source, prefix      # размер применяет общий конвейер
    else:
        assert "vlm.photo_max_w(" in source, prefix        # свой подготовщик — общая ручка

    assert f"#{prefix}_PHOTO_MAX_PX=" in example, prefix


def test_photo_pipeline_applies_the_knob():
    """Общий конвейер VLM применяет ручку размера к prepare_b64."""
    vlm_source = Path(vlm.__file__).read_text(encoding="utf-8")

    assert "prepare_b64(b64, photo_max_w(prefix))" in vlm_source
