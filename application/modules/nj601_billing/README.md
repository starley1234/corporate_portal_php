# nj601_billing — приём платежей Robokassa

Фундамент монетизации модулей: счета, оплата, серверное подтверждение.
Протокол: docs.robokassa.ru (MD5-подписи, v1 без Receipt/Shp/ResultUrl2).

## Поток

1. `POST /billing/pay` (amount, description, service) → счёт `nj_601_invoices` → 302 в Robokassa.
2. Robokassa → **Result URL** `GET/POST /billing/result` (задать в кабинете!):
   проверка `md5("OutSum:InvId:PASSWORD2")`, сумма обязана совпасть, статус → `paid`,
   публикация `nj601.invoice_paid` в шину платформы, ответ `OK$InvId`.
3. Возвраты: `/billing/success` (проверка `md5("OutSum:InvId:PASSWORD1")`),
   `/billing/fail`.

## Env (полным слагом, .env.example)

NJ601_ROBOKASSA_LOGIN · NJ601_ROBOKASSA_PASSWORD1 · NJ601_ROBOKASSA_PASSWORD2 ·
NJ601_ROBOKASSA_IS_TEST (1 = тест.режим test.robokassa.ru; по умолчанию 1).

## Настройка кабинета Robokassa

Result URL: `https://n2j.ru/billing/result` (метод GET или POST), алгоритм — MD5.

## Админка

`/nj601/admin` (право `nj601.admin`): сводка, тестовый счёт, список счетов.

## Для монетизации модулей (позже)

Модуль-потребитель вызывает `Service_Nj601_Billing::create_invoice($kop, $desc, 'слаг.пакета')`,
на `nj601.invoice_paid` слушает шину и активирует купленное.
