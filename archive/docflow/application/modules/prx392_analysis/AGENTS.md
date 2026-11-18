# prx392_analysis — ИИ-разбор входящих

> Архив: отключён, требует доработки. См. `archive/docflow/README.md`.

## Состояние

MVP: профиль `classify_extract_v1`, прогоны `prx_392_runs`, предложения
`prx_392_suggestions`, канал `doc.analyze`, проверка человеком и применение в
prx391. Модель — только `local::…`; проверка выполняется до очереди.

## Границы

Модуль читает текст через `Service_Prx390_Intake` и карточку через
`Service_Prx391_Documents`. Пишет только свои две таблицы. В карточку попадает
исключительно через `Service_Prx391_Documents::apply_analysis()` после явного
действия. Права не решает и ACL не обходит.

## Маршруты

- `/analysis` — очередь предложений и журнал прогонов.
- `POST /analysis/run/<document_id>` — постановка `doc.analyze`.
- `POST /analysis/accept/<suggestion_id>` — применить (можно с правками).
- `POST /analysis/reject/<suggestion_id>`.
- `GET /prx392/api/input/<run_id>`, `POST /prx392/api/result/<run_id>`,
  `/prx392/api/health` — API воркера.

## Права

`prx392.view` — `login`; `prx392.run` — `docflow_clerk`, `docflow_lawyer`,
`docflow_manager`; `prx392.admin` — `docflow_manager`.

## Структура сервиса

`Service_Prx392_Analysis` — публичный фасад. Чтение прогонов, предложений и
сводки вынесено в `Service_Prx392_Runs`; постановка задач и разбор ответа
модели остаются в `Analysis`.

## Контракт предложения

```json
{"type":"claim","type_confidence":0.94,
 "fields":{"outbound_no":{"value":"118","quote":"…","confidence":0.9}},
 "summary":"…","risk":{"level":"high","reasons":["…"]},
 "findings":[{"kind":"deadline","text":"…"}]}
```

PHP нормализует значения: тип только из `prx_391_types` (иначе `other`),
дата — `Y-m-d`, ИНН — 10/12 цифр, сумма — число, срок — 1..365 дней.
Валидация схемы — на сайте, не в воркере.

## Очередь и надёжность

Payload: `run_id`, `document_id`, `profile`. `input_hash` дедуплицирует
одинаковый текст/модель/промпт: повторная кнопка не плодит прогоны. Задача
помечается доставленной после записи предложения. Ответ модели без полей и
summary отклоняется, прогон получает `failed`.

## Ограничения

`max_input_chars` (60000) обрезает гигантские тексты; цитаты ограничены
500 символами. Внешние провайдеры запрещены конфигом. Промпт-инъекция в
документе не может изменить права: результат — только данные, не команды.

## Проверки

`python3 -m pytest workers/tests/test_docflow.py` (нормализация/парсер JSON),
`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`.
