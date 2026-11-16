# prl391_documents — реестр входящих и карточка

## Состояние

MVP: регистрация из конверта prl390, карточка с реквизитами, типы,
корреспонденты, провенанс полей и журнал. Применение предложений prl392 и
снимок срока от prl393 — через публичные методы, не чтением чужих таблиц.

## Границы

Владеет `prl_391_types`, `prl_391_correspondents`, `prl_391_documents`,
`prl_391_field_events`, `prl_391_history`. `deadline_at` — денормализованный
снимок для списков; источник истины — prl393. Карточка никогда не пишется
из воркера напрямую.

## Маршруты

- `/documents` — реестр с фильтрами и лентой.
- `/documents/<id>` — карточка, вложения, текст, предложение ИИ, сроки, ответы.
- `POST /documents/save/<id>`, `/documents/close/<id>`, `/documents/reopen/<id>`.
- `/documents/file/<file_id>` — скачивание вложения после проверки доступа.
- `/prl391/api/health`.

## Права

`prl391.view` — `login`; `prl391.edit` — `docflow_clerk`, `docflow_lawyer`,
`docflow_manager`; `prl391.admin` — `docflow_manager`. Документы с
`sensitivity` = `personal|commercial` видят только юрист/руководитель/admin.

## Публичные методы

- `register_from_message($message_id)` — идемпотентная регистрация.
- `document($id, $actor)` / `list_documents($filters, $actor)` / `stats($actor)`.
- `save_card($id, $input, $actor)` — ручная правка, событие
  `prl391.document.updated` с `recalc`.
- `apply_analysis($id, $payload, $suggestion_id, $actor, $overwrite)` — только
  после «Применить»; каждое поле пишется в `prl_391_field_events` с `source=llm`.
- `set_sla_snapshot($id, $data)` — вызывает prl393, обратного чтения нет.
- `upsert_correspondent($name, $inn, $email)`.

События: `prl391.document.registered`, `prl391.document.updated`.

## Правила данных

- Рег. № `ВХ-<год>-<5 цифр>` из последовательности, неизменяем.
- `source_message_id` уникален: повторный текст не создаёт вторую карточку.
- Пустые значения не затирают заполненные без `overwrite`; пропуски
  перечисляются в результате применения.
- Секретов и полных текстов писем в `prl_391_history` нет.

## Проверки

`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`,
`python3 -m pytest workers/tests/test_docflow.py`.
