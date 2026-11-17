# PDF.js

Локальная копия `pdfjs-dist@6.5.438` для демо `prl398_vlm_demo`.

Используются только:

- `pdf.min.mjs` — API;
- `pdf.worker.min.mjs` — worker;
- `LICENSE` — лицензия Apache-2.0.

Источник: `npm pack pdfjs-dist@6.5.438`, каталог `package/build/`.
Обновлять вместе с проверкой `public_html/.htaccess` и
`docker/nginx/default.conf`: для `.mjs` нужен `Content-Type: text/javascript`,
иначе браузер не выполнит ES-модуль.
