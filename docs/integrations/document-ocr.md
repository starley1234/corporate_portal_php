# Распознавание документов: API и подключение шлюза

`prx450_document_ocr` — сервис «PDF/скан → Markdown» с внешним OCR-шлюзом.
Модуль работает главным образом через API: потребитель получает ключ
`prx150`, адрес и контракт «multipart file → JSON». Страница
`/prx450_document_ocr` — админская проверка настройки.

Модуль не реализует OCR сам и не хранит документы: он проверяет файл и
параметры, зовёт шлюз и нормализует ответ. Шлюз может быть боевым
(Docling/MinerU/PaddleOCR) или локальной обёрткой над Tesseract — контракт
один и тот же.

## 1. Контракт API

```bash
curl --fail-with-body \
  "https://portal.example/api/v1/document-ocr/parse?engine=auto&output_format=md" \
  -H "Authorization: Bearer $PRX_API_KEY" \
  -F "file=@document.pdf;type=application/pdf"
```

- Метод: `POST`, `multipart/form-data`, поле `file`.
- Право: ключ `prx150` со scope `prx450.parse`.
- Лимит: у ключа (по умолчанию 60 запросов/мин).
- Файл: до 25 МБ; PDF, PNG, JPEG, TIFF, WEBP, BMP. Зашифрованные PDF шлюз
  отклоняет.
- Ответ — JSON, тот же, что у шлюза:

```json
{
  "file": "document.pdf",
  "content": "# Заголовок\nТекст документа…",
  "cached": false,
  "cache_source": null,
  "engine_used": "docling-cpu",
  "llm_fixed": false,
  "raw_result": null
}
```

`raw_result` заполняется только при `output_format=full`; в `md` он `null`,
чтобы не тащить сырой JSON потребителю, которому нужен только текст.

### Параметры запроса (query)

| параметр | тип | по умолчанию | что делает |
|---|---|---|---|
| `engine` | `auto` \| `docling-cpu` \| `mineru` \| `paddleocr-vl` | `auto` | движок шлюза |
| `output_format` | `md` \| `full` | `md` | `md` — только `file` и `content`; `full` — с `raw_result` |
| `force` | bool | `false` | игнорировать кэш шлюза и пересчитать |
| `llm_fix` | bool | `false` | исправить опечатки OCR и разметку через LLM |
| `llm_model` | string | `default` | модель для LLM-коррекции |
| `llm_prompt` | string \| null | `null` | своя инструкция корректору |
| `preprocess` | bool | `true` | убрать пустые страницы и сжать сканы |
| `max_image_side` | int 512–8192 | `2048` | макс. сторона картинки при препроцессинге |
| `ocr` | bool | `true` | OCR для сканов |
| `extract_images` | bool | `false` | вырезать изображения |
| `vlm_pictures` | bool | `false` | описывать изображения через VLM (только Docling) |
| `extract_tables` | bool | `true` | восстанавливать структуру сложных таблиц |
| `extra_config` | JSON string \| null | `null` | нативные параметры шлюза, например `{"lang":"ru"}` |

Потребитель может переопределить только эти параметры. Всё остальное —
конфиг модуля; произвольные query-параметры в шлюз не проходят.

### Ошибки

| HTTP | код | когда |
|---|---|---|
| 400 | `file_required`, `empty_file`, `upload_unavailable` | нет файла или загрузка не удалась |
| 413 | `file_too_large` | больше лимита (25 МБ по умолчанию) |
| 415 | `unsupported_file` | не PDF/изображение |
| 422 | `invalid_option`, `ocr_rejected` | неверный параметр или шлюз отклонил файл |
| 502 | `ocr_upstream_error`, `ocr_invalid_response`, `result_too_large` | шлюз упал, вернул не JSON или текст больше лимита |
| 503 | `ocr_unavailable`, `ocr_auth`, `ocr_config` | адрес/ключ шлюза не настроены или шлюз недоступен |
| 504 | `ocr_timeout` | шлюз не успел за `PRX450_OCR_TIMEOUT` |

Формат — общий для машинного API: `{"error":{"code":"…","details":{…}}}`.
Внутренний адрес и ответ шлюза не отдаются; в `details` — только безопасные
поля (`upstream_status`, `limit_bytes`, `allowed`).

## 2. Настройка шлюза

В корневом `.env`:

| переменная | по умолчанию | зачем |
|---|---|---|
| `PRX450_OCR_BASE_URL` | `http://127.0.0.1:8090` | origin шлюза, без `/parse` |
| `PRX450_OCR_PARSE_PATH` | `/parse` | путь, если у шлюза другой |
| `PRX450_OCR_API_KEY` | пусто | ключ шлюза, если требуется |
| `PRX450_OCR_AUTH_HEADER` | `Authorization` | заголовок авторизации |
| `PRX450_OCR_AUTH_SCHEME` | `Bearer` | схема; пусто — просто ключ |
| `PRX450_OCR_TIMEOUT` | `120` | таймаут запроса, секунды |
| `PRX450_OCR_CONNECT_TIMEOUT` | `5` | таймаут подключения |
| `PRX450_OCR_VERIFY_TLS` | `1` | проверка TLS; `0` — только для локального стенда |
| `PRX450_MAX_FILE_BYTES` | `26214400` | лимит файла |
| `PRX450_MAX_CONTENT_BYTES` | `4194304` | лимит текста в ответе |
| `PRX450_OCR_ENGINE` | `auto` | движок по умолчанию |
| `PRX450_OCR_LLM_FIX` | `0` | включать LLM-коррекцию по умолчанию |

Полный список — `application/modules/prx450_document_ocr/config/prx450_document_ocr.php`.
Секреты — только в `.env`, не в конфиге и не в git.

## 3. Локальный Tesseract (127.0.0.1)

Tesseract — CLI, а не HTTP-сервис. Вызывать его напрямую из PHP нельзя:
shell_exec, таймауты, память и параллельность становятся проблемой. Правильно
— тонкая обёртка над Tesseract с тем же контрактом `/parse`, которая слушает
только loopback. Она уже лежит в `services/tesseract-ocr/`.

### Почему FastAPI, а не «прикрутить к PHP»

- **Один контракт.** Переключение с Tesseract на боевой шлюз — одна переменная
  `PRX450_OCR_BASE_URL`, без правки кода и тестов.
- **Изоляция.** У обёртки свой процесс, таймаут и лимит памяти; падение OCR не
  роняет PHP-воркер.
- **Параллельность.** Tesseract CPU-bound: обёртка ограничивает воркеры, а PHP
  не блокирует пул FPM.
- **Тестируемость.** Можно поднять на ноутбуке, проверить `curl`, остановить.

### Запуск

```bash
sudo apt-get install -y tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng
tesseract --list-langs   # должны быть rus и eng

cd /var/www/prx
python3 -m venv .venv
. .venv/bin/activate
pip install -r services/tesseract-ocr/requirements.txt
cd services/tesseract-ocr
uvicorn main:app --host 127.0.0.1 --port 8090
```

Проверка:

```bash
curl -s http://127.0.0.1:8090/health
curl -s -F 'file=@document.pdf' \
  'http://127.0.0.1:8090/parse?engine=auto&output_format=md' | head -c 400
```

В `.env` портала:

```dotenv
PRX450_OCR_BASE_URL=http://127.0.0.1:8090
PRX450_OCR_API_KEY=
```

`127.0.0.1` — сервер, где работают PHP и обёртка. В Docker это контейнер:
используйте `host.docker.internal` (Linux — `--add-host=host.docker.internal:host-gateway`)
или имя сервиса в compose. Не открывайте обёртку наружу: у неё нет авторизации.

systemd-юнит и переменные (`TESSERACT_OCR_LANG`, `TESSERACT_OCR_DPI`,
`TESSERACT_OCR_TIMEOUT`, `TESSERACT_OCR_MAX_BYTES`) — в
`services/tesseract-ocr/README.md`.

### Когда что использовать

| контур | шлюз | почему |
|---|---|---|
| разработка, CI, smoke | Tesseract-обёртка на `127.0.0.1:8090` | без внешних зависимостей и ключей |
| стенд | боевой шлюз или Tesseract | проверить контракт и реальные тайминги |
| production | боевой шлюз (Docling/MinerU/PaddleOCR) | качество, таблицы, VLM, LLM-коррекция, кэш |

Tesseract хорош для проверки контракта и простых сканов; для сложных таблиц,
Handwriting и плохих сканов он уступает Docling/MinerU. Не делайте его
боевым по умолчанию, если важна точность.

## 4. Проверка

```bash
# 401 без ключа
curl -i -X POST http://127.0.0.1:8080/api/v1/document-ocr/parse -F file=@doc.pdf

# 200 с ключом
curl -s http://127.0.0.1:8080/api/v1/document-ocr/parse \
  -H "Authorization: Bearer $PRX_API_KEY" -F file=@doc.pdf | jq -r .content
```

Администратору: `/prx450_document_ocr` — пробный файл и код ошибки;
`/prx150_api_hub/usage` — кто и с каким статусом звал; `/prx100/logs` —
технический лог без текста документа.
