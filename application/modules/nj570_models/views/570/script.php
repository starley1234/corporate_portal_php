<script>
/* nj570 «Sketch 2 Solid»: прогресс воркера, poll статуса, кнопки вопросов, ползунки */
(function () {
    'use strict';

    /* ---------- уточняющие вопросы: кнопка = значение + поле «свой вариант» ---------- */
    document.querySelectorAll('[data-qgroup]').forEach(function (group) {
        var idx = group.getAttribute('data-qgroup');
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'a[' + idx + ']';
        group.appendChild(hidden);

        group.querySelectorAll('.m3d-opt').forEach(function (btn) {
            btn.addEventListener('click', function () {
                hidden.value = btn.getAttribute('data-qval');
                group.querySelectorAll('.m3d-opt').forEach(function (b) {
                    b.classList.remove('is-on');
                });
                btn.classList.add('is-on');
                var free = group.querySelector('[data-qtext]');
                if (free) free.value = '';
            });
        });

        var free = group.querySelector('[data-qtext]');
        if (free) {
            free.addEventListener('input', function () {
                hidden.value = free.value;
                group.querySelectorAll('.m3d-opt').forEach(function (b) {
                    b.classList.remove('is-on');
                });
            });
        }
    });

    /* ---------- IndexedDB nj570 (общая: ползунки + настройки вьюера) ---------- */
    window.m3dIDB = (function () {
        if (!window.indexedDB) return null;
        var dbp = new Promise(function (resolve) {
            try {
                var rq = indexedDB.open('nj570', 2);
                rq.onupgradeneeded = function () {
                    var db = rq.result;
                    if (!db.objectStoreNames.contains('params')) db.createObjectStore('params', { keyPath: 'code' });
                    if (!db.objectStoreNames.contains('settings')) db.createObjectStore('settings');
                };
                rq.onsuccess = function () { resolve(rq.result); };
                rq.onerror = function () { resolve(null); };
                rq.onblocked = function () { resolve(null); };
            } catch (e) { resolve(null); }
        });
        function get(store, key) {
            return dbp.then(function (db) {
                if (!db) return undefined;
                return new Promise(function (resolve) {
                    try {
                        var rq = db.transaction(store).objectStore(store).get(key);
                        rq.onsuccess = function () { resolve(rq.result); };
                        rq.onerror = function () { resolve(undefined); };
                    } catch (e) { resolve(undefined); }
                });
            });
        }
        function putKV(store, key, val) {   // хранилище БЕЗ keyPath
            return dbp.then(function (db) {
                if (!db) return;
                try { db.transaction(store, 'readwrite').objectStore(store).put(val, key); } catch (e) {}
            });
        }
        function putRow(row) {              // хранилище с keyPath
            return dbp.then(function (db) {
                if (!db) return;
                try { db.transaction('params', 'readwrite').objectStore('params').put(row); } catch (e) {}
            });
        }
        return { get: get, putKV: putKV, putRow: putRow };
    })();

    /* ---------- ползунки: живое значение + память значений в IndexedDB ---------- */
    (function () {
        var form = document.getElementById('m3d-sliders');
        if (!form) return;
        var codeEl = document.querySelector('[data-m3d-code]');
        var code = codeEl ? (codeEl.getAttribute('data-m3d-code') || '') : '';
        var ranges = form.querySelectorAll('.m3d-range');

        ranges.forEach(function (range) {
            var out = range.parentElement.querySelector('.m3d-val');
            if (out) range.addEventListener('input', function () { out.textContent = range.value; });
        });

        if (!code || !window.m3dIDB) return;

        function nameOf(range) { return (range.name || '').replace(/^p\[/, '').replace(/\]$/, ''); }

        // прошлые значения — подставляем (кнопка пересборки работает с ними)
        window.m3dIDB.get('params', code).then(function (row) {
            var vals = row && row.vals;
            if (!vals) return;
            var applied = 0;
            ranges.forEach(function (range) {
                var v = vals[nameOf(range)];
                if (v !== undefined && v !== null && v !== '') {
                    range.value = v;
                    var out = range.parentElement.querySelector('.m3d-val');
                    if (out) out.textContent = range.value;
                    applied++;
                }
            });
            if (applied) {
                var note = document.getElementById('m3d-vals-note');
                if (note) note.hidden = false;
            }
        });

        // текущие значения — сохраняем с дебаунсом
        var saveTimer = null;
        function saveVals() {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(function () {
                var vals = {};
                ranges.forEach(function (range) { vals[nameOf(range)] = range.value; });
                window.m3dIDB.putRow({ code: code, vals: vals, t: Date.now() });
            }, 500);
        }
        ranges.forEach(function (range) { range.addEventListener('input', saveVals); });
    })();

    /* ---------- ревизии: кнопка «в 3D» ---------- */
    document.querySelectorAll('[data-load-stl]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var viewer = document.getElementById('m3d-viewer');
            if (viewer && viewer.scrollIntoView) viewer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            if (window.m3dLoadSTL) window.m3dLoadSTL(btn.getAttribute('data-load-stl'));
        });
    });

    /* ---------- прогресс воркера + poll статуса ----------
       • опрос продолжается, пока вкладка НЕ видима (останавливается только по тайм-ауту);
       • секунды extrapolируются лишь между опросами — никогда не «тикают в пустоту». */
    var root = document.querySelector('[data-m3d-code]');
    var hasPending = document.querySelectorAll('[data-rev-pending]').length > 0;

    if (root && hasPending) {
        var code = root.getAttribute('data-m3d-code');
        if (/^[0-9a-z]+$/.test(code)) {
            var timer = null, delay = 4000, idle = 0, lastReload = 0;
            var t0 = Date.now(), MAX_MS = 15 * 60 * 1000;
            var bText = null, bElapsed = null, lastElapsedSec = 0, lastElapsedAt = 0, elapsedFrozen = false;

            var banner = document.createElement('div');
            banner.setAttribute('data-m3d-banner', '1');
            banner.className = 'n2j-flash n2j-flash-info mb-3 block text-xs';
            banner.innerHTML = '<div class="flex items-center gap-2">'
                + '<i class="fas fa-spinner n2j-spin shrink-0 text-blue-500" data-b-spin></i>'
                + '<span data-b-elapsed class="shrink-0 rounded-md bg-blue-100 px-1.5 py-0.5 font-mono text-xs font-bold text-blue-800 tabular-nums dark:bg-blue-900/60 dark:text-blue-200" style="min-width:4.2em; white-space:nowrap">0:00</span>'
                + '<span data-b-text class="min-w-0 flex-1 break-words font-semibold">Проверяем статус…</span>'
                + '</div>'
                + '<div class="mt-1 text-xs text-blue-400 dark:text-blue-500">этапы: код → рендер → STL</div>';
            root.insertBefore(banner, root.firstChild);
            bText = banner.querySelector('[data-b-text]');
            bElapsed = banner.querySelector('[data-b-elapsed]');

            // оверлей «ожидание» прямо на области 3D-вьюера
            var viewer = document.getElementById('m3d-viewer'),
                wait = null;
            if (viewer) {
                wait = document.createElement('div');
                wait.setAttribute('data-m3d-wait', '1');
                wait.className = 'absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-xl bg-white/70 text-blue-700 backdrop-blur-[1px] dark:bg-gray-900/70 dark:text-blue-300';
                wait.innerHTML = '<i class="fas fa-spinner n2j-spin text-2xl text-blue-500"></i>'
                    + '<span class="rounded-md bg-blue-100 px-1.5 py-0.5 font-mono text-xs font-bold tabular-nums dark:bg-blue-900/60" style="min-width:4.2em" data-w-elapsed>0:00</span>'
                    + '<span class="max-w-[90%] text-center text-xs font-semibold" data-w-text>Воркер работает…</span>';
                viewer.appendChild(wait);
            }

            function setWait(text, elapsedSec) {
                if (!wait) return;
                wait.classList.remove('hidden');
                var wt = wait.querySelector('[data-w-text]'),
                    we = wait.querySelector('[data-w-elapsed]');
                if (wt && text) wt.textContent = text;
                if (we && elapsedSec != null) we.textContent = fmtTimer(elapsedSec);
            }

            function hideWait() { if (wait) wait.classList.add('hidden'); }

            // Таймер постоянной ШИРИНЫ: формат всегда М:СС, табличные цифры,
            // фикс. min-width и выравнивание вправо — соседний текст не «прыгает»,
            // когда меняется число символов (0:59 -> 1:00).
            bElapsed.classList.add('tabular-nums', 'text-right');
            bElapsed.style.minWidth = '4.2em';
            bElapsed.style.whiteSpace = 'nowrap';
            bElapsed.textContent = '0:00';

            function fmtTimer(sec) {
                var m = Math.floor(sec / 60), s = sec % 60;
                return m + ':' + (s < 10 ? '0' : '') + s;
            }

            setInterval(function () {
                if (!bElapsed || !lastElapsedAt || elapsedFrozen) return;
                var sec = lastElapsedSec + Math.min(3, Math.round((Date.now() - lastElapsedAt) / 1000));
                bElapsed.textContent = fmtTimer(sec);
            }, 1000);

            function setProgress(text, elapsedSec) {
                if (bText) bText.textContent = text;
                if (elapsedSec !== null && elapsedSec !== undefined) {
                    lastElapsedSec = elapsedSec;
                    lastElapsedAt = Date.now();
                    elapsedFrozen = false;
                }
            }
            function freezeElapsed() { elapsedFrozen = true; }

            // автоперезагрузка максимум 1 раз в 60 с на модель — страница не «моргает»
            function canAutoReload() {
                try {
                    var key = 'm3dreload_' + code;
                    var prev = Number(sessionStorage.getItem(key) || 0);
                    if (Date.now() - prev < 60000) return false;
                    sessionStorage.setItem(key, String(Date.now()));
                    return true;
                } catch (e) { return true; }
            }

            function tick() {
                if (Date.now() - t0 > MAX_MS) {
                    freezeElapsed();
                    if (bText) bText.textContent = 'Работа длится дольше 15 минут — похоже, воркер недоступен. Обновите страницу позже или создайте модель заново.';
                    return;
                }
                fetch('/3d/api/status/' + code, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data || !data.revisions) {
                            delay = Math.min(delay * 1.5, 15000);
                            timer = setTimeout(tick, delay);
                            return;
                        }
                        var work = null, failed = null;
                        data.revisions.forEach(function (rev) {
                            if (rev.status !== 'done' && rev.status !== 'error' && rev.status !== 'clarify') work = rev;
                            if (rev.status === 'error') failed = rev;
                        });
                        if (work) {
                            idle = 0;
                            var j = work.job,
                                phase = work.status === 'coding' ? 'Пишет код OpenSCAD…'
                                    : (work.status === 'rendering' ? 'Рендерит и собирает STL…' : 'Воркер работает…');
                            if (j && j.status === 'processing') {
                                setProgress('Воркер: ' + phase, j.elapsed_sec);
                                setWait(phase, j.elapsed_sec);
                            } else if (j) {
                                setProgress('Задача в очереди — ждём свободный воркер…', j.elapsed_sec);
                                setWait('Ждём свободный воркер…', j.elapsed_sec);
                            } else {
                                setProgress('Воркер работает…', null);
                                setWait('Воркер работает…', null);
                            }
                            delay = Math.min(delay * 1.2, 12000);
                            timer = setTimeout(tick, delay);
                            return;
                        }
                        if (failed) {
                            // честный итог в баннер, затем один reload — страница
                            // покажет ревизию с ошибкой и историей
                            freezeElapsed();
                            hideWait();
                            clearTimeout(timer);
                            banner.className = 'n2j-flash n2j-flash-err mb-3 block text-xs';
                            var spin2 = banner.querySelector('[data-b-spin]');
                            if (spin2) { spin2.classList.remove('fa-spinner', 'n2j-spin'); spin2.classList.add('fa-circle-exclamation'); }
                            if (bText) bText.textContent = 'Сборка не удалась: ' + (failed.error || 'причина не указана воркером').slice(0, 300);
                            setTimeout(function () { window.location.reload(); }, 6000);
                            return;
                        }
                        idle += 1;   // работы нет: 2 подтверждения, затем один reload (не чаще 1/мин)
                        if (idle >= 2 && Date.now() - lastReload > 20000 && canAutoReload()) {
                            lastReload = Date.now();
                            window.location.reload();
                        } else {
                            timer = setTimeout(tick, 2500);
                        }
                    })
                    .catch(function () {
                        delay = Math.min(delay * 1.5, 15000);
                        timer = setTimeout(tick, delay);
                    });
            }
            timer = setTimeout(tick, 1500);
        }
    }

    /* ---------- формы nj570: защита от двойной отправки ---------- */
    document.querySelectorAll('form[action^="/3d"], form[action^="/nj570/"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"]').forEach(function (b) {
                b.disabled = true;
                b.classList.add('opacity-60');
            });
        });
    });
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form button[type="submit"]').forEach(function (b) {
            if (b.disabled) { b.disabled = false; b.classList.remove('opacity-60'); }
        });
    });
})();
</script>

<script>
/* 3D-вьюер nj570 — ПРОГРАММНЫЙ РЕНДЕР НА CANVAS 2D (без WebGL).
   Работает на любой машине: RDP, виртуалка, отключённое ускорение, блокировка GPU.
   Конвейер: парсер STL (binary/ascii) → поворот → перспектива → сортировка по
   глубине (painter) → плоское затенение Ламберта. До ~150 тыс. тр. интерактивно.
   Пока 3D не готов — показывается PNG-рендер воркера (модель видна всегда). */
(function () {
    'use strict';

    var mount = document.getElementById('m3d-viewer');
    if (!mount) return;

    var stlUrl = mount.getAttribute('data-stl') || '';
    var pngUrl = mount.getAttribute('data-png') || '';

    function setMsg(text, dark) {
        var box = document.getElementById('m3d-msg');
        if (!box) return;
        box.textContent = text || '';
        box.className = 'pointer-events-none absolute inset-x-0 bottom-0 px-3 py-1.5 text-center text-xs '
            + (text ? (dark ? 'bg-amber-950/80 text-amber-200' : 'bg-blue-950/70 text-blue-200') : '');
    }

    /* ---------- PNG-подложка: видна, пока 3D не нарисовал первый кадр ---------- */
    var poster = null;
    if (pngUrl) {
        poster = document.createElement('img');
        poster.src = pngUrl;
        poster.alt = 'рендер модели';
        poster.className = 'absolute inset-0 m-auto max-h-full max-w-full object-contain p-3';
        mount.appendChild(poster);
        setMsg(stlUrl ? 'Показываю рендер; включаю интерактивный 3D…' : 'Рендер модели (STL ещё не собран).');
    } else if (!stlUrl) {
        var ph = document.createElement('div');
        ph.className = 'pointer-events-none flex h-full items-center justify-center px-6 text-center text-xs text-gray-500';
        ph.textContent = 'Модель появится здесь после первой генерации.';
        mount.appendChild(ph);
    }

    /* ---------- парсер STL (binary + ascii) → { pos: Float32Array, tris: N } ---------- */
    function parseSTL(buffer) {
        var reader = new DataView(buffer);
        var nFaces = reader.getUint32(80, true);
        var expect = 84 + nFaces * 50;
        if (buffer.byteLength === expect || (nFaces > 0 && nFaces < 5e6 && buffer.byteLength >= expect)) {
            var pos = new Float32Array(nFaces * 9);
            var o = 0;
            for (var f = 0; f < nFaces; f++) {
                var p = 84 + f * 50 + 12;
                for (var v = 0; v < 9; v++) pos[o++] = reader.getFloat32(p + v * 4, true);
            }
            return { pos: pos, tris: nFaces };
        }
        // ascii
        var text = new TextDecoder().decode(buffer);
        var re = /vertex\s+([-\d.eE+]+)\s+([-\d.eE+]+)\s+([-\d.eE+]+)/g, m;
        var arr = [];
        while ((m = re.exec(text)) !== null) arr.push(+m[1], +m[2], +m[3]);
        return { pos: new Float32Array(arr), tris: arr.length / 9 };
    }

    /* ---------- состояние вьюера ---------- */
    var canvas = document.createElement('canvas');
    canvas.style.display = 'none';
    canvas.style.position = 'absolute';
    canvas.style.inset = '0';
    canvas.style.touchAction = 'none';
    canvas.className = 'cursor-grab';
    mount.appendChild(canvas);
    var ctx2d = canvas.getContext('2d');

    var tris = null;            // Float32Array 0:x1 y1 z1 x2.. (центрированные, нормированные)
    var triCount = 0;

    /* ---------- настройки вьюера — в куках (вращение/каркас/вид PNG) ---------- */
    function uiCookie() {
        var m = document.cookie.match(/(?:^|;\s*)m3dui=([^;]*)/);
        if (!m) return {};
        try { var v = JSON.parse(decodeURIComponent(m[1])); return v && typeof v === 'object' ? v : {}; }
        catch (e) { return {}; }
    }
    function saveUiCookie(sp, wr, pv) {
        document.cookie = 'm3dui=' + encodeURIComponent(JSON.stringify({ s: sp ? 1 : 0, w: wr ? 1 : 0, p: pv ? 1 : 0 }))
            + '; path=/; max-age=31536000; SameSite=Lax';
    }
    var savedUi = uiCookie();
    var spinning = savedUi.s === undefined ? true : !!savedUi.s;
    var wire = !!savedUi.w;
    var pngView = !!savedUi.p && !!pngUrl;   // предпочитаемый вид: PNG-рендер вместо 3D
    var matMode = 'plastic', lightMode = 'studio';
    var baseRGB = [96, 165, 250];            // #60a5fa
    var rotX = -0.45, rotY = 0.65, dist = 2.4;
    var ready = false;

    function resize() {
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var w = mount.clientWidth || 640, h = mount.clientHeight || 320;
        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(h * dpr);
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';
        ctx2d.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    window.addEventListener('resize', function () { resize(); if (ready) draw(); });

    function loadGeometry(buf) {
        var g = parseSTL(buf);
        if (!g.tris) throw new Error('пустая геометрия');

        // центрирование + нормировка в единичный куб
        var p = g.pos, minx = 1e30, miny = 1e30, minz = 1e30, maxx = -1e30, maxy = -1e30, maxz = -1e30;
        for (var i = 0; i < p.length; i += 3) {
            if (!isFinite(p[i]) || !isFinite(p[i+1]) || !isFinite(p[i+2])) throw new Error('битые координаты в STL');
            if (p[i] < minx) minx = p[i]; if (p[i] > maxx) maxx = p[i];
            if (p[i+1] < miny) miny = p[i+1]; if (p[i+1] > maxy) maxy = p[i+1];
            if (p[i+2] < minz) minz = p[i+2]; if (p[i+2] > maxz) maxz = p[i+2];
        }
        var cx = (minx + maxx) / 2, cy = (miny + maxy) / 2, cz = (minz + maxz) / 2;
        var md = Math.max(maxx - minx, maxy - miny, maxz - minz) || 1;
        var k = 1 / md;
        for (var j = 0; j < p.length; j += 3) {
            p[j] = (p[j] - cx) * k; p[j+1] = (p[j+1] - cy) * k; p[j+2] = (p[j+2] - cz) * k;
        }
        tris = p;
        triCount = g.tris;
        rotX = -0.45; rotY = 0.65; dist = 2.4;
        ready = true;

        var dims = document.getElementById('m3d-dims');
        if (dims) {
            function fmt(v) { return (Math.round(v * 10) / 10).toString().replace('.', ','); }
            dims.textContent = '≈ ' + fmt(maxx - minx) + ' × ' + fmt(maxy - miny) + ' × ' + fmt(maxz - minz) + ' мм';
        }
        resize();
        if (pngView && poster) {
            setMsg('Показан PNG-рендер воркера — кнопка «PNG» вернёт интерактивный 3D.');
        } else {
            if (poster) poster.style.display = 'none';
            canvas.style.display = 'block';
            setMsg('3D готов: ' + triCount.toLocaleString('ru-RU') + ' треугольников · крутится мышью, зум колесом'
                + (pngUrl ? ' · кнопка PNG — плоский рендер' : ''));
        }
        draw();
    }
    /* ---------- рендер: поворот → перспектива → painter → ламберт ---------- */
    var L1 = norm3(0.45, 0.75, 0.55), L2 = norm3(-0.55, -0.25, -0.7), LT = norm3(0.1, 0.95, 0.3);

    function norm3(x, y, z) { var l = Math.hypot(x, y, z) || 1; return [x/l, y/l, z/l]; }

    function draw() {
        if (!ready) return;
        var w = canvas.clientWidth, h = canvas.clientHeight;
        ctx2d.clearRect(0, 0, w, h);

        var sy = Math.sin(rotY), cy_ = Math.cos(rotY);
        var sx = Math.sin(rotX), cx_ = Math.cos(rotX);
        var focal = h * 1.1;
        var cxs = w / 2, cys = h / 2;

        // проекция всех вершин
        var n = triCount, p = tris;
        var px = new Float32Array(n * 3), py = new Float32Array(n * 3), pz = new Float32Array(n * 3);
        for (var i = 0; i < n; i++) {
            for (var v = 0; v < 3; v++) {
                var x = p[i*9 + v*3], y = p[i*9 + v*3 + 1], z = p[i*9 + v*3 + 2];
                var x1 = x * cy_ + z * sy, z1 = -x * sy + z * cy_;
                var y2 = y * cx_ - z1 * sx, z2 = y * sx + z1 * cx_;
                var s = focal / (dist + z2);
                px[i*3+v] = cxs + x1 * s;
                py[i*3+v] = cys - y2 * s;
                pz[i*3+v] = z2;
            }
        }

        // порядок отрисовки: дальние раньше
        var order = new Array(n);
        for (i = 0; i < n; i++) order[i] = i;
        if (n > 1) {
            var keys = new Float32Array(n);
            for (i = 0; i < n; i++) keys[i] = pz[i*3] + pz[i*3+1] + pz[i*3+2];
            order.sort(function (a, b) { return keys[b] - keys[a]; });
        }

        // пресеты света — как в легаси-вьюере text-to-3d.ru (студия/сверху/рассеянный)
        var amb, k1, k2, l1 = L1, l2 = L2;
        if (lightMode === 'top') { amb = 0.22; k1 = 0.95; k2 = 0.15; l1 = LT; }
        else if (lightMode === 'ambient') { amb = 0.85; k1 = 0.18; k2 = 0.0; }
        else { amb = 0.34; k1 = 0.62; k2 = 0.22; }
        ctx2d.globalAlpha = (matMode === 'glass' && !wire) ? 0.45 : 1;

        for (var t = 0; t < n; t++) {
            var idx = order[t];
            var ax = px[idx*3], ay = py[idx*3], az = pz[idx*3];
            var bx = px[idx*3+1], by = py[idx*3+1], bz = pz[idx*3+1];
            var cx2 = px[idx*3+2], cy2 = py[idx*3+2], cz2 = pz[idx*3+2];

            // нормаль грани в мировых координатах (до поворота)
            var e1x = p[idx*9+3] - p[idx*9], e1y = p[idx*9+4] - p[idx*9+1], e1z = p[idx*9+5] - p[idx*9+2];
            var e2x = p[idx*9+6] - p[idx*9], e2y = p[idx*9+7] - p[idx*9+1], e2z = p[idx*9+8] - p[idx*9+2];
            var nx = e1y * e2z - e1z * e2y, ny = e1z * e2x - e1x * e2z, nz = e1x * e2y - e1y * e2x;
            var nl = Math.hypot(nx, ny, nz) || 1;
            nx /= nl; ny /= nl; nz /= nl;

            // грань повёрнута — поворачиваем нормаль (только для света)
            var rnx = nx * cy_ + nz * sy, rnz = -nx * sy + nz * cy_;
            var rny = ny * cx_ - rnz * sx; rnz = ny * sx + rnz * cx_;

            var d1 = Math.max(0, rnx*l1[0] + rny*l1[1] + rnz*l1[2]);
            var d2 = Math.max(0, rnx*l2[0] + rny*l2[1] + rnz*l2[2]);
            var r, g, b;
            if (matMode === 'normal') {
                // «нормали»: цвет = повёрнутая нормаль (отладка геометрии, как MeshNormalMaterial)
                r = (rnx * 0.5 + 0.5) * 255; g = (rny * 0.5 + 0.5) * 255; b = (rnz * 0.5 + 0.5) * 255;
            } else {
                var lit;
                if (matMode === 'metal') lit = 0.18 + 0.8 * d1 + 0.5 * Math.pow(d1, 12);
                else lit = amb + k1 * d1 + k2 * d2;
                if (wire) lit = 0.25;
                if (lit > 1.15) lit = 1.15;
                r = baseRGB[0] * lit; g = baseRGB[1] * lit; b = baseRGB[2] * lit;
            }
            r = Math.round(r); g = Math.round(g); b = Math.round(b);

            ctx2d.beginPath();
            ctx2d.moveTo(ax, ay); ctx2d.lineTo(bx, by); ctx2d.lineTo(cx2, cy2); ctx2d.closePath();
            if (wire) {
                ctx2d.strokeStyle = 'rgb(' + r + ',' + g + ',' + b + ')';
                ctx2d.lineWidth = 1;
                ctx2d.stroke();
            } else {
                ctx2d.fillStyle = 'rgb(' + r + ',' + g + ',' + b + ')';
                ctx2d.strokeStyle = ctx2d.fillStyle;   // обводка тем же цветом убирает щели
                ctx2d.lineWidth = 0.8;
                ctx2d.fill();
                ctx2d.stroke();
            }
        }

        // подпись в углу
        ctx2d.fillStyle = 'rgba(148,163,184,.9)';
        ctx2d.font = '10px monospace';
        ctx2d.fillText(wire ? 'каркас' : 'затенение', 8, h - 8);
    }

    (function loop() {
        requestAnimationFrame(loop);
        if (ready && spinning && !document.hidden) { rotY += 0.012; draw(); }
    })();

    /* ---------- управление ---------- */
    var dragging = false, pxs = 0, pys = 0;
    canvas.addEventListener('pointerdown', function (e) { dragging = true; pxs = e.clientX; pys = e.clientY; canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener('pointerup', function () { dragging = false; });
    canvas.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        rotY += (e.clientX - pxs) * 0.008;
        rotX = Math.max(-1.45, Math.min(1.45, rotX + (e.clientY - pys) * 0.008));
        pxs = e.clientX; pys = e.clientY;
        if (ready) draw();
    });
    canvas.addEventListener('wheel', function (e) {
        e.preventDefault();
        dist = Math.max(0.9, Math.min(12, dist * (e.deltaY > 0 ? 1.1 : 0.9)));
        if (ready) draw();
    }, { passive: false });

    function syncButtons() {
        document.querySelectorAll('[data-m3d-act]').forEach(function (btn) {
            var act = btn.getAttribute('data-m3d-act');
            var on = act === 'spin' ? spinning : (act === 'wire' ? wire : (act === 'png' ? pngView : null));
            if (on === null) return;
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }
    syncButtons();   // кнопки сразу показывают сохранённый в куках выбор

    /* ---------- материал / свет / цвет — селекторы как в легаси-вьюере, память в IndexedDB ---------- */
    function applyControls() {
        var elM = document.getElementById('view-material');
        var elL = document.getElementById('view-light');
        var elC = document.getElementById('view-color');
        if (elM) matMode = elM.value;
        if (elL) lightMode = elL.value;
        if (elC) {
            var m = /^#?([0-9a-f]{6})$/i.exec(elC.value);
            if (m) baseRGB = [parseInt(m[1].substr(0, 2), 16), parseInt(m[1].substr(2, 2), 16), parseInt(m[1].substr(4, 2), 16)];
        }
        if (ready) draw();
    }
    [['view-material', 'view_material'], ['view-light', 'view_light'], ['view-color', 'view_color']].forEach(function (pair) {
        var el = document.getElementById(pair[0]);
        if (!el) return;
        if (window.m3dIDB) {
            window.m3dIDB.get('settings', pair[1]).then(function (v) {
                if (v !== undefined && v !== null) el.value = v;
                applyControls();
            });
        }
        el.addEventListener('change', function () {
            applyControls();
            if (window.m3dIDB) window.m3dIDB.putKV('settings', pair[1], el.value);
        });
    });

    document.querySelectorAll('[data-m3d-act]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var act = btn.getAttribute('data-m3d-act');
            if (act === 'reset') {
                rotX = -0.45; rotY = 0.65; dist = 2.4;
                if (ready) draw();
            } else if (act === 'wire') {
                wire = !wire;
                saveUiCookie(spinning, wire, pngView);
                syncButtons();
                if (ready) draw();
            } else if (act === 'spin') {
                spinning = !spinning;
                saveUiCookie(spinning, wire, pngView);
                syncButtons();
            } else if (act === 'png') {
                if (!pngUrl || !poster) return;
                pngView = !pngView;
                poster.style.display = pngView ? 'block' : 'none';
                canvas.style.display = (pngView || !ready) ? 'none' : 'block';
                saveUiCookie(spinning, wire, pngView);
                syncButtons();
                if (pngView) setMsg('Показан PNG-рендер воркера — кнопка «PNG» вернёт интерактивный 3D.');
                else if (ready) setMsg('3D готов: ' + triCount.toLocaleString('ru-RU') + ' треугольников · крутится мышью, зум колесом');
            }
        });
    });

    /* ---------- загрузка STL ---------- */
    function loadSTL(url) {
        if (!url) return;
        setMsg('Загрузка модели…');
        fetch(url).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.arrayBuffer();
        }).then(function (buf) {
            try {
                loadGeometry(buf);
            } catch (e) {
                setMsg('STL разобрать не удалось: ' + (e && e.message ? e.message : e)
                     + (pngUrl ? ' — показан PNG-рендер.' : ''), true);
            }
        }).catch(function (e) {
            setMsg('STL не скачался (' + url + '): ' + (e && e.message ? e.message : e), true);
        });
    }
    window.m3dLoadSTL = loadSTL;

    if (stlUrl) loadSTL(stlUrl);
})();
</script>
