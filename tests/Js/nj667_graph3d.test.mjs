/** NJ-667 «Графовый 3D-конструктор»: логика редактора в браузере (jsdom).
 *
 * Зачем отдельно от сервисного стенда: патчи RFC 6902, умные направляющие и
 * «второе нажатие» живут в nj667-graph3d.js, а не в PHP. PHP-линт их не видит
 * вообще, а сервисный стенд проверяет свою (серверную) разность — не浏览器ную.
 *
 * three.js подменяется короткой заглушкой: нам важна логика правки, а не
 * геометрия. Сама страница берётся настоящая — её отдаёт
 * .tools/nj667_graph3d_dump.php через php-wasm, поэтому расхождение id в
 * вёрстке и в скрипте ломает тест, а не молча ломает страницу.
 *
 *   node --test tests/Js/nj667_graph3d.test.mjs
 */

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const JS = readFileSync(new URL('../../public_html/assets/js/nj667-graph3d.js', import.meta.url), 'utf8');
const APP = readFileSync(new URL('../../public_html/assets/js/app.js', import.meta.url), 'utf8');
const PHPSWASM = process.env.N2J_PHPWASM || '/tmp/phpwasm/phpwasm_stand.mjs';

/** Страница глазами браузера: настоящая вьюха 667/project. */
/**
 * Страница глазами браузера: настоящая вьюха 667/project.
 *
 * Сначала системный php (так в CI), нет его — php-wasm: вёрстка должна быть
 * настоящей, иначе тест не заметит расхождения id в разметке и в скрипте.
 */
function pageHtml(page = 'project', canModerate = true) {
    try {
        return execFileSync('php', ['.tools/nj667_graph3d_dump.php'], {
            cwd: ROOT,
            encoding: 'utf8',
            env: { ...process.env, N2J_PAGE: page, N2J_CAN_MODERATE: canModerate ? '1' : '0' },
            maxBuffer: 32 * 1024 * 1024,
        });
    } catch (error) {
        if (!existsSync(PHPSWASM)) {
            throw new Error('Нужен php или стенд php-wasm: /tmp/phpwasm/phpwasm_stand.mjs');
        }

        return execFileSync('node', [PHPSWASM, '.tools/nj667_graph3d_dump.php'], {
            cwd: ROOT,
            encoding: 'utf8',
            env: { ...process.env, N2J_ROOT: ROOT, N2J_PAGE: page, N2J_CAN_MODERATE: canModerate ? '1' : '0' },
            maxBuffer: 32 * 1024 * 1024,
        });
    }
}

/**
 * Заглушка three.js.
 *
 * Объекты минимальны, но честны: Group держит children, Vector3 считает длину
 * и векторное произведение (без них прилипание не проверить), TransformControls
 * помнит слушателей — драг узла в тесте разыгрывается именно через них.
 */
function threeStub(window) {
    class Vector3 {
        constructor(x = 0, y = 0, z = 0) { this.x = x; this.y = y; this.z = z; }

        set(x, y, z) { this.x = x; this.y = y; this.z = z; return this; }
        copy(v) { this.x = v.x; this.y = v.y; this.z = v.z; return this; }
        clone() { return new Vector3(this.x, this.y, this.z); }
        add(v) { this.x += v.x; this.y += v.y; this.z += v.z; return this; }
        sub(v) { this.x -= v.x; this.y -= v.y; this.z -= v.z; return this; }
        multiplyScalar(s) { this.x *= s; this.y *= s; this.z *= s; return this; }
        lengthSq() { return this.x * this.x + this.y * this.y + this.z * this.z; }
        length() { return Math.sqrt(this.lengthSq()); }
        normalize() { const l = this.length() || 1; return this.multiplyScalar(1 / l); }
        distanceTo(v) { return Math.hypot(this.x - v.x, this.y - v.y, this.z - v.z); }

        cross(v) {
            return this.set(this.y * v.z - this.z * v.y,
                this.z * v.x - this.x * v.z,
                this.x * v.y - this.y * v.x);
        }
    }

    class Object3D {
        constructor() {
            this.children = [];
            this.position = new Vector3();
            this.scale = { setScalar: () => {} };
            this.userData = {};
            this.visible = true;
            this.material = { dispose: () => {} };
            this.geometry = { dispose: () => {} };
            this.quaternion = { setFromUnitVectors() { return this; } };
            this.name = '';
        }

        updateMatrix() {}
        updateMatrixWorld() {}

        add(...items) { items.forEach((i) => this.children.push(i)); return this; }
        remove(...items) { this.children = this.children.filter((i) => !items.includes(i)); return this; }
        lookAt() {}
    }

    class Group extends Object3D {}

    class Mesh extends Object3D {
        constructor(geometry, material) {
            super();
            this.geometry = geometry || { dispose: () => {} };
            this.material = material || { dispose: () => {} };
        }
    }

    class Line extends Object3D {
        constructor(geometry, material) {
            super();
            this.geometry = geometry || { dispose: () => {} };
            this.material = material || { dispose: () => {}, opacity: 1 };
        }

        computeLineDistances() {}
    }

    class BufferGeometry {
        setFromPoints(points) { this.points = points; return this; }
        dispose() {}
    }

    class QuadraticBezierCurve3 {
        constructor(a, b, c) { this.a = a; this.b = b; this.c = c; }

        getPoints(n) {
            const out = [];

            for (let i = 0; i <= n; i++) {
                const t = i / n;
                const u = 1 - t;
                out.push(new Vector3(
                    u * u * this.a.x + 2 * u * t * this.b.x + t * t * this.c.x,
                    u * u * this.a.y + 2 * u * t * this.b.y + t * t * this.c.y,
                    u * u * this.a.z + 2 * u * t * this.b.z + t * t * this.c.z,
                ));
            }

            return out;
        }
    }

    class WebGLRenderer {
        constructor() {
            this.domElement = window.document.createElement('canvas');
            this.calls = 0;
        }

        setPixelRatio() {}
        setSize() {}
        getContext() { return { fake: true }; }
        render() { this.calls++; }
    }

    class GridHelper extends Object3D {}

    class TransformControls extends Object3D {
        constructor() {
            super();
            this.listeners = {};
            this.object = null;
            this.dragging = false;
            // Тест должен достать гизмо, чтобы разыграть перетаскивание.
            window.__gizmos = window.__gizmos || [];
            window.__gizmos.push(this);
        }

        setSize() {}
        setMode(mode) { this.mode = mode; }
        attach(object) { this.object = object; }
        detach() { this.object = null; }
        addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }

        fire(name, event = {}) {
            (this.listeners[name] || []).forEach((fn) => fn(event));
        }
    }

    class OrbitControls {
        constructor() { this.target = new Vector3(); this.enabled = true; }
        update() {}
    }

    class CSS2DObject extends Object3D {
        constructor(element) {
            super();
            this.element = element;
        }
    }

    class CSS2DRenderer {
        constructor() { this.domElement = window.document.createElement('div'); }
        setSize() {}
        render() {}
    }

    return {
        WebGLRenderer, Scene: class extends Object3D {}, Group, Mesh, Line, GridHelper,
        Vector2: class { constructor(x = 0, y = 0) { this.x = x; this.y = y; } },
        BufferGeometry, QuadraticBezierCurve3, Vector3, Raycaster: class {
            setFromCamera() {}
            intersectObjects() { return []; }
        },
        PerspectiveCamera: class extends Object3D {
            constructor() { super(); this.aspect = 1; }
            updateProjectionMatrix() {}
        },
        AmbientLight: Object3D, DirectionalLight: Object3D,
        Color: class { constructor(value) { this.value = value; } },
        SphereGeometry: class { dispose() {} }, BoxGeometry: class { dispose() {} },
        CylinderGeometry: class { dispose() {} },
        GLTFExporter: class {
            parse(scene, onDone, onError, options) {
                window.__gltfCalls = window.__gltfCalls || [];
                window.__gltfCalls.push({ scene, options });
                onDone(new window.ArrayBuffer(16));
            }
        },
        MeshStandardMaterial: class { constructor(o) { Object.assign(this, o); } dispose() {} },
        LineBasicMaterial: class { constructor(o) { Object.assign(this, o); } dispose() {} },
        LineDashedMaterial: class { constructor(o) { Object.assign(this, o); } dispose() {} },
        TransformControls, OrbitControls, CSS2DRenderer, CSS2DObject,
    };
}

/** Перезапуск с уже готовой заглушкой (порядок: сначала THREE, потом скрипт). */
async function boot({ webgl = true, page = 'project', html = null, canModerate = true, objectUrls = false, app = false } = {}) {
    const dom = new JSDOM(html || pageHtml(page, canModerate), {
        runScripts: 'dangerously',
        url: page === 'view'
            ? 'https://n2j.test/graph3d/c/ab34cd56ef78'
            : 'https://n2j.test/graph3d/p/ab34cd56ef78',
        pretendToBeVisual: true,
        beforeParse(window) {
            if (objectUrls) {
                let id = 0;
                window.URL.createObjectURL = () => 'blob:nj667-preview/' + (++id);
                window.URL.revokeObjectURL = () => {};
            }
        },
    });

    const { window } = dom;
    const posts = [];

    if (typeof window.Blob !== 'function') {
        window.Blob = class {
            constructor(parts, opts) {
                this.parts = parts;
                this.type = (opts && opts.type) || '';
                this.size = (parts || []).reduce((n, p) => n + (p && (p.byteLength || p.size || p.length) || 0), 0);
            }
        };
    }

    window.fetch = async (url, options) => {
        posts.push({ url: String(url), body: options && options.body ? JSON.parse(options.body) : {} });

        const href = String(url);
        let payload = { ok: true, status: 'draft', job: {} };
        if (href.includes('/save')) {
            payload = { ok: true, ops: 3, no: 4 };
        } else if (href.includes('/status')) {
            payload = { ok: true, job_id: 0, job: { found: false, status: 'none', job_id: 0 } };
        }

        return {
            status: 200,
            json: async () => payload,
        };
    };

    const THREE = threeStub(window);

    if (!webgl) {
        THREE.WebGLRenderer = class {
            constructor() { throw new Error('WebGL недоступен'); }
        };
    }

    window.__THREE = THREE;

    // Общий слой сайта (window.N2J): взвод data-n2j-arm — как в шаблоне страницы.
    if (app) { window.eval(APP); }

    const source = JS.replace(/^\s*import[\s\S]*?from\s*'[^']+';\s*$/gm, '');

    window.eval(`(function (THREE, OrbitControls, TransformControls, CSS2DRenderer, CSS2DObject, GLTFExporter) {
        ${source}
    })(window.__THREE, window.__THREE.OrbitControls, window.__THREE.TransformControls,
       window.__THREE.CSS2DRenderer, window.__THREE.CSS2DObject, window.__THREE.GLTFExporter);`);

    // Скрипт стартует по DOMContentLoaded, а он в jsdom наступает на следующем
    // такте: без ожидания проверки увидят страницу до инициализации холста.
    await new Promise((resolve) => window.setTimeout(resolve, 60));

    return { window, posts, THREE };
}

test('ожидание: секундомер, css-спиннер и поле фото в вёрстке', async () => {
    const { window } = await boot();

    assert.ok(window.document.getElementById('nj667-elapsed'), 'секундомер на оверлее');
    assert.ok(window.document.getElementById('nj667-photos'), 'поле фото');
    assert.ok(window.document.getElementById('nj667-toolbar'), 'панель на холсте');
    assert.ok(window.document.getElementById('nj667-save-icon-btn'), 'иконка сохранить');
    assert.ok(window.document.getElementById('nj667-full-btn'), 'на весь экран');
    assert.equal(window.document.getElementById('nj667-rag'), null, 'RAG на правке нет');
    assert.ok(window.document.querySelector('.nj667-spin'), 'css-спиннер, не fa-spin');
    assert.equal(window.document.querySelectorAll('.fa-spin').length, 0, 'fa-spin на странице нет');

    window.close();
});

test('обычный пользователь не видит блок «Описание → граф» на проекте', async () => {
    const { window } = await boot({ canModerate: false });

    assert.equal(window.document.getElementById('nj667-prompt'), null, 'поле генерации скрыто');
    assert.equal(window.document.getElementById('nj667-generate-btn'), null, 'кнопка генерации скрыта');
    assert.equal(window.document.getElementById('nj667-photos'), null, 'загрузка фото для генерации скрыта');

    window.close();
});

test('создание: режим Simple по умолчанию, модератору доступны четыре фото', async () => {
    const { window } = await boot({ page: 'create', canModerate: true });
    const choices = Array.from(window.document.querySelectorAll('[data-mode-choice]'));
    const photos = window.document.getElementById('nj667-photos');

    assert.equal(choices[0].value, 'simple', 'Simple идёт первым');
    assert.equal(choices[0].checked, true, 'Simple выбран при открытии');
    assert.equal(photos.getAttribute('data-max'), '4');
    assert.equal(photos.hasAttribute('multiple'), true);
    assert.ok(window.document.getElementById('nj667-photo-preview'), 'контейнер превью присутствует');

    window.close();
});

test('создание: обычный пользователь ограничен одним фото', async () => {
    const { window } = await boot({ page: 'create', canModerate: false });
    const photos = window.document.getElementById('nj667-photos');

    assert.equal(photos.getAttribute('data-max'), '1');
    assert.equal(photos.hasAttribute('multiple'), false);

    window.close();
});

test('создание: фото сразу показывает превью и его можно убрать', async () => {
    const { window } = await boot({ page: 'create', canModerate: true, objectUrls: true });
    const input = window.document.getElementById('nj667-photos');
    const file = new window.File(['image bytes'], 'prototype.png', { type: 'image/png' });

    Object.defineProperty(input, 'files', { configurable: true, value: [file] });
    input.dispatchEvent(new window.Event('change', { bubbles: true }));

    const preview = window.document.getElementById('nj667-photo-preview');
    const image = preview.querySelector('img');
    assert.equal(preview.hidden, false, 'превью открылось');
    assert.equal(image.alt, 'prototype.png');
    assert.equal(image.src, 'blob:nj667-preview/1');
    assert.match(window.document.getElementById('nj667-photo-status').textContent, /Выбрано: 1 из 4/);

    preview.querySelector('button').click();
    assert.equal(preview.hidden, true, 'после удаления карточка скрыта');
    assert.equal(preview.children.length, 0);

    window.close();
});

test('сборка без текста и без фото не уходит', async () => {
    const { window, posts } = await boot();

    window.document.getElementById('nj667-generate-btn').click();
    await new Promise((resolve) => window.setTimeout(resolve, 30));

    assert.equal(posts.filter((p) => String(p.url).includes('/generate')).length, 0,
        'пустая сборка не ставит задачу');

    window.close();
});

test('сборка по описанию ставит задачу без кадров в payload', async () => {
    const { window, posts } = await boot();

    window.document.getElementById('nj667-prompt').value = 'стул на четырёх ножках';
    window.document.getElementById('nj667-generate-btn').click();
    await new Promise((resolve) => window.setTimeout(resolve, 40));

    const sent = posts.filter((p) => String(p.url).includes('/generate')).pop();

    assert.ok(sent, 'запрос generate ушёл');
    assert.equal(sent.body.prompt, 'стул на четырёх ножках');
    assert.equal(sent.body.images, undefined, 'пустой file input не кладёт images в JSON');

    window.close();
});

test('редактор поднимается на настоящей вёрстке и видит граф', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;

    assert.ok(editor, 'скрипт отработал');
    assert.equal(editor.state.graph.nodes.length, 7, 'семь узлов велосипеда на месте');
    assert.equal(editor.state.graph.edges.length, 6);
    assert.equal(editor.state.webgl, true);

    window.close();
});

test('без WebGL редактор не показывает пустой кадр, а рисует 2D', async () => {
    const { window } = await boot({ webgl: false });
    const editor = window.NJ667Editor;

    assert.equal(editor.state.webgl, false, 'отсутствие WebGL замечено');

    const note = window.document.getElementById('nj667-note').textContent;

    assert.match(note, /WebGL/, 'причина названа');
    assert.match(note, /плоский|вид сверху/i, 'и сказано, что видно вместо 3D');
    assert.equal(editor.state.graph.nodes.length, 7, 'граф всё равно загружен');

    window.close();
});

test('патч RFC 6902 от сервера накатывается на граф браузера', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;

    editor.state.graph = editor.applyPatch(editor.state.graph, [
        { op: 'replace', path: '/nodes/0/y', value: 5 },
        { op: 'replace', path: '/nodes/0/label', value: 'Рама новая' },
        { op: 'add', path: '/nodes/-', value: { id: 'n9', type: 'endpoint', x: 1, y: 1, z: 1, label: 'Фара' } },
        { op: 'remove', path: '/edges/0' },
    ]);

    assert.equal(editor.state.graph.nodes[0].y, 5);
    assert.equal(editor.state.graph.nodes[0].label, 'Рама новая');
    assert.equal(editor.state.graph.nodes.length, 8, 'узел добавлен в конец');
    assert.equal(editor.state.graph.nodes[7].id, 'n9');
    assert.equal(editor.state.graph.edges.length, 5, 'ребро удалено');

    window.close();
});

test('удаление узла снимает его связи, удаление связи — только ребро', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;
    const beforeNodes = editor.state.graph.nodes.length;
    const beforeEdges = editor.state.graph.edges.length;

    editor.select(['n2']);
    editor.deleteSelected();
    assert.equal(editor.state.graph.nodes.length, beforeNodes - 1, 'узел удалён');
    assert.ok(editor.state.graph.edges.length < beforeEdges, 'связи узла тоже сняты');
    assert.equal(editor.state.graph.nodes.some((n) => n.id === 'n2'), false);

    const edgeId = editor.state.graph.edges[0].id;
    const nodesNow = editor.state.graph.nodes.length;
    editor.selectEdge(edgeId);
    editor.deleteSelectedEdge();
    assert.equal(editor.state.graph.nodes.length, nodesNow, 'узлы на месте');
    assert.equal(editor.state.graph.edges.some((e) => e.id === edgeId), false, 'связь удалена');

    window.close();
});

test('патч с левым путём не роняет граф', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;
    const before = JSON.stringify(editor.state.graph);

    editor.state.graph = editor.applyPatch(editor.state.graph, [
        { op: 'remove', path: '/nodes/999' },
        { op: 'replace', path: '/nodes/0/nope/deeper', value: 1 },
    ]);

    assert.equal(JSON.stringify(editor.state.graph), before, 'граф не изменился');

    window.close();
});

test('умные направляющие: прилипание к соседям по осям', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;

    // Рама стоит в (0,0,0), левое заднее колесо — в (-6,-8,4).
    const snapped = editor.snapPosition('n9', { x: 0.4, y: -7.7, z: 3.6 });

    assert.equal(snapped.x, 0, 'x прилип к раме');
    assert.equal(snapped.y, -8, 'y прилип к колесу');
    assert.equal(snapped.z, 4, 'z прилип к колесу');

    const free = editor.snapPosition('n9', { x: 11, y: 12, z: 13 });

    assert.notEqual(free.x, 0, 'далеко от соседей — прилипать не к чему');
    assert.equal(free.x, 11, 'значение не испорчено (попало в шаг сетки 0.5)');

    window.close();
});

test('правка: добавить узел → отменить → повторить', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;

    window.document.getElementById('nj667-add-btn').click();
    assert.equal(editor.state.graph.nodes.length, 8, 'узел добавлен');

    window.document.getElementById('nj667-undo-btn').click();
    assert.equal(editor.state.graph.nodes.length, 7, 'отмена вернула как было');

    window.document.getElementById('nj667-redo-btn').click();
    assert.equal(editor.state.graph.nodes.length, 8, 'повтор вернул правку');

    assert.ok(window.document.querySelectorAll('#nj667-history li').length >= 1,
        'история показала запись');

    window.close();
});

test('сохранение уходит на /graph3d/api/save вместе с графом и csrf', async () => {
    const { window, posts } = await boot();

    window.document.getElementById('nj667-add-btn').click();
    window.document.getElementById('nj667-save-btn').click();

    await new Promise((resolve) => window.setTimeout(resolve, 30));

    const saved = posts.filter((p) => p.url.includes('/save')).pop();

    assert.ok(saved, 'запрос ушёл');
    assert.equal(saved.body.code, 'ab34cd56ef78');
    assert.equal(saved.body.csrf, 'stand-csrf-token');
    assert.equal(saved.body.graph.nodes.length, 8, 'граф доехал целиком');

    window.close();
});

test('удаление проекта — вторым нажатием (data-n2j-arm), не confirm()', async () => {
    const { window, posts } = await boot({ app: true });
    const button = window.document.getElementById('nj667-delete-btn');

    assert.ok(button.querySelector('.fa-trash-alt'), 'маленькая иконка, не большая кнопка');
    assert.equal(button.getAttribute('aria-label'), 'Удалить проект');

    button.click();
    await new Promise((resolve) => window.setTimeout(resolve, 20));

    assert.ok(button.classList.contains('is-armed'), 'первое нажатие взвело кнопку');
    assert.equal(button.textContent.trim(), 'Удалить проект?');
    assert.equal(posts.filter((p) => p.url.includes('/delete')).length, 0, 'запроса ещё нет');

    button.click();
    await new Promise((resolve) => window.setTimeout(resolve, 30));

    assert.equal(posts.filter((p) => p.url.includes('/delete')).length, 1, 'второе нажатие удалило');

    window.close();
});

test('взведённая кнопка сама остывает', async () => {
    const { window } = await boot({ app: true });
    const button = window.document.getElementById('nj667-delete-btn');

    button.click();
    assert.ok(button.classList.contains('is-armed'));

    // Клик в другом месте снимает взвод: человек передумал.
    window.document.body.click();
    await new Promise((resolve) => window.setTimeout(resolve, 20));

    assert.equal(button.classList.contains('is-armed'), false, 'взвод снят');

    window.close();
});

test('инпейнтинг отправляет выбранные узлы, а не весь граф', async () => {
    const { window, posts } = await boot();
    const editor = window.NJ667Editor;

    editor.select(['n6']);
    window.document.getElementById('nj667-inpaint-prompt').value = 'сделай спортивный руль-баран';
    window.document.getElementById('nj667-inpaint-btn').click();

    await new Promise((resolve) => window.setTimeout(resolve, 30));

    const sent = posts.filter((p) => p.url.includes('/inpaint')).pop();

    assert.ok(sent, 'запрос ушёл');
    assert.deepEqual(sent.body.node_ids, ['n6'], 'в воркер уехал только выбранный узел');
    assert.equal(sent.body.prompt, 'сделай спортивный руль-баран');

    window.close();
});

test('инпейнтинг без выделения не отправляет запрос', async () => {
    const { window, posts } = await boot();

    window.document.getElementById('nj667-inpaint-prompt').value = 'сделай что-нибудь';
    window.document.getElementById('nj667-inpaint-btn').click();

    await new Promise((resolve) => window.setTimeout(resolve, 30));

    assert.equal(posts.filter((p) => p.url.includes('/inpaint')).length, 0, 'без выбора узла — тишина');
    assert.match(window.document.getElementById('nj667-note').textContent, /Выделите/);

    window.close();
});

test('перетаскивание узла пишет патч и историю', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;

    editor.select(['n6']);

    const mesh = editor.state.graph.nodes.find((n) => n.id === 'n6');
    const before = { x: mesh.x, y: mesh.y, z: mesh.z };

    // Разыгрываем драг гизмо: нажали, подвинули, отпустили.
    const gizmo = window.__gizmos[0];
    assert.ok(gizmo, 'гизмо должен быть доступен тесту');

    gizmo.fire('mouseDown');
    // Новое место выбрано подальше от соседей: иначе прилипание (оно тут
    // заслуженно агрессивное) само подтянет узел к чужим осям.
    gizmo.object.position.set(9, 9, -9);
    gizmo.fire('objectChange');
    gizmo.fire('mouseUp');

    const moved = editor.state.graph.nodes.find((n) => n.id === 'n6');

    assert.equal(moved.x, 9, 'узел переехал по x');
    assert.equal(moved.y, 9, 'переехал по y');
    assert.equal(moved.z, -9, 'переехал по z');
    assert.notEqual(moved.y, before.y, 'высота действительно изменилась');
    assert.ok(editor.state.undo.length >= 1, 'правка попала в историю');

    // И undo на ней работает: это та же пара «вперёд/назад», что и у кнопок.
    editor.undo();
    const back = editor.state.graph.nodes.find((n) => n.id === 'n6');

    assert.equal(back.y, before.y, 'отмена вернула высоту');
    assert.equal(back.x, before.x, 'и остальные оси');

    window.close();
});

test('экспорт JSON скачивает топологию, а не картинку', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;

    assert.equal(window.document.getElementById('nj667-export-json-btn').textContent.includes('JSON'), true);
    assert.ok(window.document.getElementById('nj667-export-glb-btn'), 'кнопка GLB на месте');

    window.document.getElementById('nj667-export-json-btn').click();

    const downloads = window.__NJ667_DOWNLOADS || [];
    const last = downloads[downloads.length - 1];

    assert.ok(last, 'скачивание записано');
    assert.match(last.name, /\.json$/);
    assert.match(last.type, /json/);
    assert.ok(last.size > 0, 'файл не пустой');
    assert.match(editor.fileStem(), /велосипед/i);

    window.close();
});

test('экспорт GLB собирает сцену из графа и зовёт GLTFExporter', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;

    window.document.getElementById('nj667-export-glb-btn').click();

    const calls = window.__gltfCalls || [];
    assert.equal(calls.length, 1, 'экспортёр вызван один раз');
    assert.equal(calls[0].options.binary, true, 'просим бинарный GLB, не glTF JSON');
    assert.ok(calls[0].scene, 'сцена передана');
    assert.ok(calls[0].scene.children.length > 7, 'узлы и рёбра — отдельные меши');

    const downloads = window.__NJ667_DOWNLOADS || [];
    const last = downloads[downloads.length - 1];
    assert.ok(last, 'скачивание записано');
    assert.match(last.name, /\.glb$/);
    assert.match(last.type, /gltf-binary/);

    const scene = editor.buildExportScene();
    assert.ok(scene.children.length > 7, 'buildExportScene не зависит от WebGL-холста');

    window.close();
});

test('мёртвый job_id: status один раз, дальше тишина', async () => {
    // CFG.job_id с страницы, очередь уже пуста (status=none). Раньше
    // finished не ловил none, и /api/status долбился каждые 2.5 с.
    const html = `<!doctype html><html><body>
<div id="nj667-canvas" style="width:640px;height:420px"></div>
<p id="nj667-note"></p>
<script type="application/json" id="nj667-config">${JSON.stringify({
        code: 'erp6iyf468wn',
        csrf: 'stand-csrf-token',
        job_id: 42,
        graph: { nodes: [{ id: 'seat', x: 0, y: 0, z: 0, type: 'part' }], edges: [] },
        defaults: { mode: 'translate', grid: 1, snap: 0, labels: 0 },
        limits: {},
        node_types: {},
        edge_types: {},
    })}</script>
</body></html>`;
    const { window, posts } = await boot({ html });
    const editor = window.NJ667Editor;

    assert.equal(editor.jobIsLive({ found: false, status: 'none' }, 42), false);
    assert.equal(editor.jobIsLive({ found: true, status: 'running' }, 7), true);
    assert.equal(editor.jobIsLive({ found: true, status: 'queued', error: 'не JSON' }, 7), false);

    await new Promise((resolve) => window.setTimeout(resolve, 80));
    const first = posts.filter((p) => String(p.url).includes('/status')).length;
    assert.equal(first, 1, 'первый status ушёл');
    assert.equal(editor.state.job, 0, 'хвост job_id сброшен');

    await new Promise((resolve) => window.setTimeout(resolve, 2800));
    const again = posts.filter((p) => String(p.url).includes('/status')).length;
    assert.equal(again, 1, 'повторного status нет');

    window.close();
});

test('просмотр каталога: холст без #nj667-stage не падает', async () => {
    // Вёрстка /graph3d/c/<code>: нет #nj667-stage. Раньше boot затирал
    // els.stage в null и initThree падал на clientWidth.
    const html = `<!doctype html><html><body>
<div id="nj667-view-canvas" style="width:640px;height:420px"></div>
<script type="application/json" id="nj667-config">${JSON.stringify({
        code: 'ab34cd56ef78',
        graph: { nodes: [{ id: 'seat', x: 0, y: 0, z: 0, type: 'part' }], edges: [] },
        readonly: true,
        defaults: { mode: 'translate', grid: 1, snap: 0, labels: 0 },
        limits: {},
        node_types: {},
        edge_types: {},
    })}</script>
</body></html>`;
    const { window } = await boot({ page: 'view', html });
    const host = window.document.getElementById('nj667-view-canvas');

    assert.ok(host, 'холст просмотра на месте');
    assert.equal(window.document.getElementById('nj667-stage'), null, 'внутренней сцены нет');
    assert.ok(host.querySelector('canvas'), 'WebGL-холст вставлен в #nj667-view-canvas');
    assert.doesNotThrow(() => window.NJ667Editor.rebuild(true));

    window.close();
});

test('пустой граф не собирает GLB', async () => {
    const { window } = await boot();
    const editor = window.NJ667Editor;

    editor.state.graph = editor.normalise({ nodes: [], edges: [] });
    editor.exportGlb();

    assert.equal((window.__gltfCalls || []).length, 0, 'экспортёр не звали');
    assert.match(window.document.getElementById('nj667-note').textContent, /пуст/i);

    window.close();
});
