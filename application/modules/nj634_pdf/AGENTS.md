# NJ-634 · PDF-инструменты (nj634_pdf)

## Состояние
- **Набор утилит** (легковесные, на чистом PHP, без внешних бинарников):
  `info` (версия/страницы/метаданные/шифрование), `text` (извлечение текста),
  `txt2pdf` (текст → PDF, кириллица через встраиваемый DejaVu Sans),
  `split` (вырезать диапазон страниц), `merge` (перекомпоновка страниц одного файла),
  `mergefiles` (объединение нескольких файлов: все страницы по порядку).
- **Сохранение в БД**: каждый прогон → `nj_634_runs` (сводка JSONB, для `text` — сам текст, обрезанный лимитом).
- **История** в сайдбаре `/pdf` берётся из `recent_runs()`.

## Безопасность (обязательный слой)
1. **Без exec/shell вообще**: разбор и генерация PDF — только PHP (расширения
   zlib/mbstring/fileinfo). Команд ОС и внешних программ нет в принципе —
   command injection невозможен.
2. **Приём файлов**: только multipart-поле `file`, проверка `is_uploaded_file`,
   магия `%PDF-`, лимит размера `NJ634_MAX_UPLOAD_MB` (15), отказ от шифрованных
   PDF (защита от мусора/DoS через разбор).
3. **Rate limit**: `rate_ok(ip)` — не более `NJ634_RATE_PER_MINUTE` (5) в минуту
   и `NJ634_RATE_PER_HOUR` (40) в час с одного IP; счётчик по `nj_634_runs.client_ip`.
   Слой общий: `Service_Nj100_Ratelimit::window_counts/allowed/limit_text`
   (nj100_platform); пороги — наши (env + config/nj634_pdf.php).
4. Извлечённый текст хранится с потолком `NJ634_MAX_TEXT_CHARS` (200 000).

## Классы и файлы
- `Service_Nj634_Parser` — чистый PHP-парсер: объекты, дерево /Pages, декод
  FlateDecode/ASCIIHex, минимальный интерпретатор Tj/TJ/Td/TD/Tm/T*, ToUnicode.
- `Service_Nj634_Txt2pdf` — генератор «текст → PDF» (PDF 1.4, A4, Identity-H,
  CID=GID, встраиваемый TTF cmap format 4/12, hmtx).
- `Service_Nj634_SplitMerge` — вырезание/перекомпоновка страниц («репагинация
  объектов»: перенос зависимостей страницы + новый xref/trailer).
- `Service_Nj634_Annotate` — общий движок наложения текста на страницы
  существующего PDF (водяной знак ПОД текстом, отпечаток/подпись ПОВЕРХ,
  блок строк с подчёркиванием, InlineFont через ToUnicode). Потребители —
  сервисы-надстройки nj636_pdfsign и nj637_watermark.
- `Service_Nj634_Writer` — сериализатор PDF (xref/trailer по смещениям).
- `Service_Nj634_Pdf` — оркестратор: валидация, rate limit, запись в БД.
- `Controller_Nj634_Pdf` / `Controller_Nj634_Api` — страница `/pdf` и JSON API.
- `migrations_pg/pgMigration_Nj_634_001_Runs.php` — таблица `nj_634_runs`.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_634_runs` | история операций (kind: info/text/txt2pdf/split/merge; сводка JSONB; text — извлечённый текст; client_ip — для rate limit) |

## API
```
POST /pdf/api/info        multipart: file=... 
POST /pdf/api/text        multipart: file=...
POST /pdf/api/txt2pdf     json: {title, text}   → ответ application/pdf
POST /pdf/api/split       multipart: file=..., first, last
POST /pdf/api/merge       multipart: file=..., pages=1,3,2
POST /pdf/api/mergefiles  multipart: files[]=... (до 12 файлов, все страницы подряд)
GET  /pdf/api/run/<id>    → сохранённый прогон (текст)
```
Ошибки: `{"ok":false,"error":"..."}`; rate limit → 429; не-PDF/пустой → 400.

## Проверка качества вывода
- Юнит-тесты `tests/Unit/PdfServicesTest.php` — round-trip «свой генератор ← свой парсер» + страницы + split/merge/mergefiles (запуск: `php tests/mini_runner.php PdfServices`).
- Независимая валидация результата — `python3 -c "from pypdf import PdfReader"` (pypdf ставился в песочницу через `pip3 install --break-system-packages pypdf`): сверяем число страниц и извлекаемость текста чужим парсером, чтобы отлавливать дефекты вроде битых словарей /Pages при split/merge.

## env (в хвост .env.example)
```
NJ634_RATE_PER_MINUTE=5
NJ634_RATE_PER_HOUR=40
NJ634_MAX_UPLOAD_MB=15
NJ634_MAX_TEXT_CHARS=200000
NJ634_FONT_TTF=
```

## Ограничения (осознанные)
- Текст извлекается ТОЛЬКО из текстовых PDF (без OCR; сканы → пусто).
- Не разбираются xref-потоки и объектные потоки (/ObjStm) — такие файлы
  отвергаются понятной ошибкой в `info`/`text`.
- Шифрованные PDF не читаются.
- `split`/`merge` работают с классическим PDF (разыменованным парсером);
  сложные 3D/мультимедиа-аннотации могут быть опущены.
