# Контекст проекта (читать первым, ~1 экран)

Внутренняя GenAI-платформа компании: чат с моделями (Open WebUI), роутинг LLM, DLP, песочницы, консоль управления.
Здесь описаны **AS-IS** (реализация v1.7.x, один Docker-хост), **TO-BE v2** (распределённая схема с Guardrails, отдельными OCR/RAG, локальной Qwen3) и прикладной слой **«Решения»** (пайплайны подразделений, Graph RAG, синхронизация данных, EvalOps). Первые два слоя заморожены замочками.

## AS-IS в одном абзаце
`proxy`(nginx :443) → `openwebui` (v0.11.4 с патчами) → `gateway` (:4100, JWT, бюджеты, лимиты, вызывает `dlp` :4200 синхронно) → `litellm` (:4000, роутер) → выход через `xray` (VPN, kill switch) к OpenAI/Anthropic, `cliproxy` (подписки CLI), OdiRouter, локальная LLM (LAN). Песочницы: `terminal-broker` + `docker-api` + `term-*`. Управление: `admin` (консоль) + `reconciler` (БД `platform` → Open WebUI/LiteLLM/xray). Данные: один PostgreSQL (3 БД), опц. Redis. Наблюдаемость: Prometheus, Grafana. OCR: `docling`. Известные долги: веб-поиск Open WebUI идёт напрямую мимо gateway/DLP; опубликованные порты Docker обходят ufw (DOCKER-USER пуст).

## TO-BE в одном абзаце
Все каналы (WebUI, pipelines, агенты/MCP, боты, IDE) → ingress/adapters → gateway → **синхронный Regex-DLP** → **Policy & Response Gate**, который параллельно запускает LiteLLM (генерация) и **Fast Guard** (Qwen3Guard 0.6B + Prompt Guard 2); при подозрении — **Safety Reasoner** (gpt-oss-safeguard-20b); ответ буферизуется и выдаётся только после вердиктов (Output DLP; для suspect-but-allowed — heavy output scan). OCR (Docling) и RAG (API + Qdrant + ACL) — отдельные узлы; локальная Qwen3 (vLLM, предлагается Qwen3-32B 4-bit) в изолированной GPU-зоне. Sizing: 7 VM, ~112 vCPU, 432 GiB RAM, ~4,8 TiB SSD, 2 GPU (48 + 80 GiB) — это проектные оценки, не измерения.

## Инварианты
См. `AGENTS.md`. Главное: DLP синхронный; BLOCK ≠ вызов модели; ошибка/таймаут guard ≠ allow; cloud + confidential → preflight или local-only.

## Открытые решения (из TO-BE · План внедрения)
Конкретный checkpoint Qwen3 и VRAM; preflight vs speculative для cloud; как подключить внешний RAG к Open WebUI v0.11.4; таймауты guard и fail-closed по классам данных; объём KB и поток загрузок (OCR/диск); multi-tenancy ACL в RAG.

## Прикладной слой «Решения» (пайплайны подразделений)
Модульный монолит на Python + PostgreSQL/pgvector читает Teamcenter, 1С ERP, документооборот; строит гибридный поиск и граф знаний (структурный граф без LLM + сопоставление сущностей); к LLM — только через ingress → gateway → DLP → Guard; данные источников = confidential → local. Слой вынесен отдельно от замороженного TO-BE: пайплайны подразделения, Graph RAG, синхронизация данных (CDC/MDM) и EvalOps. Аргументы для руководства — `docs/GRAPH-RAG.md`, раздел 11.

Отдельный раздел **«Сервис распознавания документов»** (`arch/recognition/`) описывает OCR-кластер на двух RTX 2070 SUPER: загрузка потоком и пачки по 15 страниц, синхронно до 20 страниц или задача с `job_id`, статусы в SQLite, один движок на задачу с профилями `paddle`/`dual`, Docling на CPU. Понимание графики (Qwen3-VL) и строгий JSON для 1С:ДО/Teamcenter вынесены в следующий этап (`recognition/02-next-stage`); решение зафиксировано в ADR-014.

Сквозная модель процессов предприятия по разработке авиационных агрегатов — от стратегии и поиска клиента до договора, требований, разработки механики/электроники/ПО, технологической подготовки, MES/производства, испытаний, соответствия, сервиса и конца жизненного цикла — описана в [`docs/AVIATION-DESIGN-BUREAU.md`](AVIATION-DESIGN-BUREAU.md). Общая визуальная схема — `processes/01-overview`; подробные схемы вынесены в `commercial/`, `engineering/`, `industrialization/`, `assurance/` и `lifecycle/`. Технические ИИ-сценарии — в `solutions/03-pipelines-avia`.

## Карта перехода AS-IS → TO-BE
`arch/map.yaml` — машиночитаемый ответ на вопрос «что с каждым компонентом текущей платформы
происходит в целевом контуре»: `status: keep | move | drop | new | tbd`, целевой узел TO-BE и
пояснение. Из файла автоматически собирается вкладка **«Карта перехода»** (последняя в TO-BE),
а покрытие проверяется командой:

```bash
npm run arch -- map               # сводка: сколько позиций, сколько покрыто
npm run arch -- map --missing     # что из AS-IS не попало в карту
```

Связь сделана данными, а не рассказом: непокрытый компонент — это дыра в плане внедрения,
и её видно.

## Снимки
Снимок — точка сравнения: «что изменилось с прошлого раза». В папке лежат страница (`architecture.html`),
данные (`architecture.json`) и **копия `arch/`** — поэтому снимок можно и сравнить построчно, и взять
за основу откатa.

```bash
npm run arch -- snapshot 2026-11-06-baseline          # сделать (в snapshots/2026-11-06-baseline/)
npm run arch -- diff snapshots/2026-11-06-baseline    # что изменилось с этой точки
```

| Снимок | Что это |
|---|---|
| `2026-11-06-baseline` | актуальная база: вкладки, требования, инварианты, типы связей |
| `2026-11-02-baseline` | история: состояние до прикладных вкладок. В нём остался только HTML, сравнение по нему невозможно — `diff` скажет об этом прямо |

Снимок весит около 1,6 МБ, поэтому делать его стоит по вехам (квартал, защита бюджета, сдача этапа),
а не каждый день. Git-тегов в репозитории нет: история — один сквинченный коммит.

## Карта репозитория и приёмы
- Инварианты: `arch/invariants/*.yaml` — правила «чего быть не должно» (обход gateway, песочница с выходом наружу); проверяются `npm run check`, отчёт — `arch invariants`.
- Типы связей: опция `rel` у стрелки (`uses`, `carries`, `runs_on`, `enforces`), по умолчанию `flow`; видно в `arch show` и `graph --json`.
- Требования: `arch/controls/*.yaml` — что требуется, чем обеспечено и чем подтверждено; пробелы — `arch controls --missing`.
- Профили домена: `profiles/*.yaml` — общий словарь для нескольких проектов; `arch profiles`, `arch init <каталог> --profile <имя>`.
- Граф в Neo4j: `arch graph --cypher --out exports/graph.cypher` (идемпотентно, числа в шапке файла).
- Выгрузка для смежников: `arch export <вкладка|раздел>` собирает один HTML-файл (без Node и интернета) в `exports/`.
- Источник истины: `arch/*.yaml` (формат `docs/FORMAT.md`). Вкладки: `arch/tobe/NN-*.yaml` (12 файлов + вкладка «Карта перехода», собранная из `map.yaml`), `arch/solutions/NN-*.yaml` (6 технических прикладных вкладок) и процессные разделы `processes/`, `commercial/`, `engineering/`, `industrialization/`, `assurance/`, `lifecycle/`; `arch/asis/NN-*.yaml` (15); `components.yaml` — описания компонентов AS-IS; `meta.yaml` — заголовки, `kinds` и `sections`.
- Слои и замочки: разделы заданы в `meta.yaml › sections` с `role` (`primary` / `solution` / `asis`). Разделы `tobe` и `asis` заморожены (`locked: true`): их вкладки нельзя менять — `check`/`build` сверяют их с `arch/lock.yaml`. Состояние замочков — `npm run arch -- lock --list`; снять/переставить осознанно — `lock --force`. Разделы `solutions` и процессные карты открыты для правок.
- Навигация без чтения файлов: `npm run arch -- tabs | show <вкладка> [o|d] | node <id|текст> | grep <текст>`.
- Правка: `add-node` / `add-edge` или YAML руками → `npm run check` → `npm run build` → `npm run serve -- --open` (или открыть `dist/architecture.html`).
- Раскладка: `npm run arch -- layout <вкладка> o --mode zone` (черновик; `--dry` покажет, не меняя файл).
- **Второй проект — подразделение:** `dept/` (операционная модель, портфель процессов по карточкам `dept/portfolio/*.yaml`, ADR подразделения). Своя сборка: `npm run build:dept`, проверки: `npm run check:dept`, портфель: `npm run portfolio` (список задач + план квартала по бюджету часов + решения совета из `dept/reviews/<квартал>.yaml`). Разбор пользы для подразделения — `docs/DEPARTMENT.md`.
- Свежий клон: сначала `npm install`; проверки — `npm test`.
- Чего нет: автораскладки как автоматизма (команда `layout` даёт черновик, финальная композиция — руками), трассировки от узла TO-BE к коду (только карта перехода на уровне компонентов).

## История модели (чтобы не спорить заново)
Модель развивалась так: сначала один HTML-файл → YAML-вкладки и сборка страницы → разделы-слои
(`asis`/`tobe`/`solutions`) вместо двух наборов схем → карта перехода `map.yaml` → журнал решений
`decisions/` → реестр сущностей `entities/` с `aliases` и `ref` → граф, команды `ask`/`impact` и
MCP-сервер → гипотезы `hypotheses/`. Что решено и почему — в вкладке «Журнал решений»;
что проверяется — в «Гипотезах».
