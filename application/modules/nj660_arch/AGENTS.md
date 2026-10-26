# nj660_arch — конструктор архитектуры ИИ (slug: arch)

## Что это

Познавательно-практический модуль: пользователь собирает архитектуру нейросети
из современных блоков (GQA/MLA/слайдинг-внимание, MoE, SwiGLU, SSM/Mamba-блоки,
RoPE/ALiBi, мультимодальные входы patch/audio, хуки steering/LoRA/tap/early-exit
в середину модели), видит живой расчёт параметров/VRAM/FLOPs/бюджета обучения,
сравнение со зоопарком реальных моделей, получает экспертный разбор и может
«поживить» проект — поболтать с реальной LLM, играющей роль спроектированной
модели. Экспорт: PyTorch-скелет (.py) и JSON.

Маршруты (init.php):

- `arch` — витрина: пресеты, мои проекты, зоопарк;
- `arch/new`, `arch/edit/<code>`, `arch/save`, `arch/delete`, `arch/view/<code>` — CRUD;
- `arch/review` — LLM-разбор проекта (только вход, POST);
- `arch/export/<format>/<code>` — pytorch|json;
- `arch/api/evaluate` — публичный JSON-калькулятор (без токенов, локальный счёт);
- `arch/api/simulate` — чат-симуляция (только вход, лимит 20/час по Visitor::uid).

## Структура

```
classes/Service/Nj660/Arch.php    ядро: палитра/validate/flatten/evaluate/compare/
                                  warnings/pytorch/fmt_*/CRUD/log_run/runs_recent
classes/Service/Nj660/Expert.php  LLM-слой: review/simulate/_persona/_parse_json/
                                  _design_summary/_chat (Llm::chat, модель из
                                  config llm.default_model)
classes/Controller/Nj660/Arch.php страницы (extends Controller_Erp100_Base)
classes/Controller/Nj660/Api.php  JSON API: evaluate (публично), simulate (auth)
views/660/{index,edit,view}.php   вьюхи; edit эмбитит window.NJ660 = {palette, dsl, csrf}
public_html/assets/js/nj660-arch.js  редактор стека + живой пересчёт + чат-симулятор
config/nj_module.php             манифест (slug arch, audience — по решению
                                  06.10.2026: страницы публичны, запись — вход)
config/nj660_arch.php             лимиты, LLM, бюджет H100, зоопарк 11 моделей,
                                  9 пресетов (gpt2/llama8b/mixtral/vit/tiny/moe-small/hybrid/diffusion/whisper)
migrations_pg/pgMigration_Nj_660_001  nj_660_designs, nj_660_runs
```

## DSL

`{family: decoder|encoder|diffusion, config: {d, S}, stack: [{t, ..., n}, ...]}`.
Блоки: `emb{V,tied}`, `patch{P,C}`, `audio{mel}`, `pos{v}`, `time`,
`attn{v: mha|gqa|mqa|mla|sliding|linear, H, KV, W}`, `xattn{H, KV}`,
`ffn{v: swiglu|gelu|glu, h}`, `moe{E, k, h, shared}`, `ssm{state, expand}`,
`norm{v: rms|layer}`, `hook{kind: steer|lora|tap|exit, at, s, r, text, note}`,
`head{v: lm|diffusion}`. `n` — повтор блока (для слоёв). Формулы evaluate()
сверены с эталонами: GPT-2 124M, Llama-3-8B ~8B, Mixtral 46.7B/12.9B активных,
ViT-L ~304M — регресс закрыт стендом.

## Правила модуля

- **Доступ**: чтение/конструктор/калькулятор — публично (локальный счёт, LLM не
  зовётся); создание проектов, review и simulate — только вход (лимиты по
  Visitor::uid: review 6/час, simulate 20/час). Владение как в nj645:
  `user_id` ИЛИ совпадение `visitor` при пустом user_id.
- **LLM** — только фасад `Llm` (классы/manual.md); движок из
  `llm.default_model`; ошибки движка → 502/редирект с флеш-сообщением, лог
  `Log::ERROR 'nj660: llm'`.
- **JS** — только `/assets/js/nj660-arch.js` (свой, без внешних хостов);
  диаграмм нет, всё — текст/таблицы/инлайн-стили платформы.
- **Тайминги/человекочитаемость** — помнить: (а) хук `at` адресует слой в
  развёрнутом стеке (нормы считаются слоями); (б) `nn.RMSNorm` в экспорте
  запрещён — только собственный класс `RMSNorm` (переносимость).

## Тесты

`.tools/nj660_arch_stand.php` — 54 проверки без БД и LLM (эталонные параметры,
validate/flatten/warnings/compare/pytorch/Expert-парсинг):
`N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs .tools/nj660_arch_stand.php`

`.tools/nj660_views_stand.php` — 20 проверок: дымовой рендер всех трёх вьюх
со стаб-данными (ловит неопределённые переменные/индексы и PHP-варнинги,
которые синтаксический линт не видит; данные mimic контроллер):
`N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs .tools/nj660_views_stand.php`

Роуты проверены матчителем Kohana (13 проверок: CRUD/export/api + чужой action
не матчится) — окружение в /tmp/phpwasm/route_check_660.mjs (неперсистентное).
(стенд-окружение в /tmp/phpwasm, см. корневой AGENTS.md).

## Состояние работ

Готово и закрыто стендом (54 проверки): калькулятор с эталонной точностью
(GPT-2/Llama/Mixtral/ViT), validate/flatten/warnings/compare, pytorch-экспорт,
Expert-персона и JSON-парсинг, вьюхи, JS-редактор и чат-симулятор, регистрация
в bootstrap. Не проверялось вживую: реальный вызов LLM (review/simulate) и
прогоны страниц под браузером — при первом запуске убедиться, что миграция
`pgMigration_Nj_660_001` применена и локальная LLM доступна.

## Расширение

Новый блок = палитра в `config/nj660_arch.php` + ветка в `Arch::_block_params()`
(формула) + валидация в `validate()` + строка в `pytorch()` + поле в
`nj660-arch.js` (renderStack). Новая модель в зоопарке — элемент `zoo` в конфиге
(поля: title, params, d, S, tags, note) — compare() подхватит сам.
