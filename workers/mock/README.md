# Мок-серверы AI для локальной разработки

Полноценная проверка конвейера LLM/эмбеддингов **без GPU и внешних API**:

```bash
python3 workers/mock/mock_llm_server.py     # OpenAI-совместимый чат, :1234
python3 workers/mock/mock_embed_server.py   # OpenAI-совместимые эмбеддинги, :1235
```

В `workers/.env`:

```
LOCAL_API_URL=http://127.0.0.1:1234/v1
LOCAL_API_KEY=not-needed
DEFAULT_LLM_MODEL=local::mock-llm
EMBEDDING_API_URL=http://127.0.0.1:1235/v1
```

Ответы детерминированные (хэш текста → вектор; пересказ сообщения → чат),
поэтому тесты повторяемы. В проде эти URL указывают на настоящие сервисы.

## Строгий JSON от мока

Конвейеры, ждущие от модели строгий JSON, ломаются об эхо-ответ мока.
`MOCK_LLM_REPLY_FILE=/path/reply.json` — мок отвечает содержимым файла verbatim
(обёртка OpenAI сохраняется); `MOCK_LLM_REPLY_FILES=a.json,b.json` — очередь
ответов по проходам. В проде эти переменные не задаются.
