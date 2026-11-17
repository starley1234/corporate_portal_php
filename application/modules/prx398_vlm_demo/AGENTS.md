# prx398_vlm_demo — демо VLM-разбора входящего PDF

## Состояние

MVP готов: страница `/vlm-demo`, PDF.js в браузере, AJAX-эндпоинт, вызов `Llm`
через vision-сообщения и карточка результата. БД, миграций и worker-каналов
нет намеренно. Проверено статическими линтерами; живой вызов модели зависит
от загруженной VLM в LLM-шлюзе.

## Назначение

Витрина возможностей vision-модели на входящей корреспонденции. Показывает,
что VLM читает бланк, таблицы, печати и подписи по изображению страницы, а не
только текстовый слой после OCR.

Это **не production-сервис**: результата нет в БД, карточки не меняются,
принятые страницы лежат только в закрытом временном каталоге 0700 и
удаляются в `finally`.

## Как работает

1. Браузер принимает PDF/PNG/JPEG.
2. PDF.js рендерит 1–3 страницы в canvas и отправляет JPEG через AJAX.
3. `POST /vlm-demo/analyze` проверяет ACL/CSRF/лимиты и вызывает
   `Service_Prx398_Vlm::analyze()`.
4. Сервис отправляет изображения в `Llm::model(...)->chat()` как
   `image_url` data URI и нормализует JSON.
5. Результат возвращается в браузер; временные копии страниц удаляются в
   `finally`, в БД и `public_html` ничего не пишется.

Очередь `prx120`, воркеры, `pypdf`, `pdftoppm` и `tesseract` не используются.
Это осознанное исключение для демо; production-путь должен идти через очередь.

## Файлы

- `config/prx398_vlm.php` — модель, allowlist провайдеров, лимиты, промпты.
- `classes/Controller/Prx398/Vlm.php` — страница, AJAX, загрузка.
- `classes/Service/Prx398/Vlm.php` — вызов LLM, парсинг и нормализация JSON.
- `views/398/index.php` — интерфейс.
- `public_html/assets/js/prx398_vlm_demo.js` — PDF.js + AJAX (вне модуля, как
  принято для общих `/assets/js`).
- `public_html/assets/vendor/pdfjs/` — локальный PDF.js, без CDN.

## Права

`prx398.view` — `login`; `prx398.run` — `docflow_clerk`, `docflow_lawyer`,
`docflow_manager`. Admin имеет полный доступ через общий контракт ACL.

## Настройка

```dotenv
TOOLFORGE_API_URL=http://127.0.0.1:1234/v1
TOOLFORGE_API_KEY=
PRX398_VLM_MODEL=local::qwen_qwen3-vl-4b-instruct
# Только если действительно нужно отправить страницы внешнему провайдеру:
# PRX398_ALLOWED_PROVIDERS=local,openrouter
```

Модель должна поддерживать изображения и быть загружена в шлюзе. Проверьте
`/v1/models` и один тестовый запрос. `PRX398_VLM_MODEL` важнее
`llm.vision_model`: иначе можно попасть на текстовую модель.

Лимиты PHP: `upload_max_filesize` и `post_max_size` должны пропускать до трёх
JPEG по 2 МБ; `max_execution_time`/`fastcgi_read_timeout` — не меньше 180 с.

## Ограничения

- Синхронный PHP-запрос занимает PHP-FPM worker на время ответа модели.
- `Llm` пишет запрос в `llm_requests`; для реальных писем это надо учитывать
  (в демо используйте обезличенные документы).
- VLM не гарантирует точность: интерфейс всегда показывает предупреждение и
  цитаты/страницы для ручной проверки.
- PDF.js — локальная копия `pdfjs-dist`; при обновлении проверьте CSP
  (`worker-src 'self' blob:`) и лицензию в `public_html/assets/vendor/pdfjs/`.
- `.mjs` должен отдаваться как `text/javascript`; в репозитории это настроено
  для nginx и Apache, на другом сервере проверьте заголовок.

## Проверки

`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`,
`npm run build:css` (если добавлены Tailwind-классы), `make architecture`.
