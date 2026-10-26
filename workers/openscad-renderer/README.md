# openscad-renderer

Сателлит-воркер рендера OpenSCAD (STL/PNG для nj570_models). HTTP-обвязка
дублирована напрямую в `workers/n2j_workers/scad.py`, который и используется
очередью nj120.

## Бинарник OpenSCAD в Git НЕ хранится

84 МБ не место в репозитории — убран 22.09.2026 (история: был в
`workers/openscad-renderer/bin/openscad`). Код ищет его так
(`scad.py:openscad_bin()`):

1. `NJ570_OPENSCAD_BIN` — явный путь из окружения;
2. `workers/openscad-renderer/bin/openscad` — сюда можно положить вручную;
3. `which openscad` — системный.

**Где взять, когда понадобится:**

- **Ubuntu/прод:** `sudo apt install openscad` (18.04+ пакует 2021.01;
  для свежих версий — PPA `ppa:openscad/releases` или AppImage);
- **AppImage (без root):** скачать с https://openscad.org/downloads.html
  (или GitHub releases openscad/openscad), `chmod +x`, положить сюда как
  `bin/openscad` — AppImage самодостаточен;
- **Библиотека BOSL2** (нужна для моделей nj570): `git clone
  https://github.com/BelfrySCAD/BOSL2.git workers/openscad-renderer/libraries/BOSL2`
  (каталог `libraries/` тоже вне Git).

Без бинарника модуль nj570 работает: модели/SCAD-коды сохраняются,
рендер-задачи честно падают с понятной ошибкой «openscad не найден».
