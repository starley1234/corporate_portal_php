# NJ-612 · Серии касаний (nj612_touch)

## Состояние
- **Сервис** «Серии касаний» (`/touches`): первое письмо + вежливые follow-up по
  компании. Пользователь создаёт серию (компания, контакт, оффер, тон, число
  шагов) → воркер пишет черновики каждого шага → отправка вручную по кнопке
  или авто (env) → пауза ~4 дня → следующий шаг.
- Ответ контакта останавливает серию: `tick()` ищет в письмах nj610 (`nj_610_messages`)
  ответы от `contact_email` после последней отправки → статус `answered`.
- **08.10.2026 — гостевой экран переведён на общую плашку входа** (решение владельца): вместо
  строки «Сервис для зарегистрированных — войдите» гость видит `Gate::wall('touches')` — общий
  partial `application/views/partials/auth_wall.php` (`application/classes/Gate.php`): что даёт
  сервис, зачем аккаунт и ОДНА кнопка входа с `return_url=touches`. Состав полей и поведение для
  вошедших не менялись.

## Механика (не в HTTP)
- Черновики — очередь nj120 канала **`nj612.touch`** (LLM), результат →
  `POST /touches/api/step`.
- Пиналка nj603 → `POST /touches/api/tick`: (1) детект ответов по nj610,
  (2) автоотправка созревших, если `NJ612_TOUCH_AUTOSEND=1` (по умолчанию выкл).
- Отправка писем — `Service_Nj597_Mail::send_direct` (SMTP сайта), не свой SMTP.
- Пауза между шагами `const GAP_DAYS = 4`; максимум 4 шага (`MAX_STEPS`) и
  30 серий на пользователя (`LIMIT_PER_USER`).

## Классы и файлы
- `Service_Nj612_Touch` — CRUD серий, черновики, отправка, tick/autosend, admin.
- `Controller_Nj612_Touch` — `/touches` и `/touches/<code>` (таймлайн).
- `Controller_Nj612_Api`   — `step` (приём черновика), `tick` (пиналка).
- `Controller_Nj612_Admin` — `/nj612/admin`.
- `views/612/{index,view,card,admin}.php`.
- `migrations_pg/pgMigration_Nj_612_001_Sequences.php`.
- `config/nj_module.php` — манифест (auth: TRUE).

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_612_sequences` | серии (code UNIQUE, user_id, company, contact_email, contact_name, offer, tone, steps_total, status active/answered/done/cancelled, next_run_at, answered_at) |
| `nj_612_steps`    | шаги/письма (sequence_id, seq, subject, body, status drafting/ready/sent, sent_at, error) |

## API
```
POST /touches/api/step   приём черновика от воркера (X-Worker-Token) → {ok}
POST /touches/api/tick   пиналка nj603: детект ответов + автосенд     → {sent, answered}
```

## Безопасность и конвенции
- Чтение чужого — только `by_code($user_id, $code)` (проверка владельца).
- Почта — единый транспорт `Service_Nj597_Mail` (не дублируется).
- Автоотправка выключена по умолчанию (явное включение env-флагом).

## env (в хвост .env.example)
```
NJ612_TOUCH_API_BASE=http://127.0.0.1/touches/api
NJ612_TOUCH_SITE_TOKEN=CHANGE_ME     # токен в окружении воркера
NJ612_TOUCH_MODEL=
NJ612_TOUCH_AUTOSEND=0               # 1 = автоотправка созревших шагов
```

## Ограничения
- Без воркера `nj612.touch` черновики не появляются (шаги застревают в `drafting`).
- Ответ контакта детектится только если письма разобраны nj610 (общий IMAP-пайплайн).
