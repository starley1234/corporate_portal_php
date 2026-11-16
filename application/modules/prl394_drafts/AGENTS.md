# prl394_drafts — ответы на письма

## Состояние

MVP: шаблоны, черновики, канал `doc.draft`, ручное утверждение и отправка
после `approved`. Автоматической отправки нет и не будет.

## Границы

Владеет `prl_394_templates` и `prl_394_drafts`; карточку читает через
`Service_Prl391_Documents`, файлы/текст — через prl390. Внешних писем сам не
отправляет: зовёт `Service_Prl597_Notify::send_email()` после явного действия.

## Маршруты

- `/replies` — черновики и шаблоны.
- `/replies/document/<document_id>` — ответы по документу, генерация, правка,
  утверждение, отправка.
- `POST /replies/new/<document_id>`, `/replies/save/<draft_id>`,
  `/replies/approve/<draft_id>`, `/replies/reject/<draft_id>`,
  `/replies/send/<draft_id>`.
- `GET /prl394/api/input/<draft_id>`, `POST /prl394/api/result/<draft_id>`,
  `/prl394/api/health`.

## Права

`prl394.view` — `login`; `prl394.draft` — `docflow_clerk`, `docflow_lawyer`;
`prl394.approve` — `docflow_lawyer`, `docflow_manager`; `prl394.send` —
`docflow_manager`. Утверждение и отправка — разные права.

## Контракт воркера

Payload: `draft_id`, `document_id`. Задание содержит шаблон с подстановками,
карточку и текст входящего (до `max_context_chars`). Модель обязана быть
`local::…`. Ответ — текст письма; допускается JSON `{"body":"…"}` (мок-LLM).
Пустой/короткий ответ → `failed`, без отправки.

## Отправка

Только `approved`. Нужны `PRL394_EMAIL_ENABLED=1`, `EMAIL_DSN`, `EMAIL_FROM` и
email корреспондента (или отправителя из конверта). Ошибка отправки
возвращается человеку флешем; статус `sent` ставится только после успеха.

## Данные

`prl_394_drafts` хранит `reg_no`/`subject` снимком для списков (карточка —
источник истины). Цитаты (`citations`) — ссылки на документ/шаблон, не копии
текста. Секретов в черновике нет.

## Проверки

`python3 -m pytest workers/tests/test_docflow.py`, `python3 .tools/portal_check.py`,
`bash .tools/php_check_all.sh`.
