# prx391_documents — реестр входящих и карточка

> Тестовый контур: `PRX_DOCFLOW_ENABLED=1`; UI только для администратора. См. `docs/QUICK-CHECK.md`.

## Состояние

MVP: регистрация из конверта prx390, карточка с реквизитами, типы,
корреспонденты, провенанс полей и журнал. Применение предложений prx392 и
снимок срока от prx393 — через публичные методы, не чтением чужих таблиц.

## Границы

Владеет `prx_391_types`, `prx_391_correspondents`, `prx_391_documents`,
`prx_391_field_events`, `prx_391_history`. `deadline_at` — денормализованный
снимок для списков; источник истины — prx393. Карточка никогда не пишется
из воркера напрямую.

## Маршруты

- `/documents` — реестр с фильтрами и лентой.
- `/documents/<id>` — карточка, вложения, текст, предложение ИИ, сроки, ответы.
- `POST /documents/save/<id>`, `/documents/close/<id>`, `/documents/reopen/<id>`.
- `/documents/file/<file_id>` — скачивание вложения после проверки доступа.
- `/prx391/api/health`.

## Права

`prx391.view` — `login`; `prx391.edit` — `docflow_clerk`, `docflow_lawyer`,
`docflow_manager`; `prx391.admin` — `docflow_manager`. Документы с
`sensitivity` = `personal|commercial` видят только юрист/руководитель/admin.

## Структура сервиса

`Service_Prx391_Documents` — публичный фасад. Внутри:
- `Service_Prx391_Registry` — чтение: реестр, карточка, справочники, сводка;
- `Service_Prx391_Card` — запись: ручные правки, закрытие, применение ИИ;
- `Documents` — регистрация из prx390 и права (общий контракт для соседей).

Вьюха карточки собрана из `views/391/partials/*` (`card`, `analysis`,
`attachments`, `field_events` и блоки правой колонки).

## Публичные методы

- `register_from_message($message_id)` — идемпотентная регистрация.
- `document($id, $actor)` / `list_documents($filters, $actor)` / `stats($actor)`.
- `visible_ids($ids, $actor)` — пакетная проверка доступа к предложенным ID;
  `recent_history($limit, $actor)` не показывает историю скрытых карточек.
  Без актора оба метода не дают доступ к пользовательским данным.
- `save_card($id, $input, $actor)` — ручная правка, событие
  `prx391.document.updated` с `recalc`.
- `apply_analysis($id, $payload, $suggestion_id, $actor, $overwrite)` — только
  после «Применить»; каждое поле пишется в `prx_391_field_events` с `source=llm`.
- `set_sla_snapshot($id, $data)` — вызывает prx393, обратного чтения нет.
- `upsert_correspondent($name, $inn, $email)`.

События: `prx391.document.registered`, `prx391.document.updated`.

## Правила данных

- Рег. № `ВХ-<год>-<5 цифр>` из последовательности, неизменяем.
- `source_message_id` уникален: повторный текст не создаёт вторую карточку.
- Пустые значения не затирают заполненные без `overwrite`; пропуски
  перечисляются в результате применения.
- Секретов и полных текстов писем в `prx_391_history` нет.

## Проверки

`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`,
`python3 -m pytest workers/tests/test_docflow.py`.
