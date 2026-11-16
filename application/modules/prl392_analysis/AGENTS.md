# prl392_analysis — ИИ-разбор входящих

## Состояние

MVP: профиль `classify_extract_v1`, прогоны `prl_392_runs`, предложения
`prl_392_suggestions`, канал `doc.analyze`, проверка человеком и применение в
prl391. Модель — только `local::…`; проверка выполняется до очереди.

## Границы

Модуль читает текст через `Service_Prl390_Intake` и карточку через
`Service_Prl391_Documents`. Пишет только свои две таблицы. В карточку попадает
исключительно через `Service_Prl391_Documents::apply_analysis()` после явного
действия. Права не решает и ACL не обходит.

## Маршруты

- `/analysis` — очередь предложений и журнал прогонов.
- `POST /analysis/run/<document_id>` — постановка `doc.analyze`.
- `POST /analysis/accept/<suggestion_id>` — применить (можно с правками).
- `POST /analysis/reject/<suggestion_id>`.
- `GET /prl392/api/input/<run_id>`, `POST /prl392/api/result/<run_id>`,
  `/prl392/api/health` — API воркера.

## Права

`prl392.view` — `login`; `prl392.run` — `docflow_clerk`, `docflow_lawyer`,
`docflow_manager`; `prl392.admin` — `docflow_manager`.

## Контракт предложения

```json
{"type":"claim","type_confidence":0.94,
 "fields":{"outbound_no":{"value":"118","quote":"…","confidence":0.9}},
 "summary":"…","risk":{"level":"high","reasons":["…"]},
 "findings":[{"kind":"deadline","text":"…"}]}
```

PHP нормализует значения: тип только из `prl_391_types` (иначе `other`),
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
