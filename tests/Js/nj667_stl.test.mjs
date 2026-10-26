/**
 * NJ-667: разбор STL в браузере и договорённость JS ↔ вёрстка страницы сборки.
 *
 * Зачем отдельным тестом: парсер и id блоков живут в nj667-stl.js, PHP-стенд
 * их не видит вообще. Плюс здесь же проверяется главное, что ломает просмотр
 * молча: вьюха переименовала data-src или span состояния — и скрипт вежливо
 * «модели нет» вместо модели.
 *
 *   node --test tests/Js/nj667_stl.test.mjs
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const JS = readFileSync(new URL('../../public_html/assets/js/nj667-stl.js', import.meta.url), 'utf8');
const VIEW = readFileSync(
	new URL('../../application/modules/nj667_graph3d/views/nj667/build.php', import.meta.url), 'utf8');

/** STL из N граней: заголовок 80 + счётчик + 50 байт на грань. */
function binaryStl(triangles) {
	const size = 84 + 50 * triangles.length;
	const buffer = new ArrayBuffer(size);
	const view = new DataView(buffer);
	const bytes = new Uint8Array(buffer);

	// Экспортёры, которые пишут «solid» в заголовок, — не редкость: проверяем,
	// что парсер ведёт себя так же и с ними.
	bytes.set(new TextEncoder().encode('solid openscad'), 0);
	view.setUint32(80, triangles.length, true);

	let offset = 84;

	for (const tri of triangles) {
		view.setFloat32(offset + 12, tri[0], true);
		view.setFloat32(offset + 16, tri[1], true);
		view.setFloat32(offset + 20, tri[2], true);
		view.setFloat32(offset + 24, tri[3], true);
		view.setFloat32(offset + 28, tri[4], true);
		view.setFloat32(offset + 32, tri[5], true);
		view.setFloat32(offset + 36, tri[6], true);
		view.setFloat32(offset + 40, tri[7], true);
		view.setFloat32(offset + 44, tri[8], true);
		offset += 50;
	}

	return buffer;
}

function threeStub(window) {
	const log = [];

	class Vector3 {
		constructor(x = 0, y = 0, z = 0) { this.x = x; this.y = y; this.z = z; }
		set(x, y, z) { this.x = x; this.y = y; this.z = z; return this; }
		copy(v) { return this.set(v.x, v.y, v.z); }
		project() { return this; }
	}

	class BufferAttribute {
		constructor(array, itemSize) { this.array = array; this.itemSize = itemSize; this.count = array.length / itemSize; }
	}

	class BufferGeometry {
		constructor() { this.attributes = {}; this.boundingSphere = null; }
		setAttribute(name, attr) { this.attributes[name] = attr; log.push(['setAttribute', name, attr.count]); }
		setIndex(attr) { this.index = attr; log.push(['setIndex', attr.count]); }
		computeVertexNormals() { log.push(['computeVertexNormals']); }
		rotateX(angle) {
			log.push(['rotateX', Math.round(angle * 1000) / 1000]);
			const c = Math.cos(angle);
			const s = Math.sin(angle);
			const a = this.attributes.position.array;

			for (let j = 0; j < a.length; j += 3) {
				const y = a[j + 1];
				const z = a[j + 2];

				a[j + 1] = y * c - z * s;
				a[j + 2] = y * s + z * c;
			}
		}
		computeBoundingBox() {
			const a = this.attributes.position.array;
			const box = new THREE_Box3();

			for (let j = 0; j < a.length; j += 3) {
				box.union({ min: new Vector3(a[j], a[j + 1], a[j + 2]), max: new Vector3(a[j], a[j + 1], a[j + 2]) });
			}

			this.boundingBox = box;
			log.push(['bbox', box.min.x, box.min.y, box.min.z, box.max.x, box.max.y, box.max.z]);
		}
		computeBoundingSphere() {
			const a = this.attributes.position.array;
			let max = 0;

			for (let i = 0; i < a.length; i += 3) {
				max = Math.max(max, Math.hypot(a[i], a[i + 1], a[i + 2]));
			}

			this.boundingSphere = { center: new Vector3(0, 0, 0), radius: max };
			log.push(['computeBoundingSphere', Math.round(max * 1000) / 1000]);
		}
		dispose() { log.push(['dispose', 'geometry']); }
	}

	class WebGLRenderer {
		constructor() {
			this.domElement = window.document.createElement('canvas');
			this.domElement.getContext = () => null;
		}
		setPixelRatio() {}
		setSize() {}
		render() { log.push(['render']); }
		dispose() { log.push(['dispose', 'renderer']); }
	}

	class Sphere {
		constructor(center, radius) {
			this.center = center || new Vector3();
			this.radius = radius === undefined ? 1 : radius;
		}
	}

	// Box3 нужен целиком: габарит сборки — union по всем группам цвета, а не
	// по первому примитиву, иначе камера сядет на первую щепку.
	class Box3 {
		constructor() {
			this.min = new Vector3(Infinity, Infinity, Infinity);
			this.max = new Vector3(-Infinity, -Infinity, -Infinity);
		}

		isEmpty() { return !(this.max.x >= this.min.x); }

		union(box) {
			if (!box || (typeof box.isEmpty === 'function' && box.isEmpty())) {
				return this;
			}

			for (const k of ['x', 'y', 'z']) {
				this.min[k] = Math.min(this.min[k], box.min[k]);
				this.max[k] = Math.max(this.max[k], box.max[k]);
			}

			return this;
		}

		getBoundingSphere(target) {
			const out = target || new Sphere();

			out.center = new Vector3(
				(this.min.x + this.max.x) / 2,
				(this.min.y + this.max.y) / 2,
				(this.min.z + this.max.z) / 2);
			out.radius = Math.max(
				Math.abs(this.max.x - out.center.x),
				Math.abs(this.max.y - out.center.y),
				Math.abs(this.max.z - out.center.z)) || 1;

			return out;
		}
	}

	const THREE_Box3 = Box3;

	const THREE = {
		Box3,
		Sphere,
		Group: class { constructor() { this.children = []; } add(o) { this.children.push(o); } },
		log,
		BufferAttribute,
		BufferGeometry,
		Vector3,
		Scene: class { constructor() { this.children = []; } add(o) { this.children.push(o); } },
		Mesh: class { constructor(g, m) { this.geometry = g; this.material = m; this.visible = true; } },
		MeshStandardMaterial: class {
			constructor(opts = {}) {
				log.push(['material', opts.color, opts.metalness, opts.roughness, opts.transparent, opts.opacity]);
			}
			dispose() { log.push(['dispose', 'material']); }
		},
		PerspectiveCamera: class {
			// Подпись как у three.js: (fov, aspect, near, far) — без fov fit()
			// считал бы NaN и тест на вписанность модели в кадр молчал бы.
			constructor(fov = 50, aspect = 1) {
				this.fov = fov;
				this.aspect = aspect;
				this.position = new Vector3();
			}
			updateProjectionMatrix() {
				log.push(['updateProjectionMatrix', this.aspect, this.position.x, this.position.y, this.position.z]);
			}
		},
		HemisphereLight: class {},
		DirectionalLight: class { constructor() { this.position = new Vector3(); } },
		GridHelper: class { constructor() { this.position = new Vector3(); } },
		LineSegments: class { constructor(g, m) { this.geometry = g; this.material = m; this.visible = true; } },
		LineBasicMaterial: class { constructor(opts = {}) { this.color = opts.color; } dispose() {} },
		WebGLRenderer,
		DoubleSide: 2,
	};

	return THREE;
}

/** Скрипт страницы без three.js: импорт вырезан, THREE приходит аргументом. */
function boot(elHtml = '', seed = {}) {
	const dom = new JSDOM(`<!doctype html><html><body>
		<div id="nj667-stl" ${elHtml}></div>
		<span id="nj667-stl-state"></span><span id="nj667-stl-meta"></span>
		<input type="checkbox" id="nj667-grid-toggle"><span id="nj667-grid-info" style="display: none"></span>
	</body></html>`, { runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/' });

	// Сохранённый выбор (например, сетка) — до того, как скрипт страницы прочитает его.
	for (const [key, value] of Object.entries(seed)) {
		dom.window.localStorage.setItem(key, value);
	}

	const THREE = threeStub(dom.window);
	dom.window.__THREE = THREE;
	dom.window.NJ667_BUILD = { code: 'ab34cd56ef78', csrf: 'x', build_id: 7, job_id: 0, parts_job_id: 0, status: '' };

	const source = JS.replace(/^\s*import[\s\S]*?from\s*'[^']+';\s*$/gm, '');

	dom.window.eval(`(function (THREE, OrbitControls) {
		${source}
	})(window.__THREE, function () { return { target: new window.__THREE.Vector3(), addEventListener() {}, update() {} }; });`);

	return { dom: dom.window, THREE };
}

test('STL: двоичный файл разобран в плоские вершины', () => {
	const { dom } = boot();
	const buffer = binaryStl([[0, 0, 0, 10, 0, 0, 0, 10, 0], [10, 0, 0, 10, 10, 0, 0, 10, 0]]);
	const pos = dom.NJ667_STL.parseStl(buffer);

	assert.equal(pos.length, 18, 'две грани = 18 чисел');
	assert.deepEqual(Array.from(pos.slice(0, 9)), [0, 0, 0, 10, 0, 0, 0, 10, 0]);
	assert.deepEqual(Array.from(pos.slice(9, 18)), [10, 0, 0, 10, 10, 0, 0, 10, 0]);
	dom.close();
});

test('STL: 80-байтный заголовок со «solid» не сбивает формат', () => {
	const { dom } = boot();
	const pos = dom.NJ667_STL.parseStl(binaryStl([[1, 2, 3, 4, 5, 6, 7, 8, 9]]));

	assert.deepEqual(Array.from(pos), [1, 2, 3, 4, 5, 6, 7, 8, 9]);
	dom.close();
});

test('STL: ASCII-грани тоже читаются', () => {
	const { dom } = boot();
	const text = `solid a
 facet normal 0 0 1
  outer loop
   vertex 0 0 0
   vertex 2.5 0 0
   vertex 0 4 0
  endloop
 endfacet
endsolid a`;
	const pos = dom.NJ667_STL.parseStl(new TextEncoder().encode(text).buffer);

	assert.deepEqual(Array.from(pos), [0, 0, 0, 2.5, 0, 0, 0, 4, 0]);
	dom.close();
});

test('STL: обрезанный и мусорный файл — ошибка словами, а не тишина', () => {
	const { dom } = boot();

	assert.throws(() => dom.NJ667_STL.parseStl(new Uint8Array(40).buffer), /короче заголовка/);
	// длиннее заголовка, но граней нет: это не «обрезанный», это не STL вовсе
	const junk = new TextEncoder().encode('solid x\nendsolid x'.padEnd(120, '\0'));

	assert.throws(() => dom.NJ667_STL.parseStl(junk.buffer.slice(junk.byteOffset, junk.byteOffset + junk.byteLength)),
		/не STL/);
	dom.close();
});

test('просмотрщик: геометрия из граней, камера по габариту', () => {
	const { dom, THREE } = boot();
	const buffer = binaryStl([[0, 0, 0, 20, 0, 0, 0, 20, 0]]);
	const pos = dom.NJ667_STL.parseStl(buffer);

	dom.NJ667_STL.mount(dom.document.getElementById('nj667-stl'), { positions: pos, normals: null, index: null });

	const names = THREE.log.map((row) => row[0]);

	assert.ok(names.includes('computeVertexNormals'), 'нормали: без них печать выглядит литой');
	assert.ok(names.includes('render'), 'кадр нарисован');
	assert.equal(THREE.log.find((r) => r[0] === 'setAttribute')[2], 3, 'три вершины');
	// дальняя вершина на 20 мм от центра — на столько же отдаляем камеру
	assert.equal(THREE.log.find((r) => r[0] === 'computeBoundingSphere')[1], 20, 'радиус по габариту');
	dom.close();
});

/**
 * GLB из двух независимых половин: писалку здесь мы собрали сами, по тексту
 * спецификации glTF 2.0, а не копией конвертера воркера. Если воркер и страница
 * разойдутся в договорённостях, тест красный — и правильно делает.
 */
function glbFile({ positions = [], normals = null, indices = null, meshes = 1, uri = null, material = null, extra = null } = {}) {
	const views = [];
	const accessors = [];
	const parts = [];
	let offset = 0;

	const push = (array, type, componentType, wide) => {
		const bytes = new Uint8Array(array.buffer, array.byteOffset, array.byteLength);

		views.push({ buffer: 0, byteOffset: offset, byteLength: bytes.byteLength });
		accessors.push({ bufferView: views.length - 1, componentType, count: bytes.byteLength / (wide * (type === 'VEC3' ? 3 : 1)), type });

		parts.push(bytes);
		offset += bytes.byteLength;

		// GLB требует выравнивания chunk'а на 4 байта; пишем паддинг всегда,
		// кроме случая, когда делится — ровно как настоящий экспортёр.
		if (offset % 4) {
			const pad = new Uint8Array(4 - (offset % 4));

			parts.push(pad);
			offset += pad.length;
		}

		return accessors.length - 1;
	};

	if (positions.length) {
		push(Float32Array.from(positions), 'VEC3', 5126, 4);
	}

	if (normals && normals.length) {
		push(Float32Array.from(normals), 'VEC3', 5126, 4);
	}

	if (indices && indices.length) {
		push(Uint16Array.from(indices), 'SCALAR', 5123, 2);
	}

	const attributes = positions.length ? { POSITION: 0 } : {};

	if (normals && normals.length) {
		attributes.NORMAL = positions.length ? 1 : 0;
	}

	const prim = { attributes, mode: 4, ...(indices && indices.length ? { indices: normals && normals.length ? 2 : 1 } : {}) };
	const made = [];

	// material — как его пишет glb.py: индекс в примитиве + массив материалов.
	if (material) {
		made.push(material);
		prim.material = made.length - 1;
	}

	const prims = [prim];

	// extra — вторая группа цвета: ровно так же, как glb.py раскладывает
	// `color()` из OpenSCAD на примитивы одного меша.
	if (extra) {
		const attrs2 = {};

		if (extra.positions && extra.positions.length) {
			attrs2.POSITION = push(Float32Array.from(extra.positions), 'VEC3', 5126, 4);
		}

		if (extra.normals && extra.normals.length) {
			attrs2.NORMAL = push(Float32Array.from(extra.normals), 'VEC3', 5126, 4);
		}

		const prim2 = { attributes: attrs2, mode: 4 };

		if (extra.indices && extra.indices.length) {
			prim2.indices = push(Uint16Array.from(extra.indices), 'SCALAR', 5123, 2);
		}

		if (extra.material) {
			made.push(extra.material);
			prim2.material = made.length - 1;
		}

		prims.push(prim2);
	}

	// Бинарник собираем ПОСЛЕДНИМ: группы цвета дописывают в parts свои
	// массивы, и «заморозить» buffer раньше — значит обрезать модель.
	const bin = new Uint8Array(offset);
	let at = 0;

	for (const part of parts) {
		bin.set(part, at);
		at += part.length;
	}

	const mesh = { primitives: prims };
	const doc = {
		asset: { version: '2.0', generator: 'nj667-test' },
		bufferViews: views,
		accessors,
		buffers: [{ byteLength: offset, ...(uri ? { uri } : {}) }],
		meshes: Array.from({ length: meshes }, () => mesh),
		...(made.length ? { materials: made } : {}),
	};

	const jsonBytes = new TextEncoder().encode(JSON.stringify(doc));
	const jsonPad = (4 - (jsonBytes.byteLength % 4)) % 4;
	const total = 12 + 8 + jsonBytes.byteLength + jsonPad + 8 + bin.byteLength;
	const buffer = new ArrayBuffer(total);
	const view = new DataView(buffer);
	const bytes = new Uint8Array(buffer);

	view.setUint32(0, 0x46546c67, true);
	view.setUint32(4, 2, true);
	view.setUint32(8, total, true);
	view.setUint32(12, jsonBytes.byteLength + jsonPad, true);
	view.setUint32(16, 0x4e4f534a, true);
	bytes.set(jsonBytes, 20);

	let at2 = 20 + jsonBytes.byteLength;

	for (let i = 0; i < jsonPad; i++) {
		bytes[at2++] = 0x20;
	}

	view.setUint32(at2, bin.byteLength, true);
	view.setUint32(at2 + 4, 0x004e4942, true);
	bytes.set(bin, at2 + 8);

	return buffer;
}

test('GLB: вершины, нормали и индексы доезжают до сцены', () => {
	const { dom, THREE } = boot();
	const buffer = glbFile({
		positions: [0, 0, 0, 10, 0, 0, 0, 10, 0],
		normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
		indices: [0, 1, 2],
	});

	const model = dom.NJ667_STL.parseGlb(buffer);

	assert.equal(model.groups[0].positions.length, 9, 'три вершины по три числа');
	assert.equal(model.groups[0].normals.length, 9, 'нормали пришли, а не пересчитаны');
	assert.equal(model.groups[0].index.length, 3, 'индексы как есть, без разворотки в грани');
	assert.equal(model.triangles, 1);

	dom.NJ667_STL.mount(dom.document.getElementById('nj667-stl'), model);

	const names = THREE.log.map((row) => row[0]);

	assert.ok(names.includes('setIndex'), 'геометрия с индексами — иначе mesh тяжелеет вдвое');
	assert.ok(!names.includes('computeVertexNormals'), 'свои нормали — вторые не считаем');
	assert.deepEqual(THREE.log.find((row) => row[0] === 'setAttribute' && row[1] === 'normal').slice(0, 2), ['setAttribute', 'normal']);
	dom.close();
});

test('GLB: данные читаются от начала BIN-chunk, а не от начала файла', () => {
	const { dom } = boot();
	const buffer = glbFile({ positions: [1, 2, 3, 4, 5, 6, 7, 8, 9] });
	const model = dom.NJ667_STL.parseGlb(buffer);

	assert.deepEqual(Array.from(model.groups[0].positions), [1, 2, 3, 4, 5, 6, 7, 8, 9],
		'съехавшая на 20 байт геометрия — это «модель не читается» или мусор, но не правда');
	dom.close();
});

test('GLB: чужой файл — отказ словами, а не наполовину нарисованная модель', () => {
	const { dom } = boot();

	// Внешний .bin: страница его не догрузит (URL в glTF — это уже чужой путь).
	assert.throws(() => dom.NJ667_STL.parseGlb(glbFile({
		positions: [0, 0, 0, 1, 0, 0, 0, 1, 0], uri: 'data.bin',
	})), /внешни/);

	// Два mesh'а — наша сборка так не пишет; молча нарисовать первый = соврать.
	assert.throws(() => dom.NJ667_STL.parseGlb(glbFile({
		positions: [0, 0, 0, 1, 0, 0, 0, 1, 0], meshes: 2,
	})), /меш/);

	// Нет POSITION — рисовать нечего.
	assert.throws(() => dom.NJ667_STL.parseGlb(glbFile({})), /POSITION/);
	dom.close();
});

test('GLB: обрезанный и не-GLB файлы названы своими именами', () => {
	const { dom } = boot();
	const good = new Uint8Array(glbFile({ positions: [0, 0, 0, 1, 0, 0, 0, 1, 0] }));
	const short = good.slice(0, good.length - 12).buffer;

	assert.throws(() => dom.NJ667_STL.parseGlb(short), /обрезан|за конец/);
	assert.throws(() => dom.NJ667_STL.parseGlb(new Uint8Array(64).buffer), /не GLB/);
	assert.throws(() => dom.NJ667_STL.parseGlb(new Uint8Array(8).buffer), /короче заголовка/);
	dom.close();
});

test('просмотрщик: без src — вежливая запись, а не сломанный холст', async () => {
	const { dom } = boot('data-state="spec" data-src=""');

	// start() — async: запись появляется на следующем микротике.
	await new Promise((resolve) => dom.setTimeout(resolve, 20));

	assert.match(dom.document.getElementById('nj667-stl-state').textContent, /Модели пока нет/);
	assert.ok(dom.document.querySelector('#nj667-stl .nj667-stl-note'), 'подсказьный блок на холсте');
	dom.close();
});

test('просмотрщик: за файлом, которого нет, не ходит', async () => {
	// Ключевая защита от «HTTP 404» в блоке просмотра: строка сборки без STL и
	// без GLB не должна превращаться в запрос к ?file=stl.
	const { dom } = boot('data-state="done" data-src="" data-kind="stl"');
	let asked = 0;

	dom.fetch = async () => {
		asked++;
		return { ok: true, arrayBuffer: async () => new ArrayBuffer(0), json: async () => ({ ok: true }) };
	};

	await dom.NJ667_STL.start();

	assert.equal(asked, 0, 'ни одного запроса: файл не обещан — не просим');
	assert.match(dom.document.querySelector('#nj667-stl .nj667-stl-note').textContent, /Модели пока нет/);
	dom.close();
});

test('просмотрщик: GLB — единственный формат изделия целиком', () => {
	const controller = readFileSync(
		new URL('../../application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php', import.meta.url), 'utf8');
	const api = readFileSync(
		new URL('../../application/modules/nj667_graph3d/classes/Controller/Nj667/Api.php', import.meta.url), 'utf8');
	const service = readFileSync(
		new URL('../../application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php', import.meta.url), 'utf8');
	const partsMigration = readFileSync(
		new URL('../../application/modules/nj667_graph3d/migrations_pg/pgMigration_Nj_667_015_Graph3d_PartsFileRequests.php', import.meta.url), 'utf8');
	const button = VIEW.indexOf('name="request_glb"');
	const showWhole = VIEW.indexOf('data-show-whole');

	assert.ok(button >= 0 && button < showWhole, 'зелёный запрос GLB стоит перед «Показать изделие целиком»');
	assert.match(VIEW, /Собрать 3D файлы сборки/, 'кнопка запроса есть в интерфейсе');
	assert.match(controller, /can_read_build\(\$project, \$build/, 'страница проверяет публикацию самой сборки');
	assert.match(controller, /\$want === 'stl' AND \$part < 0 AND ! \$this->_can_moderate/,
		'прямая выдача STL всего изделия закрыта серверной проверкой ACL');
	assert.match(controller, /log_build_file_request\(/, 'принятый запрос файла попадает в журнал');
	assert.match(api, /can_read_build\(\$project, \$build/, 'поллинг проверяет доступ к выбранной сборке');
	const statusStart = api.indexOf('public function action_build_status()');
	const statusEnd = api.indexOf('\n\tprotected function _gate', statusStart);
	const statusAction = api.slice(statusStart, statusEnd);

	assert.doesNotMatch(statusAction, /\$this->_project\(/,
		'polling не использует owner-only gate для публичного чужого проекта');
	assert.match(statusAction, /\$this->_gate\(\)/, 'polling сохраняет проверку аккаунта и CSRF');
	assert.match(statusAction, /Service_Nj667_Graph3d::can_read\(/,
		'polling разрешает только читаемый/опубликованный граф');
	assert.match(VIEW, /parts_job_id/,
		'страница отдельно передаёт id очереди узлов, не смешивая его с рендером GLB');
	assert.match(JS, /Number\(cfg\.parts_job_id \|\| 0\) > 0/,
		'watcher узлов запускается только для очереди деталей');
	assert.match(VIEW, /GLB-копию[\s\S]*STL-детали — отдельно/,
		'интерфейс объясняет, что GLB и STL-деталей — разные запросы');
	assert.match(service, /function can_read_build/, 'общая проверка доступа сборки есть');
	assert.match(service, /function can_prepare_build_parts/, 'доступ к деталям разрешается владельцу или автору сборки');
	assert.match(controller, /can_prepare_build_parts\(\$project, \$build/,
		'серверная POST-проверка использует доступ к своей сборке');
	assert.match(VIEW, /\$ok AND \$can_prepare_parts AND \$parts/,
		'кнопка узлов видна автору своей пользовательской сборки');
	assert.match(service, /in_array\(\$file_kind, \['glb', 'stl', 'parts'\]/,
		'принятый запрос STL-узлов проходит в общий журнал файлов');
	assert.match(partsMigration, /file_kind IN .*parts/,
		'миграция 015 обновляет типы запросов файла');
	assert.match(service, /function build_current\(\$project_id, \$user_id = 0\)[\s\S]*published_at IS NOT NULL/,
		'пользовательская сборка не подменяет текущую сборку владельца');
	assert.match(JS, /function pointAt[\s\S]*?if \(Number\(row\.glb_size \|\| 0\) <= 0\)/,
		'после поллинга просмотрщик не переключается на STL всего изделия');
	assert.doesNotMatch(VIEW, /name="keep_stl"|keep_stl=/, 'обычный пользователь не может заказать хранение STL изделия');
});

test('просмотрщик: job GLB не запускает watcher STL-деталей', async () => {
	const { dom } = boot('data-state="spec" data-src="" data-kind="glb" data-status="/graph3d/api/build_status?code=x&build_id=249"');
	const polls = [];

	dom.NJ667_BUILD = { code: 'ab34cd56ef78', csrf: 'x', build_id: 249, job_id: 42, parts_job_id: 0, status: 'spec' };
	dom.fetch = async (url) => {
		polls.push(String(url));
		return { ok: true, json: async () => ({ ok: true, build_id: 249, status: 'queued', job: 'running' }) };
	};

	const started = dom.NJ667_STL.start();
	await new Promise((resolve) => setTimeout(resolve, 40));

	try {
		assert.equal(polls.length, 0, 'рендер GLB не вызывает опрос деталей сразу при загрузке');
	} finally {
		dom.close();
		started.catch(() => {});
	}
});

test('просмотрщик: HTTP 403 показывает причину API, а не «связь пропала»', async () => {
	const { dom } = boot('data-state="done" data-src="" data-kind="glb" data-status="/graph3d/api/build_status?code=x&build_id=249"');

	dom.NJ667_BUILD = { code: 'ab34cd56ef78', csrf: 'x', build_id: 249, job_id: 0, parts_job_id: 42, status: 'done' };
	dom.fetch = async () => ({
		ok: false,
		status: 403,
		json: async () => ({ ok: false, error: 'Сессия устарела, обновите страницу.' }),
	});

	const started = dom.NJ667_STL.start();
	await new Promise((resolve) => setTimeout(resolve, 20));

	assert.equal(dom.document.getElementById('nj667-stl-state').textContent,
		'Сессия устарела, обновите страницу.');
	dom.close();
	started.catch(() => {});
});

test('просмотрщик: успех с GLB грузит glb и не запрашивает STL изделия', async () => {
	const { dom, THREE } = boot('data-state="queued" data-src="" data-kind="stl" data-status="/status"');
	const glb = glbFile({
		positions: [0, 0, 0, 10, 0, 0, 0, 10, 0],
		normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
		indices: [0, 1, 2],
	});
	const urls = [];

	dom.NJ667_BUILD = { code: 'ab34cd56ef78', csrf: 'x', build_id: 7, job_id: 9, status: 'queued' };

	dom.fetch = async (url) => {
		urls.push(String(url));

		if (String(url).includes('/status')) {
			return {
				ok: true,
				json: async () => ({ ok: true, build_id: 7, status: 'done', error: '', stl_size: 0, glb_size: 4096 }),
			};
		}

		return { ok: true, arrayBuffer: async () => glb };
	};

	await dom.NJ667_STL.start();

	assert.ok(urls.some((u) => u.includes('file=glb')), 'поллинг сказал glb_size>0 — грузим glTF');
	assert.ok(!urls.some((u) => u.includes('file=stl')), 'просмотр изделия не запрашивает STL');
	assert.ok(THREE.log.some((row) => row[0] === 'render'), 'модель нарисована');
	assert.match(dom.document.getElementById('nj667-stl-state').textContent, /Изделие целиком:/);
	assert.doesNotMatch(dom.document.getElementById('nj667-stl-meta').textContent, /glTF|STL/, 'формат файла в мете не нужен');
	dom.close();
});

test('просмотрщик: счётчик готовых узлов живёт в статусе, а не плашкой на холсте', async () => {
	// Вопрос владельца: «ожидание» без счётчика выглядит зависшим. Но плашка поверх холста
	// загораживала саму модель — вопрос владельца от 13.10.2026. Значит: счётчик в строке
	// статуса, холст чист. Опросов теперь два (модель и пачка узлов), поэтому смотрим не на
	// один номер запроса, а на все состояния, что страница показала за время ожидания.
	const { dom } = boot('data-state="queued" data-src="" data-kind="stl" data-status="/status"');
	const glb = glbFile({ positions: [0, 0, 0, 10, 0, 0, 0, 10, 0], indices: [0, 1, 2] });
	const parts = [{ part: 0, status: 'done' }, { part: 1, status: 'queued' }];
	const shown = { states: [], notes: [], n: 0 };

	dom.NJ667_BUILD = { code: 'ab34cd56ef78', csrf: 'x', build_id: 7, job_id: 9, status: 'queued' };

	dom.fetch = async (url) => {
		if (String(url).includes('/status')) {
			shown.n++;
			shown.states.push(dom.document.getElementById('nj667-stl-state').textContent);
			shown.notes.push((dom.document.querySelector('#nj667-stl .nj667-stl-note') || {}).textContent || '');

			// Модель готова не раньше пятого опроса: успеваем увидеть счётчик.
			const status = shown.n >= 5 ? 'done' : 'queued';

			return {
				ok: true,
				json: async () => ({ ok: true, build_id: 7, status, error: '',
					stl_size: 0, glb_size: status === 'done' ? 4096 : 0, parts }),
			};
		}

		return { ok: true, arrayBuffer: async () => glb };
	};

	try {
		await dom.NJ667_STL.start();

		assert.equal(shown.n >= 2, true, 'дождались нескольких опросов');
		assert.ok(shown.states.some((t) => /узлы: 1 из 2/.test(t)),
			'готовые узлы названы в строке статуса: ' + JSON.stringify(shown.states));
		assert.ok(!shown.notes.some((t) => /Узлов на диске/.test(t)), 'на холсте — никаких подсказок поверх модели');
	} finally {
		dom.close();
	}
});

test('просмотрщик: ошибка воркера не прячет готовую модель', async () => {
	// «STL не прислал» при лежащем на диске GLB — это жалоба на второй формат:
	// холст обязан показать модель, а заметку отдать в строку под ним.
	const { dom, THREE } = boot('data-state="queued" data-src="" data-kind="stl" data-status="/status"');
	const glb = glbFile({ positions: [0, 0, 0, 10, 0, 0, 0, 10, 0], indices: [0, 1, 2] });

	dom.NJ667_BUILD = { code: 'ab34cd56ef78', csrf: 'x', build_id: 7, job_id: 9, status: 'queued' };

	dom.fetch = async (url) => {
		if (String(url).includes('/status')) {
			return {
				ok: true,
				json: async () => ({
					ok: true, build_id: 7, status: 'error', stl_size: 0, glb_size: 4096,
					error: 'Отказ приёмки: GLB больше 24 МБ.',
				}),
			};
		}

		return { ok: true, arrayBuffer: async () => glb };
	};

	await dom.NJ667_STL.start();

	assert.ok(THREE.log.some((row) => row[0] === 'render'), 'модель показана, несмотря на error');
	assert.match(dom.document.getElementById('nj667-stl-state').textContent, /Воркер добавил/);
	dom.close();
});

test('glTF: цвет, металл и шероховатость читаются из файла', () => {
	// baseColorFactor в glTF лежит в ЛИНЕЙНОМ пространстве, three.js ждёт sRGB.
	// Латунь 0.82/0.72/0.55 после гаммы — 0xE9DCC2; если кто-то «упростит»
	// парсер до raw-значений, модель станет грязно-коричневой, и человек
	// прочитает это как «GLB не сошёл».
	const { dom } = boot('');
	const buffer = glbFile({
		positions: [0, 0, 0, 10, 0, 0, 0, 10, 0],
		normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
		indices: [0, 1, 2],
		material: {
			name: 'print',
			pbrMetallicRoughness: {
				baseColorFactor: [0.82, 0.72, 0.55, 1],
				metallicFactor: 0,
				roughnessFactor: 0.85,
			},
		},
	});
	const model = dom.NJ667_STL.parseGlb(buffer.buffer || buffer);

	assert.equal(model.groups[0].material.color, 0xe9dcc2, 'цвет из glTF, переведённый в sRGB');
	assert.equal(model.groups[0].material.metalness, 0, 'metallicFactor из файла, а не 0.05 на глаз');
	assert.equal(model.groups[0].material.roughness, 0.85, 'шероховатость из файла');
	assert.equal(model.groups[0].material.opacity, 1, 'альфа 1 — материал глухой');
	dom.close();
});

test('glTF без материала и STL рисуются нейтральным серым', () => {
	const { dom, THREE } = boot('');

	dom.NJ667_STL.mount(dom.document.getElementById('nj667-stl'), {
		positions: Float32Array.from([0, 0, 0, 10, 0, 0, 0, 10, 0]),
		normals: null,
		index: null,
	});

	const row = THREE.log.filter((x) => x[0] === 'material').pop();

	assert.equal(row[1], 0x8296ad, 'материала нет — остаётся прежний серый');
	assert.equal(row[2], 0.05, 'metalness по умолчанию');
	assert.equal(row[3], 0.7, 'roughness по умолчанию');
	assert.notEqual(row[4], true, 'прозрачность включается только когда её попросили');
	dom.close();
});

/**
 * Переписать GLB, не трогая бинарник: удобно проверать отказы на файлах,
 * которые наш конвертер никогда не выдаст (25 групп цвета, битый chunk).
 */
function patchGlb(buffer, mutate) {
	const bytes = new Uint8Array(buffer);
	const view = new DataView(bytes.buffer);
	const jsonLen = view.getUint32(12, true);
	const doc = JSON.parse(new TextDecoder('utf-8').decode(bytes.subarray(20, 20 + jsonLen)));
	const binAt = 20 + jsonLen + 8;
	const binLen = view.getUint32(20 + jsonLen, true);
	const bin = bytes.subarray(binAt, binAt + binLen);

	mutate(doc);

	const jsonBytes = new TextEncoder().encode(JSON.stringify(doc));
	const jsonPad = (4 - (jsonBytes.byteLength % 4)) % 4;
	const total = 12 + 8 + jsonBytes.byteLength + jsonPad + 8 + bin.byteLength;
	const out = new ArrayBuffer(total);
	const oview = new DataView(out);
	const obytes = new Uint8Array(out);

	oview.setUint32(0, 0x46546c67, true);
	oview.setUint32(4, 2, true);
	oview.setUint32(8, total, true);
	oview.setUint32(12, jsonBytes.byteLength + jsonPad, true);
	oview.setUint32(16, 0x4e4f534a, true);
	obytes.set(jsonBytes, 20);

	let at = 20 + jsonBytes.byteLength;

	for (let i = 0; i < jsonPad; i++) {
		obytes[at++] = 0x20;
	}

	oview.setUint32(at, bin.byteLength, true);
	oview.setUint32(at + 4, 0x004e4942, true);
	obytes.set(bin, at + 8);

	return out;
}

test('GLB: сколько цветов, столько мешей в сцене', () => {
	// Владелец (13.10.2026): «хочется в браузере видеть раскраску по типам
	// узлов». Значит две группы в файле = два материала на сцене, а не один
	// «средний» на всю сборку.
	const { dom, THREE } = boot();
	const color = (rgb) => ({
		name: 'print',
		pbrMetallicRoughness: { baseColorFactor: [...rgb, 1], metallicFactor: 0, roughnessFactor: 0.85 },
	});
	const buffer = glbFile({
		positions: [0, 0, 0, 10, 0, 0, 0, 10, 0],
		normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
		indices: [0, 1, 2],
		material: color([0.278, 0.51, 0.706]),
		extra: {
			positions: [0, 0, 5, 10, 0, 5, 10, 10, 5],
			normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
			indices: [0, 1, 2],
			material: color([0.85, 0.65, 0.13]),
		},
	});
	const model = dom.NJ667_STL.parseGlb(buffer);

	assert.equal(model.groups.length, 2);
	assert.equal(model.colors, 2, 'число цветов — часть ответа: его показывает подпись под окном');
	assert.equal(model.triangles, 2, 'грани считаются по всем группам');
	assert.notEqual(model.groups[0].material.color, model.groups[1].material.color);

	dom.NJ667_STL.mount(dom.document.getElementById('nj667-stl'), model);

	const mats = THREE.log.filter((row) => row[0] === 'material');

	assert.equal(mats.length, 2, 'материал на каждую группу: scene.add(mesh) раз N');
	assert.ok(mats.every((row) => Number.isFinite(row[1])), 'оба цвета прочитаны из файла');
	dom.close();
});

test('GLB: групп больше лимита — отказ словами, а не половина изделия', () => {
	const { dom } = boot();
	const base = glbFile({
		positions: [0, 0, 0, 10, 0, 0, 0, 10, 0],
		normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
		indices: [0, 1, 2],
	});
	const buffer = patchGlb(base, (doc) => {
		doc.meshes[0].primitives = Array.from({ length: 25 }, () => doc.meshes[0].primitives[0]);
	});

	assert.throws(() => dom.NJ667_STL.parseGlb(buffer), /24/,
		'лимит просмотра обязан совпадать с приёмкой сайта и MAX_GROUPS воркера');
	dom.close();
});

test('ожидание модели: секундомер приклеен к строке, узлы досчитаны', () => {
	const { dom } = boot();
	const waitNote = dom.NJ667_STL.waitNote;
	const partsReady = dom.NJ667_STL.partsReady;

	assert.equal(waitNote(85, [{ status: 'done' }, { status: 'queued' }]),
		'Ждём модель: 1 мин 25 с, узлы: 1 из 2…',
		'«сколько ждём» и «сколько узлов готово» — одна строка: секундомер не отрывается к краю блока');
	assert.equal(waitNote(5, []), 'Ждём модель: 5 с…', 'пока узлов нет — не врать про «0 из 0»');
	assert.equal(waitNote(0, null), 'Ждём модель: 0 с…');
	assert.deepEqual(partsReady([{ part: 0, status: 'done' }, { part: 1, status: 'queued' }, { part: 3, status: 'done' }]),
		[0, 3]);
	dom.close();
});

test('узел в 3D: ссылка и кнопка ссылаются на один файл', () => {
	const { dom } = boot();
	const cell = dom.document.createElement('td');
	const stl = dom.document.createElement('span');

	stl.setAttribute('data-part-state', '0');
	stl.textContent = 'в очереди';
	cell.appendChild(stl);
	dom.document.body.appendChild(cell);

	dom.NJ667_STL.paintParts([{ part: 0, status: 'done', size: 1048576 }], 7, 'ab34cd56ef78');

	const link = dom.document.querySelector('[data-part-dl]');
	const btn = dom.document.querySelector('[data-part-view="0"]');

	assert.ok(link && btn, 'готовый узел получает и STL, и кнопку просмотра');
	assert.equal(link.getAttribute('href'), dom.NJ667_STL.partUrl('ab34cd56ef78', 0, 7));
	assert.equal(btn.getAttribute('data-build'), '7', 'кнопка обязана нести id сборки: без неё файл берётся не тот');
	assert.equal(btn.textContent, 'в 3D');
	dom.close();
});

test('договор вёрстки: всё, о чём спрашивает скрипт, есть на странице', () => {
	for (const attr of ['data-src', 'data-status', 'data-state', 'data-stl-size', 'data-render-ms', 'data-kind',
		'data-part-view', 'data-part-state']) {
		assert.ok(VIEW.includes(attr), `вьюха обязана отдавать ${attr}`);
	}

	for (const id of ['nj667-stl', 'nj667-stl-state', 'nj667-stl-meta', 'nj667-build-config']) {
		assert.ok(VIEW.includes(`id="${id}"`), `в вёрстке должен быть блок ${id}`);
	}

	assert.ok(VIEW.includes('/assets/js/nj667-stl.js'), 'страница подключает скрипт');
	assert.ok(VIEW.includes('"three"') && VIEW.includes('three/addons/'), 'importmap на месте');
});

test('three.js только локальный: страница сборки не зависит от CDN', () => {
	const imports = JS.match(/^import[\s\S]*?from\s*'[^']+';/gm) || [];

	assert.ok(imports.length >= 2, 'два импорта: three и OrbitControls');

	for (const line of imports) {
		assert.match(line, /from 'three/, `импорт должен идти в importmap: ${line}`);
	}

	assert.ok(!/https?:\/\/(unpkg|cdn|jsdelivr)/.test(JS), 'никаких CDN в скрипте');
});

test('узлы к печати: строка таблицы обновляется без перезагрузки', () => {
	const { dom } = boot();
	// Голая <td> парсером выбрасывается: строку вставляем таблицей, как на странице.
	dom.document.body.insertAdjacentHTML('beforeend', `<table><tr>
		<td data-part="0"><span data-part-state="0">не заказывали</span><span data-part-error="0"></span></td>
		<td data-part="1"><span data-part-state="1">не заказывали</span><span data-part-error="1"></span></td>
	</tr></table>`);

	dom.NJ667_STL.paintParts([
		{ part: 0, status: 'done', size: 2097152, error: '' },
		{ part: 1, status: 'error', size: 0, error: 'openscad: ERROR: CGAL failed' },
	], 7, 'ab34cd56ef78');

	const zero = dom.document.querySelector('[data-part="0"]');
	assert.equal(zero.querySelector('[data-part-state="0"]').textContent, 'STL готов');

	const link = zero.querySelector('a');
	assert.ok(link, 'ссылка на STL узла появляется сама — иначе жми F5');
	assert.equal(link.getAttribute('href'), '/graph3d/b/ab34cd56ef78?part=0&file=stl&build=7');
	assert.equal(link.textContent, 'STL', 'ссылка подписана как на сервере: «STL»');
	assert.match(zero.textContent, /STL\s*2,00 МБ/, 'размер рядом, тем же видом, что у сервера');

	assert.equal(dom.document.querySelector('[data-part-error="1"]').textContent,
		'openscad: ERROR: CGAL failed');
	assert.equal(dom.document.querySelector('[data-part-state="1"]').className,
		'text-rose-700 dark:text-rose-400');

	// Повторный опрос не должен плодить вторую ссылку на тот же узел.
	dom.NJ667_STL.paintParts([{ part: 0, status: 'done', size: 2097152 }], 7, 'ab34cd56ef78');
	assert.equal(zero.querySelectorAll('a').length, 1);
});

test('камера подбирается под кадр, а не живёт на «2.1 радиуса»', () => {
	// Пустое место под моделью (замечание владельца, 13.10.2026) родилось из
	// расстояния, подобного на глаз: в широком окне сборка сидела мелкой
	// вверху кадра. Теперь дистанция считается из fov и отношения сторон.
	const { dom } = boot();
	const pos = new Float32Array([-50, -50, -50, 50, 50, 50, -50, 50, -50]);

	dom.NJ667_STL.mount(dom.document.getElementById('nj667-stl'),
		{ positions: pos, normals: null, index: null });

	const hit = dom.window.__THREE.log.filter(([name]) => name === 'updateProjectionMatrix').pop();

	assert.ok(hit, 'fit обязан позвонить в updateProjectionMatrix');

	const aspect = Number(hit[1]);
	const dist = Math.hypot(Number(hit[2]), Number(hit[3]), Number(hit[4]));
	// jsdom не раскладывает блоки: clientWidth = clientHeight = 0 → aspect 1,
	// fov 45°, габарит ±50 → радиус 50.
	assert.equal(Math.round(aspect * 100) / 100, 1, 'аспект приходит из окна, а не из 1 по умолчанию');
	assert.ok(Math.abs(dist - 50 / Math.sin((22.5 * Math.PI) / 180) * 1.06) < 0.5,
		`дистанция = радиус / sin(fov/2) с запасом; получили ${dist}`);
	assert.ok(Math.abs(Number(hit[3]) / Number(hit[2]) - 0.8 / 0.6) < 0.01,
		'направление взгляда: сверху под углом (y/x = 0.8/0.6), а не снизу');

	dom.close();
});

test('договор вёрстки: у строк узлов есть якоря для скрипта', () => {
	for (const attr of ['data-part="', 'data-part-state="', 'data-part-error="']) {
		assert.ok(VIEW.includes(attr), `вьюха обязана отдавать ${attr}`);
	}

	assert.ok(VIEW.includes('name="parts"'), 'кнопка пачки узлов на месте');
});

/**
 * Ось: OpenSCAD пишет Z вверх, three.js — Y вверх. Без поворота модель лежала
 * на боку (вопрос владельца 13.10.2026, скриншот стенда). Поворот обязан стоять
 * до расчёта габарита: иначе камера и сетка считаются по неповёрнутой модели.
 */
test('ось: Z вверх из OpenSCAD становится Y вверх до расчёта габарита', () => {
	const { dom, THREE } = boot();
	// вершина на высоте 20 мм по Z — как у модели в OpenSCAD
	const pos = new Float32Array([0, 0, 0, 20, 0, 0, 0, 0, 20]);

	dom.NJ667_STL.mount(dom.document.getElementById('nj667-stl'), { positions: pos, normals: null, index: null });

	const names = THREE.log.map((row) => row[0]);
	const rot = THREE.log.find((row) => row[0] === 'rotateX');

	assert.ok(rot, 'поворот по X применён');
	assert.equal(rot[1], -1.571, 'поворот -90°: (x, y, z) → (x, z, -y)');
	assert.ok(names.indexOf('rotateX') < names.indexOf('computeBoundingSphere'),
		'поворот раньше расчёта габарита');

	const box = THREE.log.find((row) => row[0] === 'bbox').slice(1).map((v) => Math.round(v) + 0);

	// высота (Y) = 20 — это бывший Z; глубина по Z у нас больше не растёт
	assert.deepEqual(box, [0, 0, 0, 20, 20, 0], 'модель стоит на Y: высота 20 по Y, глубина 0');
	dom.close();
});

test('сетка: выключена по умолчанию, включается и подписана в мм', () => {
	const { dom } = boot();
	const el = dom.document.getElementById('nj667-stl');
	const info = dom.document.getElementById('nj667-grid-info');
	// подставка 120 × 80 мм в плане (OpenSCAD XY), высоты нет
	const pos = new Float32Array([0, 0, 0, 120, 0, 0, 0, 80, 0]);
	const view = dom.NJ667_STL.mount(el, { positions: pos, normals: null, index: null });
	const ticks = el.querySelector('.nj667-stl-ticks');

	assert.equal(ticks.style.display, 'none', 'по умолчанию сетка выключена');
	assert.equal(info.style.display, 'none', 'строка размеров тоже скрыта');

	view.setGrid(true);
	assert.equal(ticks.style.display, 'block', 'галка включает подписи');
	assert.equal(info.style.display, '', 'строка размеров видна');
	assert.equal(info.textContent,
		'сетка 20 мм · габарит 120 × 80 × 0 мм (ширина × глубина × высота)');
	assert.equal(el.querySelectorAll('.nj667-stl-tick').length, 7 + 5,
		'подписи: 0..120 по X (7 шт.) и 0..80 по Z (5 шт.)');

	view.setGrid(false);
	assert.equal(ticks.style.display, 'none', 'выключили — подписи спрятаны');
	assert.equal(info.style.display, 'none');
	dom.close();
});

test('сетка: шаг «круглый», лежит на нижней грани модели, отсчёт от угла', () => {
	const { dom } = boot();
	const { niceStep, gridPlan, gridTicks } = dom.NJ667_STL;

	assert.equal(niceStep(12), 20, '12 → 20, не 12 и не 10');
	assert.equal(niceStep(10), 10);
	assert.equal(niceStep(0.3), 0.5);

	const plan = gridPlan({ x: 10, y: 5, z: -3 }, { x: 130, y: 50, z: 77 });

	assert.equal(plan.step, 20, 'по большей стороне 120 мм → около десяти клеток');
	assert.equal(plan.y, 5, 'сетка на нижней грани модели, а не под ней');
	assert.equal(plan.nx, 6, '120 мм по X — шесть клеток по 20');
	assert.equal(plan.nz, 4, '80 мм по Z — четыре клетки');

	const ticks = gridTicks(plan);

	assert.equal(ticks[0].text, '0', 'отсчёт от угла основания');
	assert.equal(ticks[6].text, '120', 'последняя подпись по X — край модели');
	assert.equal(ticks[ticks.length - 1].text, '80', 'последняя подпись по Z — край модели');
	dom.close();
});

test('вёрстка: галка «Сетка» есть и выключена по умолчанию', () => {
	assert.match(VIEW, /<input type="checkbox" id="nj667-grid-toggle"/);
	assert.doesNotMatch(VIEW, /id="nj667-grid-toggle"[^>]*\schecked/, 'по умолчанию галка снята');
	assert.match(VIEW, /id="nj667-grid-info"/);
	assert.match(JS, /GRID_TOGGLE_ID = 'nj667-grid-toggle'/);
});

/**
 * Камера: взгляд сверху, даже когда модель далеко от начала координат. Раньше
 * позиция считалась от нуля, и при модели в километре камера уходила под неё
 * (скриншот владельца: «снизу вверх»).
 */
test('камера: сверху под углом, даже если модель далеко от нуля', () => {
	const { dom, THREE } = boot();
	// OpenSCAD: x 1000..1020, y 1000..1020, z 1000..1020 → после поворота Y-up
	// центр около (1010, 1010, -1010)
	const pos = new Float32Array([1000, 1000, 1000, 1020, 1000, 1010, 1000, 1020, 1020]);

	dom.NJ667_STL.mount(dom.document.getElementById('nj667-stl'), { positions: pos, normals: null, index: null });

	const hit = THREE.log.filter(([name]) => name === 'updateProjectionMatrix').pop();
	const dx = Number(hit[2]) - 1010;
	const dy = Number(hit[3]) - 1010;
	const dz = Number(hit[4]) + 1010;
	const dist = Math.hypot(dx, dy, dz);

	assert.ok(dy > 0, `камера над моделью, а не под ней (dy = ${dy.toFixed(1)})`);
	assert.ok(dy / dist > 0.6, `угол от горизонта больше 35°: sin = ${(dy / dist).toFixed(2)}`);
	dom.close();
});

test('сетка: выбор помнится в браузере под общим ключом модуля', async () => {
	const { dom } = boot('', { 'nj667.grid': '1' });

	await dom.NJ667_STL.start();

	const box = dom.document.getElementById('nj667-grid-toggle');

	assert.equal(box.checked, true, 'галка берёт сохранённое значение');

	box.checked = false;
	box.dispatchEvent(new dom.Event('change'));
	assert.equal(dom.localStorage.getItem('nj667.grid'), '0', 'снятие галки пишется в браузер');
	dom.close();
});

test('задача: кнопка блокируется, рядом спиннер и секундомер с сервера, потом снова активна', () => {
	const { dom } = boot('');
	const doc = dom.document;
	const btn = doc.createElement('button');

	btn.type = 'submit';
	btn.dataset.jobBtn = 'render';
	doc.body.appendChild(btn);

	dom.NJ667_STL.setBusy('render', true, 42, 'идёт');

	assert.equal(btn.disabled, true, 'во время задачи кнопка недоступна');
	assert.equal(btn.dataset.busy, '1');

	const box = doc.querySelector('[data-busy-box="render"]');

	assert.ok(box, 'плашка стоит сразу за кнопкой');
	assert.equal(box.querySelector('[data-busy-word]').textContent, 'идёт');
	assert.equal(box.querySelector('[data-busy-clock]').textContent, '0:42', 'секундомер взял время с сервера');

	dom.NJ667_STL.setBusy('render', false);

	assert.equal(btn.disabled, false, 'после задачи кнопка снова активна');
	assert.equal(doc.querySelector('[data-busy-box="render"]'), null, 'плашка убрана');
	dom.close();
});

test('задача: m:ss как в редакторе', () => {
	const { dom } = boot('');

	assert.equal(dom.NJ667_STL.formatClock(0), '0:00');
	assert.equal(dom.NJ667_STL.formatClock(65.9), '1:05');
	assert.equal(dom.NJ667_STL.formatClock(600), '10:00');
	dom.close();
});

test('вёрстка: кнопки задач помечены, у холста есть «на весь экран»', () => {
	assert.match(VIEW, /name="render" value="1" data-job-btn="render"/, 'Пересчитать и в 3D помечена');
	assert.equal((VIEW.match(/name="parts" value="1" data-job-btn="parts"/g) || []).length, 2,
		'обе кнопки «Собрать узлы» помечены');
	assert.match(VIEW, /name="render_stl" value="1"/, 'модераторский STL-запрос отправляется отдельной кнопкой');
	assert.match(VIEW, /Скачать STL/, 'подпись STL-кнопки сохранена');
	assert.match(VIEW, /id="nj667-stl-full"/, 'кнопка «на весь экран» стоит в блоке просмотра');
	assert.match(VIEW, /#nj667-stl:fullscreen \{[^}]*100vw[^}]*100vh/, ':fullscreen растягивает холст');
	assert.match(JS, /requestFullscreen\(\)/, 'кнопка вызывает полноэкранный режим');
	assert.match(JS, /document\.addEventListener\('fullscreenchange'/, 'иконка следит за выходом из полного экрана');
});

test('узлы к печати: строка, которую уже нарисовал сервер, не дублируется', () => {
	// Жалоба владельца: после загрузки колонка «К печати» показывала ссылку и
	// «в 3D» дважды. Сервер помечает их data-part-dl / data-part-view, и опрос
	// не должен дописывать второй комплект.
	const { dom } = boot();
	const doc = dom.document;

	doc.body.insertAdjacentHTML('beforeend', `<table><tr><td data-part="3">`
		+ `<span data-part-state="3">STL готов</span>`
		+ `<a data-part-dl href="/graph3d/b/ab34cd56ef78?part=3&file=stl&build=7">STL</a>`
		+ `<span>0,20 МБ</span>`
		+ `<button type="button" data-part-view="3" data-build="7" data-size="200000">в 3D</button>`
		+ `<span data-part-error="3"></span>`
		+ `</td></tr></table>`);

	dom.NJ667_STL.paintParts([{ part: 3, status: 'done', size: 200000 }], 7, 'ab34cd56ef78');
	dom.NJ667_STL.paintParts([{ part: 3, status: 'done', size: 200000 }], 7, 'ab34cd56ef78');

	const cell = doc.querySelector('[data-part="3"]');

	assert.equal(cell.querySelectorAll('[data-part-dl]').length, 1, 'одна ссылка STL');
	assert.equal(cell.querySelectorAll('[data-part-view]').length, 1, 'одна кнопка «в 3D»');
	dom.close();
});

test('чтение модели: счётчик по кускам, из Content-Length', async () => {
	// «Читаю модель…» раньше молчало: пока файл едет, человек не знал, сколько
	// осталось. Теперь load отдаёт прогресс кусками и известный размер.
	const { dom } = boot();
	const stl = binaryStl([[0, 0, 1, 0, 0, 0, 10, 0, 0, 0, 10, 0], [0, 0, 1, 0, 0, 0, 0, 10, 0, 10, 10, 0]]);
	const bytes = new Uint8Array(stl);
	const cut = [bytes.slice(0, 100), bytes.slice(100, 180), bytes.slice(180)];
	const seen = [];

	dom.fetch = async () => ({
		ok: true,
		headers: { get: (name) => (name === 'Content-Length' ? String(bytes.byteLength) : null) },
		body: {
			getReader() {
				let at = 0;

				return { read: async () => (at < cut.length ? { done: false, value: cut[at++] } : { done: true }) };
			},
		},
	});

	const el = dom.document.getElementById('nj667-stl');
	const model = await dom.NJ667_STL.load('/graph3d/b/ab34cd56ef78?part=0&file=stl&build=7', el,
		(got, total) => seen.push([got, total]));

	assert.deepEqual(seen.map((x) => x[0]), [0, 100, 180, bytes.byteLength], 'прогресс растёт кусками, с нуля');
	assert.ok(seen.every((x) => x[1] === bytes.byteLength), 'всего берётся из Content-Length');
	assert.equal(model.triangles, 2, 'файл дочитан и разобран целиком');
	dom.close();
});

test('чтение модели: сжатый ответ — без «из», только полученное', () => {
	// Сжатый ответ браузер распаковывает сам: Content-Length тогда — размер
	// архива, и «412 из 1 236 КБ» соврало бы. Показываем только полученное.
	const { dom } = boot();
	const text = dom.NJ667_STL.readingText(412 * 1024, 0, Date.now() - 3000);

	assert.match(text, /^Читаю модель: 412 КБ · 0:03$/);
	const sized = dom.NJ667_STL.readingText(412 * 1024, 1236 * 1024, Date.now() - 65000);

	assert.match(sized, /412 из 1.236 КБ · 1:05/, 'число из числа и секундомер');
	dom.close();
});

test('узлы к печати: отправка без перехода страницы (fetch), ошибка — в строке статуса', async () => {
	// Жалоба владельца: нажатие «Собрать узлы» перезагружало страницу целиком.
	// Теперь пачка уходит fetch-ом, а переход не происходит.
	const { dom } = boot('');
	const doc = dom.document;

	doc.body.insertAdjacentHTML('beforeend', `<form id="fp" method="post" action="/graph3d/b/x">`
		+ `<input type="hidden" name="build" value="7">`
		+ `<button type="submit" name="parts" value="1" data-job-btn="parts">Собрать узлы к печати (5)</button>`
		+ `</form>`);

	const sent = [];

	dom.fetch = async (url, init) => {
		sent.push({ url, method: init.method, parts: init.body.get('parts'), build: init.body.get('build'),
			ajax: init.headers['X-Requested-With'] });

		return { ok: true, status: 200, json: async () => ({ ok: false, error: 'нет свободного узла' }) };
	};

	// start() вешает обработчики на DOMContentLoaded: ждём его, как в браузере.
	await new Promise((resolve) => dom.document.readyState === 'complete'
		? resolve() : dom.addEventListener('load', resolve));
	await new Promise((resolve) => setTimeout(resolve, 0));

	const form = doc.getElementById('fp');
	const btn = form.querySelector('button');
	const ev = new dom.Event('submit', { bubbles: true, cancelable: true });

	Object.defineProperty(ev, 'submitter', { value: btn });
	form.dispatchEvent(ev);

	await new Promise((resolve) => setTimeout(resolve, 30));

	assert.equal(ev.defaultPrevented, true, 'браузер не уходит со страницы сам');
	assert.equal(sent.length >= 1, true, 'пачка ушла запросом');
	assert.equal(sent[0].method, 'POST');
	assert.equal(sent[0].parts, '1', 'имя кнопки едет в теле');
	assert.equal(sent[0].build, '7', 'сборка едет в теле');
	assert.equal(sent[0].ajax, 'XMLHttpRequest', 'сервер увидит AJAX и ответит JSON');
	assert.equal(doc.getElementById('nj667-stl-state').textContent, 'нет свободного узла');
	assert.equal(btn.disabled, false, 'после отказа кнопка снова активна');
	dom.close();
});

test('узлы к печати: после перезагрузки живая пачка видна на кнопке (статус «spec»)', async () => {
	// Жалоба: после перезагрузки прогресса не видно. Сборка без модели (статус spec) с живой
	// пачкой прежде не опрашивалась: страница уходила в ожидание модели и молчала о пачке.
	const { dom } = boot('data-state="spec" data-src="" data-kind="stl" data-status="/graph3d/api/build_status?code=x&build_id=7"');

	dom.document.body.insertAdjacentHTML('beforeend', `<form id="fp" method="post" action="/graph3d/b/x">`
		+ `<button type="submit" name="parts" value="1" data-job-btn="parts">Собрать узлы к печати (2)</button></form>`
		+ `<div class="nj667-parts-table"><div><span data-part-state="0">в очереди</span></div><div><span data-part-state="1">STL готов</span></div></div>`
		+ `<div id="nj667-working" style="display: none"></div>`);

	dom.NJ667_BUILD = { code: 'ab34cd56ef78', csrf: 'x', build_id: 7, job_id: 9, parts_job_id: 14, status: 'spec' };
	dom.fetch = async () => ({
		ok: true,
		json: async () => ({ ok: true, build_id: 7, job: 'running', job_elapsed: 42, status: 'spec',
			parts: [{ part: 0, status: 'queued' }, { part: 1, status: 'done' }] }),
	});

	const started = dom.NJ667_STL.start(); // ждёт модель: не await, иначе тест ждал бы сборку
	await new Promise((resolve) => setTimeout(resolve, 60));

	const btn = dom.document.querySelector('[data-job-btn="parts"]');

	try {
		assert.equal(btn.disabled, true, 'кнопка занята сразу после загрузки');
		assert.match(btn.nextElementSibling.textContent, /идёт · 1 из 2/, 'видно, сколько готово');
		// Слой «Модель строит граф» — только при рендере модели, не при пачке узлов.
		assert.equal(dom.document.getElementById('nj667-working').style.display, 'none', 'пачка — не рендер модели');
	} finally {
		dom.close();
		started.catch(() => {});
	}
});

test('узлы к печати: отправка без submitter тоже не уходит обычным POST', async () => {
	// Старые браузеры не отдают event.submitter: кнопку берём из формы.
	const { dom } = boot('');

	dom.document.body.insertAdjacentHTML('beforeend', `<form id="fp" method="post" action="/graph3d/b/x">`
		+ `<input type="hidden" name="build" value="7">`
		+ `<button type="submit" name="parts" value="1" data-job-btn="parts">Собрать узлы к печати (5)</button></form>`);

	const sent = [];

	dom.fetch = async (url, init) => {
		sent.push(init.body.get('parts'));

		return { ok: true, status: 200, json: async () => ({ ok: false, error: 'нет узла' }) };
	};

	await new Promise((resolve) => setTimeout(resolve, 0));

	const ev = new dom.Event('submit', { bubbles: true, cancelable: true });

	dom.document.getElementById('fp').dispatchEvent(ev);
	await new Promise((resolve) => setTimeout(resolve, 30));

	try {
		assert.equal(ev.defaultPrevented, true, 'переход отменён');
		assert.deepEqual(sent, ['1'], 'кнопка найдена и ушла в fetch');
	} finally {
		dom.close();
	}
});

test('узлы к печати: сборки при загрузке не было — опрос идёт по build_id из ответа', async () => {
	// Жалоба владельца: после клика нет прогресса, после F5 — есть. Сборка появлялась
	// только на сервере: страница без data-status не опрашивала её вовсе.
	const { dom } = boot('');

	dom.document.body.insertAdjacentHTML('beforeend', `<form id="fp" method="post" action="/graph3d/b/x">`
		+ `<button type="submit" name="parts" value="1" data-job-btn="parts">Собрать узлы к печати (2)</button></form>`
		+ `<div><span data-part-state="0">ждёт очереди</span></div><div><span data-part-state="1">ждёт очереди</span></div>`);

	const polled = [];

	dom.fetch = async (url) => {
		if (String(url).includes('build_status')) {
			polled.push(String(url));

			return { ok: true, json: async () => ({ ok: true, build_id: 9, job: 'running', job_elapsed: 1, status: 'spec',
				parts: [{ part: 0, status: 'queued' }, { part: 1, status: 'queued' }] }) };
		}

		return { ok: true, status: 200, json: async () => ({ ok: true, job_id: 5, parts: 2, error: '', build_id: 9, render: false }) };
	};

	await new Promise((resolve) => setTimeout(resolve, 0));

	const ev = new dom.Event('submit', { bubbles: true, cancelable: true });

	dom.document.getElementById('fp').querySelector('button').click();
	await new Promise((resolve) => setTimeout(resolve, 60));

	const btn = dom.document.querySelector('[data-job-btn="parts"]');

	try {
		assert.ok(polled.some((u) => u.includes('build_id=9')), 'опрос идёт по build_id из ответа: ' + polled.join(' '));
		assert.equal(btn.disabled, true, 'кнопка занята, пока узлы в очереди');
	} finally {
		dom.close();
	}
});

test('узлы к печати: живая задача пачки держит кнопку, даже если строк в очереди нет', async () => {
	// Жалоба владельца: кнопка освобождалась через ~3 с, хотя задача шла.
	// Строки могли не отразить задачу — её состояние отдаёт сервер отдельно.
	const { dom } = boot('');

	dom.document.body.insertAdjacentHTML('beforeend', `<form id="fp" method="post" action="/graph3d/b/x">`
		+ `<button type="submit" name="parts" value="1" data-job-btn="parts">Собрать узлы к печати (2)</button></form>`
		+ `<div><span data-part-state="0">ждёт очереди</span></div><div><span data-part-state="1">ждёт очереди</span></div>`);

	let polls = 0;

	dom.fetch = async (url) => {
		if (String(url).includes('build_status')) {
			polls += 1;
			const running = polls === 1;

			return { ok: true, json: async () => ({ ok: true, build_id: 9, job: '', job_elapsed: 0, status: 'spec',
				parts_job: running ? 'running' : 'done', parts_job_elapsed: running ? 3 : 0, parts: [] }) };
		}

		return { ok: true, status: 200, json: async () => ({ ok: true, job_id: 5, parts: 2, error: '', build_id: 9, render: false }) };
	};

	await new Promise((resolve) => setTimeout(resolve, 0));

	dom.document.getElementById('fp').querySelector('button').click();
	await new Promise((resolve) => setTimeout(resolve, 60));

	const btn = dom.document.querySelector('[data-job-btn="parts"]');

	try {
		assert.equal(btn.disabled, true, 'задача идёт — кнопка занята, хотя строк в очереди нет');
		await new Promise((resolve) => setTimeout(resolve, 3300));
		assert.equal(btn.disabled, false, 'задача кончилась — кнопка снова активна');
	} finally {
		dom.close();
	}
});

test('страница сборки: .scad и выдача файла — только администратору; колонки «Чертёж» нет', () => {
	const C = readFileSync(
		new URL('../../application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php', import.meta.url), 'utf8');
	const at = C.indexOf("if ($format === 'scad')");

	assert.ok(at > 0, 'ветка scad есть');
	assert.match(C.slice(at, at + 300), /_can_moderate/, 'выдача .scad закрыта для не-админа (право nj667.moderate)');
	const V = readFileSync(
		new URL('../../application/modules/nj667_graph3d/views/nj667/build.php', import.meta.url), 'utf8');

	assert.doesNotMatch(V, />Чертёж<\/th>/, 'колонка «Чертёж» убрана из таблицы');
	assert.doesNotMatch(V, /format=svg/, 'ссылка на чертёж узла убрана из вьюхи');
	assert.match(V, /\$can_moderate \? '\.scad/, 'подсказка про .scad только модератору');
});

test('страница сборки: спиннер слоя «Модель строит граф» крутится (общий n2j-spin)', () => {
	const V = readFileSync(
		new URL('../../application/modules/nj667_graph3d/views/nj667/build.php', import.meta.url), 'utf8');

	assert.match(V, /<svg class="nj667-spin n2j-spin /, 'у спиннера слоя нет общего класса n2j-spin');
});

test('кнопка «в 3D» / «вернуть изделие»: в активном состоянии нет белого на белом', () => {
	const J = readFileSync(
		new URL('../../public_html/assets/js/nj667-stl.js', import.meta.url), 'utf8');
	const on = /const PART_BTN_ON = '([^;]*)';/.exec(J);
	const off = /const PART_BTN_OFF = '([^;]*)';/.exec(J);

	assert.ok(on && off, 'классы кнопки вынесены в две константы');
	assert.match(on[1], /\btext-white\b/);
	assert.doesNotMatch(on[1], /\bbg-white\b/, 'активная кнопка не может быть белой');
	assert.doesNotMatch(J, /btn\.classList\.toggle\('bg-blue-600'/, 'toggle-классы не возвращать');
});

test('узлы к печати: превью узла появляется в своей ячейке, когда узел готов', async () => {
	const { dom } = boot('');

	dom.document.body.insertAdjacentHTML('beforeend', `<form id="fp" method="post" action="/graph3d/b/x">`
		+ `<button type="submit" name="parts" value="1" data-job-btn="parts">Собрать узлы к печати (1)</button></form>`
		+ `<table><tr><td><span data-part-state="0">ждёт очереди</span></td><td data-part-preview="0"></td></tr></table>`);

	dom.fetch = async (url) => {
		if (String(url).includes('build_status')) {
			return { ok: true, json: async () => ({ ok: true, build_id: 9, job: '', job_elapsed: 0, status: 'spec',
				parts_job: '', parts: [{ part: 0, status: 'done', size: 100 }] }) };
		}

		return { ok: true, status: 200, json: async () => ({ ok: true, job_id: 5, parts: 1, error: '', build_id: 9, render: false }) };
	};

	await new Promise((resolve) => setTimeout(resolve, 0));

	dom.document.getElementById('fp').querySelector('button').click();
	await new Promise((resolve) => setTimeout(resolve, 60));

	try {
		const img = dom.document.querySelector('[data-part-preview="0"] img');

		assert.ok(img, 'картинка вставлена в ячейку превью');
		assert.match(img.getAttribute('src'), /file=preview&build=9/, 'адрес превью — по file=preview');
	} finally {
		dom.close();
	}
});

test('превью узла: сервер отдаёт PNG по file=preview; колонка «Превью» между «Тип» и «Копий»', () => {
	const C = readFileSync(
		new URL('../../application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php', import.meta.url), 'utf8');
	const V = readFileSync(
		new URL('../../application/modules/nj667_graph3d/views/nj667/build.php', import.meta.url), 'utf8');
	const S = readFileSync(
		new URL('../../application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php', import.meta.url), 'utf8');

	assert.match(C, /\$want === 'preview'/, 'GET с file=preview отдаёт превью');
	assert.match(C, /image\/png/, 'превью отдаётся как PNG');
	assert.match(S, /function build_part_attach_preview/, 'приём превью на сайте есть');
	assert.match(S, /x89PNG/, 'принимаем только PNG');
	assert.match(V, /Тип<\/th><th[^>]*>Превью<\/th><th[^>]*>Копий/, 'порядок колонок: Тип, Превью, Копий');
	assert.match(V, /data-part-preview=/, 'у каждой строки есть ячейка превью');
});

test('узлы к печати: сервер отвечает JSON на AJAX вместо перехода 303', () => {
	const C = readFileSync(
		new URL('../../application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php', import.meta.url), 'utf8');
	const at = C.indexOf("Кнопка «узлы» из страницы");

	assert.ok(at > 0, 'ветка AJAX есть');
	assert.match(C.slice(at, at + 400), /is_ajax\(\)/, 'ветка срабатывает только на AJAX');
	// Оба пути (сборка есть / сборки не было) отвечают через один помощник.
	const helper = C.indexOf('function _parts_reply');
	assert.ok(helper > 0, 'общий помощник ответа есть');
	assert.match(C.slice(helper, helper + 900), /application\/json/, 'помощник отдаёт JSON');
	// Без этого базовый after() подменяет JSON телом страницы (200 + HTML) — кнопка
	// писала «не поставились» при уже стоящей в очереди пачке.
	assert.match(C.slice(helper, helper + 900), /\$this->auto_render = FALSE;/,
		'помощник выключает авто-шаблон, иначе JSON заменяет страница');
	assert.equal((C.match(/_parts_reply\(/g) || []).length, 3, 'два пути + объявление');
	assert.match(C, /build_parts_render\(\$here,/, 'пачка на $here, не на поле формы');
});

const MODULE = (...p) => new URL(path.join('../../application/modules/nj667_graph3d', ...p), import.meta.url);

test('палочки и детали: «Дополнительно» убрана, колонки видны; вид «Плитки/Таблица» на месте', () => {
	const V = readFileSync(MODULE('views/nj667/build.php'), 'utf8');

	assert.doesNotMatch(V, /Дополнительно/, 'кнопки «Дополнительно» больше нет');
	assert.doesNotMatch(V, /nj667-xcol|data-xcols/, 'скрытых колонок больше нет — все видны');
	assert.match(V, /<th class="py-0\.5 font-medium">Между узлами<\/th>/, 'колонка «Между узлами» видна всегда');
	assert.match(V, /<th class="py-0\.5 pr-2 font-medium">Пазов<\/th>/, 'в таблице деталей есть «Пазов»');
	assert.match(V, /<th class="py-0\.5 pr-2 font-medium">Узлы<\/th>/, 'в таблице деталей есть «Узлы»');
	assert.match(V, /data-pview="tiles"[^>]*>Плитки<\/button>/, 'переключатель: «Плитки»');
	assert.match(V, /data-pview="table"[^>]*>Таблица<\/button>/, 'переключатель: «Таблица»');
	assert.match(V, /nj667_pview=table/, 'вид запоминается в куке модуля');
	assert.match(V, /data-part-tile=/, 'плитка узла есть');
	assert.doesNotMatch(V, /К печати<\/div>/, 'в плитке нет лишней подписи «К печати»');
	// Одна разметка превью и статуса на обе раскладки — правка не разъедется.
	assert.equal(V.split('echo $part_preview_html[$pi];').length - 1, 2, 'превью в таблице и в плитке — из одной разметки');
	assert.equal(V.split('echo $part_status_html[$pi];').length - 1, 2, 'статус в таблице и в плитке — из одной разметки');
});

test('вид списка: JS ведёт класс на <html> и кнопки, счёт узлов только по таблице', () => {
	const JS = readFileSync(new URL('../../public_html/assets/js/nj667-stl.js', import.meta.url), 'utf8');

	assert.match(JS, /document\.cookie = 'nj667_pview=' \+ \(table \? 'table' : 'tiles'\) \+ '; path=\/graph3d;/,
		'кука nj667_pview на весь модуль');
	assert.match(JS, /querySelectorAll\('\.nj667-parts-table \[data-part-state\]'\)/,
		'счёт «Собрать узлы (N)» — по таблице, без дублей плиток');
});

test('кнопки задач: «Пересчитать» без «в 3D» убрана; «Пересчитать и в 3D» — главная и крупнее', () => {
	const V = readFileSync(MODULE('views/nj667/build.php'), 'utf8');

	assert.doesNotMatch(V, /<button[^>]*>\s*Пересчитать\s*<\/button>/, 'отдельной «Пересчитать» нет');
	assert.doesNotMatch(V, /без «в 3D» — только расчёт/, 'подсказка про неё тоже убрана');

	const render = V.match(/<button[^>]*data-job-btn="render"[^>]*>/);

	assert.ok(render, 'кнопка render есть');
	// Роль по эталону v6: пересчёт — запуск расчёта, синяя «процесс»; полный
	// размер компонента (44 px, текст 14 px), не компактный n2j-btn-sm.
	assert.match(render[0], /\bn2j-btn-process\b/, 'главная кнопка расчёта');
	assert.doesNotMatch(render[0], /\bn2j-btn-sm\b/, 'крупнее: полный размер, не компактный');
});

test('ночной режим: неотмеченный «тип палочек» при наведении не белый на белом', () => {
	const V = readFileSync(MODULE('views/nj667/build.php'), 'utf8');

	assert.match(V, /hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white/,
		'в ночи при наведении фон тёмный, текст светлый');
});

test('глубина посадки по умолчанию 14 мм — и в конфиге, и в расчёте', () => {
	const cfg = readFileSync(MODULE('config/nj667_graph3d.php'), 'utf8');
	const svc = readFileSync(MODULE('classes/Service/Nj667/Build.php'), 'utf8');

	assert.match(cfg, /'stick_insert_depth' => \['title' => 'Глубина посадки, мм'[^\]]*'def' => 14\.0\]/);
	assert.match(svc, /'stick_insert_depth', 14\.0\)/);
});

test('вид списка: «Таблица» ставит класс на <html>, «Плитки» снимает; aria-pressed отражает выбор', async () => {
	const { dom } = boot('');

	dom.document.body.insertAdjacentHTML('beforeend',
		'<div><button type="button" data-pview="tiles" aria-pressed="true">Плитки</button>'
		+ '<button type="button" data-pview="table" aria-pressed="false">Таблица</button></div>');

	await new Promise((resolve) => setTimeout(resolve, 0));

	const root = dom.window.document.documentElement;
	const [tiles, table] = dom.document.querySelectorAll('[data-pview]');

	try {
		assert.equal(root.classList.contains('nj667-pview-table'), false, 'по умолчанию плитки');

		table.click();
		assert.equal(root.classList.contains('nj667-pview-table'), true, 'после «Таблица» — таблица');
		assert.equal(table.getAttribute('aria-pressed'), 'true');
		assert.equal(tiles.getAttribute('aria-pressed'), 'false');

		tiles.click();
		assert.equal(root.classList.contains('nj667-pview-table'), false, 'после «Плитки» — плитки');
		assert.equal(tiles.getAttribute('aria-pressed'), 'true');
	} finally {
		dom.close();
	}
});

test('узлы к печати: статус и превью обновляются и в таблице, и в плитке', async () => {
	const { dom } = boot('');

	dom.document.body.insertAdjacentHTML('beforeend',
		'<form id="fp" method="post" action="/graph3d/b/x">'
		+ '<button type="submit" name="parts" value="1" data-job-btn="parts">Собрать узлы к печати (1)</button></form>'
		+ '<div class="nj667-parts-table"><table><tr><td><span data-part-state="0">ждёт очереди</span></td>'
		+ '<td data-part-preview="0"></td></tr></table></div>'
		+ '<div class="nj667-parts-tiles"><div><div><span data-part-state="0">ждёт очереди</span></div>'
		+ '<div data-part-preview="0"></div></div></div>');

	dom.fetch = async (url) => {
		if (String(url).includes('build_status')) {
			return { ok: true, json: async () => ({ ok: true, build_id: 9, job: '', job_elapsed: 0, status: 'spec',
				parts_job: '', parts: [{ part: 0, status: 'done', size: 100 }] }) };
		}

		return { ok: true, status: 200, json: async () => ({ ok: true, job_id: 5, parts: 1, error: '', build_id: 9, render: false }) };
	};

	await new Promise((resolve) => setTimeout(resolve, 0));

	dom.document.getElementById('fp').querySelector('button').click();
	await new Promise((resolve) => setTimeout(resolve, 60));

	try {
		const states = Array.from(dom.document.querySelectorAll('[data-part-state="0"]'));

		assert.equal(states.length, 2, 'два экземпляра статуса: таблица и плитка');
		states.forEach((el) => assert.equal(el.textContent, 'STL готов', 'оба показывают «STL готов»'));
		assert.equal(dom.document.querySelectorAll('[data-part-preview="0"] img').length, 2, 'картинка в обеих');
		assert.equal(dom.document.querySelectorAll('[data-part-dl]').length, 2, 'ссылка STL в обеих');
	} finally {
		dom.close();
	}
});

test('«Показать изделие целиком»: возврат из узла восстанавливает размер и рендер целиком', async () => {
	const glb = glbFile({
		positions: [0, 0, 0, 10, 0, 0, 0, 10, 0],
		normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
		indices: [0, 1, 2],
	});
	const { dom } = boot('data-state="queued" data-src="" data-kind="stl" data-status="/status" data-stl-size="4194304" data-render-ms="13800"');

	dom.NJ667_BUILD = { code: 'ab34cd56ef78', csrf: 'x', build_id: 7, job_id: 9, status: 'queued' };
	dom.fetch = async (url) => {
		if (String(url).includes('/status')) {
			return { ok: true, json: async () => ({ ok: true, build_id: 7, status: 'done', error: '', stl_size: 0, glb_size: 4194304 }) };
		}

		return { ok: true, arrayBuffer: async () => glb };
	};

	const body = dom.document.body;
	const part = dom.document.createElement('button');

	part.setAttribute('data-part-view', '0');
	part.setAttribute('data-build', '7');
	part.setAttribute('data-size', '200000');
	part.setAttribute('data-render-ms', '6500');
	body.appendChild(part);

	const whole = dom.document.createElement('button');

	whole.setAttribute('data-show-whole', '');
	body.appendChild(whole);

	// start() сам запускается на DOMContentLoaded, как в браузере. Второй вызов из теста
	// завёл бы вторую копию опроса, и она перерисовала бы изделие поверх узла.
	const state = () => dom.document.getElementById('nj667-stl-state').textContent;
	const meta = () => dom.document.getElementById('nj667-stl-meta').textContent;
	// Показ узла асинхронный: ждём смены надписи, а не угадываем паузу.
	const settle = async (text) => {
		for (let i = 0; i < 200 && state() !== text; i++) {
			await new Promise((resolve) => setTimeout(resolve, 20));
		}
	};

	await new Promise((resolve) => dom.document.readyState === 'complete' ? resolve() : dom.addEventListener('load', resolve));
	await settle('Изделие целиком:');
	assert.equal(state(), 'Изделие целиком:');
	assert.equal(meta(), '4 МБ, рендер 13.8 с', 'изделие целиком: свой размер и свой рендер');

	part.click();
	await settle('Узел #1');
	assert.equal(state(), 'Узел #1');
	assert.equal(meta(), '0,19 МБ, рендер 6.5 с', 'узел: свой размер и рендер узла');

	whole.click();
	await settle('Изделие целиком:');
	assert.equal(state(), 'Изделие целиком:');
	assert.equal(meta(), '4 МБ, рендер 13.8 с', 'после узла возврат обязан вернуть размер изделия, а не узла');
	dom.close();
});

test('вёрстка: сетка в холсте, «изделие целиком» под деталями, в статусе нет служебной болтовни', () => {
	const canvasAt = VIEW.indexOf('id="nj667-stl"');
	const gridAt = VIEW.indexOf('id="nj667-grid-toggle"');
	const stateAt = VIEW.indexOf('id="nj667-stl-state"');

	assert.ok(gridAt > canvasAt && gridAt < stateAt, 'переключатель «Сетка» стоит внутри холста, а не в строке статуса');
	assert.ok(VIEW.includes('class="nj667-stl-grid absolute '), 'сетка оформлена плашкой на холсте');
	assert.ok(VIEW.includes('data-show-whole'), 'под деталями есть «Показать изделие целиком»');

	for (const phrase of ['вращайте', 'колесо — зум', 'Красок в файле', 'glTF, цвет из glTF', 'из файла для печати']) {
		assert.ok(!VIEW.includes(phrase), `в статусе не должно быть «${phrase}»`);
	}
});

