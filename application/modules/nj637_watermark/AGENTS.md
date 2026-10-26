# Водяные знаки на PDF (nj637_watermark) — памятка для агентов

Добавьте защитный текст на страницы PDF: полупрозрачный по диагонали или
заметный отпечаток — без установки программ. Всё на сервере, чистым PHP.

## Что умеет

- **Диагональный знак** — полупрозрачный, за текстом, на каждой странице
  («КОПИЯ», «Внутренний документ»).
- **Заметный отпечаток** — поверх содержимого, с подчёркиванием.
- **Выбор страниц** — все / первая / последняя / диапазон «N-M».
- **Настройки** — размер шрифта и насыщенность (в процентах).

## Безопасность

| Угроза | Мера |
|---|---|
| Command injection | shell не используется: наложение — чистый PHP (nj634) |
| Загрузка мусора | `is_uploaded_file`, магия `%PDF-`, лимит 15 МБ, отказ от шифрованных файлов |
| DoS | rate limit по IP в БД (мин/час) |
| Утечка | файлы не покидают сервер и не публикуются |

## Сохранение в БД

`nj_637_runs` — история наложений (mode, pages, сводка JSONB).

## API

```bash
curl -F 'file=@document.pdf' -F 'text=КОПИЯ' -F 'mode=diagonal' \
     /watermark/api/apply -o signed.pdf

curl -F 'file=@document.pdf' -F 'text=Для служебного пользования' \
     -F 'mode=stamp' -F 'pos=br' /watermark/api/apply -o stamped.pdf
```

## Правила для агента

## Состояние
- **Наложение** (чистый PHP, поверх движка nj634, без внешних бинарников):
  `diagonal` — полупрозрачный знак по диагонали ЗА текстом каждой страницы;
  `stamp` — заметный отпечаток ПОВЕРХ (как подпись по строке). Страницы:
  all/first/last/«N-M»; насыщенность и кегль регулируются.
- **Движок** — `Service_Nj634_Annotate::watermark/stamp` (общий с nj636).
- **Сохранение в БД** — каждый прогон → `nj_637_runs` (mode, pages, сводка JSONB).

## Безопасность (обязательный слой)
1. **Без exec/shell**: наложение через nj634; команд ОС нет.
2. **Приём файлов**: `read_upload()` — `is_uploaded_file`, магия `%PDF-`,
   лимит `NJ637_MAX_UPLOAD_MB` (15), отказ от шифрованных PDF.
3. **Rate limit**: `rate_ok(ip)` — `NJ637_RATE_PER_MINUTE` (5)/`NJ637_RATE_PER_HOUR`
   (40) с одного IP; счётчик по `nj_637_runs.client_ip`; слой общий.
4. Текст знака — поток `NJ637_MAX_TEXT_CHARS` (400).

## Классы и файлы
- `Service_Nj637_Watermark` — оркестратор: валидация, apply, история.
- `Controller_Nj637_Watermark` / `Controller_Nj637_Api` — страница `/watermark` и API.
- `migrations_pg/pgMigration_Nj_637_001_Runs.php` — таблица `nj_637_runs`.
- `views/637/index.php` + `views/637/script.php`; карточка `views/nj637/card.php`.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_637_runs` | история (mode: diagonal|stamp; JSONB сводка; client_ip — rate limit) |

## API
```
POST /watermark/api/apply   multipart: file=..., text=..., mode=diagonal|stamp,
                            pages=all|first|last|N-M, size=.., opacity=0..1,
                            pos=br|bl|tr|tl|center, underline=1 → application/pdf
GET  /watermark/api/run/<id> → запись истории
```
Ошибки: `{"ok":false,"error":"..."}`; rate limit → 429; не-PDF → 400.

## env (в хвост .env.example)
```
NJ637_SCHEMA_AUTOCREATE=0
NJ637_RATE_PER_MINUTE=5
NJ637_RATE_PER_HOUR=40
NJ637_MAX_UPLOAD_MB=15
NJ637_MAX_TEXT_CHARS=400
```

## Ограничения (осознанные)
- Полупрозрачность — серым цветом заливки (без /ExtGState), совместимо с любым
  просмотрщиком; чисто «цветная» прозрачность не поддерживается.
- Парольная защита PDF не поддерживается.
