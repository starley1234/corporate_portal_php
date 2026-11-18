# =============================================================================
# Python 3.12 — асинхронные воркеры PRX (папка workers/)
# Запуск: docker compose --profile workers up -d
# =============================================================================

FROM python:3.12-slim-bookworm

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    TZ=Europe/Moscow

WORKDIR /app

# OCR выполняется внутри воркера; наличие Tesseract только на хосте не помогает.
RUN apt-get update && apt-get install -y --no-install-recommends \
        poppler-utils tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng \
    && rm -rf /var/lib/apt/lists/*

# Зависимости кэшируем отдельным слоем
COPY workers/requirements.txt /app/workers/requirements.txt
RUN pip install --no-cache-dir -r workers/requirements.txt

COPY workers/ /app/workers/

# По умолчанию поднимаем воркер (каналы задаёт WORKER_CHANNELS)
WORKDIR /app/workers
CMD ["python", "-m", "prx_workers"]
