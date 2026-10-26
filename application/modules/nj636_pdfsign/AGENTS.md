# NJ-636 · Подписи и реквизиты PDF (nj636_pdfsign)

## Состояние
- **Операции** (чистый PHP, поверх движка nj634, без внешних бинарников):
  `stamp` — блок реквизитов поверх документа (должность/ФИО/дата), позиции
  bl/br/tl/tr/center, страницы first/last/all; `count` — эвристика числа
  визуальных подписей (/Annots + текстовые маркеры); `strip` — вырезать
  визуальные аннотации-подписи, не трогая тело документа.
- **Движок** — `Service_Nj634_Annotate::stamp` (многострочный блок, подчёркивания).
- **Сохранение в БД** — каждый прогон → `nj_636_runs` (kind: stamp|count|strip,
  сводка JSONB). История — в сайдбаре `/pdf-sign`.

## Безопасность (обязательный слой)
1. **Без exec/shell**: наложение через nj634 (шрифт встраивается, ресурсы
   страниц сохраняются); команд ОС нет — command injection невозможен.
2. **Приём файлов**: `read_upload()` — `is_uploaded_file`, магия `%PDF-`,
   лимит `NJ636_MAX_UPLOAD_MB` (15), отказ от шифрованных PDF.
3. **Rate limit**: `rate_ok(ip)` — `NJ636_RATE_PER_MINUTE` (5)/`NJ636_RATE_PER_HOUR`
   (40) с одного IP; счётчик по `nj_636_runs.client_ip`; слой общий
   `Service_Nj100_Ratelimit`.
4. Порог строк реквизитов — `NJ636_MAX_SIGN_LINES` (48).

## Классы и файлы
- `Service_Nj636_Pdfsign` — оркестратор: валидация, stamp/count/strip, история.
- `Controller_Nj636_Pdfsign` / `Controller_Nj636_Api` — страница `/pdf-sign` и API.
- `migrations_pg/pgMigration_Nj_636_001_Runs.php` — таблица `nj_636_runs`.
- `views/636/index.php` + `views/636/script.php` — форма и клиент; карточка `views/nj636/card.php`.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_636_runs` | история (stamp|count|strip; JSONB сводка; client_ip — для rate limit) |

## API
```
POST /pdf-sign/api/stamp   multipart: file=..., lines=«Должность\nФИО\nДата», pos=bl|br|tl|tr|center, pages=last|first|all, underline=1 → application/pdf
POST /pdf-sign/api/count   multipart: file=... → {ok, pages, annotations, text_markers, likely_signed}
POST /pdf-sign/api/strip   multipart: file=... → application/pdf (без визуальных подписей)
GET  /pdf-sign/api/run/<id> → запись истории
```
Ошибки: `{"ok":false,"error":"..."}`; rate limit → 429; не-PDF → 400.

## env (в хвост .env.example)
```
NJ636_SCHEMA_AUTOCREATE=0
NJ636_RATE_PER_MINUTE=5
NJ636_RATE_PER_HOUR=40
NJ636_MAX_UPLOAD_MB=15
NJ636_MAX_SIGN_LINES=48
```

## Ограничения (осознанные)
- `count`/`strip` работают по эвристике: внешние подписи-картинки (не аннотации)
  не считаются и не вырезаются.
- Парольная защита PDF не поддерживается (файлы отвергаются).
