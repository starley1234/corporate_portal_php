"""nj676.translate — русские названия и описания карточек каталога MCP.

Зачем отдельный канал. Сайт русскоязычный, а каталог наполнялся из GitHub и
реестра MCP, где всё по-английски («Playwright MCP Server», «Browser automation
for AI agents»). Перевод делает модель в воркере: у сайта нет ни модели, ни
права занимать HTTP-запрос минутами ожидания.

Как устроено (pull, а не push):

  1. воркер спрашивает сайт `GET /mcphub/api/translate-candidates?limit=N` —
     карточки без перевода (`name_ru` пуст), самые полезные первыми;
  2. модель получает пачку и возвращает JSON с `name_ru` и `description_ru`;
  3. готовое уходит на `POST /mcphub/api/translate-item`;
  4. сайт пишет перевод, пересобирает `search_text`, помечает `ru_source = llm`
     и никогда не трогает ручной перевод редакции (`ru_source = manual`);
  5. в конце прогон докладывает итог на `POST /mcphub/api/translate-finish` —
     админка показывает его в блоке перевода.

Почему цикл, а не одна пачка. 23.10.2026 прогон перевёл ровно 5 карточек из
1037 и молча остановился: одна неудачная пачка (или сбой пульса) убивала всю
задачу. Теперь:

  * пачка с ошибкой делится пополам и повторяется — плохой ответ модели стоит
    одной карточки, а не прогона;
  * пока не набрано `limit`, воркер добирает новые кандидаты (переведённые
    исчезают из выдачи), так что один прогон действительно переводит `limit`;
  * размышления модели отключены: на коротком переводе рассуждающая модель
    тратила на них весь ответ, контент приходил пустым, и пачка считалась
    непереведённой;
  * режим `all` («Перевести всё») берёт 40 карточек, а сайт ставит цепочку
    таких задач на весь каталог: упавшая задача видна, остальные доезжают;
  * у прогона есть собственный бюджет времени (по умолчанию 25 минут при
    таймауте задачи 30): перед очередной пачкой воркер проверяет часы и, если
    время вышло, спокойно заканчивает и докладывает итог. Так задача никогда не
    умирает по таймауту молча — сделанное сохраняется, остальное достаётся
    следующей задаче цепочки;
  * карточки берутся **окнами по две пачки** и с номером задачи (`&job=`):
    клейм принадлежит ей, и сайт отпускает карточки, когда задача закончилась.
    Всё, что взяли и не успели (время, таймаут, падение), возвращается в
    очередь сразу — иначе карточки висели бы «в работе» час, а кнопки в
    админке были бы выключены (24.10.2026: 25 карточек, 8 переводов, пустая
    очередь и простаивающая модель).

Env: NJ676_TRANSLATE_LIMIT (сколько карточек в обычном прогоне, 20),
NJ676_TRANSLATE_BATCH (сколько в одном запросе к модели, 5),
NJ676_TRANSLATE_BUDGET (бюджет одной задачи в секундах, 1500),
NJ676_TRANSLATE_MODEL (или общий NJ676_MODEL), адрес и токен сайта — как у
nj676.discover.
"""

from __future__ import annotations

import logging
import time

from .. import site, webread
from ..llmchat import chat_with_usage as _llm
from .nj676_mcphub import (
    _PREFIX,
    _env_int,
    _site_base,
    _site_post,
    _strip_think,
    _think_options,
)

log = logging.getLogger("n2j.nj676")

CHANNEL = "nj676.translate"

SYSTEM = (
    "Ты — редактор русскоязычного каталога MCP-серверов N2J.ru. Твоя работа — "
    "коротко и точно пересказать по-русски, что делает сервер и его инструменты. "
    "Только факты из присланных данных: без выдумок, оценок и рекламы. "
    "Отвечай СТРОГО одним JSON-объектом без markdown."
)

PROMPT = (
    "Для каждой карточки верни: slug (как пришёл), name_ru (русское название "
    "для каталога: 2–6 слов, без кавычек; бренды и аббревиатуры можно оставить "
    "латиницей), description_ru (1–2 предложения по-русски: что делает и зачем). "
    "Не переводи слово «MCP» и имена инструментов. Не добавляй того, чего нет "
    "в описании и инструментах. Формат: "
    '{"items": [{"slug": "owner/repo", "name_ru": "…", "description_ru": "…"}]}'
)


def _card_text(card: dict) -> str:
    """Компактное описание карточки для модели — без схем и служебных полей."""
    tools = []
    for tool in (card.get("tools") or [])[:10]:
        if isinstance(tool, dict) and tool.get("name"):
            tools.append(f"{tool['name']}: {tool.get('description') or ''}")

    categories = ", ".join(str(c) for c in (card.get("categories") or [])[:4])

    return (
        f"- slug: {card.get('slug', '')}\n"
        f"  name: {card.get('name', '')}\n"
        f"  description: {card.get('description', '')}\n"
        f"  categories: {categories or '-'}\n"
        f"  tools: {'; '.join(tools) if tools else '-'}"
    )


def _parse_items(raw: str, cards: list[dict]) -> list[dict]:
    """Разобрать ответ модели и оставить только карточки из этой пачки.

    Модель любит добавить лишнее или вернуть `items` списком верхнего уровня —
    принимаем оба варианта, но чужой slug и пустое имя не сохраняем.
    """
    parsed = webread.json_from_text(_strip_think(raw or ""))

    if isinstance(parsed, dict):
        items = parsed.get("items")
    elif isinstance(parsed, list):
        items = parsed
    else:
        items = None

    if not isinstance(items, list):
        return []

    known = {str(card.get("slug")) for card in cards}
    out: list[dict] = []

    for entry in items:
        if not isinstance(entry, dict):
            continue

        slug = str(entry.get("slug") or "").strip()
        name_ru = str(entry.get("name_ru") or "").strip()
        description_ru = str(entry.get("description_ru") or "").strip()

        if slug not in known or not name_ru:
            continue

        out.append({
            "slug": slug,
            "name_ru": name_ru[:160],
            "description_ru": description_ru[:2000],
        })

    return out


async def _translate_batch(ctx, cards: list[dict]) -> list[dict]:
    """Одна пачка карточек → переводы. Исключение модели летит наверх."""
    prompt = PROMPT + "\n\nКарточки:\n" + "\n".join(_card_text(card) for card in cards)

    raw, _usage = await _llm(
        ctx,
        [{"role": "system", "content": SYSTEM}, {"role": "user", "content": prompt}],
        "Перевожу карточки",
        model_env="NJ676_TRANSLATE_MODEL",
        max_tokens=min(6000, 700 * len(cards) + 500),
        # Размышления отключаем: перевод короткой карточки не требует рассуждений,
        # а рассуждающая модель тратила на них весь ответ (контент приходил
        # пустым, пачка «считалась» непереведённой — 23.10.2026).
        options=_think_options(),
        # 100 секунд на пачку из 5–10 коротких карточек: если модель не успела,
        # лучше потерять одну пачку (и повторить её), чем всю задачу по таймауту.
        timeout=100,
    )

    return _parse_items(raw or "", cards)


async def _translate_cards(ctx, cards: list[dict], errors: list[str],
                           retries: int = 1) -> list[dict]:
    """Пачка с терпением: повтор, затем деление пополам.

    Один битый ответ модели не должен стоить всей пачки: пробуем ещё раз, а
    если снова пусто — режем пачку и переводим частями. В худшем случае
    теряется одна карточка, и она просто останется в очереди на следующий
    прогон.
    """
    for _attempt in range(retries + 1):
        try:
            out = await _translate_batch(ctx, cards)
        except Exception as exc:  # noqa: BLE001 — модель бывает недоступна
            errors.append(f"модель: {exc.__class__.__name__}")
            log.warning("nj676.translate: пачка не переведена (%s)", exc.__class__.__name__)
            out = []

        if out:
            return out

    if len(cards) > 1:
        mid = len(cards) // 2

        # Делим без повторных повторов: у мелких пачек шанс уже проверен, а
        # каждый лишний вызов модели — это минуты и токены.
        return (
            await _translate_cards(ctx, cards[:mid], errors, retries=0)
            + await _translate_cards(ctx, cards[mid:], errors, retries=0)
        )

    return []


async def _post_translations(base: str, translated: list[dict], errors: list[str]) -> int:
    """Отдать переводы сайту. Возвращает, сколько реально сохранилось."""
    try:
        ok, reason, saved = await _site_post(base, "/translate-item", {"items": translated})
    except Exception as exc:  # noqa: BLE001 — сеть до сайта не должна ронять прогон
        errors.append(f"сайт: {exc.__class__.__name__}")

        return 0

    if not ok:
        errors.append(f"сайт: {reason}")

        return 0

    return int((saved or {}).get("saved") or 0) if isinstance(saved, dict) else 0


async def _report(base: str, stats: dict) -> None:
    """Итог прогона — на сайт, чтобы админка показала причину, а не только числа."""
    try:
        await _site_post(base, "/translate-finish", {"stats": stats})
    except Exception as exc:  # noqa: BLE001 — отчёт не важнее самой работы
        log.warning("nj676.translate: итог не отправлен (%s)", exc.__class__.__name__)


def _job_id(ctx) -> int:
    """Номер задачи nj120: клейм карточек принадлежит ей.

    Без номера сайт не может отличить «карточки взяла живая задача» от
    «карточки висят за задачей, которая давно закончилась» — и держал их час
    (24.10.2026: 25 карточек «в работе», модель простаивает).
    """
    job = getattr(ctx, "_current_job", None) or {}

    try:
        return int((job or {}).get("id") or 0)
    except (TypeError, ValueError):
        return 0


async def handle(ctx, payload: dict) -> dict:
    payload = payload if isinstance(payload, dict) else {}
    mode = "all" if str(payload.get("mode") or "") == "all" else "batch"

    default_limit = 40 if mode == "all" else _env_int("NJ676_TRANSLATE_LIMIT", 20, 1, 100)
    limit = max(1, min(100, int(payload.get("limit") or default_limit)))
    batch = _env_int("NJ676_TRANSLATE_BATCH", 5, 1, 10)
    # Свой бюджет: страховка от «тихой смерти» по таймауту задачи. Воркер
    # успевает доложить итог и сохранить переведённое, а не обрывается на
    # середине (23.10.2026: перевелись 5 карточек из 1037 и всё стихло).
    budget = _env_int("NJ676_TRANSLATE_BUDGET", 1500, 120, 5400)
    started = time.monotonic()

    base = _site_base()
    if not base:
        raise RuntimeError(
            "не настроен адрес сайта: NJ676_API_BASE (или NJ120_API_BASE) в workers/.env"
        )

    stats = {"translated": 0, "skipped": 0, "found": 0}
    errors: list[str] = []
    # Карточки, которые не осилили или не успели: сайт снимет с них клейм, и
    # следующее нажатие «Перевести всё» снова их увидит (иначе они висели бы
    # «в работе» до истечения срока).
    untranslated: list[str] = []
    tried: set[str] = set()
    pending = None
    rounds = 0
    # Клейм принадлежит задаче: сайт по этому номеру отпускает карточки, когда
    # задача закончилась (бюджет времени, таймаут, падение воркера).
    job_id = _job_id(ctx)

    def _out_of_time() -> bool:
        return time.monotonic() - started >= budget

    def _done() -> int:
        return stats["translated"] + stats["skipped"]

    try:
        while _done() < limit and rounds < 12:
            if _out_of_time():
                errors.append("время прогона вышло — остальное переведёт следующая задача")
                break

            rounds += 1

            # Берём в работу не больше, чем успеем за один заход: карточка,
            # которую задача забрала и не тронула, — это чужая работа, запертая
            # до конца прогона (24.10.2026: 25 карточек взяли, 8 перевели).
            want = max(1, min(limit - _done(), batch * 2))

            ok, reason, answer = await site.get_json(
                _PREFIX, f"/translate-candidates?limit={want}&job={job_id}", base=base
            )

            if not ok:
                errors.append(f"сайт не отдал карточки: {reason}")
                break

            if isinstance(answer, dict) and answer.get("pending") is not None:
                pending = int(answer["pending"])

            cards = [
                card for card in (answer.get("items") or [])
                if isinstance(card, dict) and card.get("slug") and str(card.get("slug")) not in tried
            ]

            if not cards:
                break

            stats["found"] += len(cards)
            tried.update(str(card.get("slug")) for card in cards)

            saved_slugs: set[str] = set()
            index = 0

            while index < len(cards) and _done() < limit and not _out_of_time():
                part = cards[index:index + batch]
                index += len(part)

                try:
                    await ctx.progress(
                        f"Перевожу: {stats['translated']} из {limit}",
                        f"в очереди ещё {pending}" if pending else "",
                    )
                except Exception:  # noqa: BLE001 — рапорт не важнее работы
                    pass

                translated = await _translate_cards(ctx, part, errors)

                if not translated:
                    stats["skipped"] += len(part)
                    untranslated.extend(str(card.get("slug")) for card in part)
                    continue

                saved = await _post_translations(base, translated, errors)
                stats["translated"] += saved
                stats["skipped"] += max(0, len(part) - saved)
                saved_slugs.update(str(item.get("slug")) for item in translated)

                if saved < len(part):
                    untranslated.extend(str(card.get("slug")) for card in part
                                        if str(card.get("slug")) not in saved_slugs)

                try:
                    await ctx.heartbeat()
                except Exception:  # noqa: BLE001 — пульс не должен ронять прогон
                    pass

            # Всё, что забрали и не перевели (кончилось время или лимит) — обратно
            # в очередь сразу, а не через час по сроку клейма.
            leftover = [str(card.get("slug")) for card in cards[index:]
                        if str(card.get("slug")) not in saved_slugs]

            if leftover:
                untranslated.extend(leftover)

            if _out_of_time():
                errors.append("время прогона вышло — остальное переведёт следующая задача")
                break
    except Exception as exc:  # noqa: BLE001 — отчёт и отпускание клейма важнее
        # Прогон упал на середине: сообщаем сайту, какие карточки мы взяли и не
        # перевели, иначе они останутся «в работе» до проверки живой задачи.
        errors.append(f"сбой прогона: {exc.__class__.__name__}")
        log.exception("nj676.translate: прогон прерван")

        await _report(base, {
            "limit": limit, "mode": mode, **stats,
            "remaining": pending,
            "note": f"прогон прерван: {exc.__class__.__name__}",
            "errors": errors[:5],
            # Все карточки, которые мы забрали: сайт снимает клейм только с
            # непереведённых, поэтому лишние в списке безвредны.
            "untranslated": sorted(tried | set(untranslated))[:100],
        })

        raise

    # Свежий остаток: за время прогона переведённое исчезло из очереди, и
    # «осталось N» должно считаться уже без него. Один дешёвый запрос, а
    # админка показывает правду, а не число на старте.
    try:
        ok, _reason, answer = await site.get_json(
            _PREFIX, "/translate-candidates?limit=1&claim=0", base=base
        )
        if ok and isinstance(answer, dict) and answer.get("pending") is not None:
            pending = int(answer["pending"])
    except Exception:  # noqa: BLE001 — остаток не важнее итога
        pass

    note = f"переведено {stats['translated']}, пропущено {stats['skipped']}"

    if errors and any("время прогона вышло" in e for e in errors):
        note += " (остановлен по времени, остальное — в следующей задаче)"

    log.info("nj676.translate: %s", note)

    result = {
        "limit": limit,
        "mode": mode,
        **stats,
        "remaining": pending,
        "note": note,
    }

    if untranslated:
        result["untranslated"] = untranslated[:100]

    if errors:
        result["errors"] = errors[:5]

    await _report(base, result)

    return result
