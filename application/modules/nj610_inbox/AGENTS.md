# Входящие под контролем (nj610_inbox) — памятка для агентов

Почтовый ящик разбирает ИИ: воркер забирает непрочитанные письма (IMAP),
сортирует их, ставит приоритеты и готовит черновики ответов — владельцу
остаётся просмотреть ленту и подтвердить.

- **Лента входящих** — новые письма и готовые черновики сверху;
- **Категории и приоритет** — high/normal/low с короткой категорией;
- **Черновик ответа** — ИИ пишет, человек правит и подтверждает.

Ящик настраивается env-переменными (`NJ610_INBOX_HOST/USER/PASS/FOLDER`).
Если ящик не настроен — сервис честно показывает «не настроен».

## Как это устроено

```
IMAP-ящик → python-воркер (nj610.inbox) → письма в /inbox/api/mail →
LLM-разбор → категория/приоритет/черновик → /inbox/api/drafts → лента
```

Это аккаунтная страница владельца (не в общем меню сервисов).

## Правила для агента

## Состояние
- **Сервис** «Входящие под контролем» (`/inbox`): воркер по расписанию забирает
  непрочитанные письма из почтового ящика (IMAP), LLM сортирует их, ставит
  приоритеты и готовит черновики ответов. Владелец просматривает ленту и
  подтверждает ответы.
- **Аккаунтная страница** (`hidden: TRUE` в манифесте — из левого меню убрана),
  `auth: TRUE` — только для владельца/админа.
- Ящик настраивается env `NJ610_INBOX_*`; если не настроен — `configured()`
  возвращает FALSE и сервис показывает «не настроен».

## Конвейер (не в HTTP)
- Воркер `workers/n2j_workers/handlers/nj610_inbox.py` (IMAP) →
  `POST /inbox/api/mail` (письма) → LLM-разбор →
  `POST /inbox/api/drafts` (категория, приоритет, черновик).
- Авторизация воркера — заголовок `X-Worker-Token` (= `WORKER_API_TOKEN` на сайте);
  дедуп писем по `message_id` (`ON CONFLICT DO NOTHING`).

## Классы и файлы
- `Service_Nj610_Inbox` — configured/feed/stats/set_status + API воркера.
- `Controller_Nj610_Inbox` — страница `/inbox` (лента + черновики).
- `Controller_Nj610_Api`   — API воркера (`mail`, `drafts`, `poll`).
- `views/610/{index,card}.php`.
- `migrations_pg/pgMigration_Nj_610_001_Messages.php`.
- `config/nj_module.php` — манифест каталога.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_610_messages` | письма (message_id UNIQUE, from_addr, subject, body, received_at, category, priority high/normal/low, draft, status new/drafted/done/ignored) |

## API
```
POST /inbox/api/mail     приём писем от воркера (токен)  → {ok, saved}
POST /inbox/api/drafts   приём категорий/черновиков      → {ok, applied}
POST /inbox/api/poll     опрос (страница/воркер)
```
Только с заголовком `X-Worker-Token` (= `WORKER_API_TOKEN` на сайте).

## Безопасность и конвенции
- Страница владельца: доступ ограничен (auth + ACL), письма чужим не показываются.
- Пароль ящика — только в env (`NJ610_INBOX_PASS`), в код не попадает.
- `set_status()` принимает только белый список статусов.

## env (в хвост .env.example)
```
NJ610_INBOX_HOST=imap.yandex.ru:993
NJ610_INBOX_USER=
NJ610_INBOX_PASS=
NJ610_INBOX_FOLDER=INBOX
NJ610_INBOX_API_BASE=http://127.0.0.1/inbox/api
NJ610_INBOX_SITE_TOKEN=CHANGE_ME
NJ610_INBOX_LIMIT=20
```

## Ограничения
- Без настроенного IMAP-ящика и воркера лента пуста (UI показывает «не настроен»).
- Черновики пишет LLM; перед отправкой их правит человек.
