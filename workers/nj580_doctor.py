#!/usr/bin/env python3
"""Самопроверка nj580 «Документы организации» на сервере воркера.

Запуск:  python3 workers/nj580_doctor.py
Проверяет: python-зависимости (python-docx, openpyxl, reportlab, langgraph, ddgs),
TTF-шрифты для PDF (Liberation/DejaVu), env NJ580_* (канал, API сайта, токен),
доступность /docs/api/storage на сайте, пробную сборку DOCX+PDF+XLSX в памяти.
"""
from __future__ import annotations

import os
import sys

OK, BAD, WARN = "  OK ", " FAIL", " WARN"


def line(status: str, text: str) -> None:
    print(f"[{status}] {text}")


def main() -> int:
    code = 0

    # 1. Зависимости
    for mod in ("docx", "openpyxl", "reportlab", "ddgs"):
        try:
            __import__(mod)
            line(OK, f"python-модуль {mod}")
        except Exception as e:  # noqa: BLE001
            line(BAD, f"python-модуль {mod}: {e} (pip install -r workers/requirements.txt)")
            code = 1
    try:
        import langgraph  # noqa: F401
        line(OK, "langgraph установлен — конвейер идёт через StateGraph")
    except Exception:  # noqa: BLE001
        line(WARN, "langgraph не установлен — канал работает в последовательном режиме")

    # 2. Шрифты для PDF
    sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__))))
    try:
        from n2j_workers.docgen import gost

        fonts = gost.find_fonts()
        for style, path in sorted(fonts.items()):
            line(OK, f"шрифт {style}: {path}")
    except Exception as e:  # noqa: BLE001
        line(BAD, f"шрифты: {e} (apt install fonts-liberation fonts-dejavu-core)")
        code = 1

    # 3. Пробная сборка DOCX+PDF+XLSX
    try:
        from n2j_workers.docgen import build_docx, build_pdf, build_xlsx

        doc = {"title": "Проверка", "org_name": "Тест", "needs_title_page": True,
               "approve_line": "УТВЕРЖДАЮ\nТест",
               "sections": [{"heading": "1. Раздел", "blocks": [
                   {"type": "p", "text": "Проверка сборки."},
                   {"type": "table", "caption": "Т", "columns": ["А"], "rows": [["1"]]}]}]}
        d = build_docx.build_docx(doc)
        p = build_pdf.build_pdf(doc)
        x = build_xlsx.build_xlsx("Т", ["А"], [["1"]])
        line(OK, f"пробная сборка: DOCX {len(d)} Б, PDF {len(p)} Б, XLSX {len(x)} Б")
    except Exception as e:  # noqa: BLE001
        line(BAD, f"пробная сборка: {e}")
        code = 1

    # 4. Env и API сайта
    from n2j_workers.llm import default_provider_name, scan_providers

    providers = scan_providers()

    if providers:
        dflt = default_provider_name(providers)
        line(OK, "LLM-провайдеры: " + ", ".join(
            ("[" + p.name + "]" if p.name == dflt else p.name)
            + "(" + p.base_url + (", proxy" if p.proxy else "") + ")"
            for p in providers.values())
            + "  — [имя] = провайдер по умолчанию")
    else:
        line(BAD, "LLM-провайдеры не настроены: задайте <ИМЯ>_API_URL в workers/.env "
                  "(напр. TOOLFORGE_API_URL / OPENROUTER_API_URL)")
        code = 1

    base = os.getenv("NJ580_API_BASE", "").strip().rstrip("/")
    token = os.getenv("NJ580_SITE_TOKEN", "").strip() or os.getenv("WORKER_API_TOKEN", "").strip()
    if not base:
        line(BAD, "NJ580_API_BASE пуст — воркер не сможет сохранять документы (workers/.env)")
        code = 1
    else:
        line(OK, f"NJ580_API_BASE = {base}")
    if not token:
        line(BAD, "NJ580_SITE_TOKEN/WORKER_API_TOKEN пуст — сайт ответит 401")
        code = 1

    if base and token:
        try:
            import httpx

            r = httpx.get(base + "/storage", headers={"X-Worker-Token": token}, timeout=15)
            if r.status_code == 200:
                info = r.json()
                line(OK, f"API сайта: хранилище {info.get('dir')} (dir={info.get('is_dir')}, "
                         f"writable={info.get('is_writable')}, файлов={info.get('count')})")
                if not info.get("is_writable"):
                    line(BAD, "хранилище сайта недоступно на запись — bash bin/setup.sh на сервере сайта")
                    code = 1
            else:
                line(BAD, f"API сайта /storage -> HTTP {r.status_code}: {r.text[:200]}")
                code = 1
        except Exception as e:  # noqa: BLE001
            line(BAD, f"API сайта недоступен: {e}")
            code = 1

        # скачивание файлов пакета (диагностика «404/500 у ZIP»)
        pkg_code = os.getenv("NJ580_DOCTOR_PKG", "").strip()
        if pkg_code:
            try:
                import httpx

                r = httpx.get(f"{base}/status/{pkg_code}", timeout=15,
                              headers={"Accept": "application/json"})
                files = []
                if r.status_code == 200:
                    for d in (r.json().get("documents") or []):
                        files += [dict(f, where="doc") for f in (d.get("files") or [])]
                    files += [dict(f, where="pkg") for f in (r.json().get("package_files") or [])]
                for f in files:
                    fr = httpx.get(f"https://n2j.ru/docs/{pkg_code}/file/{f['id']}", timeout=60)
                    ok = fr.status_code == 200 and len(fr.content) == f.get("size", -1)
                    line(OK if ok else BAD,
                         f"файл {f['id']} ({f['where']}/{f['kind']}, {f.get('size')} Б) -> "
                         f"HTTP {fr.status_code}, получено {len(fr.content)} Б")
                    if not ok:
                        code = 1
            except Exception as e:  # noqa: BLE001
                line(WARN, f"проверка скачивания не удалась: {e}")

    for name in ("NJ580_DOC_MODEL", "NJ580_PLAN_MODEL", "NJ580_QA_MODEL"):
        v = os.getenv(name, "").strip()
        line(OK if v else WARN, f"{name} = {v or '(пусто — будет TOOLFORGE_DEFAULT_MODEL)'}")

    print("\nИтог:", "ЕСТЬ ПРОБЛЕМЫ" if code else "всё готово — канал nj580.generate можно запускать")
    return code


if __name__ == "__main__":
    sys.exit(main())
