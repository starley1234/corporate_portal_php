#!/usr/bin/env node
/* =============================================================================
 * N2J — проверка «Как выглядит» (набор view/643/viewer.php) без браузера.
 *
 *   node .tools/viewer_smoke.mjs
 *
 * Зачем: рендер живёт в <script> внутри PHP-вьюхи, его не покрывает ни PHP-линт,
 * ни pytest. Живые грабли 27.09.2026: сфера рисовалась двумя треугольниками
 * (вершины писались с индексом t*3+j, а читались как t*9+j*3), а масштаб не
 * учитывал перспективу. Такое видно только на картинке, поэтому здесь скрипт
 * вьюхи запускается в Node с поддельным DOM/Canvas и localStorage: считаем
 * нарисованные полигоны, проверяем конечность координат, вписанность в кадр,
 * разбор бинарного и ASCII STL и ЗАПОМИНАНИЕ РЕЖИМА в браузере — причём память
 * ОДНА НА ВЕСЬ САЙТ (ключ n2j:cad3d:mode): выбрал ракурс у одной детали — он же
 * применяется у остальных (владелец, 27.09.2026).
 *
 * Зависимостей нет — только Node 18+ (TextDecoder).
 * ========================================================================== */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const VIEWER = path.join(ROOT, 'application/modules/nj643_cad/views/643/viewer.php');
const W = 640, H = 400;

/* ---------- сборка STL (бинарный и ASCII) ---------- */
function binarySTL(tris) {
    const buf = Buffer.alloc(84 + tris.length * 50);
    buf.writeUInt32LE(tris.length, 80);
    let off = 84;

    for (const t of tris) {
        for (let n = 0; n < 3; n++) { buf.writeFloatLE(0, off); off += 4; }   // нормаль грани
        for (const v of t) for (const k of v) { buf.writeFloatLE(k, off); off += 4; }
        off += 2;                                                            // атрибут
    }

    return buf;
}

function sphere(R = 20, seg = 48, rings = 24) {
    const tris = [];
    const p = (i, j) => {
        const th = (i / seg) * Math.PI * 2, ph = (j / rings) * Math.PI;
        return [R * Math.sin(ph) * Math.cos(th), R * Math.sin(ph) * Math.sin(th), R * Math.cos(ph)];
    };

    for (let j = 0; j < rings; j++) for (let i = 0; i < seg; i++) {
        const a = p(i, j), b = p(i + 1, j), c = p(i + 1, j + 1), d = p(i, j + 1);
        tris.push([a, b, c], [a, c, d]);
    }

    return tris;
}

function cube(s = 20) {
    const h = s / 2;
    const v = [[-h,-h,-h],[h,-h,-h],[h,h,-h],[-h,h,-h],[-h,-h,h],[h,-h,h],[h,h,h],[-h,h,h]];
    const f = [[0,1,2],[0,2,3],[4,6,5],[4,7,6],[0,4,5],[0,5,1],[1,5,6],[1,6,2],[2,6,7],[2,7,3],[3,7,4],[3,4,0]];

    return f.map((t) => t.map((i) => v[i]));
}

function asciiSTL(tris) {
    let out = 'solid part\n';

    for (const t of tris) {
        out += 'facet normal 0 0 1\n  outer loop\n';

        for (const v of t) out += `    vertex ${v[0]} ${v[1]} ${v[2]}\n`;

        out += '  endloop\nendfacet\n';
    }

    return Buffer.from(out + 'endsolid part\n');
}

/* ---------- «браузер»: DOM + Canvas + localStorage ---------- */
function makeStorage() {
    const map = new Map();

    return {
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => map.set(k, String(v)),
        removeItem: (k) => map.delete(k),
        _dump: () => Object.fromEntries(map),
    };
}

function open(stl, { storage = makeStorage(), press = 'iso', drag = null, legacy = 'n2j:cad3d:cad12345678:r1',
                     hasStl = true, canvasW = W, canvasH = H } = {}) {
    const php = fs.readFileSync(VIEWER, 'utf8');
    const scripts = [...php.matchAll(/<script>([\s\S]*?)<\/script>/g)];

    if (!scripts.length) {
        throw new Error('во вьюхе нет <script>');
    }

    const code = scripts[scripts.length - 1][1];
    const polys = [];
    let cur = null, style = '#000';
    const ctx = {
        setTransform() {}, clearRect() {},
        beginPath() { cur = []; }, moveTo(x, y) { cur.push([x, y]); }, lineTo(x, y) { cur.push([x, y]); },
        closePath() {}, fill() { polys.push({ pts: cur.slice(), color: style }); }, stroke() {},
        set fillStyle(v) { style = v; }, get fillStyle() { return style; },
        set strokeStyle(v) {}, set lineWidth(v) {},
    };

    const on = {}, onBox = {};
    const canvas = {
        clientWidth: canvasW, clientHeight: canvasH, width: 0, height: 0,
        getContext: () => ctx, addEventListener(k, f) { on[k] = f; }, setPointerCapture() {},
    };
    const info = { textContent: '' };
    const spin = { setAttribute(k, v) { this[k] = v; }, getAttribute: () => 'true', className: '' };

    const viewButtons = [];

    for (let i = 0; i < 4; i++) {
        viewButtons.push({
            _name: ['iso', 'front', 'top', 'right'][i],
            className: '',
            getAttribute(n) { return n === 'data-nj643-3d-view' ? this._name : null; },
            setAttribute() {},
        });
    }

    const attrs = {
        'data-legacy': legacy,
        'data-bytes': String(hasStl ? stl.byteLength : 0),
    };

    if (hasStl) {
        attrs['data-src'] = '/cad/file/1';
        attrs['data-name'] = 'part.stl';
    }

    const box = {
        querySelector: (s) => (s.includes('canvas') ? canvas : s.includes('info') ? info : spin),
        querySelectorAll: () => viewButtons,
        getAttribute: (n) => (n in attrs ? attrs[n] : null),
        setAttribute: (n, v) => { attrs[n] = String(v); },
        addEventListener(k, f) { onBox[k] = f; },
    };

    const rafQ = [];

    globalThis.document = {
        querySelector: () => box,
        addEventListener() {},
        hidden: false,
    };
    globalThis.window = {
        devicePixelRatio: 1,
        localStorage: storage,
        requestAnimationFrame: (cb) => { rafQ.push(cb); return rafQ.length; },
        cancelAnimationFrame() {}, addEventListener() {},
    };
    globalThis.IntersectionObserver = undefined;
    globalThis.fetch = async () => {
        if (!hasStl) {
            return { ok: false, status: 404 };
        }

        return { ok: true, arrayBuffer: async () => stl.buffer.slice(stl.byteOffset, stl.byteOffset + stl.byteLength) };
    };

    new Function(code)();

    return new Promise((resolve) => setTimeout(() => {
        // closest() как в браузере: для имени режима совпадает только
        // [data-nj643-3d-view], для 'spin' — только [data-nj643-3d-spin].
        const click = (name) => {
            if (typeof onBox.click !== 'function') {
                return;
            }

            onBox.click({
                target: {
                    closest: (sel) => {
                        if (sel.indexOf('data-nj643-3d-view') !== -1) {
                            return name === 'spin' ? null : { getAttribute: () => name };
                        }

                        return (sel.indexOf('spin') !== -1 && name === 'spin') ? {} : null;
                    },
                },
            });
        };

        // Считаем только то, что нарисовано ПОСЛЕ действия: при 'asis' (без
        // действия) проверяем кадр, который дала загрузка модели.
        if (press !== 'asis' || drag) {
            polys.length = 0;
        }

        if (press === 'spin') {
            click('spin');
        } else if (press && press !== 'asis') {
            click(press);
        }

        if (drag) {
            on.pointerdown({ pointerId: 1, clientX: drag[0], clientY: drag[1] });
            on.pointermove({ pointerId: 1, clientX: drag[2], clientY: drag[3] });
            on.pointerup({ pointerId: 1 });
        }

        resolve({ polys, info: info.textContent, canvas, storage, viewButtons, click, on, box });
    }, 40));
}

/* ---------- проверки ---------- */
let failed = 0;

function check(name, ok, extra = '') {
    console.log(`${ok ? ' OK ' : 'FAIL'}  ${name}${extra ? ' — ' + extra : ''}`);

    if (!ok) failed++;
}

function frame(polys) {
    const box = [Infinity, Infinity, -Infinity, -Infinity];
    let bad = 0;

    for (const p of polys) {
        for (const [x, y] of p.pts) {
            if (!Number.isFinite(x) || !Number.isFinite(y)) bad++;
            box[0] = Math.min(box[0], x); box[1] = Math.min(box[1], y);
            box[2] = Math.max(box[2], x); box[3] = Math.max(box[3], y);
        }
    }

    return { box, bad, width: box[2] - box[0], height: box[3] - box[1] };
}

const cubeTris = cube();
const sphereTris = sphere();

{
    const { polys, info, canvas } = await open(binarySTL(cubeTris), { press: 'iso' });
    const { box, bad, width } = frame(polys);

    check('бинарный STL: 12 граней куба разобраны', polys.length === cubeTris.length, `полигонов ${polys.length}`);
    check('координаты конечны', bad === 0, `нечисловых ${bad}`);
    check('деталь целиком в кадре', box[0] > -4 && box[1] > -4 && box[2] < W + 4 && box[3] < H + 4,
        `рамка ${box.map((v) => Math.round(v)).join(',')}`);
    check('деталь занимает кадр (не точка и не впритык)', width > W * 0.4 && width < W, `ширина ${Math.round(width)} из ${W}`);
    check('холст получил физический размер', canvas.width > 0 && canvas.height > 0, `${canvas.width}×${canvas.height}`);
    check('подпись сообщает о гранях', /Граней: 12/.test(info), info);
}

{
    const { polys } = await open(binarySTL(sphereTris), { press: 'iso' });
    const { width } = frame(polys);

    check('бинарный STL: шар разобран целиком', polys.length === sphereTris.length, `полигонов ${polys.length}`);
    check('шар вписан в кадр', width > W * 0.5 && width < W, `ширина ${Math.round(width)}`);
}

{
    const { polys } = await open(asciiSTL(cubeTris), { press: 'front' });

    check('ASCII STL: грани разобраны', polys.length === cubeTris.length, `полигонов ${polys.length}`);
}

{
    const { polys } = await open(binarySTL(cubeTris), { press: 'iso', drag: [300, 200, 420, 150] });

    check('поворот мышью перерисовывает деталь',
        polys.length >= cubeTris.length && polys.length % cubeTris.length === 0, `полигонов ${polys.length}`);
}

{
    // Режим запоминается в браузере ОДИН на весь сайт и применяется при открытии
    // любой детали (владелец, 27.09.2026: «запоминай в браузере ДЛЯ всего сайта»).
    const storage = makeStorage();
    const first = await open(binarySTL(cubeTris), { storage, press: 'top' });
    const saved = storage.getItem('n2j:cad3d:mode');

    check('выбор режима сохраняется в браузере', !!saved && /"view":"top"/.test(saved), saved || 'пусто');

    const topFrame = frame(first.polys);
    const again = await open(binarySTL(cubeTris), { storage, press: 'asis' });
    const againFrame = frame(again.polys);

    check('камера восстановлена при новом открытии',
        Math.abs(againFrame.width - topFrame.width) < 1 && Math.abs(againFrame.height - topFrame.height) < 1,
        `было ${Math.round(topFrame.width)}×${Math.round(topFrame.height)}, стало ${Math.round(againFrame.width)}×${Math.round(againFrame.height)}`);
    check('кнопка выбранного режима подсвечена', again.viewButtons.some((b) => b._name === 'top' && /border-blue-400/.test(b.className)),
        again.viewButtons.map((b) => b._name + (b.className ? ':' + (/border-blue-400/.test(b.className) ? 'on' : 'off') : '')).join(' '));

    // ДРУГАЯ деталь (и другая ревизия): режим тот же — ключ один на весь сайт.
    const other = await open(binarySTL(cubeTris), { storage, press: 'asis', legacy: 'n2j:cad3d:cad99999999:r7' });
    const otherFrame = frame(other.polys);

    check('режим применяется к другой детали и ревизии',
        other.viewButtons.some((b) => b._name === 'top' && /border-blue-400/.test(b.className))
        && Math.abs(otherFrame.width - topFrame.width) < 1,
        `ширина ${Math.round(otherFrame.width)}, было ${Math.round(topFrame.width)}`);

    await open(binarySTL(cubeTris), { storage, press: 'spin' });

    check('«Вращение» тоже запоминается', /"spin":false/.test(storage.getItem('n2j:cad3d:mode')),
        storage.getItem('n2j:cad3d:mode'));

    // Переезд со старой памяти «у каждой ревизии свой режим».
    const old = makeStorage();
    old.setItem('n2j:cad3d:cad12345678:r1', JSON.stringify({ view: 'right', spin: false }));
    const migrated = await open(binarySTL(cubeTris), { storage: old, press: 'asis' });

    check('старая память режима переезжает в общий ключ',
        /"view":"right"/.test(old.getItem('n2j:cad3d:mode') || '') && old.getItem('n2j:cad3d:cad12345678:r1') === null
        && migrated.viewButtons.some((b) => b._name === 'right' && /border-blue-400/.test(b.className)),
        `общий ключ: ${old.getItem('n2j:cad3d:mode')}, старый: ${old.getItem('n2j:cad3d:cad12345678:r1')}`);
}

{
    // Тяжёлая сетка: включается прореживание, но кадр обязан остаться целым.
    const { polys } = await open(binarySTL(sphere(20, 160, 80)), { press: 'iso' });

    check('тяжёлая сетка не ломает рендер', polys.length > 20000, `полигонов ${polys.length}`);
}

{
    // Ревизия без STL (сборка упала): блок честно говорит об этом и не падает.
    const { polys, info, box } = await open(Buffer.alloc(0), { press: 'iso', hasStl: false });

    // STL нет: холста на странице тоже нет — блок обязан просто не падать.
    check('без STL блок не падает', polys.length === 0 && !!box.getAttribute('data-nj643-3d-bound'),
        `полигонов ${polys.length}, обработчик ${box.getAttribute('data-nj643-3d-bound') ? 'готов' : 'нет'}, подпись «${info}»`);
}

console.log(failed === 0 ? '\nПросмотрщик в порядке.' : `\nПроблем: ${failed}`);

process.exit(failed === 0 ? 0 : 1);
