# Демонстрация входящего документооборота (prl390–prl394)

Три PDF — претензия, судебное извещение и требование налоговой. Они нужны,
чтобы показать весь конвейер без почтового ящика и без внешних сервисов.

## Быстрый прогон

```bash
# 1. миграции и окружение
make migrate
make docflow-demo          # копирует PDF в storage/docflow/inbox

# 2. воркер с локальной моделью (или мок-LLM для проверки без GPU)
make up-worker             # настоящий воркер
# либо:
python3 workers/mock/mock_llm_server.py
# и в workers/.env: TOOLFORGE_API_URL=http://127.0.0.1:1234/v1

# 3. откройте /inbox и нажмите «Сканировать папку»
```

Дальше:

1. prl390 принимает файлы, считает SHA-256, кладёт в prl212 и ставит
   `doc.normalize`.
2. Воркер извлекает текст (pypdf) и отправляет его на сайт. prl390 публикует
   `prl390.text.ready`.
3. prl391 регистрирует карточку (`ВХ-2026-00001` …), prl393 сразу назначает
   маршрут и срок по правилам.
4. В карточке нажмите «Запустить разбор» → задача `doc.analyze` → предложение
   с типом, реквизитами и риском. Проверьте и нажмите «Применить в карточку».
5. «Подготовить ответ» → `doc.draft` → черновик, утверждение и (если настроен
   `EMAIL_DSN`) ручная отправка.

## Сквозная проверка с мок-LLM

Ответы лежат в `mock/`. Пример для одного процесса мока:

```bash
export MOCK_LLM_ROUTES="examples/docflow/mock/claim.json=ПРЕТЕНЗИЯ;\
examples/docflow/mock/court.json=СУДЕБНОЕ ИЗВЕЩЕНИЕ;\
examples/docflow/mock/tax.json=ТРЕБОВАНИЕ;\
examples/docflow/mock/draft.json=Составь проект ответа"
python3 workers/mock/mock_llm_server.py
```

В `workers/.env`:

```
TOOLFORGE_API_URL=http://127.0.0.1:1234/v1
TOOLFORGE_API_KEY=
LLM_PROVIDER=TOOLFORGE
PRL392_MODEL=local::mock-llm
PRL394_MODEL=local::mock-llm
```

`PRL392_MODEL`/`PRL394_MODEL` должны начинаться с `local::` — иначе сайт
откажет до постановки задачи (allowlist провайдеров).

## Пересобрать PDF

```bash
python3 -m pip install --break-system-packages reportlab
python3 examples/docflow/make_pdfs.py
```

Тексты намеренно короткие и «чистые»: демо проверяет конвейер, а не качество
OCR. Для сканов без текстового слоя нужен `pdftoppm` + `tesseract`; воркер
включит OCR сам, если они установлены.
