# prx393_workflow — маршруты, календарь и сроки

## Состояние

MVP: правила маршрутизации, производственный календарь РФ 2026, срок по
правилу или подсказке из письма, эскалации T−24/T−4/просрочка. Источник
истины по сроку — `prx_393_deadlines`; prx391 получает снимок только через
`set_sla_snapshot()`.

## Границы

Владеет `prx_393_rules`, `prx_393_calendars`, `prx_393_assignments`,
`prx_393_deadlines`, `prx_393_escalations`. Читает карточку через
`Service_Prx391_Documents::document()`. Не читает таблицы prx391 напрямую.

## Маршруты

- `/sla` — открытые сроки, правила, эскалации.
- `POST /sla/route/<document_id>` — пересчитать маршрут и срок.
- `POST /sla/recalc` — пересчитать до 200 документов.
- `/prx393/api/health`.

## Права

`prx393.view` — `login`; `prx393.manage` — `docflow_manager`.

## Алгоритм

1. Правила по `priority`; побеждает ПЕРВОЕ подходящее. Сильные признаки —
   домен отправителя и ИНН; затем тип; ключевое слово в теме/резюме не меняет
   маршрут, если тип уже известен и другой. Поле `stop` оставлено в схеме для
   совместимости и на выбор больше не влияет.
2. Нет правила — безопасный маршрут «Канцелярия», роль `docflow_clerk`.
3. Срок: подсказка из текста (`deadline_hint_days/unit`) важнее правила;
   ≤ 48 ч — календарные часы; длинные — рабочие дни до 18:00.
4. `prx_393_assignments` и `prx_393_deadlines` — upsert по `document_id`.
5. Снимок в prx391; событие `prx393.document.assigned`.

Правила-first: тип от ИИ лишь один из входов; при ошибке модели сработает
безопасный маршрут, а не «ИИ решил права».

## События

- Слушает `prx391.document.registered` → `route()`.
- Слушает `prx391.document.updated` с `recalc=true` → `route()`.
- Публикует `prx393.document.assigned`, `prx393.deadline.approaching`,
  `prx393.deadline.overdue`.

## Эскалации

`maybe_escalate()` вызывается на `/sla` и из CLI `prx393:escalate` (cron).
Уровни: `t24`, `t4`, `overdue`; уникальный индекс `(deadline_id, level)`
защищает от дублей. Получатели — пользователи роли исполнителя/менеджера.

## Проверки

`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`.
