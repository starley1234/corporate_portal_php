<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Декодер JSON (nj664_jsondecode) — страница /json-decode.
 *
 * Слева — вход: JSON в любом виде (экранированный, из URL, из HTML, в кавычках).
 * Справа — разбор: JSON, сжатый JSON, вид глазами PHP и готовый код.
 *
 * @var string     $input  текст входа
 * @var string     $mode   вкладка результата: json|min|array|php
 * @var array|NULL $result Service_Nj664_Decode::decode()
 * @var bool       $sent   форма отправлена (работа без JavaScript)
 * @var string     $csrf
 */

$ok    = ($result !== NULL AND ! empty($result['ok']));
$stats = $ok ? Service_Nj664_Decode::stats($result['data']) : NULL;

if ( ! $ok)
{
    $mode = 'json';
}

$prepared = '';

if ($ok)
{
    switch ($mode)
    {
        case 'min':   $prepared = Service_Nj664_Decode::minify($result['data']); break;
        case 'array': $prepared = Service_Nj664_Decode::php_array($result['data']); break;
        case 'php':   $prepared = Service_Nj664_Decode::php_code($result['data']); break;
        default:      $prepared = Service_Nj664_Decode::beautify($result['data']);
    }
}

$tabs = [
    'json'  => 'JSON',
    'min'   => 'Сжатый',
    'array' => 'PHP Array',
    'php'   => 'json_decode()',
];

$area  = 'n2j-textarea w-full resize-y font-mono leading-relaxed';
$btn   = 'n2j-btn-secondary n2j-btn-sm';
$label = 'n2j-label';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <h1 class="n2j-h1">Декодер JSON</h1>
    <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
        JSON прилетает в разные руки по-разному: из лога — с экранированием
        <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-xs dark:bg-gray-800">{\"a\":1}</code>,
        из адреса — процентами
        <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-xs dark:bg-gray-800">%7B…%7D</code>,
        из HTML — сущностями. Вставьте что угодно из этого — покажем структуру,
        значения и готовый PHP-код. Ничего не сохраняется.
    </p>

    <form method="post" action="/json-decode" class="mt-5" id="nj664-form">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
        <input type="hidden" name="mode" id="nj664-mode" value="<?php echo HTML::chars($mode); ?>">

        <div class="grid gap-4 lg:grid-cols-2">

            <!-- ======================== Вход ======================== -->
            <div>
                <div class="mb-1.5 flex items-center justify-between gap-3">
                    <label for="nj664-input" class="<?php echo $label; ?>">Вход: JSON в любом виде</label>
                    <span class="text-xs text-gray-400" data-nj664-size></span>
                </div>

                <textarea id="nj664-input" name="input" spellcheck="false" rows="16"
                          placeholder='Например: {"name":"Иван","age":30} или %7B%22name%22%3A%22Иван%22%7D'
                          class="<?php echo $area; ?> h-80"><?php echo HTML::chars($input); ?></textarea>

                <div class="mt-2 flex flex-wrap gap-1.5">
                    <button type="button" class="<?php echo $btn; ?>" data-nj664-act="sample"><i class="fas fa-flask"></i> Пример</button>
                    <button type="button" class="<?php echo $btn; ?>" data-nj664-act="open"><i class="fas fa-folder-open"></i> Файл</button>
                    <button type="button" class="<?php echo $btn; ?>" data-nj664-act="paste"><i class="fas fa-paste"></i> Из буфера</button>
                    <button type="button" class="<?php echo $btn; ?>" data-nj664-act="url"><i class="fas fa-link"></i> Ссылка</button>
                    <button type="button" class="<?php echo $btn; ?>" data-nj664-act="clear"><i class="fas fa-eraser"></i> Очистить</button>
                    <input type="file" id="nj664-file" accept=".json,.txt,.log,application/json,text/plain" class="hidden">
                </div>

                <!-- Загрузка по адресу: браузер тянет URL сам, сервер в чужие
                     сети не ходит (иначе любую ссылку можно превратить в
                     запрос во внутреннюю сеть сайта). -->
                <div id="nj664-url-row" class="mt-2 hidden items-center gap-1.5">
                    <input type="url" id="nj664-url" placeholder="https://api.example.com/data.json"
                           class="n2j-input min-w-0 flex-1 py-1.5 text-xs min-h-0">
                    <button type="button" class="<?php echo $btn; ?>" data-nj664-act="load"><i class="fas fa-download"></i> Загрузить</button>
                </div>

                <noscript>
                    <div class="mt-2">
                        <button type="submit" class="n2j-btn-process n2j-btn-sm">
                            <i class="fas fa-bolt"></i> Раскодировать
                        </button>
                    </div>
                </noscript>
            </div>

            <!-- ======================== Результат ======================== -->
            <div>
                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                    <div class="n2j-seg flex-wrap gap-1" role="tablist">
                        <?php foreach ($tabs as $code => $title): ?>
                            <button type="button" role="tab" data-nj664-tab="<?php echo HTML::chars($code); ?>"
                                    class="n2j-seg-item<?php echo $code === $mode ? ' is-active' : ''; ?>">
                                <?php echo HTML::chars($title); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" class="<?php echo $btn; ?>" data-nj664-act="copy"><i class="fas fa-copy"></i> Копировать</button>
                        <button type="button" class="<?php echo $btn; ?>" data-nj664-act="save"><i class="fas fa-download"></i> Скачать</button>
                        <button type="button" class="<?php echo $btn; ?>" data-nj664-act="full"><i class="fas fa-expand"></i> Экран</button>
                    </div>
                </div>

                <?php /* «Экран»: JS ставит метку .nj664-full — вид задают варианты [&.nj664-full] */ ?>
                <div class="relative [&.nj664-full]:fixed [&.nj664-full]:inset-4 [&.nj664-full]:z-50 [&.nj664-full]:rounded-xl [&.nj664-full]:bg-white [&.nj664-full]:p-2 [&.nj664-full]:shadow-[0_0_0_100vmax_rgb(17_24_39/0.7)] dark:[&.nj664-full]:bg-gray-900">
                    <pre id="nj664-out" class="h-80 overflow-auto rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"><?php echo HTML::chars($prepared); ?></pre>
                    <pre id="nj664-out-raw" class="hidden"><?php echo HTML::chars($prepared); ?></pre>
                </div>

                <!-- Статус: что раскодировалось и что это за данные -->
                <div class="mt-2 min-h-[2rem]" id="nj664-status" data-nj664-mode-label="<?php echo $ok ? HTML::chars(Service_Nj664_Decode::mode_label($result['mode'])) : ''; ?>">
                    <?php if ($result === NULL): ?>
                        <p class="n2j-hint">Разбор живой: начните вставлять — результат появится сразу.</p>
                    <?php elseif ($ok): ?>
                        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                            <span class="inline-flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400">
                                <i class="fas fa-circle-check"></i> Разобрано
                            </span>
                            <span><?php echo HTML::chars(Service_Nj664_Decode::mode_label($result['mode'])); ?></span>
                            <?php if ($stats): ?>
                                <span class="text-gray-400">корень: <?php echo HTML::chars($stats['type']); ?></span>
                                <span class="text-gray-400">узлов: <?php echo (int) $stats['nodes']; ?></span>
                                <span class="text-gray-400">ключей: <?php echo (int) $stats['keys']; ?></span>
                                <span class="text-gray-400">глубина: <?php echo (int) $stats['depth']; ?></span>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="flex flex-wrap items-center gap-x-2 text-xs font-medium text-rose-600 dark:text-rose-400">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span><?php echo HTML::chars($result['error']); ?></span>
                            <?php if ( ! empty($result['line'])): ?>
                                <span class="text-gray-500 dark:text-gray-400">— строка <?php echo (int) $result['line']; ?>, позиция <?php echo (int) $result['col']; ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </form>

    <!-- ======================== Подсказки ======================== -->
    <div class="mt-8 grid gap-4 sm:grid-cols-3">
        <div class="n2j-card">
            <h2 class="n2j-h3"><i class="fas fa-quote-left text-blue-500"></i> Что понимает</h2>
            <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                <li>обычный JSON — объект, массив, строку, число</li>
                <li>экранированный: <code class="font-mono">{\"a\":1}</code>, <code class="font-mono">\n</code> внутри</li>
                <li>URL-кодированный: <code class="font-mono">%7B%22a%22%3A1%7D</code></li>
                <li>HTML: <code class="font-mono">&amp;quot;</code>, <code class="font-mono">&amp;amp;</code></li>
                <li>строку в кавычках: <code class="font-mono">'{"a":1}'</code></li>
            </ul>
        </div>

        <div class="n2j-card">
            <h2 class="n2j-h3"><i class="fas fa-code text-blue-500"></i> Вкладки</h2>
            <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                <li><strong>JSON</strong> — красиво, с отступами и живым юникодом</li>
                <li><strong>Сжатый</strong> — одна строка, для лога и запроса</li>
                <li><strong>PHP Array</strong> — как это видит <code class="font-mono">print_r()</code></li>
                <li><strong>json_decode()</strong> — готовый кусок PHP-кода</li>
            </ul>
        </div>

        <div class="n2j-card">
            <h2 class="n2j-h3"><i class="fas fa-shield-halved text-blue-500"></i> Про данные</h2>
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                Всё считается на месте, в браузере: JSON никуда не отправляется
                и не сохраняется. Вкладки PHP уходят на сервер — там считается
                <code class="font-mono">print_r</code> и генерируется код, но и этот
                текст живёт ровно одно мгновение, до ответа.
            </p>
            <p class="n2j-hint mt-2">
                <a href="/nj664/api/sample" class="text-blue-600 hover:underline dark:text-blue-400">JSON API</a>
                — для агентов и скриптов.
            </p>
        </div>
    </div>

    <h2 class="n2j-h3 mt-8">Раскодировать JSON: коротко</h2>
    <div class="mt-2 space-y-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
        <p>
            Экранированный JSON встречается чаще, чем кажется: так он выглядит в
            ответе API, завёрнутом в другую строку, в логе nginx и в теле письма.
            Раскодировать его глазами можно только на маленьком кусочке — поэтому
            декодер и нужен.
        </p>
        <p>
            Вставьте строку, получите структуру. Ошибка подсвечивается с указанием
            строки и позиции — этого обычно хватает, чтобы найти лишнюю запятую
            перед закрывающей скобкой.
        </p>
    </div>

</div>
</div>
</div>

<?php echo View::factory('664/script'); ?>
