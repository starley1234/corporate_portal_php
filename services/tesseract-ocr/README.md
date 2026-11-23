# Tesseract OCR shim

Локальная замена внешнего OCR-шлюза для модуля `prx450_document_ocr`.
Отвечает на тот же `POST /parse`, что и боевой шлюз, поэтому переключение —
только `PRX450_OCR_BASE_URL`, без правки PHP.

**Назначение:** разработка и тестирование. Качество ниже Docling/MinerU/PaddleOCR,
нет LLM-коррекции и кэша. Для production используйте боевой шлюз.

## Установите Tesseract

Debian/Ubuntu:

```bash
sudo apt-get install -y tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng
tesseract --list-langs
```

## Запустите обёртку

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install -r services/tesseract-ocr/requirements.txt
cd services/tesseract-ocr
uvicorn main:app --host 127.0.0.1 --port 8090
```

> Каталог содержит дефис, поэтому импорт `services.tesseract-ocr.main` не
> работает; запускайте `uvicorn` из самого каталога.

Проверка:

```bash
curl -s http://127.0.0.1:8090/health
curl -s -F 'file=@document.pdf' \
  'http://127.0.0.1:8090/parse?engine=auto&output_format=md' | head
```

## Подключите портал

В корневом `.env`:

```dotenv
PRX450_OCR_BASE_URL=http://127.0.0.1:8090
PRX450_OCR_API_KEY=
PRX450_OCR_AUTH_HEADER=Authorization
```

`127.0.0.1` — это сервер, где работают PHP и обёртка. В Docker `127.0.0.1`
внутри контейнера — сам контейнер: используйте `host.docker.internal` или имя
сервиса в compose.

## systemd (сервер без Docker)

```ini
[Unit]
Description=PRX Tesseract OCR shim
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/prx
WorkingDirectory=/var/www/prx/services/tesseract-ocr
ExecStart=/var/www/prx/.venv/bin/uvicorn main:app --host 127.0.0.1 --port 8090
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

Обёртка слушает только loopback и не имеет авторизации: наружу её не открывать.
Ограничение размера — `TESSERACT_OCR_MAX_BYTES` (по умолчанию 25 МБ), таймаут
страницы — `TESSERACT_OCR_TIMEOUT` (120 с), языки — `TESSERACT_OCR_LANG`
(`rus+eng`), DPI рендера PDF — `TESSERACT_OCR_DPI` (300).
