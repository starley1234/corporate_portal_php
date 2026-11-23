# prx391_documents — реестр входящих и карточка

> Тестовый контур: раскомментируйте общий блок в `application/bootstrap.php`; UI только для администратора. См. `docs/QUICK-CHECK.md`.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx391_documents/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние | 18 | 0,4 КБ |
|   Границы | 24 | 0,4 КБ |
|   Маршруты | 31 | 0,5 КБ |
|   Права | 39 | 0,3 КБ |
|   Структура сервиса | 45 | 0,6 КБ |
|   Публичные методы | 55 | 1,1 КБ |
|   Правила данных | 71 | 0,5 КБ |
|   Проверки | 79 | 0,1 КБ |
<!-- context-index:end -->
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

- `/prx391_documents` — реестр с фильтрами и лентой.
- `/prx391_documents/<id>` — карточка, вложения, текст, предложение ИИ, сроки, ответы.
- `POST /prx391_documents/save/<id>`, `/prx391_documents/close/<id>`, `/prx391_documents/reopen/<id>`.
- `/prx391_documents/file/<file_id>` — скачивание вложения после проверки доступа.
- `/prx391_documents/api/health`.

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
`python3 -m pytest workers/tests/handlers/trial/test_docflow.py`.
