# Генератор favicon (nj663_favicon)

Один исходник — полный комплект иконок сайта. По образцу
[redketchup.io/favicon-generator](https://redketchup.io/favicon-generator),
но с серверной сборкой: PNG, ICO, манифест и zip считает сам сайт.

**Адрес:** [`/favicon-gen`](https://n2j.ru/favicon-gen)

## Что на входе

| Режим | Что делает |
|---|---|
| Из картинки | PNG / JPEG / GIF / WebP до 8 МБ; вписывание целиком, заполнением или растягиванием |
| Буква или эмодзи | до 4 символов (буква, цифра, эмодзи), цвет символа и размер (20–200 % стороны: 102–1024 px на полотне 512 — верх уходит за край, символ ложится во всю иконку) |
| Общее | фон подложки (или прозрачный), скругление 0–50 %, отступ 0–30 %, путь на сайте |

Цветные эмодзи рисует браузер на канвасе — GD умеет только монохромные
глифы. Поэтому предпросмотр и полотно собираются в браузере, а сервер
размножает готовый PNG по размерам.

## Что на выходе

- PNG всех размеров: `favicon-16x16.png` … `favicon-512x512.png`
  (16, 32, 48, 64, 96, 128, 180, 192, 256, 384, 512);
- `apple-touch-icon.png` (180), `android-chrome-192x192.png`,
  `android-chrome-512x512.png`;
- `favicon.ico` — настоящий многоразмерный (16, 32 и 48 в одном файле);
- `site.webmanifest` — для Android/Chrome;
- `favicon-pack.zip` — весь комплект + демо-страница;
- готовый HTML-код для `<head>` с указанным путём сайта.

Ссылка на набор (`/favicon-gen/p/<код>`) — capability: угадать чужой набор
нельзя, живёт 30 дней.

## Как устроено

```
браузер: канвас 512×512 (фон, отступ, скругление, буква или эмодзи)
   ↓  POST /favicon-gen/api/generate  {image_b64, composed: true, …}
сервер:  resample по каскаду (512 → 384 → 256 … → 16)
         PNG + ICO (BGRA, снизу вверх) + webmanifest + zip
   ↓  строка в nj_663_packs, файлы в public_html/storage/nj663/<код>
выдача:  /favicon-gen/f/<код>/<имя> и страница набора
```

Без JS форма постится как есть: файл уходит полем `image`, полотно собирает
сервер (`composed: false`).

## API

```bash
# что именно получится
curl https://n2j.ru/favicon-gen/api/sizes

# собрать комплект из data URL канваса
curl -X POST https://n2j.ru/favicon-gen/api/generate \
     -H 'Content-Type: application/json' \
     -d '{"csrf":"…","image_b64":"data:image/png;base64,…","composed":true,"base_path":"/"}'

# состав готового набора
curl https://n2j.ru/favicon-gen/api/pack/<код>
```

Ответ `generate`: `{ok, code, url, html, manifest, bytes, files:[{name,label,bytes,size,url}]}`.

## Ограничения

- 10 комплектов в минуту, 60 в час с одного IP (считает
  `Service_Nj100_Ratelimit` по журналу);
- файл исходника — до 8 МБ;
- набор живёт 30 дней, потом выметается вместе с файлами.

Подробности для агентов — в `AGENTS.md` этого каталога.
