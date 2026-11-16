# prl393_workflow — маршруты, календарь и сроки

## Состояние

MVP: правила маршрутизации, производственный календарь РФ 2026, срок по
правилу или подсказке из письма, эскалации T−24/T−4/просрочка. Источник
истины по сроку — `prl_393_deadlines`; prl391 получает снимок только через
`set_sla_snapshot()`.

## Границы

Владеет `prl_393_rules`, `prl_393_calendars`, `prl_393_assignments`,
`prl_393_deadlines`, `prl_393_escalations`. Читает карточку через
`Service_Prl391_Documents::document()`. Не читает таблицы prl391 напрямую.

## Маршруты

- `/sla` — открытые сроки, правила, эскалации.
- `POST /sla/route/<document_id>` — пересчитать маршрут и срок.
- `POST /sla/recalc` — пересчитать до 200 документов.
- `/prl393/api/health`.

## Права

`prl393.view` — `login`; `prl393.manage` — `docflow_manager`.

## Алгоритм

1. Правила по `priority`; побеждает ПЕРВОЕ подходящее. Сильные признаки —
   домен отправителя и ИНН; затем тип; ключевое слово в теме/резюме не меняет
   маршрут, если тип уже известен и другой. Поле `stop` оставлено в схеме для
   совместимости и на выбор больше не влияет.
2. Нет правила — безопасный маршрут «Канцелярия», роль `docflow_clerk`.
3. Срок: подсказка из текста (`deadline_hint_days/unit`) важнее правила;
   ≤ 48 ч — календарные часы; длинные — рабочие дни до 18:00.
4. `prl_393_assignments` и `prl_393_deadlines` — upsert по `document_id`.
5. Снимок в prl391; событие `prl393.document.assigned`.

Правила-first: тип от ИИ лишь один из входов; при ошибке модели сработает
безопасный маршрут, а не «ИИ решил права».

## События

- Слушает `prl391.document.registered` → `route()`.
- Слушает `prl391.document.updated` с `recalc=true` → `route()`.
- Публикует `prl393.document.assigned`, `prl393.deadline.approaching`,
  `prl393.deadline.overdue`.

## Эскалации

`maybe_escalate()` вызывается на `/sla` и из CLI `prl393:escalate` (cron).
Уровни: `t24`, `t4`, `overdue`; уникальный индекс `(deadline_id, level)`
защищает от дублей. Получатели — пользователи роли исполнителя/менеджера.

## Проверки

`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`.
