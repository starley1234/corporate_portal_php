# AGENTS.md — памятка по модулю nj601_billing

## Состояние

Счета Robokassa (v1): create → redirect → result-подтверждение → paid.
Воркеров/каналов нет. Монетизация модулей — через событие `nj601.invoice_paid`.

## Ключевые классы

* `Service_Nj601_Billing` — ensure_tables, create_invoice, pay_url, process_result,
  verify_success, invoice, latest, stats.
* `Controller_Nj601_Billing` (/billing/*), `Controller_Nj601_Admin` (/nj601/admin).

## Правила

* Подписи: init `Login:OutSum:InvId:Pass1`; result `OutSum:InvId:Pass2`;
  success `OutSum:InvId:Pass1`; strtoupper-сравнение.
* Обязательна сверка суммы с счётом (process_result) — иначе bad sign.
* Ответ Result URL — строго `OK$InvId\n`, plain text (auto_render FALSE).
* Боевой режим: NJ601_ROBOKASSA_IS_TEST=0 ТОЛЬКО после проверки на тестовом.
* Статус `paid` идемпотентен (повторные колбэки не задваивают события).

## Долг / идеи

- Тарифы на прогоны: пакет задач, а не подписка «в никуда» (docs/PLAN-2026-10-01.md §6)
- Пороги и лимиты: «бесплатно до N в сутки» → мягкое предупреждение → 429 с понятным текстом
* Shp_-параметры (привязка service без БД-поиска) — при первом реальном тарифе.
* Receipt (54-ФЗ) — при подключении онлайн-кассы.
* Повторные попытки доставки ResultURL Robokassa делает сам (несколько часов).
