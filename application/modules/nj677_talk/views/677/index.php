<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /talk — разговор с ИИ голосом.
 *
 * Правило страницы: одно главное действие и никаких кнопок на каждый вдох.
 * «Начать разговор» включает микрофон, дальше запись и остановка идут сами:
 * конец фразы определяет тишина (см. views/677/script.php). Текстовая форма —
 * не второй сценарий, а выход для тех, у кого нет микрофона.
 *
 * Переменные: enabled, missing, voices, voice, rate_text, recent, limits,
 *             csrf, talk (разговор или NULL), messages (его реплики).
 */
$talk = isset($talk) && is_array($talk) ? $talk : NULL;
$messages = isset($messages) && is_array($messages) ? $messages : [];
$recent = isset($recent) && is_array($recent) ? $recent : [];
$voices = isset($voices) && is_array($voices) ? $voices : [];
$limits = isset($limits) && is_array($limits) ? $limits : [];

$talk_code = $talk ? (string) $talk['code'] : '';
$voice_now = isset($voice) ? (string) $voice : '';
$cfg = [
    'csrf'          => (string) $csrf,
    'talk'          => $talk_code,
    'voice'         => $voice_now,
    'url_hear'      => '/talk/api/hear',
    'url_reply'     => '/talk/api/reply',
    'url_say'       => '/talk/api/say',
    // Конец фразы — тишина 0,8 с, но не раньше 300 мс голоса: иначе кашель
    // и щелчок уходят на распознавание как реплика.
    'silence_ms'    => 800,
    'min_voiced_ms' => 300,
    // Перебивание: голос должен длиться ~0,4 с и быть заметно громче эха.
    'barge_ticks'   => 7,
    'max_ms'        => max(5000, min(60000, (int) Arr::get($limits, 'audio_sec', 60) * 1000)),
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Сервисы', '/services'], ['Разговор голосом']],
        'title'  => 'Разговор с ИИ голосом',
        'lead'   => 'Включите разговор и говорите: конец фразы определяется сам, ответ читается вслух, перебить можно голосом. Кнопок на каждую реплику нет.',
    ]); ?>

<?php if (empty($enabled)): ?>
    <section class="mt-6 n2j-card" aria-labelledby="talk-off-title">
        <h2 id="talk-off-title" class="n2j-h2">Разговор пока не включён</h2>
        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
            <?php echo HTML::chars(isset($missing) ? (string) $missing : 'Сервис не настроен.'); ?>
        </p>
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
            Синтез и распознавание идут через OmniVoice — тот же сервис, что озвучивает текст на
            <a class="n2j-link" href="/voice">/voice</a>. Как только администратор задаст
            <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">NJ665_API_URL</code> и
            <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">NJ665_API_KEY</code>, разговор заработает.
        </p>
    </section>
<?php else: ?>

    <?php /* Паттерн A: главный блок + колонка пояснений (docs/DESIGN-SYSTEM.md). */ ?>
    <div class="mt-6 space-y-4 xl:flex xl:items-start xl:gap-10 xl:space-y-0">
        <section id="talk" class="n2j-card min-w-0 flex-1" aria-labelledby="talk-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="talk-title" class="n2j-h2">Разговор</h2>
                    <p id="talk-status" class="n2j-hint mt-1 flex items-center gap-2" role="status" aria-live="polite">
                        <i id="talk-spin" class="fas fa-circle-notch n2j-spin hidden" aria-hidden="true"></i>
                        <span id="talk-status-text">Микрофон выключен — нажмите «Начать разговор».</span>
                        <span id="talk-elapsed" class="n2j-clock hidden" aria-hidden="true"></span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" id="talk-start" class="n2j-btn-cta">
                        <i class="fas fa-microphone" aria-hidden="true"></i>Начать разговор
                    </button>
                    <button type="button" id="talk-stop" class="n2j-btn-danger-soft hidden">
                        <i class="fas fa-stop" aria-hidden="true"></i>Закончить
                    </button>
                    <button type="button" id="talk-new" class="n2j-btn-secondary" title="Начать разговор с чистого листа">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i>Новая тема
                    </button>
                </div>
            </div>

            <ol id="talk-log" class="mt-4 max-h-96 space-y-3 overflow-y-auto pr-1" aria-live="polite" aria-relevant="additions">
                <?php if ( ! $messages): ?>
                <li id="talk-empty" class="text-sm text-gray-500 dark:text-gray-400">
                    Здесь появится разговор. Скажите первое слово — или напишите его ниже, если микрофона нет.
                </li>
                <?php else: ?>
                    <?php foreach ($messages as $message): ?>
                    <?php $is_user = ((string) $message['role'] === 'user'); ?>
                    <li class="flex <?php echo $is_user ? 'justify-end' : 'justify-start'; ?>">
                        <div class="max-w-[85%] rounded-2xl px-3.5 py-2 text-sm leading-relaxed shadow-sm <?php echo $is_user
                            ? 'bg-blue-600 text-white'
                            : 'border border-gray-200 bg-white text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100'; ?>">
                            <p class="whitespace-pre-line"><?php echo HTML::chars((string) $message['text']); ?></p>
                            <?php if ((int) $message['ms'] > 0): ?>
                            <p class="mt-1 text-xs <?php echo $is_user ? 'opacity-80' : 'text-gray-500 dark:text-gray-400'; ?>"><?php echo $is_user ? 'распознано' : 'ответ'; ?> · <?php echo number_format((int) $message['ms'] / 1000, 1, ',', ' '); ?> с</p>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ol>

            <form id="talk-text-form" class="mt-4 flex flex-wrap gap-2" autocomplete="off">
                <label class="sr-only" for="talk-text">Написать сообщение вместо голоса</label>
                <input id="talk-text" name="text" type="text" maxlength="2000" class="n2j-input min-w-0 flex-1"
                       placeholder="Или напишите сообщение — ответ тоже прочитают вслух">
                <button type="submit" class="n2j-btn-process">Отправить</button>
            </form>

            <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-gray-100 pt-3 dark:border-gray-700/60">
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <span>Голос</span>
                    <select id="talk-voice" name="voice" class="n2j-select w-auto py-1 text-sm">
                        <?php if ( ! $voices): ?>
                        <option value="">по умолчанию</option>
                        <?php endif; ?>
                        <?php foreach ($voices as $item): ?>
                        <option value="<?php echo HTML::chars((string) $item); ?>"<?php echo ((string) $item === $voice_now) ? ' selected' : ''; ?>><?php echo HTML::chars((string) $item); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300"
                       title="Микрофон остаётся открытым во время ответа. На Bluetooth-гарнитуре звук может становиться глуше.">
                    <input type="checkbox" id="talk-barge" name="barge" value="1" data-n2j-remember
                           class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
                    Перебивать голосом
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <input type="checkbox" id="talk-cue" name="cue" value="1" checked data-n2j-remember
                           class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
                    Сигнал перед ответом
                </label>
                <span class="n2j-hint">Лимит: <?php echo HTML::chars(isset($rate_text) ? (string) $rate_text : ''); ?></span>
                <p class="n2j-hint w-full">
                    Микрофон включается только на время вашей реплики и сразу выключается — поэтому ответ звучит полным голосом.
                    Перебивание держит микрофон открытым: на гарнитуре из-за этого звук может становиться глуше.
                </p>
            </div>

            <noscript>
                <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                    Разговор с микрофоном работает только со скриптами. Без них страница показывает историю разговора.
                </p>
            </noscript>
        </section>

        <aside class="w-full shrink-0 space-y-4 xl:w-80">
            <section class="n2j-card" aria-labelledby="talk-how-title">
                <h2 id="talk-how-title" class="n2j-h3">Как это работает</h2>
                <ol class="mt-3 space-y-2.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li><b class="text-gray-900 dark:text-white">1.</b> Одно нажатие включает режим — дальше микрофон берётся сам, на каждую вашу реплику.</li>
                    <li><b class="text-gray-900 dark:text-white">2.</b> Короткий сигнал означает «слушаю». Пока читается ответ, микрофон выключен: страница не слышит саму себя, а звук не уходит в «режим связи».</li>
                    <li><b class="text-gray-900 dark:text-white">3.</b> Пауза в конце фразы отправляет запись на распознавание: нажимать нечего.</li>
                    <li><b class="text-gray-900 dark:text-white">4.</b> Ответ читается вслух по частям: первая фраза звучит, пока готовятся следующие.</li>
                    <li><b class="text-gray-900 dark:text-white">5.</b> Включили перебивание — заговорили во время ответа, он замолкает, а сказанное становится новой репликой.</li>
                </ol>
            </section>

            <section class="n2j-card" aria-labelledby="talk-data-title">
                <h2 id="talk-data-title" class="n2j-h3">Голос и данные</h2>
                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li>Запись уходит на сервер, распознаётся и не сохраняется — в базе остаётся только текст.</li>
                    <li>Тишина и шум на распознавание не уходят: запись короче 0,3 с голоса отменяется в браузере, а известные «выдумки» распознавателя отсекаются на сервере.</li>
                    <li>Микрофон при закрытии освобождает устройство: гарнитура возвращается в обычный режим, и озвучка снова звучит полным голосом.</li>
                    <li>Ответ модели озвучивает OmniVoice — тот же голос, что на <a class="n2j-link" href="/voice">/voice</a>.</li>
                    <li>Разговоры хранятся 30 дней и видны только вам. Микрофон выключается кнопкой «Закончить» или клавишей Esc.</li>
                </ul>
            </section>

            <?php if ($recent): ?>
            <section class="n2j-card" aria-labelledby="talk-recent-title">
                <h2 id="talk-recent-title" class="n2j-h3">Вернуться к теме</h2>
                <ul class="mt-3 space-y-1.5 text-sm">
                    <?php foreach ($recent as $item): ?>
                    <li>
                        <a class="n2j-link" href="/talk?c=<?php echo HTML::chars((string) $item['code']); ?>"><?php echo HTML::chars((string) ($item['title'] !== '' ? $item['title'] : 'Разговор')); ?></a>
                        <span class="text-xs text-gray-500 dark:text-gray-400">· <?php echo (int) $item['turns']; ?> ход(ов)</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </aside>
    </div>

    <?php /* Шаблоны реплик: классы живут в разметке, скрипт только клонирует — так сборка Tailwind их видит. */ ?>
    <template id="talk-tpl-user">
        <li class="flex justify-end">
            <div class="max-w-[85%] rounded-2xl bg-blue-600 px-3.5 py-2 text-sm leading-relaxed text-white shadow-sm">
                <p class="whitespace-pre-line" data-part="text"></p>
                <p class="mt-1 text-xs opacity-80" data-part="meta"></p>
            </div>
        </li>
    </template>
    <template id="talk-tpl-bot">
        <li class="flex justify-start">
            <div class="max-w-[85%] rounded-2xl border border-gray-200 bg-white px-3.5 py-2 text-sm leading-relaxed text-gray-800 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                <p class="whitespace-pre-line" data-part="text"></p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-part="meta"></p>
            </div>
        </li>
    </template>

    <script type="application/json" id="talk-config"><?php echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <?php echo View::factory('677/script'); ?>
<?php endif; ?>
</div>
