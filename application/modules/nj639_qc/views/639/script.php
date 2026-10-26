<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj639: контроль качества — ручной прогон, источники, история. */
(function () {
    'use strict';

    var photo = document.getElementById('nj639-photo');
    var refsInput = document.getElementById('nj639-refs');
    var refsHint = document.getElementById('nj639-refs-hint');
    var ruleSel = document.getElementById('nj639-rule-select');
    var ruleName = document.getElementById('nj639-rule-name');
    var ruleText = document.getElementById('nj639-rule-text');
    var variants = document.getElementById('nj639-variants');
    var schema = document.getElementById('nj639-schema');
    var goBtn = document.getElementById('nj639-go');

    var statusRow = document.getElementById('nj639-status');
    var statusTx = document.getElementById('nj639-status-text');
    var timerEl = document.getElementById('nj639-timer');
    var errBox = document.getElementById('nj639-error');
    var resBox = document.getElementById('nj639-result');

    function setBusy(busy, label) {
        if (busy) {
            statusRow.classList.remove('hidden');
            statusRow.classList.add('flex');
            statusTx.textContent = label || 'VLM анализирует кадр…';
            var t0 = Date.now();
            clearInterval(timerEl._t);
            timerEl._t = setInterval(function () {
                timerEl.textContent = ((Date.now() - t0) / 1000).toFixed(1) + 's';
            }, 100);
        } else {
            clearInterval(timerEl._t);
            timerEl.textContent = '';
            statusRow.classList.add('hidden');
            statusRow.classList.remove('flex');
        }
    }

    function parseVariants(s) {
        return s.split(',').map(function (v) { return v.trim(); }).filter(function (v) { return v !== ''; });
    }

    /**
     * Файл → data URL, уменьшенный до 1400 px (общий N2JVision.shrink).
     * Полноразмерный снимок с телефона — это мегабайты в теле запроса и те же
     * мегабайты base64 в payload задачи очереди; модель всё равно смотрит
     * уменьшенный кадр (урок nj646, так же работают /label, /meal, /plant).
     */
    function readFileAsDataUrl(file) {
        return new Promise(function (resolve, reject) {
            if (!file) { resolve(null); return; }

            if (window.N2JVision && window.N2JVision.shrink) {
                window.N2JVision.shrink(file, function (dataUrl) {
                    if (dataUrl) { resolve(dataUrl); return; }
                    // не отрисовался (экзотический формат) — отдаём как есть
                    var fb = new FileReader();
                    fb.onload = function () { resolve(fb.result); };
                    fb.onerror = reject;
                    fb.readAsDataURL(file);
                });
                return;
            }

            var fr = new FileReader();
            fr.onload = function () { resolve(fr.result); };
            fr.onerror = reject;
            fr.readAsDataURL(file);
        });
    }

    function readFilesAsDataUrls(fileList) {
        var files = Array.prototype.slice.call(fileList || []);
        return Promise.all(files.map(readFileAsDataUrl)).then(function (arr) {
            return arr.filter(function (d) { return d !== null; });
        });
    }

    // подтянуть сохранённое правило в форму (текст/варианты/схема/эталоны)
    function loadRule(ruleId) {
        if (!ruleId) { return; }
        fetch('/qc/api/rule/' + ruleId, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { return; }
                ruleName.value = j.name || '';
                ruleText.value = j.rule_text || '';
                variants.value = (j.variants || []).join(', ');
                schema.value = j.schema_json || '';
                if ((j.refs || []).length) {
                    refsHint.textContent = 'Правило содержит ' + j.refs.length + ' эталон(а) — они применятся автоматически, если не выбрать свои.';
                }
            })
            .catch(function () {});
    }

    function fmtVerdict(j) {
        if (!j || typeof j !== 'object') return '';
        try { return JSON.stringify(j, null, 2); } catch (e) { return String(j); }
    }

    function showResult(j) {
        var okFlag = j.schema_ok !== false && j.status === 'done';
        resBox.className = 'n2j-flash ' + (okFlag ? 'n2j-flash-ok' : 'n2j-flash-warn') + ' block';
        resBox.innerHTML = '';

        var p = document.createElement('p');
        p.className = 'font-semibold';
        p.textContent = 'Статус: ' + (j.status || '') + (j.model ? ' · модель ' + j.model : '');
        resBox.appendChild(p);

        if (j.verdict) {
            var pre = document.createElement('pre');
            pre.className = 'mt-2 max-h-64 overflow-auto rounded-md bg-white/70 p-3 font-mono text-xs dark:bg-gray-900/60';
            pre.textContent = fmtVerdict(j.verdict);
            resBox.appendChild(pre);
        }
        if (j.error) {
            var e = document.createElement('p');
            e.className = 'mt-2 text-rose-700 dark:text-rose-300';
            e.textContent = j.error;
            resBox.appendChild(e);
        }
        resBox.classList.remove('hidden');
    }

    /** Показать прогон из журнала. */
    function openRun(runId) {
        errBox.classList.add('hidden');
        resBox.classList.add('hidden');

        fetch('/qc/api/result/' + runId, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { errBox.textContent = j.error || 'Прогон не найден.'; errBox.classList.remove('hidden'); return; }
                showResult(j);
                resBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(function () {
                errBox.textContent = 'Сервер не ответил.';
                errBox.classList.remove('hidden');
            });
    }

    /** Свежий прогон — первой строкой в журнал (вместо перезагрузки страницы). */
    function addHistory(j) {
        var list = document.getElementById('nj639-history-list');
        var empty = document.getElementById('nj639-history-empty');

        if (!list || !j || !j.id) { return; }

        if (document.querySelector('.nj639-history[data-run="' + j.id + '"]')) { return; }

        var badge = j.status === 'done'
            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'
            : (j.status === 'error'
                ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400'
                : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400');

        var li = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('data-run', String(j.id));
        btn.className = 'nj639-history w-full rounded-lg border border-transparent px-2 py-1.5 text-left transition hover:border-gray-200 hover:bg-white dark:hover:border-gray-700/60 dark:hover:bg-gray-800/60';

        var head = document.createElement('span');
        head.className = 'flex items-center justify-between gap-2';

        var title = document.createElement('span');
        title.className = 'truncate';
        title.textContent = '#' + j.id + ' · ' + (j.mode || 'manual');

        var state = document.createElement('span');
        state.className = 'shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ' + badge;
        state.textContent = j.status || '';

        head.appendChild(title);
        head.appendChild(state);
        btn.appendChild(head);

        var summary = j.error || summaryOf(j.verdict);

        if (summary) {
            var sub = document.createElement('span');
            sub.className = j.error
                ? 'mt-1 block truncate text-rose-500 dark:text-rose-400'
                : 'mt-1 block truncate text-gray-400 dark:text-gray-500';
            sub.textContent = summary;
            btn.appendChild(sub);
        }

        btn.addEventListener('click', function () { openRun(j.id); });
        li.appendChild(btn);
        list.insertBefore(li, list.firstChild);

        if (empty) { empty.classList.add('hidden'); }
    }

    /** Короткая выжимка вердикта для строки журнала. */
    function summaryOf(verdict) {
        if (!verdict || typeof verdict !== 'object') { return ''; }

        var keys = ['verdict', 'result', 'status', 'defect', 'defects', 'ok'];

        for (var i = 0; i < keys.length; i++) {
            var v = verdict[keys[i]];
            if (typeof v === 'string' && v !== '') { return v; }
            if (typeof v === 'boolean') { return v ? 'да' : 'нет'; }
            if (Array.isArray(v) && v.length) { return v.join(', '); }
        }

        try { return JSON.stringify(verdict).slice(0, 120); } catch (e) { return ''; }
    }

    function pollResult(runId, attempts) {
        var n = attempts || 0;
        fetch('/qc/api/result/' + runId, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status === 'done' || j.status === 'error') {
                    setBusy(false);
                    goBtn.disabled = false;
                    showResult(j);
                    addHistory(j);   // раньше здесь был location.reload():
                                     // страница перезагружалась ПОВЕРХ только
                                     // что показанного вердикта
                    return;
                }
                if (n > 60) { setBusy(false); goBtn.disabled = false; errBox.textContent = 'Проверка затянулась — повторите позже.'; errBox.classList.remove('hidden'); return; }
                setTimeout(function () { pollResult(runId, n + 1); }, 2500);
            })
            .catch(function () {
                setTimeout(function () { pollResult(runId, n + 1); }, 4000);
            });
    }

    function run() {
        errBox.classList.add('hidden');
        resBox.classList.add('hidden');

        Promise.all([
            readFileAsDataUrl(photo.files && photo.files[0]),
            readFilesAsDataUrls(refsInput.files)
        ]).then(function (res) {
            var dataUrl = res[0] || demo.frame;
            var refUrls = (res[1] && res[1].length) ? res[1] : (demo.ref ? [demo.ref] : []);
            var ruleId = parseInt(ruleSel.value, 10);
            var text = ruleText.value.trim();

            if (!dataUrl) { throw new Error('Приложите фото изделия или нажмите кнопку «Демо».'); }
            if (ruleId === 0 && text === '') { throw new Error('Задайте правило или выберите сохранённое.'); }

            var payload = {
                rule_id: ruleId,
                rule_name: ruleName.value.trim(),
                rule_text: text,
                variants: parseVariants(variants.value),
                schema_json: schema.value,
                data_urls: [dataUrl],
                refs: refUrls
            };

            setBusy(true);

            return fetch('/qc/api/run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload),
                cache: 'no-store'
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { setBusy(false); throw new Error(j.error || 'Не удалось запустить проверку.'); }
                pollResult(j.run_id, 0);
            });
        }).catch(function (e) {
            setBusy(false);
            goBtn.disabled = false;
            errBox.textContent = e.message || 'Сервер не ответил — попробуйте ещё раз.';
            errBox.classList.remove('hidden');
        });
    }

    goBtn.addEventListener('click', function () { goBtn.disabled = true; run(); });

    // история: клик по строке — показать вердикт в результирующем блоке
    Array.prototype.forEach.call(document.querySelectorAll('.nj639-history'), function (btn) {
        btn.addEventListener('click', function () { openRun(btn.getAttribute('data-run')); });
    });

    ruleSel.addEventListener('change', function () {
        var rid = parseInt(ruleSel.value, 10);
        if (rid > 0) { loadRule(rid); }
        else {
            ruleName.value = '';
            ruleText.value = '';
            variants.value = '';
            schema.value = '';
            refsHint.textContent = 'С чем сверять кадр. Если не указать — возьмём эталоны выбранного правила.';
        }
    });

    // ---------------------------------------------------------------- пресеты
    // Готовые наборы «правило + варианты вердикта + выходная JSON-схема»:
    // заполнить форму руками — минута, а без схемы и вариантов сервис выглядит
    // «не работающим». Две последние кнопки подставляют ещё и снимки с сайта,
    // поэтому проверка возможна без своих файлов и без камеры.
    var DEMO_DIR = '/assets/demo/nj639/';

    var SCHEMA_BASIC = JSON.stringify({
        type: 'object',
        required: ['verdict', 'defects'],
        properties: {
            verdict: { type: 'string' },
            defects: { type: 'array' },
            confidence: { type: 'number' }
        }
    }, null, 2);

    var SCHEMA_COUNT = JSON.stringify({
        type: 'object',
        required: ['verdict', 'count'],
        properties: {
            verdict: { type: 'string' },
            count: { type: 'integer' },
            confidence: { type: 'number' }
        }
    }, null, 2);

    var RULES = {
        scratch: {
            name: 'Царапины и сколы корпуса',
            text: 'Сравни кадр изделия с эталоном. Ищи трещины, сколы, глубокие царапины, вмятины, заусенцы и потёртости на корпусе. Мелкие блики и пыль дефектом не считай. Перечисли найденные дефекты и укажи их расположение.',
            variants: 'годен, брак: царапина, брак: скол, брак: трещина, брак: иное',
            schema: SCHEMA_BASIC
        },
        label: {
            name: 'Этикетка: наличие и ровность',
            text: 'Проверь этикетку на изделии: она должна присутствовать, быть наклеена ровно (без перекоса более 5 градусов), не отслаиваться, не быть порванной или замятой, текст и штрихкод должны читаться. Сравни положение этикетки с эталоном.',
            variants: 'годен, брак: этикетки нет, брак: перекос, брак: отслоение, брак: повреждена',
            schema: JSON.stringify({
                type: 'object',
                required: ['verdict', 'label_present'],
                properties: {
                    verdict: { type: 'string' },
                    label_present: { type: 'boolean' },
                    skew_deg: { type: 'number' },
                    defects: { type: 'array' }
                }
            }, null, 2)
        },
        assembly: {
            name: 'Комплектность сборки',
            text: 'Сверь сборку с эталоном: все ли винты на месте и затянуты, нет ли пропущенных или лишних деталей, подключены ли разъёмы, совпадает ли расположение элементов. Перечисли отличия от эталона.',
            variants: 'годен, брак: нет винта, брак: разъём не подключён, брак: лишняя деталь, брак: перепутаны детали',
            schema: JSON.stringify({
                type: 'object',
                required: ['verdict', 'missing'],
                properties: {
                    verdict: { type: 'string' },
                    missing: { type: 'array' },
                    extra: { type: 'array' },
                    confidence: { type: 'number' }
                }
            }, null, 2)
        },
        weld: {
            name: 'Сварной шов / пайка',
            text: 'Оцени качество шва: равномерность валика, непровары, прожоги, поры, подрезы, брызги металла; для пайки — непропаи, холодная пайка, перемычки между контактами, избыток флюса. Укажи участок с дефектом.',
            variants: 'годен, брак: непровар, брак: пора, брак: перемычка, брак: непропай, требуется осмотр человеком',
            schema: SCHEMA_BASIC
        },
        count: {
            name: 'Пересчёт изделий в таре',
            text: 'Посчитай количество изделий в кадре (в таре/на поддоне) и сравни с эталоном. Укажи, видны ли повреждённые или перевёрнутые изделия. Если часть изделий перекрыта, честно скажи, что точный счёт невозможен.',
            variants: 'годен, брак: недостача, брак: перебор, брак: повреждённые изделия, счёт невозможен',
            schema: SCHEMA_COUNT
        },
        demo_defect: {
            name: 'Демо: корпус с дефектом',
            text: 'Сравни кадр изделия с эталоном. Ищи трещины, сколы, глубокие царапины и перекос наклейки. Перечисли найденные дефекты с указанием места.',
            variants: 'годен, брак: царапина, брак: скол, брак: трещина, брак: перекос этикетки',
            schema: SCHEMA_BASIC,
            frame: DEMO_DIR + 'part-defect.jpg',
            ref: DEMO_DIR + 'ref-ok.jpg',
            say: 'Кадр с дефектом и эталон подставлены — нажмите «Проверить изделие».'
        },
        demo_ok: {
            name: 'Демо: годный корпус',
            text: 'Сравни кадр изделия с эталоном. Ищи трещины, сколы, глубокие царапины и перекос наклейки. Перечисли найденные дефекты с указанием места.',
            variants: 'годен, брак: царапина, брак: скол, брак: трещина, брак: перекос этикетки',
            schema: SCHEMA_BASIC,
            frame: DEMO_DIR + 'part-ok.jpg',
            ref: DEMO_DIR + 'ref-ok.jpg',
            say: 'Годная деталь и эталон подставлены — нажмите «Проверить изделие».'
        }
    };

    var demo = { frame: '', ref: '' };

    var demoBox = document.getElementById('nj639-demo');
    var demoFrameImg = document.getElementById('nj639-demo-frame');
    var demoRefImg = document.getElementById('nj639-demo-ref');
    var demoMsg = document.getElementById('nj639-demo-msg');

    /** Картинка с этого же сайта → data URL (её примет /qc/api/run). */
    function urlToDataUrl(url) {
        return fetch(url, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) { throw new Error('нет файла ' + url); }
                return r.blob();
            })
            .then(function (blob) {
                return new Promise(function (resolve, reject) {
                    var fr = new FileReader();
                    fr.onload = function () { resolve(fr.result); };
                    fr.onerror = reject;
                    fr.readAsDataURL(blob);
                });
            });
    }

    function clearDemo() {
        demo.frame = '';
        demo.ref = '';
        if (demoBox) {
            demoBox.classList.add('hidden');
            demoBox.classList.remove('flex');
        }
    }

    function applyPreset(code) {
        var preset = RULES[code];

        if (!preset) { return; }

        errBox.classList.add('hidden');
        resBox.classList.add('hidden');

        // пресет — это НОВОЕ правило: иначе выбранное в списке перетирало бы
        // текст, который человек только что увидел в форме
        ruleSel.value = '0';
        ruleName.value = preset.name;
        ruleText.value = preset.text;
        variants.value = preset.variants;
        schema.value = preset.schema;

        if (!preset.frame) { clearDemo(); return; }

        if (demoMsg) { demoMsg.textContent = 'Загружаю демо-снимки…'; }
        if (demoBox) { demoBox.classList.remove('hidden'); demoBox.classList.add('flex'); }

        Promise.all([urlToDataUrl(preset.frame), urlToDataUrl(preset.ref)]).then(function (pair) {
            demo.frame = pair[0];
            demo.ref = pair[1];
            if (demoFrameImg) { demoFrameImg.src = preset.frame; }
            if (demoRefImg) { demoRefImg.src = preset.ref; }
            if (demoMsg) { demoMsg.textContent = preset.say || ''; }
            refsHint.textContent = 'Эталон взят из демо-набора. Свои файлы, если выберете, главнее.';
        }).catch(function () {
            clearDemo();
            errBox.textContent = 'Демо-снимки не загрузились — приложите своё фото.';
            errBox.classList.remove('hidden');
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-nj639-preset]'), function (btn) {
        btn.addEventListener('click', function () { applyPreset(btn.getAttribute('data-nj639-preset')); });
    });

    // выбрали свой файл — демо больше не нужно
    photo.addEventListener('change', function () { if (photo.files && photo.files.length) { clearDemo(); } });

    // --- источники ---
    var srcKind = document.getElementById('nj639-src-kind');
    var srcTrigger = document.getElementById('nj639-src-trigger');
    var srcName = document.getElementById('nj639-src-name');
    var srcRule = document.getElementById('nj639-src-rule');
    var srcCfg = document.getElementById('nj639-src-cfg');
    var srcInterval = document.getElementById('nj639-src-interval');
    var srcAdd = document.getElementById('nj639-src-add');
    var srcMsg = document.getElementById('nj639-src-msg');

    var PRESETS = {
        snapshot: '{"snapshot_url":"http://camera/axis-cgi/jpg/image.cgi","login":"","password":""}',
        onvif: '{"host":"http://camera","port":80,"login":"admin","password":""}',
        ftp: '{"host":"ftp.example.com","port":21,"login":"","password":"","remote_dir":"/in","pattern":"*.jpg"}',
        http: '{"url":"https://obj.example.com/line2/latest.jpg"}',
        folder: '{"path":"/srv/nj639/drop","pattern":"*.jpg"}',
        queue: '{"protocol":"mqtt","host":"mqtt.example.com","port":1883,"topic":"qc/camera2","via":"url"}'
    };

    srcKind.addEventListener('change', function () {
        var k = srcKind.value;
        if (srcCfg.value.trim() === '' || (k in PRESETS && PRESETS[k] !== srcCfg.value)) {
            srcCfg.value = PRESETS[k] || '{}';
        }
    });

    srcAdd.addEventListener('click', function () {
        var ruleId = parseInt(srcRule.value, 10);
        if (ruleId === 0) { srcMsg.textContent = 'Выберите правило для источника.'; return; }
        if (srcName.value.trim() === '') { srcMsg.textContent = 'Назовите источник.'; return; }

        var cfg = {};
        try { cfg = JSON.parse(srcCfg.value || '{}'); } catch (e) { srcMsg.textContent = 'Настройки не JSON.'; return; }

        var payload = {
            kind: srcKind.value,
            trigger: srcTrigger.value,
            name: srcName.value.trim(),
            rule_id: ruleId,
            interval_sec: parseInt(srcInterval.value, 10) || 60,
            config: cfg
        };

        srcAdd.disabled = true;
        srcMsg.textContent = 'Сохраняю…';

        fetch('/qc/api/source', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
            cache: 'no-store'
        }).then(function (r) { return r.json(); }).then(function (j) {
            srcAdd.disabled = false;
            if (!j.ok) { srcMsg.textContent = j.error || 'Ошибка'; return; }
            srcMsg.textContent = j.webhook_url ? ('Webhook: ' + j.webhook_url) : 'Источник добавлен.';
            setTimeout(function () { location.reload(); }, 1200);
        }).catch(function () {
            srcAdd.disabled = false;
            srcMsg.textContent = 'Сервер не ответил.';
        });
    });

    // Демо-источник: HTTP-кадр, который лежит на этом же сайте. Нужен ровно
    // затем, чтобы проверить авторежим (захват → VLM → вердикт) там, где
    // промышленной камеры под рукой нет.
    var srcDemo = document.getElementById('nj639-src-demo');

    if (srcDemo) {
        srcDemo.addEventListener('click', function () {
            srcKind.value = 'http';
            srcTrigger.value = 'timer';
            srcName.value = 'Демо-кадр с этого сайта';
            srcCfg.value = JSON.stringify({ url: location.origin + '/assets/demo/nj639/part-defect.jpg' });
            srcInterval.value = '300';

            var picked = 0;

            Array.prototype.forEach.call(srcRule.options, function (opt) {
                if (!picked && parseInt(opt.value, 10) > 0) { picked = parseInt(opt.value, 10); }
            });

            if (picked) {
                srcRule.value = String(picked);
                srcMsg.textContent = 'Форма заполнена — нажмите «Добавить источник», затем «Тест».';
            } else {
                srcMsg.textContent = 'Сначала нужно правило: нажмите пресет выше и один раз проверьте изделие — правило сохранится само.';
            }
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.nj639-fire'), function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = '…';
            fetch('/qc/api/source_fire', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ code: btn.getAttribute('data-fire') }),
                cache: 'no-store'
            }).then(function (r) { return r.json(); }).then(function (j) {
                btn.disabled = false;
                btn.textContent = 'Тест';
                if (!j.ok) { srcMsg.textContent = j.error || 'Ошибка запуска.'; return; }
                srcMsg.textContent = 'Захват запущен, прогон #' + j.run_id + '.';
                setTimeout(function () { location.reload(); }, 1500);
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = 'Тест';
                srcMsg.textContent = 'Сервер не ответил.';
            });
        });
    });
})();
</script>
