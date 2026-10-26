# NJ-633 · Сетевые инструменты (nj633_nettools)

## Состояние
- **Набор сервисов**: DNS-записи (A/AAAA/MX/TXT/NS/CNAME/SOA), WHOIS домена (с переходом по `refer:`), проверка доступности TCP (connect к порту), PTR-запись, конвертер IP↔integer (+hex), конвертер Base64.
- **Доп. LLM-фича**: «Объяснить результат» — короткое пояснение через очередь nj120 (канал `llm.summarize`); результат пишется в `nj_633_notes`.
- **Сохранение в БД**: каждая проверка → `nj_633_checks` (result JSONB, client_ip для rate limit); каждое LLM-объяснение → `nj_633_notes`. Конвертеры пишутся в историю (факт+результат IP-конвертера, факт+длина для Base64 — сам текст не сохраняется).

## Безопасность (обязательный слой)
1. **SSRF**: `Service_Nj633_Nettools::assert_public_target()` + `is_blocked_ip()` закрывают
   localhost, 127.0.0.0/8, 10/8, 172.16/12, 192.168/16, 169.254.0.0/16 (метаданные облаков),
   ::1, ::, ULA fc00::/7, link-local fe80::/10, multicast, 0/8, 100.64/10 CGNAT, документационные зоны.
   Проверка двухслойная: по исходной строке И по фактическому резолву DNS (защита от rebinding).
2. **Без shell**: только `stream_socket_client()` (WHOIS TCP 43, TCP-connect) и `Llm` фасад.
   Пользовательский ввод никогда не попадает в `exec()`/`shell_exec()`/`system()` — таких вызовов нет.
3. **Rate limit**: `rate_ok(ip)` — не более `NJ633_RATE_PER_MINUTE` (5) в минуту и
   `NJ633_RATE_PER_HOUR` (60) в час с одного IP; счётчик — по `nj_633_checks.client_ip` в БД,
   считает ТОЛЬКО сетевые kind (`dns`/`whois`/`tcp`) — конвертеры (ipconv/base64) лимит не расходуют.
   Слой общий: `Service_Nj100_Ratelimit::window_counts/allowed/limit_text`
   (nj100_platform); пороги и filter — наши.
4. Таймауты на всех сокетах (whois 8с, tcp 6с), лимит чтения WHOIS 30 КБ.

## Классы и файлы
- `Service_Nj633_Nettools` — вся логика + безопасность + БД.
- `Controller_Nj633_Net` — страница `/net` (публичная).
- `Controller_Nj633_Api` — JSON API `/net/api/{dns,whois,check,ipconv,base64,llm,llm_note,llm_cb}`.
- `views/633/index.php`, `views/633/script.php` — UI (JS — зеркало интерфейса).
- `migrations_pg/pgMigration_Nj_633_001_Nettools_Tables.php` — таблицы.
- `migrations_pg/pgMigration_Nj_633_002_Kind_Width.php` — kind VARCHAR(16)→VARCHAR(24) под конвертеры.
- `config/nj633_nettools.php`, `config/nj_module.php` — настройки и манифест каталога.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_633_checks` | история проверок (kind: dns/whois/tcp/ipconv/base64; result JSONB; client_ip для rate limit) |
| `nj_633_notes` | LLM-объяснения (status: queued/done/error; job_id → задача nj120) |

## API
```
GET  /net/api/dns?host=example.com&type=MX
GET  /net/api/whois?host=example.com
GET  /net/api/check?host=example.com&port=443
POST /net/api/llm       {"target": "...", "prompt": "...", "check_id": 0}
GET  /net/api/llm_note?note_id=1        → статус объяснения
POST /net/api/llm_cb                    → колбэк nj120 (не для людей)
```
Ответы: `{"ok": true/false, ...}`. Rate limit → HTTP 429. Невалидный/внутренний адрес → 400.

## env (в хвост .env.example)
```
NJ633_RATE_PER_MINUTE=5
NJ633_RATE_PER_HOUR=60
NJ633_WHOIS_SERVER=whois.iana.org
NJ633_WHOIS_TIMEOUT=8
NJ633_TCP_TIMEOUT=6
NJ633_LLM_MODEL=
```

## Ограничения
- WHOIS/PTR/DNS полагаются на `dns_get_record`/сокеты PHP (cli собран с этим).
- LLM-объяснение — опция; без воркера/LLM-шлюза задача уходит в `error` и UI это показывает.
