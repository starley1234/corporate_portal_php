# Профили домена

Профиль — это **общий словарь для нескольких проектов**: типы узлов с цветами, разделы страницы,
стартовые вкладки, сущности, правила и пример решения. Один профиль — один домен
(например, «платформа ИИ» или «подразделение»).

Зачем: когда сервисов и проектов становится больше одного, они должны называть вещи одинаково.
Иначе «gateway» в одном проекте и «шлюз» в другом — это два разных объекта, и свести их потом
дороже, чем сразу договориться.

```bash
node scripts/arch.mjs profiles                                  # что есть
node scripts/arch.mjs init ../team-a --profile platform         # новый проект из профиля
node scripts/arch.mjs init ../team-b --profile platform         # второй — тот же словарь
node scripts/arch.mjs init ../my-own --profile ./мой.yaml       # свой профиль файлом
```

Что получается: каталог с `arch/` (`meta.yaml`, `components.yaml`, `entities.yaml`, вкладки,
решения, инварианты), который сразу проходит `check --strict` и собирается в страницу.

## Формат

```yaml
name: department                 # имя для `arch profiles`
description: "одна строка о домене"
title: "Подразделение ИИ-автоматизации"   # заголовок страницы (meta.title)
heading: "Подразделение"                  # крупный заголовок
subtitle: "что оптимизируем и в каком порядке"
sections:                        # те же разделы, что в meta.yaml
  - { dir: tobe, label: "Портфель", role: primary }
kinds:                           # палитра типов узлов: id -> [цвет, подпись]
  core: [ "#f59e0b", Ключевые процессы ]
components:                      # описания компонентов: id -> текст
  intake: "**Приём заявок.** Одна дверь для всех."
entities:                        # стартовый реестр сущностей
  - { id: process, type: object, title: "Процесс-кандидат", owner: "Владелец процесса", data_class: internal }
tabs:                            # стартовые вкладки: файл + содержимое
  - file: tobe/01-portfolio.yaml
    tab:
      title: Воронка процессов
      overview: { lead: "...", blocks: [ ... ] }
      detail: { lead: "...", blocks: [ ... ] }
invariants:                      # стартовые правила (необязательно)
  - file: 01-pilot-has-metric.yaml
    rule: { id: INV-001, title: "...", rule: reachable, tabs: [ tobe/01-portfolio ], from: [ pilot ], to: [ metric ] }
controls:                        # стартовые требования (необязательно)
  - file: 01-ib.yaml
    control: { id: IB-01, title: "...", area: ИБ, satisfied_by: [ dlp ] }
decisions:                       # стартовые решения (необязательно)
  - file: 01-one-door.yaml
    adr: { id: ADR-001, title: "...", status: proposed, context: "...", decision: "..." }
```

Обязательные поля: `title`, `sections`, `kinds`. Остальное — по вкусу: пустой профиль лучше
не делать, но и складывать туда весь проект не нужно — профиль задаёт **старт**, дальше проект
живёт сам.

## Что уже есть

| Профиль | Для чего |
|---|---|
| `minimal` | пустой каркас: одна схема-пример и объяснение формата |
| `platform` | платформа ИИ: клиенты → ingress → gateway → DLP → модели, guardrails, инвариант обхода |
| `department` | подразделение: воронка процессов, роли и ритм, правило «у пилота есть метрика» |

Новый домен — новый файл `profiles/<имя>.yaml`. Проверка: `arch profiles` увидит его сразу,
`arch init <каталог> --profile <имя>` — соберёт проект. Общие для всех доменов тесты лежат
в `tests/profiles.test.mjs`.
