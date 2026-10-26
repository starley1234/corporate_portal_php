# NJ-638 · Конструктор А4 (nj638_pdfplan)

## Состояние
- **Генератор** (чистый PHP, поверх шрифтового движка nj634): шапка (заголовок,
  подзаголовок, дата, разделитель), тело в 1 или 2 колонки с переносом по
  словам (учёт ширины глифов), подвал каждой страницы «Страница X из Y» +
  свой текст. Кириллица встроена, текст копируется из PDF (ToUnicode).
- **Сохранение в БД** — каждая генерация → `nj_638_runs` (title, pages, columns).

## Безопасность (обязательный слой)
1. **Без exec/shell**: генерация — чистый PHP; команд ОС нет.
2. **Ограничения входа**: текст ≤ `NJ638_MAX_TEXT_CHARS` (120 000), пустые
   обязательные поля (заголовок ИЛИ тело) отвергаются.
3. **Rate limit**: `rate_ok(ip)` — `NJ638_RATE_PER_MINUTE` (5)/`NJ638_RATE_PER_HOUR`
   (40) с одного IP; счётчик по `nj_638_runs.client_ip`; слой общий.
4. В документ не подставляется HTML/разметка — только текст (естественное
   экранирование внутри PDF-потока).

## Классы и файлы
- `Service_Nj638_Pdfplan` — сборка PDF (геометрия, перенос, 1–2 колонки, подвал).
- `Controller_Nj638_Pdfplan` / `Controller_Nj638_Api` — страница `/pdf-plan` и API.
- `migrations_pg/pgMigration_Nj_638_001_Runs.php` — таблица `nj_638_runs`.
- `views/638/index.php` + `views/638/script.php`; карточка `views/nj638/card.php`.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_638_runs` | история генераций (title, pages, columns; JSONB; client_ip — rate limit) |

## API
```
POST /pdf-plan/api/build   json: {title, subtitle, date, footer, text, columns:1|2, size} → application/pdf
GET  /pdf-plan/api/run/<id> → запись истории
```
Ошибки: `{"ok":false,"error":"..."}`; rate limit → 429.

## env (в хвост .env.example)
```
NJ638_SCHEMA_AUTOCREATE=0
NJ638_RATE_PER_MINUTE=5
NJ638_RATE_PER_HOUR=40
NJ638_MAX_TEXT_CHARS=120000
```

## Ограничения (осознанные)
- Шрифт — DejaVu Sans (или свой через `NJ634_FONT_TTF`), другие начертания не
  подключаются.
- Это не вёрстка с таблицами/картинками: только текст, линии-разделители и
  колонки (легковесный документ, не офисный макет).
