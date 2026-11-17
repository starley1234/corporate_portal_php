# AGENTS.md — llm (единый шлюз к LLM и эмбеддингам)

> Памятка агенту. Общие правила платформы — в корневом /AGENTS.md.
> Подробная документация: README.md и Использование.md в этом каталоге.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/llm/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 20 | 0,3 КБ |
|   Состояние | 25 | 0,1 КБ |
|   Ключевые классы | 28 | 0,6 КБ |
|   Таблицы | 38 | 0,1 КБ |
|   Конфигурация (application/config/llm.php) | 41 | 0,6 КБ |
|   Правила использования модулями | 50 | 0,7 КБ |
|   Выбор модели (порядок, зафиксирован 08.10.2026) | 58 | 1,2 КБ |
|   Не трогать / подводные камни | 72 | 1,5 КБ |
|   Долги и идеи (TODO) | 89 | 0,2 КБ |
<!-- context-index:end -->
## Назначение
Фасад `Llm::` над провайдерами (OpenAI-совместимые): чат, vision, извлечение
JSON (`extract`), tool-calling, MCP, промпты-шаблоны, эмбеддинги. Все запросы
логируются в БД (`llm_requests`) с токенами и временем.

## Состояние
- Готовность: рабочий; активно используется (уведомления, module_manager).

## Ключевые классы
| Класс | Роль |
|---|---|
| `Llm` | статический фасад: `Llm::ask()`, `Llm::model('openrouter::...')`, `Llm::system()`, `Llm::extract()`, `Llm::vision()`, `Llm::tools()` |
| `Llm_Service` | реализация; `get_embedding()` для векторов |
| `Llm_Factory` | сборка сервиса из конфига `llm.php` |
| `Llm_Provider_OpenAICompatible` / `_Toolforge*` | провайдеры |
| `CurlRequest` | статический HTTP-помощник (POST JSON) |
| `Model_Llm_Prompt`, `Model_Llm_Request` | промпты и журнал запросов |

## Таблицы
- `llm_prompts`, `llm_requests` — владелец: этот модуль (без префикса — legacy).

## Конфигурация (application/config/llm.php)
- Провайдеры: `local` (LOCAL_API_URL/KEY), `openrouter` (OPENROUTER_API_KEY),
  `toolforge` (TOOLFORGE_API_URL/KEY — корпоративный шлюз),
  **`embedding_server`** — ОТДЕЛЬНЫЙ эндпоинт эмбеддингов
  (EMBEDDING_API_URL/KEY/MODEL/DIMENSIONS, секция `embedding`).
- Модель выбирается строкой `провайдер::модель`. Платформенный выбор —
  `DEFAULT_LLM_MODEL`; модуль без своей `<PRX>_MODEL` наследует его.
- Секреты только через .env.

## Правила использования модулями
1. Быстрые одиночные вызовы — можно в запросе (`Llm::ask(...)`).
2. Батчи/индексация/агенты/длинные цепочки — ТОЛЬКО через очередь prx120
   (воркеры на python), не в HTTP-запросе.
3. Для эмбеддингов: `Llm::get_embedding($text)` — уйдёт на embedding_server;
   не хардкодить URL/модели в коде модулей — только конфиг/окружение.
4. Лог запросов растёт: объёмные задачи — учитывать, периодически чистить.

## Выбор модели (порядок, зафиксирован 08.10.2026)
`Llm_Service::chat()` берёт модель в таком порядке — побеждает первый найденный:
1. `$options['model']` самого вызова: `Llm::chat($messages, ['model' => 'openrouter::google/gemini-2.5-flash'])`
   (так модель навыка передаёт prx657_alice и движок prx660_arch);
2. модель цепочки `Llm::model('провайдер::модель')`;
3. модель из опций шаблона промпта (`Llm::prompt(...)`);
4. `vision_model` из конфига — когда в сообщениях есть изображения;
5. `default_model` из конфига — модель платформы;
6. модель провайдера.

Формат `провайдер::модель` понимается везде, алиасы `llm.model_aliases`
применяются к любому варианту. **Модель платформы — это «модель, когда её не
выбрали»**, а не выбор вызывающего: она не перебивает `$options['model']`.

## Не трогать / подводные камни
- Модель платформы (`default_model`) НЕЛЬЗЯ класть в `_current_model_name`
  (`set_model()`): это поле означает «вызывающий выбрал модель» и перетирает
  `$options['model']`. Именно так 08.10.2026 навык Алисы получал
  `qwen3-coder-30b-a3b-instruct` вместо модели из формы. Для модели платформы
  есть `set_default_model()` (её ставит `Llm_Factory`), для провайдера —
  `set_default_provider()`. Проверяет `.tools/llm_options_stand.php`.
- `embedding_server` — известный провайдер Llm_Service (класс Toolforge:
  OpenAI-совместимый /v1/embeddings на EMBEDDING_API_URL). 06.10.2026 он не
  был известен фабрике: в лог лило «Unknown LLM provider configured», а
  get_embedding() молча уходил на провайдера local. embedding_model
  переключается на embedding_server:: только при заданном EMBEDDING_API_URL.
  Проверяет .tools/llm_providers_stand.php.
- Формат логов llm_requests используют отчёты — не менять схему без миграции.
- `embedding_model` в конфиге — строка `провайдер::модель`; правится согласованно
  с секцией `embedding`.

## Долги и идеи (TODO)
- Модуль семантического поиска поверх pgvector (см. docs/ARCHITECTURE.md, п.7).
- Rate-limit/приоритеты между провайдерами.
