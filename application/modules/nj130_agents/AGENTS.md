# nj130_agents — LLM-агенты — памятка для агентов

Платформенный рантайм агентов: определение (промпт/модель/параметры),
запуск через очередь (`agent.run`), диалоги, чтение статуса.

## Принцип stateless-исполнения

Задача `agent.run` несёт в payload всё необходимое (конфиг агента + история),
поэтому воркер не имеет доступа к таблицам агентов и может работать на любом
сервере и в любом режиме очереди (pg/http). Ответ воркера попадает в run при
чтении статуса (`Service_Nj130_Agents::sync_run`).

## Быстрый старт

```php
Service_Nj130_Agents::define('demo', [
    'system_prompt' => 'Ты кратко отвечаешь на вопросы о платформе N2J.',
]);

$run_id = Service_Nj130_Agents::start_run('demo', ['message' => 'Что такое nj120?']);
// ... воркер обработал ...
$run = Service_Nj130_Agents::sync_run($run_id);   // status=done, ответ в transcript
```

Локально без GPU: `make mock-llm` (workers/mock/) — фейковый OpenAI-совместимый
эндпоинт.

## Правила для агента

> Памятка агенту. Общие правила платформы — в корневом /AGENTS.md.

## Назначение
Платформенный рантайм LLM-агентов: определения агентов (промпт, модель,
параметры), запуски через очередь nj120, история диалогов. Исполнение —
stateless-воркер `agent.run` (python), результат применяется при чтении статуса.

## Состояние
- Готовность: рабочий (миграция, сервис, API, python-обработчик agent.run).
- Инструменты (tool-calling) в агентных запусках пока НЕ реализованы — v1 только диалог.

## Как пользоваться другим модулям
```php
// определить агента (или через админку/консоль):
Service_Nj130_Agents::define('support', [
    'name' => 'Поддержка',
    'system_prompt' => 'Ты вежливый помощник поддержки N2J...',
    'temperature' => 0.3,
]);

// запустить:
$run_id = Service_Nj130_Agents::start_run('support', ['message' => 'Не работает калькулятор'], $user_id);

// узнать статус/ответ:
$run = Service_Nj130_Agents::sync_run($run_id);
// $run['status'] pending|done|failed; диалог: transcript($run_id)
```

## Ключевые классы
| Класс | Роль |
|---|---|
| `Service_Nj130_Agents` | define/get/list, start_run/continue_run/sync_run, run_row/transcript |
| `Controller_Nj130_Api` | GET agents, POST run, GET status/<id>, POST continue/<id> (login) |
| `workers/.../handlers/agent_run.py` | stateless-исполнение: payload(агент+история) → reply |

## Таблицы
- `nj_130_agents` — определения (slug unique, system_prompt, model, temperature, max_tokens, config jsonb).
- `nj_130_agent_runs` — запуски (status pending|done|failed, input/result jsonb, job_id).
- `nj_130_agent_messages` — диалог запуска (role user|assistant|system|tool).

## Маршруты и API
- `GET /nj130/api/agents`
- `POST /nj130/api/run` {agent, input}
- `GET /nj130/api/status/<id>` — статус + transcript (применяет результат задачи)
- `POST /nj130/api/continue/<id>` {message}

## События
- Публикует: — (TODO: nj130.run.done через шину)
- Слушает: —

## Очередь
- Канал `agent.run` — stateless: в payload агент + сообщения, воркер не ходит
  в nj_130_*; работает в любом режиме (pg/http), на любом сервере.

## Не трогать / подводные камни
- Результат применяет `sync_run()` при GET статуса — НЕ делать отдельный
  открытый колбэк-эндпоинт (auth-проблемы), если не появится подпись HMAC.
- `continue_run` только после завершения (status != pending).
- temperature/max_tokens передаются в LLM; модель '' = дефолт провайдера.

## Долги и идеи (TODO)
- Tool-calling: инструменты через Llm::tools-механику в воркере.
- Событие nj130.run.done в шину (из sync_run при переходе в done).
- Лимиты на пользователя (квоты запусков/токенов).
- Стриминг ответов (SSE) — отдельным каналом.
