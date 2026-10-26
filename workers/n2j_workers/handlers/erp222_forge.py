"""erp222.forge — «Кузница систем»: цикл синтеза артефактов.

Что делает конвейер (образец — nj661_airadar: сайт ставит цикл в очередь nj120,
воркер ведёт шаги и рапортует, страница поллит ход):

  1. POST /forge/api/step {run_id} → ПЛАН шага от сайта: роль (Архитектор,
     Инженер, Аналитик, Критик, Редактор), тип артефакта, постановка мира,
     уже выпущенное (id, заголовки, ценности), контекст базы знаний ИИ-радара
     (факты и идеи) и требование «чем доказывается» тип (type_proof);
  2. ctx.progress(...) — живой этап на сайте (спиннер + этап + секундомер);
  3. один LLM-вызов со строгой JSON-схемой → РАБОЧИЙ ДОКУМЕНТ: заголовок,
     сводка, тело, входы/выходы/допущения/открытые вопросы, оценки по осям,
     опора на уже выпущенное (depends_on) и на находки радара (sources);
  4. локальная проверка «документ или пересказ» (_document_gaps) — если телу
     не хватает обязательных частей типа, одна доработка тем же шагом (без
     второй записи на сайте: доработка идёт ДО отправки);
  5. POST /forge/api/artifact → сайт дедупит по отпечатку, сам считает новизну
     и ценность, сохраняет и связывает. Критик дополнительно присылает
     target_id и target_scores — сайт ПЕРЕСЧИТЫВАЕТ ценность чужого артефакта,
     так набор со временем становится честнее, а не «больше»;
  6. POST /forge/api/finish {status, stats}.

Почему документы, а не «рассуждения агентов»: замечание владельца (08.10.2026)
«нужно, чтобы модуль формировал ценные и полезные артефакты и был интересен».
Ценность = документ, по которому можно начать работу: конкретика, числа,
схемы, шаги, а не «важно отметить, что...». Отсюда и промпты: у каждого типа
артефакта описан обязательный состав (TYPE_BRIEF), а роль получает свой голос
(ROLE_VOICE: архитектор не пишет код, инженер не философствует).

Env (все опциональны, значения по умолчанию работоспособны):
  NJ120_API_BASE / WORKER_SITE_TOKEN  — общий адрес сайта и токен (как у всех
                                        модульных каналов);
  ERP222_FORGE_API_BASE/SITE_TOKEN    — свой адрес/токен (канал на другом сайте);
  ERP222_FORGE_MODEL                  — «провайдер::модель» для шагов;
  ERP222_FORGE_CONTEXT                — примерный контекст модели в токенах
                                        (из него считаются срезы промпта);
  ERP222_FORGE_MAX_TOKENS             — бюджет ответа шага (по умолчанию 2600);
  ERP222_FORGE_POLISH                 — «0», чтобы выключить доработку по
                                        проверке документа (по умолчанию включена,
                                        не больше одной на шаг);
  ERP222_FORGE_BUDGET                 — секунд на весь цикл (по умолчанию 2400);
  ERP222_FORGE_STEP_TIMEOUT           — секунд на один вызов модели (420).
"""

from __future__ import annotations

import json
import logging
import os
import time

from .. import site, webread

log = logging.getLogger("erp222_forge")

_PREFIX = "ERP222_FORGE"

_CHANNEL = "erp222.forge"

# Потолок длины тела документа: перекликается с Service_Erp222_Artifact::BODY_MAX
# (24000) — режем раньше, чтобы сайт не резал молча посреди фразы.
_BODY_MAX = 20000


# ------------------------------------------------------------------ словари
# Голос роли: что она решает и чего НЕ делает. Роль приходит из плана шага.
ROLE_VOICE = {
    "architect": (
        "Ты — Архитектор. Твоя работа — границы системы и данные: что входит, что "
        "НЕ входит, из каких частей состоит, как они связаны. Не пиши код целиком и "
        "не считай деньги: это работа Инженера и Аналитика."
    ),
    "engineer": (
        "Ты — Инженер. Твоя работа — как это сделать: контракты, форматы, шаги "
        "алгоритма, обработка ошибок, крайние случаи. Каждое утверждение должно быть "
        "проверяемым: метод, поле, шаг. Не пересказывай замысел — доводи его до "
        "рабочего вида."
    ),
    "analyst": (
        "Ты — Аналитик. Твоя работа — числа и опора: откуда берутся данные, чем "
        "мерится успех, сколько это стоит по этапам, какие допущения ты принял. "
        "Числа давай диапазоном и с источником, а не «примерно»."
    ),
    "critic": (
        "Ты — Критик. Твоя работа — разобрать КОНКРЕТНУЮ чужую работу (она дана в "
        "поле target): что не сойдётся на практике, какое допущение неверно, что "
        "сломается первым, чего не хватает для внедрения. Затем выставь цели "
        "разбираемому артефакту по осям — придирчиво, но по делу: оценка меняет его "
        "ценность в каталоге. Не хвали и не унижай — находишь проблему, пишешь меру."
    ),
    "editor": (
        "Ты — Редактор. Твоя работа — свести выпущенное в готовый к употреблению "
        "документ: промпт, план проверки или оффер, который можно взять и "
        "использовать сегодня. Пиши так, чтобы человеку не пришлось ничего "
        "досочинять."
    ),
}

_ROLE_DEFAULT = (
    "Ты — участник группы проектирования. Твоя работа — рабочий документ по "
    "задаче мира, а не рассуждение о ней."
)

# Обязательный состав документа по типу артефакта. Это и есть «ценность»:
# человек должен найти в теле то, что написал бы сам, но потратил бы день.
TYPE_BRIEF = {
    "spec": (
        "Состав: (1) цель и пользователь; (2) границы — что НЕ входит; "
        "(3) 3-6 сценариев работы по шагам; (4) входы и выходы с типами данных; "
        "(5) нефункциональные требования (объём, скорость, права); "
        "(6) критерии готовности — по каким признакам приёмка пройдена."
    ),
    "schema": (
        "Состав: таблицы/коллекции с полями, типами и признаком обязательности; "
        "связи и кардинальность; индексы и почему они нужны; миграция из текущего "
        "состояния (что уже есть у пользователя). DDL (SQL) обязателен."
    ),
    "api": (
        "Состав: для каждого метода — назначение, запрос (метод, путь, параметры, "
        "тело), пример ответа в JSON, коды ошибок и что означает каждый; права "
        "доступа; лимиты и идемпотентность. Без примеров ответа контракт не считается "
        "готовым."
    ),
    "algo": (
        "Состав: вход и выход с типами; нумерованные шаги; что считается ошибкой на "
        "каждом шаге и что тогда делать; псевдокод; сложность/лимиты; как проверить "
        "на маленьком примере (числа)."
    ),
    "prompt": (
        "Состав: роль модели; задача; что даётся на вход (с форматом); требования к "
        "ответу (формат — строго); ограничения и запреты; 1-2 примера "
        "вход→выход. Промпт должен работать после копирования в чат без правок."
    ),
    "testplan": (
        "Состав: что проверяем и почему это важно; таблица проверок «шаг — данные — "
        "ожидаемый результат»; крайние случаи и как их воспроизвести; что считаем "
        "провалом; как прогонять повторно."
    ),
    "risk": (
        "Состав: 4-8 рисков, у каждого — причина, признак наступления, цена ошибки и "
        "мера (что делаем заранее и что делаем, если случилось). Риск без меры — не "
        "риск, а жалоба."
    ),
    "metric": (
        "Состав: метрика, формула, источник данных (где именно взять), "
        "периодичность, целевой порог и текущее значение (если известно), что "
        "делаем при отклонении. Никаких метрик без источника данных."
    ),
    "estimate": (
        "Состав: этапы с длительностью в днях и зависимостями; что делается "
        "параллельно; допущения оценки (команда, доступы, скорость); что может "
        "сдвинуть срок и на сколько; минимальный первый этап, который уже даёт "
        "пользу."
    ),
    "pitch": (
        "Состав: боль клиента его словами; что получает и когда; чем это отличается "
        "от того, что у него есть; цена и формат работы; следующий шаг (конкретный и "
        "маленький — «пришлите выгрузку», «созвонимся 20 минут»)."
    ),
}

SYSTEM_SYNTH = (
    "Ты — проектировщик на службе владельца платформы n2j.ru. Тебе дают МИР (задачу "
    "проектирования) и РОЛЬ в группе: ты выпускаешь ОДИН рабочий документ — "
    "артефакт, по которому можно начинать работу. Пиши по-русски, конкретно, с "
    "числами, именами полей, методами, шагами и примерами. Запрещено: вода "
    "(«важно отметить», «в современном мире»), пересказ замысла вместо решения, "
    "обещания без проверяемых признаков. Если данных не хватает — придумай "
    "разумное допущение и ВЫНЕСИ его в assumptions, а не спрашивай.\n"
    "ОТВЕЧАЙ ТОЛЬКО ВАЛИДНЫМ JSON без markdown:\n"
    '{"title": "название документа до 120 знаков", '
    '"summary": "3-4 предложения: что это и что с ним делать", '
    '"body": "тело документа в markdown: заголовки ##, списки, таблицы, код в '
    'тройных обратных кавычках; 4000-14000 знаков", '
    '"metadata": {"inputs": ["..."], "outputs": ["..."], "assumptions": ["..."], '
    '"open_questions": ["..."]}, '
    '"scores": {"usefulness": 0.0-1.0, "concreteness": 0.0-1.0, '
    '"novelty": 0.0-1.0, "feasibility": 0.0-1.0}, '
    '"depends_on": [номера уже выпущенных артефактов, на которые опираешься], '
    '"sources": ["URL или «факт #N» из базы знаний, если опирался"]}. '
    "Оценки ставь честно: 0.9 — только если документ действительно готов к работе."
)

SYSTEM_FIX = (
    "Ты — тот же автор, что написал документ. Рецензент проверил его и нашёл "
    "пробелы (список дан). Доработай документ: добавь ровно то, чего не хватает, "
    "сохранив всё ценное. Не сокращай и не переписывай с нуля. Отвечай ТОЛЬКО "
    "ВАЛИДНЫМ JSON той же схемы (title, summary, body, metadata, scores, "
    "depends_on, sources), без markdown вокруг JSON."
)

SYSTEM_CRITIC = (
    "Ты — Критик группы проектирования (n2j.ru). Тебе дают МИР и КОНКРЕТНЫЙ "
    "артефакт коллеги. Твоя работа — разбор, по которому автор сможет исправить "
    "работу: где не сойдётся на практике, какие допущения неверны, чего не хватает "
    "для внедрения, что сломается первым. Без похвалы и без общих слов: каждое "
    "замечание — с причиной и мерой. Затем оцени разбираемый артефакт по осям "
    "придирчиво (это меняет его ценность в каталоге).\n"
    "ОТВЕЧАЙ ТОЛЬКО ВАЛИДНЫМ JSON без markdown:\n"
    '{"title": "название разбора до 120 знаков", '
    '"summary": "2-3 предложения: главный вывод разбора", '
    '"body": "разбор в markdown: находки по пунктам, у каждой — причина и мера", '
    '"metadata": {"inputs": ["..."], "outputs": ["..."], "assumptions": ["..."], '
    '"open_questions": ["..."]}, '
    '"scores": {"usefulness": 0.0-1.0, "concreteness": 0.0-1.0, '
    '"novelty": 0.0-1.0, "feasibility": 0.0-1.0}, '
    '"target_scores": {"usefulness": 0.0-1.0, "concreteness": 0.0-1.0, '
    '"novelty": 0.0-1.0, "feasibility": 0.0-1.0}, '
    '"sources": ["на что опирался в разборе"]}. '
    "target_scores — оценка РАЗБИРАЕМОГО артефакта, scores — оценка самого разбора."
)

# ------------------------------------------------------------------ среда

def _env_int(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, "") or default)
    except ValueError:
        return default


def _flag(name: str, default: bool = True) -> bool:
    raw = (os.environ.get(name) or "").strip().lower()

    if not raw:
        return default

    return raw not in ("0", "false", "no", "off")


def _context(limits: dict) -> int:
    """Примерный контекст модели в токенах: из него считаются срезы промпта."""
    return max(4000, _env_int("ERP222_FORGE_CONTEXT", int(limits.get("context") or 32000)))


def _budget(limits: dict) -> int:
    """Секунд на весь цикл: цикл может не успеть — лучше закрыть честно."""
    return max(300, _env_int("ERP222_FORGE_BUDGET", int(limits.get("budget_sec") or 2400)))


def _step_timeout() -> int:
    return max(60, _env_int("ERP222_FORGE_STEP_TIMEOUT", 420))


def _max_tokens(limits: dict) -> int:
    """Бюджет ответа шага: документ на 10-14 тысяч знаков ≈ 4-5 тысяч токенов."""
    default = max(1400, min(6000, _context(limits) // 4))

    return max(800, _env_int("ERP222_FORGE_MAX_TOKENS", default))


def _existing_cap(limits: dict) -> int:
    return max(4, int(limits.get("artifacts_context") or 12))


# ------------------------------------------------------------------ приёмка


def _api_base() -> str:
    return site.module_api_base(_PREFIX, "/forge/api")


def _api_unset(reason: str) -> bool:
    return "не задан" in reason or "API сайта не настроен" in reason


async def _api_post(path: str, data: dict) -> dict:
    """POST на сайт: ошибка соединения — RuntimeError, ненастроенный API — {}."""
    ok, reason, j = await site.post_json(_PREFIX, path, data, json_body=True,
                                         timeout=120.0, base=_api_base())

    if not ok:
        if _api_unset(reason):
            log.info("erp222_forge: API сайта не настроен — пропускаю %s", path)
            return {}

        raise RuntimeError(f"erp222_forge: сайт {path} → {reason}")

    return j if isinstance(j, dict) else {}


def _model(plan: dict) -> str | None:
    return (os.environ.get("ERP222_FORGE_MODEL", "").strip()
            or str(plan.get("model") or "").strip() or None)


async def _llm(ctx, system: str, user: str, phase: str, plan: dict,
               limits: dict, max_tokens: int | None = None) -> str:
    """Один вызов модели. model — из плана мира (или env, или умолчание воркера)."""
    text, _usage = await ctx.llm.chat_with_usage(
        [{"role": "system", "content": system},
         {"role": "user", "content": user}],
        model=_model(plan),
        max_tokens=max_tokens or _max_tokens(limits),
        request_timeout=_step_timeout())

    return text


# ------------------------------------------------------------------ промпты


def _world_block(plan: dict, step: int, steps: int) -> str:
    w = plan.get("world") or {}
    role = plan.get("role") or {}

    parts = [
        f"МИР: {w.get('title', '')}",
        f"Задумка: {w.get('brief', '') or '— не описана, работай по названию'}",
        f"Цель: {w.get('goal', '') or '—'}",
        f"Правила мира: {w.get('protocol', '') or '—'}",
        f"Домен: {w.get('domain', '') or 'не указан'}",
        "",
        f"ШАГ {step} из {steps}. РОЛЬ: {role.get('title', '')}.",
        f"Фокус роли: {role.get('focus', '')}",
        ROLE_VOICE.get(str(role.get("code") or ""), _ROLE_DEFAULT),
    ]

    type_code = str(plan.get("type") or "")

    if type_code in TYPE_BRIEF:
        parts.append(f"ТИП ДОКУМЕНТА: {type_code}. {TYPE_BRIEF[type_code]}")

    proof = str(plan.get("type_proof") or "")

    if proof:
        parts.append(f"ПРИЗНАК ГОТОВНОСТИ (обязателен): {proof}.")

    return "\n".join(parts)


def _existing_block(plan: dict) -> str:
    """Уже выпущенное этим миром: не повторяться и опираться (depends_on)."""
    rows = plan.get("existing") or []

    if not rows:
        return "Выпущенного пока нет — ты первый, опираться не на что."

    parts = ["УЖЕ ВЫПУЩЕНО ЭТИМ МИРОМ (не повторяй; если опираешься — укажи номер в depends_on):"]

    for a in rows:
        parts.append(f"- #{a.get('id')} [{a.get('type')}] {a.get('title')} — "
                     f"ценность {a.get('value_score')}. {str(a.get('summary') or '')[:240]}")

    return "\n".join(parts)


def _knowledge_block(plan: dict) -> str:
    """База знаний ИИ-радара: факты и идеи, на которые можно опереться."""
    k = plan.get("knowledge") or {}

    if not k:
        return ""

    parts = []

    if k.get("facts"):
        parts.append("ФАКТЫ ИИ-РАДАРА (можно ссылаться как «факт #N» в sources):")

        for f in k["facts"][:12]:
            parts.append(f"- факт #{f.get('id')}: {str(f.get('text') or '')[:300]}")

    if k.get("ideas"):
        parts.append("ИДЕИ ИИ-РАДАРА (можно ссылаться как «идея #N»):")

        for g in k["ideas"][:8]:
            parts.append(f"- идея #{g.get('id')}: {g.get('title', '')} — {str(g.get('body') or '')[:200]}")

    return "\n".join(parts)


def _target_block(plan: dict) -> str:
    """Разбираемый артефакт для Критика + рубрика, чтобы оценка не была «на глаз»."""
    t = plan.get("target") or {}

    if not t:
        return ""

    parts = [
        f"РАЗБИРАЕМЫЙ АРТЕФАКТ #{t.get('id')} [{t.get('type')}] «{t.get('title')}» "
        f"(ценность сейчас {t.get('value_score')})",
        f"Сводка: {t.get('summary', '')}",
        "ТЕЛО:",
        str(t.get("body") or "")[:6000],
    ]

    if t.get("critique"):
        parts.append("ПРЕДЫДУЩИЙ РАЗБОР (учти, не повторяй): " + str(t["critique"])[:1500])

    return "\n".join(parts)


def _rubric_block(plan: dict) -> str:
    rubric = plan.get("rubric") or {}

    if not rubric:
        return ""

    words = {
        "usefulness": "решает ли задачу мира",
        "concreteness": "можно ли делать руками прямо сейчас",
        "novelty": "не пересказ ли уже выпущенного",
        "feasibility": "реально ли это в условиях n2j",
    }

    parts = ["РУБРИКА ОЦЕНКИ (вес в ценности):"]

    for key, weight in rubric.items():
        parts.append(f"- {key} ({weight}): {words.get(key, '')}")

    return "\n".join(parts)


def _history_block(plan: dict) -> str:
    """Ход цикла до этого шага: чтобы не повторять уже сделанные шаги."""
    rows = plan.get("history") or []

    if not rows:
        return ""

    parts = ["ЧТО БЫЛО В ЭТОМ ЦИКЛЕ РАНЬШЕ:"]

    for e in rows[-8:]:
        parts.append(f"- {e.get('kind')}: {str(e.get('message') or '')[:200]}")

    return "\n".join(parts)


# ------------------------------------------------------------------ документ


def _json_from(raw: str) -> dict:
    """JSON из ответа модели.

    Сначала строгий разбор: модель иногда отвечает массивом-обёрткой
    ([{…}] или {"items": [...]}) — общий поиск «первый объект в тексте» (nj661)
    вернул бы первый попавшийся словарь, а не документ. Если строгий разбор не
    сложился (markdown-обёртка, текст вокруг) — падаем на общий приём.
    """
    text = (raw or "").strip()
    data = None

    try:
        data = json.loads(text)
    except Exception:  # noqa: BLE001 — дальше общий разбор
        data = None

    if data is None:
        data = webread.json_from_text(text)

    if isinstance(data, dict):
        return data

    if isinstance(data, list):
        for item in data:
            if isinstance(item, dict) and (item.get("title") or item.get("body")):
                return item

    return {}


def _clean_artifact(data: dict) -> dict:
    """Привести ответ модели к виду, который ждёт сайт (чистая функция).

    Сайт (Service_Erp222_Artifact::normalize) всё равно перепроверит поля и
    отбросит пустое, но грязь лучше снять на воркере: тело в 300 знаков, оценки
    словами и «summary» из пяти абзацев — это не документ.
    """
    out: dict = {}

    out["title"] = " ".join(str(data.get("title") or "").split())[:240]
    out["summary"] = " ".join(str(data.get("summary") or "").split())[:700]

    body = data.get("body")

    if body is None:
        body = data.get("content") or ""

    if isinstance(body, (dict, list)):
        body = json.dumps(body, ensure_ascii=False, indent=2)

    out["body"] = str(body).strip()[:_BODY_MAX]

    meta = data.get("metadata")

    if not isinstance(meta, dict):
        meta = {}

    out["metadata"] = {
        key: _string_list(meta.get(key) if key in meta else data.get(key))
        for key in ("inputs", "outputs", "assumptions", "open_questions")
    }

    scores = data.get("scores")

    if isinstance(scores, dict):
        out["scores"] = scores

    if out["title"] and not out["summary"]:
        out["summary"] = out["body"][:300]

    return out


def _string_list(value) -> list[str]:
    """Значение → список строк (как Service_Erp222_Artifact::list_of, но в Python)."""
    if value is None:
        return []

    if isinstance(value, str):
        value = [part for part in value.replace(";", "\n").split("\n")]

    if isinstance(value, dict):
        value = list(value.values())

    if not isinstance(value, (list, tuple)):
        return []

    out = []

    for item in value:
        if isinstance(item, (dict, list)):
            item = json.dumps(item, ensure_ascii=False)

        item = " ".join(str(item).split())

        if item:
            out.append(item[:400])

    return out[:20]


def _document_gaps(artifact: dict, type_code: str) -> list[str]:
    """Проверка «документ или пересказ» (чистая функция).

    Не вместо LLM, а ДО отправки на сайт: то, что проверяемо словом, незачем
    ловить повторным вызовом. Найденные пробелы уходят в доработку.
    """
    gaps: list[str] = []
    body = str(artifact.get("body") or "")
    meta = artifact.get("metadata") or {}

    if len(body) < 900:
        gaps.append(f"тело слишком короткое для документа ({len(body)} знаков, нужно от 900)")

    if type_code in ("spec", "api", "schema", "algo") and not meta.get("inputs"):
        gaps.append("не описаны входы (metadata.inputs)")

    if type_code in ("spec", "api", "algo") and not meta.get("outputs"):
        gaps.append("не описаны выходы (metadata.outputs)")

    if type_code == "api" and ("/v1" not in body and "GET " not in body and "POST " not in body):
        gaps.append("в контракте API нет ни одного метода (GET/POST) и пути — это описание, а не контракт")

    if type_code == "schema" and ("CREATE TABLE" not in body.upper() and "|" not in body):
        gaps.append("в схеме данных нет ни DDL, ни таблицы полей")

    if type_code == "algo" and not any(f"{n}." in body for n in range(1, 10)):
        gaps.append("в алгоритме нет нумерованных шагов")

    if type_code == "risk" and not any(w in body.lower() for w in ("мера", "снижаем", "делаем заранее")):
        gaps.append("у рисков не указано, что делать (мера)")

    if type_code == "metric" and not any(w in body.lower() for w in ("источник", "источником", "откуда")):
        gaps.append("у метрик не указан источник данных")

    if type_code == "testplan" and not any(w in body.lower() for w in ("ожидаем", "ожидаемый", "ожид.")):
        gaps.append("в плане проверки нет ожидаемых результатов")

    return gaps


def _document_needs_work(artifact: dict, type_code: str) -> list[str]:
    """Пробелы, из-за которых шаг уходит в доработку: только проверяемые."""
    if not _flag("ERP222_FORGE_POLISH", True):
        return []

    return _document_gaps(artifact, type_code)


# ------------------------------------------------------------------ шаги


async def _send_progress(run_id: int, stage: str, note: str, live: bool = True) -> None:
    """Живой этап на сайте: спиннер + этап + секундомер на странице цикла."""
    payload = {"run_id": run_id, "stage": stage, "note": note[:380]}

    if live:
        try:
            await _api_post("/progress", payload)
        except Exception as e:  # noqa: BLE001 — рапорт не повод ронять цикл
            log.info("erp222_forge: прогресс не ушёл: %s", e)


async def _run_synth(ctx, plan: dict, limits: dict) -> tuple[dict | None, str, bool]:
    """Синтез обычного артефакта (все роли, кроме Критика).

    Возвращает (артефакт для отправки, причина отказа, была ли доработка).
    """
    role = plan.get("role") or {}
    type_code = str(plan.get("type") or "spec")

    parts = [_world_block(plan, int(plan.get("step") or 1), int(plan.get("steps") or 1)),
             _existing_block(plan)]

    knowledge = _knowledge_block(plan)
    history = _history_block(plan)

    if knowledge:
        parts.append(knowledge)

    if history:
        parts.append(history)

    parts.append(_rubric_block(plan))
    parts.append("Напиши документ и верни JSON строго по схеме.")

    user = "\n\n".join(part for part in parts if part)

    raw = await _llm(ctx, SYSTEM_SYNTH, user, "synth", plan, limits)
    data = _clean_artifact(_json_from(raw))

    if not data.get("title") or not data.get("body"):
        return None, "модель вернула не документ", False

    gaps = _document_needs_work(data, type_code)
    polished = False

    if gaps:
        fix_user = "\n\n".join([
            _world_block(plan, int(plan.get("step") or 1), int(plan.get("steps") or 1)),
            "ЧЕРНОВИК ДОКУМЕНТА (JSON):\n" + json.dumps(data, ensure_ascii=False)[:14000],
            "ПРОБЕЛЫ, которые нашёл рецензент:\n" + "\n".join(f"- {g}" for g in gaps),
            "Доработай документ и верни JSON той же схемы.",
        ])
        try:
            raw = await _llm(ctx, SYSTEM_FIX, fix_user, "polish", plan, limits)
            fixed = _clean_artifact(_json_from(raw))

            if fixed.get("title") and fixed.get("body") and len(fixed["body"]) >= len(data["body"]):
                data = fixed
                polished = True
        except Exception as e:  # noqa: BLE001 — доработка не удалась — отдаём черновик
            log.info("erp222_forge: доработка не удалась: %s", e)

    # Тип документа — из плана шага, а не догадка: сайт без него берёт первый тип
    # роли, и «Алгоритм» Инженера лёг бы в каталог «Контрактом API». В плане тип
    # уже выбран по кругу роли (Архитектор: спецификация → схема данных).
    data["type"] = type_code

    return data, "", polished


async def _run_critic(ctx, plan: dict, limits: dict) -> tuple[dict | None, str, bool]:
    """Разбор Критика: документ-разбор + новые оценки разбираемого артефакта."""
    target = plan.get("target") or {}

    if not target:
        return None, "сайт не дал артефакт для разбора", False

    parts = [_world_block(plan, int(plan.get("step") or 1), int(plan.get("steps") or 1)),
             _target_block(plan),
             _rubric_block(plan),
             "Напиши разбор и верни JSON строго по схеме."]

    user = "\n\n".join(part for part in parts if part)

    raw = await _llm(ctx, SYSTEM_CRITIC, user, "critic", plan, limits)
    data = _clean_artifact(_json_from(raw))

    if not data.get("title") or not data.get("body"):
        return None, "модель вернула не разбор", False

    payload = dict(data)
    payload["type"] = "risk"
    payload["target_id"] = int(target.get("id") or 0)

    # Оценки Критика по разбираемому артефакту — сайт пересчитает его ценность.
    raw_scores = _json_from(raw)

    if isinstance(raw_scores.get("target_scores"), dict):
        payload["target_scores"] = raw_scores["target_scores"]

    return payload, "", False


async def handle(ctx, payload: dict) -> dict:
    """Один цикл синтеза (payload: run_id, world_id, steps, limits, model)."""
    run_id = int(payload.get("run_id") or 0)

    if not run_id:
        raise RuntimeError("erp222_forge: в задаче нет run_id")

    limits = dict(payload.get("limits") or {})
    started = time.monotonic()
    budget = _budget(limits)

    stats: dict = {"steps_ok": 0, "steps_failed": 0, "duplicates": 0, "value_total": 0,
                   "polish": 0, "critiques": 0}
    failed_streak = 0
    note = ""

    await _send_progress(run_id, "Роли", "воркер взял цикл: собираю роли и вводную")
    await ctx.progress("Роли", "цикл взят воркером")

    while True:
        if time.monotonic() - started > budget:
            note = f"бюджет цикла исчерпан ({budget} с): закрываю цикл честно"
            log.warning("erp222_forge: %s", note)
            break

        res = await _api_post("/step", {"run_id": run_id})

        if not res or not res.get("ok", True):
            note = f"сайт не дал план шага: {res.get('error') if isinstance(res, dict) else 'нет ответа'}"
            await _send_progress(run_id, "Сводка", note)
            break

        if res.get("done"):
            note = "все шаги цикла сделаны"
            break

        step = int(res.get("step") or 0)
        steps = int(res.get("steps") or 0)
        role = res.get("role") or {}
        role_code = str(role.get("code") or "")
        type_title = str(res.get("type") or "")

        stage = "Критика" if role_code == "critic" else "Синтез"
        await ctx.progress(stage, f"шаг {step}/{steps}: {role.get('title', '')} → {type_title}")
        await _send_progress(run_id, stage, f"шаг {step}/{steps}: {role.get('title', '')} → {type_title}")

        try:
            if role_code == "critic":
                artifact, problem, polished = await _run_critic(ctx, res, limits)
            else:
                artifact, problem, polished = await _run_synth(ctx, res, limits)
        except Exception as e:  # noqa: BLE001 — один шаг не должен убивать цикл
            problem = f"{type(e).__name__}: {e}"
            artifact = None
            polished = False
            log.warning("erp222_forge: шаг %s упал: %s", step, problem)

        if not artifact:
            stats["steps_failed"] += 1
            failed_streak += 1
            await _send_progress(run_id, stage, f"шаг {step}/{steps} не удался: {problem[:200]}")

            if failed_streak >= 3:
                note = f"три шага подряд не удались, последняя причина: {problem[:300]}"
                break

            continue

        failed_streak = 0

        if polished:
            stats["polish"] += 1

        sent = {
            "run_id": run_id,
            "artifact": artifact,
        }

        if role_code == "critic":
            stats["critiques"] += 1

        try:
            back = await _api_post("/artifact", sent)
        except Exception as e:  # noqa: BLE001
            stats["steps_failed"] += 1
            await _send_progress(run_id, stage, f"артефакт не принят: {str(e)[:200]}")
            log.warning("erp222_forge: артефакт шага %s не принят: %s", step, e)
            continue

        if back.get("ok"):
            if back.get("duplicate"):
                stats["duplicates"] += 1
                await _send_progress(run_id, stage, f"шаг {step}/{steps}: повтор — {artifact.get('title', '')[:120]}")
            else:
                stats["steps_ok"] += 1
                stats["value_total"] += int(back.get("value_score") or 0)
                await _send_progress(
                    run_id, stage,
                    f"шаг {step}/{steps}: {role.get('title', '')} выпустил «{artifact.get('title', '')[:120]}» "
                    f"(ценность {back.get('value_score')})")
        else:
            stats["steps_failed"] += 1
            await _send_progress(run_id, stage,
                                 f"шаг {step}/{steps} отклонён сайтом: {str(back.get('error') or '')[:200]}")

    await _send_progress(run_id, "Сводка", note or "цикл завершён: собираю итог")

    summary = {
        "steps_ok": stats["steps_ok"],
        "steps_failed": stats["steps_failed"],
        "duplicates": stats["duplicates"],
        "value_total": stats["value_total"],
        "polish": stats["polish"],
        "critiques": stats["critiques"],
        "elapsed_sec": int(time.monotonic() - started),
    }

    if note:
        summary["note"] = note[:300]

    fin = await _api_post("/finish", {"run_id": run_id, "status": "done", "stats": summary})

    return {"ok": True, "run_id": run_id, "stats": summary, "finish": fin}
