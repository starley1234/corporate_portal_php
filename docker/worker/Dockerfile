# =============================================================================
# Python 3.12 — асинхронные воркеры N2J (папка workers/)
# Запуск: docker compose --profile workers up -d
# =============================================================================

FROM python:3.12-slim-bookworm

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    TZ=Europe/Moscow

WORKDIR /app

# Зависимости кэшируем отдельным слоем
COPY workers/requirements.txt /app/workers/requirements.txt
RUN pip install --no-cache-dir -r workers/requirements.txt

COPY workers/ /app/workers/

# По умолчанию поднимаем воркер (каналы задаёт WORKER_CHANNELS)
WORKDIR /app/workers
CMD ["python", "-m", "n2j_workers"]
