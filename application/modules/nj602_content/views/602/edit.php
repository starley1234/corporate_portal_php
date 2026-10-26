<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj602/admin/edit/<id> — ручная правка статьи: заголовок, лид, тело и
 * mermaid-схема. Владелец: «возможность ручного редактирования статьи и
 * mermaid-схемы». Рядом — модерация (публикация, перегенерация схемы).
 *
 * @var array  $article статья (Service_Nj602_Content::article_payload_by_id)
 * @var string $flash   сообщение после сохранения
 * @var string $csrf    CSRF-токен
 * @var array  $live    состояние перегенерации схемы (может быть NULL)
 */
$r = (array) $article;

$st_map = [
    'published'  => ['опубликовано', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'],
    'review'     => ['на модерации', 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'],
    'collecting' => ['собирается', 'bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300'],
    'rejected'   => ['отклонено', 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'],
    'error'      => ['ошибка', 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300'],
];
[$st_text, $st_cls] = $st_map[(string) $r['status']] ?? [(string) $r['status'], 'bg-gray-100 text-gray-500 dark:bg-gray-800'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/content" class="transition hover:text-blue-600 dark:hover:text-blue-400">Контент-конвейер</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <a href="/nj602/admin" class="transition hover:text-blue-600 dark:hover:text-blue-400">Модерация</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Правка статьи</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5">
        <h1 class="n2j-h1">
            <i class="fas fa-pen-ruler mr-2 text-blue-500"></i>Правка статьи
        </h1>
        <span class="font-mono text-sm font-bold text-blue-600 dark:text-blue-400"><?php echo HTML::chars((string) $r['code']); ?></span>
        <span class="inline-flex items-center rounded-md px-2.5 py-1 text-sm font-semibold <?php echo $st_cls; ?>"><?php echo HTML::chars($st_text); ?></span>
        <?php if (in_array((string) $r['status'], ['published', 'review'], TRUE)): ?>
        <a href="/content/<?php echo HTML::chars((string) $r['slug']); ?>" target="_blank"
           class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-arrow-up-right-from-square text-[11px]"></i>Открыть на сайте
        </a>
        <?php endif; ?>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">
        Правки сохраняются поверх текста воркера — следующая пересборка темы снова заменит статью.
        Схему можно обновить кнопкой «Перегенерировать схему»: ИИ перерисует её по текущему тексту.
    </p>

    <?php if ((string) $flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mt-4 font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <form method="post" action="/nj602/admin" class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
        <input type="hidden" name="back" value="edit">

        <div class="space-y-5 lg:col-span-2">
            <div class="n2j-card">
                <h2 class="n2j-h3">Текст статьи</h2>

                <label for="nj602-edit-title" class="n2j-label mt-3">Заголовок</label>
                <input id="nj602-edit-title" name="title" type="text" required maxlength="300" value="<?php echo HTML::chars((string) $r['title']); ?>"
                       class="n2j-input mt-1.5 w-full">

                <label for="nj602-edit-summary" class="n2j-label mt-4">Лид <span class="font-normal text-gray-400">— 1–3 предложения, виден в ленте и поиске</span></label>
                <textarea id="nj602-edit-summary" name="summary" rows="2" maxlength="600"
                          class="n2j-textarea mt-1.5 w-full"><?php echo HTML::chars((string) $r['summary']); ?></textarea>

                <label for="nj602-edit-body" class="n2j-label mt-4">Тело статьи <span class="font-normal text-gray-400">— Markdown, разделы начинаются с «## »</span></label>
                <textarea id="nj602-edit-body" name="body_md" rows="22" required
                          class="n2j-textarea mt-1.5 w-full font-mono leading-relaxed"><?php echo HTML::chars((string) $r['body_md']); ?></textarea>

                <p class="mt-2 text-sm text-gray-400">Знаков: <?php echo (int) mb_strlen((string) $r['body_md']); ?>. Сохранение не меняет статус публикации — статья остаётся там, где была.</p>
            </div>

            <div class="n2j-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="n2j-h3"><i class="fas fa-diagram-project mr-1.5 text-blue-500"></i>Mermaid-схема</h2>
                    <span class="text-sm text-gray-400">каждая связь — отдельной строкой; «&amp;»-склейки не рисуются</span>
                </div>

                <textarea id="nj602-edit-mermaid" name="mermaid" rows="9"
                          class="n2j-textarea mt-3 w-full font-mono leading-relaxed"
                          placeholder='flowchart TD&#10;    A["Сбор данных"] --> B["Анализ"]'><?php echo HTML::chars((string) $r['mermaid']); ?></textarea>

                <p class="mt-2 text-sm text-gray-400">Пустая строка — статья без схемы. Проверить вид можно по «Обновить превью».</p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" id="nj602-preview-mermaid" class="n2j-btn-secondary">
                        <i class="fas fa-eye text-[11px]"></i>Обновить превью
                    </button>
                    <span id="nj602-mermaid-state" class="text-sm text-gray-400"></span>
                </div>

                <div id="nj602-mermaid-box" class="mt-3 overflow-x-auto rounded-lg border border-gray-100 bg-gray-50/60 p-3 text-sm dark:border-gray-700/60 dark:bg-gray-900/40"></div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" name="act" value="save_manual" class="n2j-btn-cta">
                    <i class="fas fa-floppy-disk text-[11px]"></i>Сохранить
                </button>
                <a href="/nj602/admin" class="n2j-btn-secondary">К списку статей</a>
            </div>
        </div>

        <aside class="space-y-4">
            <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-4 shadow-xs dark:border-amber-800/60 dark:bg-amber-950/30 sm:p-5">
                <div class="flex items-center gap-2 text-amber-900 dark:text-amber-100">
                    <i class="fas fa-user-shield text-amber-600 dark:text-amber-400"></i>
                    <h3 class="text-sm font-bold">Модерация</h3>
                </div>

                <div class="mt-3 space-y-2">
                    <button type="button" data-nj602-regen
                            class="n2j-btn-secondary n2j-btn-sm w-full">
                        <i class="fas fa-diagram-project text-[11px]"></i><span data-regen-text>Перегенерировать схему</span>
                    </button>

                    <?php if ((string) $r['status'] === 'review'): ?>
                    <button type="submit" name="act" value="publish" class="n2j-btn-cta n2j-btn-sm w-full">Опубликовать</button>
                    <?php endif; ?>

                    <?php if ((string) $r['status'] === 'published'): ?>
                    <button type="submit" name="act" value="reject" class="n2j-btn-secondary n2j-btn-sm w-full">Снять с публикации</button>
                    <?php endif; ?>

                    <?php if (in_array((string) $r['status'], ['error', 'rejected'], TRUE)): ?>
                    <button type="submit" name="act" value="retry" class="n2j-btn-secondary n2j-btn-sm w-full">
                        <i class="fas fa-rotate-right text-[11px]"></i>Перезапустить сбор
                    </button>
                    <?php endif; ?>

                    <button type="submit" name="act" value="delete" id="nj602-edit-delete" data-n2j-arm="Удалить статью?"
                            class="n2j-btn-danger n2j-btn-sm w-full">
                        <span data-n2j-arm-label>Удалить</span>
                    </button>
                </div>

                <p class="mt-3 border-t border-amber-200/70 pt-3 text-sm text-amber-900/80 dark:border-amber-800/50 dark:text-amber-200/80">
                    Схема перегенерируется отдельной задачей воркера: текст, статус и дата публикации не меняются.
                </p>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-blue-500"></i>Служебное</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Тема задания</dt>
                        <dd class="max-w-[60%] text-right font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $r['topic']); ?></dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Слаг</dt>
                        <dd class="max-w-[60%] truncate text-right font-mono text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $r['slug']); ?></dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Обновлено</dt>
                        <dd class="text-right text-gray-800 dark:text-gray-200"><?php echo HTML::chars(Date::formatted_time((string) $r['updated_at'], 'd.m.y H:i')); ?></dd>
                    </div>
                </dl>

                <?php if ( ! empty($live['found'])): ?>
                <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                    Перегенерация схемы: <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) ($live['status'] ?? '')); ?></span>
                    <?php if ( ! empty($live['stage'])): ?> · <?php echo HTML::chars((string) $live['stage']); ?><?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
        </aside>
    </form>
</div>

<script>
/* Правка схемы: «Обновить превью» рисует ровно тот текст, что сейчас в поле —
   без сохранения. Кнопка «Перегенерировать схему» — общий механизм nj602. */
(function () {
    'use strict';

    var ta = document.getElementById('nj602-edit-mermaid'),
        box = document.getElementById('nj602-mermaid-box'),
        src = document.getElementById('nj602-mermaid-src'),
        state = document.getElementById('nj602-mermaid-state'),
        btn = document.getElementById('nj602-preview-mermaid');

    if (src && box) {
        src.textContent = (ta && ta.value.trim()) || '';
    }

    function renderMermaid() {
        if (!ta || !box) return;

        var code = ta.value.trim();

        if (!code) {
            box.innerHTML = '<p class="text-gray-400">Схемы нет — статья будет без диаграммы.</p>';
            if (state) state.textContent = '';
            return;
        }

        var done = function () {
            try {
                mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'strict' });
                mermaid.render('nj602-edit-mmd-' + Date.now(), code).then(function (res) {
                    box.innerHTML = res.svg;
                    if (state) state.textContent = 'схема рисуется';
                }).catch(function () {
                    box.innerHTML = '';
                    var pre = document.createElement('pre');
                    pre.className = 'overflow-x-auto font-mono text-sm text-rose-600 dark:text-rose-400';
                    pre.textContent = 'Схема не рисуется — проверьте синтаксис:\n' + code;
                    box.appendChild(pre);
                    if (state) state.textContent = 'ошибка синтаксиса';
                });
            } catch (e) {
                if (state) state.textContent = 'mermaid не загрузился';
            }
        };

        if (window.mermaid) {
            done();
        } else {
            var s = document.createElement('script');
            s.src = '/assets/vendor/mermaid/mermaid.min.js';
            s.onload = done;
            s.onerror = function () { if (state) state.textContent = 'mermaid не загрузился'; };
            document.head.appendChild(s);
        }
    }

    if (btn) btn.addEventListener('click', renderMermaid);
    renderMermaid();

    /* Удаление — вторым нажатием: data-n2j-arm на кнопке (app.js), своего кода нет. */

    var regen = document.querySelector('[data-nj602-regen]');

    if (regen) {
        regen.addEventListener('click', function () {
            window.nj602RegenMermaid(regen, {
                csrf: <?php echo json_encode($csrf); ?>,
                id: <?php echo (int) $r['id']; ?>,
                code: <?php echo json_encode((string) $r['code']); ?>,
                label: 'Перегенерировать схему',
                toast: function (text, ok) {
                    if (state) state.textContent = text;
                    if (!ok) return;
                }
            });
        });
    }
})();
</script>

<?php echo View::factory('602/regen_js'); ?>
<?php echo View::factory('602/script'); ?>
