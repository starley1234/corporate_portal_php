<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: «Как выглядит» — интерактивный просмотр детали.
 *
 * Владелец (27.09.2026):
 *   «выбор режима вращения запоминай в браузере ДЛЯ всего сайта» — режим
 *   (общий вид / спереди / сверху / сбоку, вращение, приближение) хранится под
 *   ОДНИМ ключом `n2j:cad3d:mode` и применяется к любой детали: выбрал «Сверху»
 *   один раз — все детали открываются сверху;
 *   «ракурсы не показывай» — лента PNG-ракурсов движка со страницы детали убрана.
 *
 * Рисует браузер сам, на Canvas 2D: разбираем STL (бинарный или ASCII) и выводим
 * треугольники с сортировкой по глубине и плоской заливкой по нормалям. Ни WebGL,
 * ни three.js, ни CDN — у владельца WebGL-сцена (nj570) давала пустой кадр.
 *
 * Ожидает: $models  — файлы step/stl/glb выбранной ревизии;
 *          $code    — код детали (для миграции старой памяти режима);
 *          $rev     — выбранная ревизия (для той же миграции);
 *          $card    — класс карточки;
 *          $metrics — необязательные метрики ([[label, value], …]) под холстом.
 */
$models  = isset($models) ? (array) $models : [];
$code    = isset($code) ? (string) $code : '';
$rev     = isset($rev) ? (int) $rev : 0;
$metrics = (isset($metrics) AND is_array($metrics)) ? $metrics : [];
$card    = isset($card) ? (string) $card : 'rounded-xl border border-gray-200 bg-white p-3.5 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';

$stl = NULL;
$glb = NULL;

foreach ($models as $f)
{
    $kind = strtolower((string) ($f['kind'] ?? ''));

    if ($kind === 'stl' AND $stl === NULL)
    {
        $stl = $f;
    }

    if ($kind === 'glb' AND $glb === NULL)
    {
        $glb = $f;
    }
}

$keys = ['iso' => 'Общий вид', 'front' => 'Спереди', 'top' => 'Сверху', 'right' => 'Сбоку'];

$btn    = 'n2j-chip';
$btn_on = 'n2j-chip is-on';
?>
<section class="<?php echo $card; ?>" data-nj643-3d
         <?php if ($stl !== NULL): ?>data-src="/cad/file/<?php echo (int) $stl['id']; ?>"
         data-name="<?php echo HTML::chars((string) (($stl['name'] ?? '') !== '' ? $stl['name'] : 'модель.stl')); ?>"
         data-bytes="<?php echo (int) $stl['size']; ?>"<?php endif; ?>
         data-legacy="n2j:cad3d:<?php echo HTML::chars($code); ?>:r<?php echo $rev; ?>">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
        <h2 class="n2j-h3">Как выглядит</h2>

        <?php if ($stl !== NULL): ?>
        <div class="flex flex-wrap items-center gap-1" role="group" aria-label="Ракурс">
            <?php foreach ($keys as $k => $label): ?>
            <button type="button" class="<?php echo $k === 'iso' ? $btn_on : $btn; ?>" data-nj643-3d-view="<?php echo $k; ?>" title="Повернуть: <?php echo HTML::chars($label); ?>"><?php echo HTML::chars($label); ?></button>
            <?php endforeach; ?>
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-1">
            <button type="button" class="<?php echo $btn; ?>" data-nj643-3d-spin aria-pressed="true" title="Постоянное вращение — щёлкните, чтобы остановить">
                <i class="fas fa-arrows-rotate text-[11px]"></i>Вращение
            </button>
            <a href="/cad/file/<?php echo (int) $stl['id']; ?>?download=1" class="n2j-btn-secondary n2j-btn-sm" title="Скачать STL для 3D-печати">
                <i class="fas fa-download text-[11px]"></i>STL
            </a>
            <?php if ($glb !== NULL): ?>
            <a href="/cad/file/<?php echo (int) $glb['id']; ?>?download=1" class="n2j-btn-secondary n2j-btn-sm" title="Скачать GLB для своей программы">
                <i class="fas fa-download text-[11px]"></i>GLB
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($stl === NULL): ?>
    <p class="mt-2 rounded-lg border border-dashed border-gray-200 px-3 py-5 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        У этой ревизии нет STL — модель либо ещё собирается, либо сборка не удалась. Файлы — справа.
    </p>
    <?php else: ?>
    <div class="relative mt-2 overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
        <canvas class="block max-h-[300px] w-full cursor-grab touch-none select-none active:cursor-grabbing" data-nj643-3d-canvas
                style="aspect-ratio: 16 / 10;"></canvas>
        <p class="pointer-events-none absolute left-2.5 top-2.5 rounded-md bg-white/85 px-2 py-0.5 text-sm font-semibold text-gray-600 shadow-xs backdrop-blur dark:bg-gray-800/85 dark:text-gray-200"
           data-nj643-3d-info>Загружаю модель…</p>
    </div>
    <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">Тяните мышью — повернётся, колесо — приблизит, двойной клик — общий вид. Режим помнится для всего сайта.</p>
    <?php endif; ?>

    <?php if ($metrics): ?>
    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <?php foreach ($metrics as $m): ?>
        <div class="rounded-lg border border-gray-100 px-2.5 py-1 dark:border-gray-700/60">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo HTML::chars((string) $m[0]); ?></p>
            <p class="truncate text-sm font-bold text-gray-900 dark:text-white" title="<?php echo HTML::chars((string) $m[1]); ?>"><?php echo HTML::chars((string) $m[1]); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<script>
/* Просмотр STL на Canvas 2D + память режима на весь сайт.
   Зависимостей нет намеренно — только то, что гарантированно есть в браузере.
   Модель в CAD строится «Z вверх»: камера вращается вокруг Z (yaw), наклон —
   вокруг X (pitch), поэтому «Сверху» смотрит на XY, «Спереди» — на XZ.

   Ключ режима один на весь сайт (n2j:cad3d:mode) — требование владельца: выбрал
   ракурс у одной детали, он же применяется у остальных. Старый ключ
   n2j:cad3d:<код>:r<ревизия> (память у каждой ревизии) читается один раз как
   запасной, чтобы у владельца не сбросился уже выбранный режим. */
(function () {
    'use strict';

    var box = document.querySelector('[data-nj643-3d]');

    if (!box || box.getAttribute('data-nj643-3d-bound')) {
        return;
    }

    box.setAttribute('data-nj643-3d-bound', '1');

    var canvas = box.querySelector('[data-nj643-3d-canvas]');
    var src = box.getAttribute('data-src');
    var legacy = box.getAttribute('data-legacy') || '';
    var bytes = parseInt(box.getAttribute('data-bytes') || '0', 10) || 0;
    var STORE = 'n2j:cad3d:mode';

    var VIEWS = {
        iso:   { yaw: -0.7, pitch: -0.62, zoom: 1 },
        front: { yaw: 0, pitch: -Math.PI / 2, zoom: 1 },
        top:   { yaw: 0, pitch: 0, zoom: 1 },
        right: { yaw: -Math.PI / 2, pitch: -Math.PI / 2, zoom: 1 }
    };

    /* --- память браузера: режим один на весь сайт ---------------------- */
    function readKey(key) {
        if (!key || !window.localStorage) {
            return null;
        }

        try {
            var raw = window.localStorage.getItem(key);

            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function load() {
        var site = readKey(STORE);

        if (site) {
            return site;
        }

        // Переезд со старой памяти «у каждой ревизии свой режим»
        var old = readKey(legacy);

        if (old) {
            save(old);
        }

        return old;
    }

    function save(data) {
        if (!window.localStorage) {
            return;
        }

        try {
            window.localStorage.setItem(STORE, JSON.stringify(data || {
                view: run.view, yaw: run.yaw, pitch: run.pitch, zoom: run.zoom, spin: run.spin
            }));

            if (legacy) {
                window.localStorage.removeItem(legacy);
            }
        } catch (e) {
            /* приватный режим/переполнение — не мешаем смотреть */
        }
    }

    var saved = load();
    var run = {
        view: saved && saved.view && VIEWS[saved.view] ? saved.view : 'iso',
        yaw: saved && typeof saved.yaw === 'number' ? saved.yaw : VIEWS.iso.yaw,
        pitch: saved && typeof saved.pitch === 'number' ? saved.pitch : VIEWS.iso.pitch,
        zoom: saved && typeof saved.zoom === 'number' ? saved.zoom : 1,
        spin: saved ? saved.spin !== false : true,
        dragging: false,
        visible: true
    };

    // 24 оттенка: заливка одним цветом на грань (плоский свет), без градиентов.
    var SHADES = (function () {
        var out = [];

        for (var i = 0; i < 24; i++) {
            var k = 0.34 + 0.66 * (i / 23);
            out.push('rgb(' + Math.round(96 * k + 26) + ',' + Math.round(128 * k + 26) + ',' + Math.round(176 * k + 30) + ')');
        }

        return out;
    })();

    var LIGHT = [-0.42, 0.5, 0.75];
    var geo = null;
    var rot = null;
    var raf = 0;
    var lastTs = 0;
    var w = 0, h = 0;
    var ctx = null;
    var info = box.querySelector('[data-nj643-3d-info]');
    var spinBtn = box.querySelector('[data-nj643-3d-spin]');

    function say(text) {
        if (info) {
            info.textContent = text;
        }
    }

    function fmtSize(b) {
        return b >= 1048576 ? (b / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ';
    }

    function fmtInt(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function markView(name) {
        run.view = name;

        var btns = box.querySelectorAll('[data-nj643-3d-view]');

        for (var i = 0; i < btns.length; i++) {
            var on = btns[i].getAttribute('data-nj643-3d-view') === name;
            btns[i].className = 'n2j-chip' + (on ? ' is-on' : '');
        }
    }

    function markSpin() {
        if (spinBtn) {
            spinBtn.setAttribute('aria-pressed', run.spin ? 'true' : 'false');
        }
    }

    /* Показываем выбранный режим сразу (даже до загрузки файла) — требование
       владельца: «при обновлении страницы применяй». */
    markView(run.view);
    markSpin();

    if (!canvas || !src) {
        say('Модель не загружена');
        return;
    }

    ctx = canvas.getContext('2d');

    /* --- разбор STL: бинарный (84 + 50·n байт) или ASCII --- */
    function parseSTL(data) {
        var dv = new DataView(data);
        var count = data.byteLength >= 84 ? dv.getUint32(80, true) : 0;
        var i, v;

        if (count > 0 && 84 + count * 50 === data.byteLength) {
            var bin = new Float32Array(count * 9);
            var off = 84;

            for (i = 0; i < count; i++) {
                for (v = 0; v < 3; v++) {
                    var b = off + 12 + v * 12;
                    bin[i * 9 + v * 3]     = dv.getFloat32(b, true);
                    bin[i * 9 + v * 3 + 1] = dv.getFloat32(b + 4, true);
                    bin[i * 9 + v * 3 + 2] = dv.getFloat32(b + 8, true);
                }

                off += 50;
            }

            return bin;
        }

        var text = new TextDecoder('utf-8').decode(data);
        var re = /vertex\s+(-?[\d.]+(?:[eE][-+]?\d+)?)\s+(-?[\d.]+(?:[eE][-+]?\d+)?)\s+(-?[\d.]+(?:[eE][-+]?\d+)?)/g;
        var out = [];
        var m;

        while ((m = re.exec(text)) !== null) {
            out.push(parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3]));
        }

        var n = out.length - (out.length % 9);

        if (n < 9) {
            return null;
        }

        var arr = new Float32Array(n);
        arr.set(out.slice(0, n));

        return arr;
    }

    /* --- габарит, центр, радиус, нормали граней --- */
    function prepare(pos) {
        var n = pos.length / 9;
        var mn = [Infinity, Infinity, Infinity];
        var mx = [-Infinity, -Infinity, -Infinity];
        var i, k;

        for (i = 0; i < pos.length; i += 3) {
            for (k = 0; k < 3; k++) {
                if (pos[i + k] < mn[k]) { mn[k] = pos[i + k]; }
                if (pos[i + k] > mx[k]) { mx[k] = pos[i + k]; }
            }
        }

        var nrm = new Float32Array(n * 3);

        for (var t = 0; t < n; t++) {
            var a = t * 9;
            var ux = pos[a + 3] - pos[a], uy = pos[a + 4] - pos[a + 1], uz = pos[a + 5] - pos[a + 2];
            var vx = pos[a + 6] - pos[a], vy = pos[a + 7] - pos[a + 1], vz = pos[a + 8] - pos[a + 2];
            var nx = uy * vz - uz * vy, ny = uz * vx - ux * vz, nz = ux * vy - uy * vx;
            var len = Math.sqrt(nx * nx + ny * ny + nz * nz) || 1;
            nrm[t * 3] = nx / len;
            nrm[t * 3 + 1] = ny / len;
            nrm[t * 3 + 2] = nz / len;
        }

        geo = {
            pos: pos,
            nrm: nrm,
            n: n,
            center: [(mn[0] + mx[0]) / 2, (mn[1] + mx[1]) / 2, (mn[2] + mx[2]) / 2],
            // Радиус ОПИСАННОЙ сферы, а не половина наибольшей стороны: у куба
            // и деталей «с углами» диагональ больше стороны, и углы вылезали за кадр.
            radius: Math.max(1e-6, 0.5 * Math.sqrt(
                Math.pow(mx[0] - mn[0], 2) + Math.pow(mx[1] - mn[1], 2) + Math.pow(mx[2] - mn[2], 2)))
        };
        // 9 чисел на грань (3 вершины × 3 координаты) — как в pos, не n*3
        rot = { x: new Float32Array(n * 9), y: new Float32Array(n * 9), z: new Float32Array(n * 9) };
    }

    function fit() {
        var dpr = Math.min(2, window.devicePixelRatio || 1);
        var cw = Math.max(120, canvas.clientWidth | 0);
        var ch = Math.max(120, canvas.clientHeight | 0);

        if (canvas.width === (cw * dpr | 0) && canvas.height === (ch * dpr | 0)) {
            return false;
        }

        canvas.width = cw * dpr | 0;
        canvas.height = ch * dpr | 0;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        w = cw;
        h = ch;

        return true;
    }

    function draw() {
        if (!geo) {
            return;
        }

        fit();
        ctx.clearRect(0, 0, w, h);

        var n = geo.n;
        var limit = run.dragging ? 26000 : 90000;
        var stride = Math.max(1, Math.ceil(n / limit));
        var shown = 0;
        var cy = Math.cos(run.yaw), sy = Math.sin(run.yaw);
        var cp = Math.cos(run.pitch), sp = Math.sin(run.pitch);
        var pos = geo.pos, nrm = geo.nrm, center = geo.center;
        var camDist = geo.radius * 3.4;
        var halfW = w / 2, halfH = h / 2;
        var px = rot.x, py = rot.y, pz = rot.z;
        var maxA = 0, maxB = 0, fv;
        var order = [];
        var depth = new Float32Array(Math.ceil(n / stride));
        var t, a, j;

        // 1) поворот вокруг Z (yaw), наклон вокруг X (pitch), затем перспектива
        for (t = 0; t < n; t += stride) {
            a = t * 9;

            for (j = 0; j < 3; j++) {
                var x = pos[a + j * 3] - center[0];
                var y = pos[a + j * 3 + 1] - center[1];
                var z = pos[a + j * 3 + 2] - center[2];
                var x1 = cy * x - sy * y;
                var y1 = sy * x + cy * y;
                // Индекс тот же, что у вершины в pos: 9 чисел на грань (3×3).
                var at = t * 9 + j * 3;
                px[at] = x1;
                py[at] = cp * y1 - sp * z;
                pz[at] = sp * y1 + cp * z;

                // Заодно измеряем, сколько места деталь занимает в кадре С УЧЁТОМ
                // перспективы: масштаб подбираем по факту, поэтому и шар, и куб, и
                // любая повёрнутая деталь вписываются всегда (и не мелкие).
                fv = camDist / Math.max(1e-6, camDist - pz[at]);
                if (Math.abs(x1) * fv > maxA) { maxA = Math.abs(x1) * fv; }
                if (Math.abs(cp * y1 - sp * z) * fv > maxB) { maxB = Math.abs(cp * y1 - sp * z) * fv; }
            }
        }

        var scale = 0.9 * run.zoom * Math.min(halfW / Math.max(1e-6, maxA), halfH / Math.max(1e-6, maxB));

        // 2) порядок «дальние первыми»: своего zbuffer нет, сортируем по глубине
        for (t = 0; t < n; t += stride) {
            a = t * 9;
            depth[shown] = (pz[a] + pz[a + 3] + pz[a + 6]) / 3;
            order.push(shown);
            shown++;
        }

        order.sort(function (a1, b1) { return depth[a1] - depth[b1]; });

        var edges = shown <= 14000 && !run.dragging;
        var idx, x0, y0, x1s, y1s, x2, y2, f;

        for (var s = 0; s < order.length; s++) {
            idx = order[s];
            t = idx * stride;
            a = t * 9;

            var nx = nrm[t * 3], ny = nrm[t * 3 + 1], nz = nrm[t * 3 + 2];
            var rx = cy * nx - sy * ny;
            var ry1 = sy * nx + cy * ny;
            var ry = cp * ry1 - sp * nz;
            var rz = sp * ry1 + cp * nz;
            var level = Math.min(23, Math.max(0, (Math.abs(rx * LIGHT[0] + ry * LIGHT[1] + rz * LIGHT[2]) * 27) | 0));

            f = camDist / Math.max(1e-6, camDist - pz[a]);
            x0 = halfW + px[a] * f * scale;
            y0 = halfH - py[a] * f * scale;
            f = camDist / Math.max(1e-6, camDist - pz[a + 3]);
            x1s = halfW + px[a + 3] * f * scale;
            y1s = halfH - py[a + 3] * f * scale;
            f = camDist / Math.max(1e-6, camDist - pz[a + 6]);
            x2 = halfW + px[a + 6] * f * scale;
            y2 = halfH - py[a + 6] * f * scale;

            ctx.fillStyle = SHADES[level];
            ctx.beginPath();
            ctx.moveTo(x0, y0);
            ctx.lineTo(x1s, y1s);
            ctx.lineTo(x2, y2);
            ctx.closePath();
            ctx.fill();

            if (edges) {
                ctx.strokeStyle = 'rgba(15,23,42,0.16)';
                ctx.lineWidth = 0.5;
                ctx.stroke();
            }
        }

        say('Граней: ' + fmtInt(shown) + (stride > 1 ? ' из ' + fmtInt(n) : '') + ' · ' + fmtSize(bytes));
    }

    function tick(ts) {
        raf = 0;

        if (!run.visible) {
            return;
        }

        var dt = lastTs ? Math.min(0.1, (ts - lastTs) / 1000) : 0;
        lastTs = ts;

        if (run.spin && !run.dragging) {
            run.yaw += 0.22 * dt;
            draw();
        }

        if (run.spin || run.dragging) {
            raf = window.requestAnimationFrame(tick);
        } else {
            lastTs = 0;
        }
    }

    function wake() {
        if (!raf && !document.hidden && run.visible) {
            raf = window.requestAnimationFrame(tick);
        }
    }

    function setView(name) {
        var v = VIEWS[name] || VIEWS.iso;

        run.yaw = v.yaw;
        run.pitch = v.pitch;
        run.zoom = 1;
        markView(name);
        save();
        draw();
        wake();
    }

    /* --- мышь, палец, колесо --- */
    var pt = null;
    var pinch = null;

    canvas.addEventListener('pointerdown', function (e) {
        if (canvas.setPointerCapture) {
            canvas.setPointerCapture(e.pointerId);
        }

        pt = { x: e.clientX, y: e.clientY, id: e.pointerId };
        run.dragging = true;
        wake();
    });

    canvas.addEventListener('pointermove', function (e) {
        if (!pt || pt.id !== e.pointerId) {
            return;
        }

        run.yaw += (e.clientX - pt.x) * 0.012;
        run.pitch = Math.max(-Math.PI / 2, Math.min(0, run.pitch - (e.clientY - pt.y) * 0.012));
        pt.x = e.clientX;
        pt.y = e.clientY;
        draw();
        wake();
    });

    function release(e) {
        if (pt && pt.id === e.pointerId) {
            pt = null;
            run.dragging = false;
            run.view = '';
            markView('');
            save();
            draw();
            wake();
        }
    }

    canvas.addEventListener('pointerup', release);
    canvas.addEventListener('pointercancel', release);

    canvas.addEventListener('wheel', function (e) {
        e.preventDefault();
        run.zoom = Math.max(0.35, Math.min(6, run.zoom * Math.exp(-e.deltaY * 0.0014)));
        save();
        draw();
    }, { passive: false });

    canvas.addEventListener('touchmove', function (e) {
        if (e.touches.length !== 2) {
            return;
        }

        e.preventDefault();

        var dx = e.touches[0].clientX - e.touches[1].clientX;
        var dy = e.touches[0].clientY - e.touches[1].clientY;
        var dist = Math.sqrt(dx * dx + dy * dy);

        if (pinch) {
            run.zoom = Math.max(0.35, Math.min(6, run.zoom * (dist / pinch)));
            save();
        }

        pinch = dist;
        draw();
    }, { passive: false });

    canvas.addEventListener('touchend', function () { pinch = null; });
    canvas.addEventListener('dblclick', function () { setView('iso'); });

    box.addEventListener('click', function (e) {
        if (!e.target.closest) {
            return;
        }

        var viewBtn = e.target.closest('[data-nj643-3d-view]');

        if (viewBtn) {
            setView(viewBtn.getAttribute('data-nj643-3d-view'));
            return;
        }

        if (e.target.closest('[data-nj643-3d-spin]')) {
            run.spin = !run.spin;
            markSpin();
            save();
            lastTs = 0;
            wake();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden && raf) {
            window.cancelAnimationFrame(raf);
            raf = 0;
            lastTs = 0;
        } else {
            wake();
        }
    });

    if (window.IntersectionObserver) {
        new IntersectionObserver(function (entries) {
            run.visible = entries[0].isIntersecting;

            if (run.visible) {
                wake();
            } else if (raf) {
                window.cancelAnimationFrame(raf);
                raf = 0;
                lastTs = 0;
            }
        }, { threshold: 0.05 }).observe(canvas);
    }

    window.addEventListener('resize', function () {
        if (fit()) {
            draw();
        }
    });

    /* --- загрузка модели --- */
    fetch(src, { credentials: 'same-origin' })
        .then(function (r) {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }

            return r.arrayBuffer();
        })
        .then(function (data) {
            var pos = parseSTL(data);

            if (!pos) {
                throw new Error('не STL');
            }

            prepare(pos);
            draw();
            wake();
        })
        .catch(function () {
            say('Файл модели не прочитался — скачайте STL справа');
        });
})();
</script>
