<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сетевые инструменты (nj633_nettools) — страница /net.
 *
 * Главный блок (форма + результат) + правая колонка-компаньон 1/3.
 * Сетевой I/O — на сервере (/net/api/*): DNS, WHOIS, доступность TCP.
 * Проверки защищены от SSRF и ограничены по частоте (счётчик в БД).
 */
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3"><i class="fas fa-shield-halved mr-1.5 text-emerald-500"></i>Проверка сети</h2>
            <span class="n2j-badge n2j-badge-ok ml-auto">
                <i class="fas fa-lock text-[11px]"></i>SSRF-защита
            </span>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div>
                <label for="nj633-host" class="n2j-label">Домен или адрес</label>
                <input id="nj633-host" name="host" type="text" placeholder="example.com или 8.8.8.8" autocomplete="off" spellcheck="false"
                       class="n2j-input mt-1.5 w-full font-mono">
            </div>
            <div>
                <label for="nj633-kind" class="n2j-label">Проверка</label>
                <select id="nj633-kind" class="n2j-input n2j-select mt-1.5 w-full cursor-pointer">
                    <option value="dns" selected>DNS-записи</option>
                    <option value="whois">WHOIS домена</option>
                    <option value="check">Доступность порта</option>
                </select>
                <p class="n2j-hint" id="nj633-kind-hint">A / AAAA / MX / TXT / NS</p>
            </div>
            <div>
                <label for="nj633-type-port" class="n2j-label">Тип / порт</label>
                <input id="nj633-type-port" type="text" value="A" autocomplete="off" spellcheck="false"
                       class="n2j-input mt-1.5 w-full">
                <p class="n2j-hint" id="nj633-port-hint" >A, AAAA, MX, TXT, NS, CNAME, SOA</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="button" id="nj633-run"
                    class="n2j-btn-process">
                <i class="fas fa-bolt"></i> Проверить
            </button>
            <span class="text-xs text-gray-400 dark:text-gray-500">Лимит: <?php echo HTML::chars($rate_text); ?> с одного адреса.</span>
        </div>

        <!-- Нерасчленяемый статус: спиннер + этап + таймер -->
        <div id="nj633-status" class="mt-4 hidden items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <i class="fas fa-circle-notch fa-spin text-blue-500"></i>
            <span id="nj633-status-text">Выполняю проверку…</span>
            <span id="nj633-timer" class="font-mono text-xs text-gray-400"></span>
        </div>

        <div id="nj633-error" class="n2j-flash n2j-flash-err hidden mt-4 block"></div>
    </div>

    <!-- Результат -->
    <div class="n2j-card mt-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-list mr-1.5 text-gray-400"></i>Результат</p>
            <button type="button" id="nj633-copy" class="n2j-btn-secondary n2j-btn-sm hidden">
                <i class="fas fa-copy"></i> Копировать
            </button>
        </div>

        <div id="nj633-result" class="mt-3 overflow-auto rounded-lg bg-gray-50 p-4 font-mono text-sm leading-relaxed text-gray-800 dark:bg-gray-950 dark:text-gray-100">
            <span class="text-gray-400">Результат появится здесь — укажите домен и нажмите «Проверить».</span>
        </div>

        <!-- LLM-опция: объяснить результат -->
        <div id="nj633-llm-box" class="hidden mt-4 border-t border-gray-100 pt-4 dark:border-gray-700/60">
            <div class="flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1">
                    <label for="nj633-llm-q" class="n2j-label">
                        <i class="fas fa-wand-magic-sparkles mr-1 text-blue-500"></i>Объяснить результат (ИИ) — опционально
                    </label>
                    <input id="nj633-llm-q" type="text" placeholder="Что означает этот результат для сайта?" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
                <button type="button" id="nj633-llm-run"
                        class="n2j-btn-secondary mt-6">
                    <i class="fas fa-wand-magic-sparkles"></i> Объяснить
                </button>
            </div>
            <div id="nj633-llm-out" class="hidden mt-3 rounded-lg border border-blue-100 bg-blue-50/60 px-4 py-3 text-sm text-gray-700 dark:border-blue-900/40 dark:bg-blue-950/30 dark:text-gray-200"></div>
        </div>
    </div>

    <!-- Конвертеры (IP↔integer и Base64) -->
    <div class="n2j-card mt-4">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3"><i class="fas fa-arrows-rotate mr-1.5 text-blue-500"></i>Конвертеры</h2>
            <div class="n2j-seg ml-auto inline-flex" role="tablist">
                <button type="button" id="nj633-cv-ip" class="n2j-seg-item is-active">IP ↔ число</button>
                <button type="button" id="nj633-cv-b64" class="n2j-seg-item">Base64</button>
            </div>
        </div>

        <!-- IP ↔ integer -->
        <div id="nj633-cv-ip-pane" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_1fr] sm:items-center">
            <div>
                <label for="nj633-ipin" class="n2j-label">IP-адрес или число</label>
                <input id="nj633-ipin" type="text" placeholder="192.168.0.1 или 3232235521" autocomplete="off" spellcheck="false"
                       class="n2j-input mt-1.5 w-full font-mono">
            </div>
            <button type="button" id="nj633-ip-run" class="n2j-btn-process justify-self-start">
                <i class="fas fa-arrows-rotate"></i> Преобразовать
            </button>
            <div>
                <label class="n2j-label">Результат</label>
                <div id="nj633-ip-out" class="mt-1.5 min-h-[44px] rounded-lg bg-gray-50 px-3.5 py-2.5 font-mono text-sm text-gray-800 dark:bg-gray-950 dark:text-gray-100">—</div>
            </div>
        </div>

        <!-- Base64 -->
        <div id="nj633-cv-b64-pane" class="mt-4 hidden">
            <div class="flex flex-wrap items-center gap-2">
                <label for="nj633-b64-mode" class="n2j-label">Действие</label>
                <select id="nj633-b64-mode" class="n2j-input n2j-select cursor-pointer w-auto">
                    <option value="encode" selected>Закодировать (encode)</option>
                    <option value="decode">Раскодировать (decode)</option>
                </select>
            </div>
            <div class="mt-3 grid gap-3">
                <div>
                    <label for="nj633-b64-text" class="n2j-label">Текст</label>
                    <textarea id="nj633-b64-text" rows="3" autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 w-full font-mono"></textarea>
                </div>
                <button type="button" id="nj633-b64-run" class="n2j-btn-process">
                    <i class="fas fa-arrow-right-arrow-left"></i> Преобразовать
                </button>
                <div>
                    <label class="n2j-label">Результат</label>
                    <div id="nj633-b64-out" class="mt-1.5 rounded-lg bg-gray-50 px-3.5 py-2.5 font-mono text-sm leading-relaxed break-all whitespace-pre-wrap text-gray-800 dark:bg-gray-950 dark:text-gray-100">—</div>
                </div>
            </div>
        </div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <!-- Быстрые советы -->
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>• DNS: тип записи — A, AAAA, MX, TXT, NS, CNAME, SOA.</li>
            <li>• «Доступность порта» без ping: открываем TCP-соединение к порту.</li>
            <li>• Доступны домены и публичные адреса — внутренние сети закрыты.</li>
            <li>• Каждая проверка сохраняется в историю ниже.</li>
            <li>• Конвертеры (IP↔число, Base64) считаются мгновенно, без сети.</li>
        </ul>
    </div>

    <!-- Безопасность (как защищён сервис) -->
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-shield-halved mr-1.5 text-emerald-500"></i>Как защищён сервис</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>• SSRF: закрыты localhost, приватные подсети и облачные метаданные (169.254.169.254) — и по вводу, и по резолву DNS.</li>
            <li>• Без shell: сетевые проверки идут потоковыми сокетами, без команд операционной системы.</li>
            <li>• Rate limit: <?php echo HTML::chars($rate_text); ?> с одного IP.</li>
        </ul>
    </div>

    <!-- История проверок -->
    <?php if ( ! empty($recent)): ?>
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-history mr-1.5 text-gray-400"></i>Мои проверки</p>
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
            <?php foreach ($recent as $rc): ?>
            <li class="flex items-center justify-between gap-2 py-2 text-xs">
                <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">
                    <span class="font-mono"><?php echo HTML::chars($rc['kind']); ?></span>
                    <?php echo HTML::chars($rc['target']); ?><?php echo $rc['port'] ? ':'.(int) $rc['port'] : ''; ?>
                </span>
                <span class="shrink-0 <?php echo $rc['status'] === 'ok' ? 'text-emerald-500' : 'text-rose-500'; ?>">
                    <i class="fas <?php echo $rc['status'] === 'ok' ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- FAQ -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему нельзя проверить 192.168.1.1?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Сервис работает только с публичными адресами и доменами. Приватные адреса, localhost
                    и облачные метаданные закрыты по требованиям безопасности — иначе через сервис можно
                    было бы атаковать сами серверы изнутри (SSRF).
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Проверка выполняется на сервере?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: DNS, WHOIS и TCP-подключение выполняет сервер — из браузера этих запросов не сделать.
                    Чтобы сервером нельзя было злоупотребить, действует ограничение частоты с одного IP.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Объяснение ИИ — обязательно?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет, это дополнение: обычный ответ и так показывается текстом. Кнопка «Объяснить»
                    запускает фон задач, и через пару секунд под результатом появляется короткое
                    пояснение простыми словами — оно сохраняется в вашей истории.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('633/script'); ?>
