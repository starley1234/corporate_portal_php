/**
 * nj667_graph3d — редактор пространственных графов на three.js.
 *
 * Что здесь главное:
 *
 *   1. ИСТОЧНИК ПРАВДЫ — топология (nodes/edges), а не картинка. Всё, что
 *      видно на холсте, каждый раз пересобирается из graph: поменяли массив —
 *      перерисовали. Поэтому «перерисовать из SVG», как в 2D-редакторах, тут
 *      невозможно по построению;
 *   2. ПРАВКИ — патчами RFC 6902. Каждое действие человека (сдвинул узел,
 *      добавил деталь, соединил) рождает пару «вперёд/назад», поэтому
 *      undo/redo не ограничены по глубине и не требуют копий графа. Сервер
 *      считает свою разность (для истории ревизий), браузер — свою (для
 *      мгновенного отката);
 *   3. УМНЫЕ НАПРАВЛЯЮЩИЕ — при перетаскивании узел прилипает к координатам
 *      соседей по X/Y/Z. Без этого симметрия («ножки стула на одной высоте»)
 *      живёт только на глаз;
 *   4. ИНПЕЙНТИНГ — выделил узел, написал «сделай спортивный руль-баран» →
 *      воркер получает только окрестность выбранного, а ответ приклеивается к
 *      существующему графу по id (merge);
 *   5. ЕСЛИ WEBGL НЕ ЗАВЕЛСЯ — не пустой кадр, а 2D-проекция на canvas: её
 *      можно тягать и она честно говорит, что 3D недоступен. Владелец уже
 *      видел пустой WebGL-кадр в nj570 (см. nj643_cad) — второй раз так нельзя;
 *   6. ЭКСПОРТ — GLB собирается из графа отдельной сценой (узлы-меши,
 *      рёбра-цилиндры), без гизмо и подписей. WebGL для этого не нужен:
 *      GLTFExporter работает от геометрии. JSON — сам источник правды.
 *
 * Модуль ES: three подключён картой импорта (importmap) в разметке страницы,
 * вендор лежит в /assets/vendor/three/mod — CDN в этом проекте запрещён
 * проверкой .tools/php_check_all.sh (assets_cdn_lint).
 */

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { TransformControls } from 'three/addons/controls/TransformControls.js';
import { CSS2DRenderer, CSS2DObject } from 'three/addons/renderers/CSS2DRenderer.js';
import { GLTFExporter } from 'three/addons/exporters/GLTFExporter.js';

// ---------------------------------------------------------------- состояние

const CFG = window.NJ667
	|| JSON.parse(((document.getElementById('nj667-config') || {}).textContent) || '{}');
const API = '/graph3d/api';

const limits = CFG.limits || { max_nodes: 500, max_label: 80 };
const types = CFG.node_types || {};
const etypes = CFG.edge_types || {};
const canvasCfg = CFG.canvas || { radius: 24, snap: 0.5, snap_px: 8 };
const RADIUS = parseFloat(canvasCfg.radius) || 24;
const STEP = parseFloat(canvasCfg.snap) || 0.5;
// Порог прилипания в единицах сцены: «snap_px» — про экран, а меряем мы в
// мире, поэтому шаг сетки умножаем на 4 (на радиусе 24 это ~2 единицы).
const SNAP_TOL = STEP * 4;

// Сетка помнится в браузере для всего модуля: редактор и просмотр сборки
// читают и пишут один ключ. Записи нет — каждая страница берёт свой дефолт.
const GRID_PREF_KEY = 'nj667.grid';

function readGridPref(fallback) {
	try {
		const raw = window.localStorage.getItem(GRID_PREF_KEY);

		return raw === null ? fallback : raw === '1';
	} catch (e) {
		return fallback; // запрет хранилища или приватный режим — живём без памяти
	}
}

function writeGridPref(on) {
	try {
		window.localStorage.setItem(GRID_PREF_KEY, on ? '1' : '0');
	} catch (e) {
		// не запомнили — галка всё равно работает в этом окне
	}
}

const state = {
	code: CFG.code || '',
	csrf: CFG.csrf || '',
	graph: normalise(CFG.graph || { meta: {}, nodes: [], edges: [] }),
	mode: CFG.defaults && CFG.defaults.mode ? CFG.defaults.mode : 'translate',
	grid: readGridPref(CFG.defaults ? !!CFG.defaults.grid : true),
	snap: CFG.defaults ? !!CFG.defaults.snap : true,
	labels: CFG.defaults ? !!CFG.defaults.labels : false,
	selected: [],
	selectedEdge: null,
	undo: [],
	redo: [],
	busy: false,
	dirty: false,
	review: !!CFG.review,
	published: !!CFG.published,
	job: Number(CFG.job_id || 0) || 0,
	llmAllowed: CFG.llm_allowed !== false,
	webgl: true,
};

let renderer, labelRenderer, scene, camera, controls, gizmo, raycaster, pointer;
let groupNodes, groupEdges, groupGuides, gridHelper;
const nodeMesh = new Map();   // id узла → меш
const edgeLine = new Map();   // id ребра → линия
const nodeLabel = new Map();  // id узла → CSS2DObject

const els = {};

// ---------------------------------------------------------------- утилиты

function clamp(v, min, max) { return v < min ? min : (v > max ? max : v); }

function uid(prefix) {
	let n = 1;
	const have = new Set();
	state.graph.nodes.forEach((n2) => have.add(n2.id));
	while (have.has(prefix + n)) { n++; }
	return prefix + n;
}

/** Привести граф к виду, с которым можно работать (id, числа, типы). */
function normalise(graph) {
	graph = graph && typeof graph === 'object' ? graph : {};
	const nodes = [];
	const seen = new Set();

	(Array.isArray(graph.nodes) ? graph.nodes : []).forEach((n, i) => {
		if (!n || typeof n !== 'object') { return; }
		let id = String(n.id || '').trim() || 'n' + (i + 1);
		while (seen.has(id)) { id = id + '_'; }
		seen.add(id);
		nodes.push({
			id,
			type: types[n.type] ? n.type : 'connector',
			x: round2(num(n.x)), y: round2(num(n.y)), z: round2(num(n.z)),
			label: String(n.label || '').slice(0, limits.max_label || 80),
			data: (n.data && typeof n.data === 'object') ? n.data : {},
		});
	});

	const edges = [];
	const seenE = new Set();
	const byId = new Set(nodes.map((n) => n.id));

	(Array.isArray(graph.edges) ? graph.edges : []).forEach((e, i) => {
		if (!e || typeof e !== 'object') { return; }
		let id = String(e.id || '').trim() || 'e' + (i + 1);
		while (seenE.has(id)) { id = id + '_'; }
		if (!byId.has(e.source) || !byId.has(e.target) || e.source === e.target) { return; }
		seenE.add(id);
		edges.push({
			id, source: String(e.source), target: String(e.target),
			type: etypes[e.type] ? e.type : 'rigid',
			label: String(e.label || '').slice(0, limits.max_label || 80),
			style: e.style === 'curved' ? 'curved' : 'line',
		});
	});

	return { meta: (graph.meta && typeof graph.meta === 'object') ? graph.meta : {}, nodes, edges };
}

function num(v) { const f = parseFloat(v); return isFinite(f) ? f : 0; }
function round2(v) { return Math.round(v * 100) / 100; }

function nodeIndex(id) { return state.graph.nodes.findIndex((n) => n.id === id); }
function nodeById(id) { return state.graph.nodes[nodeIndex(id)] || null; }

function typeColor(type) { return (types[type] && types[type].color) || '#3b82f6'; }
function edgeColor(type) { return (etypes[type] && etypes[type].color) || '#9ca3af'; }

function distToSegment(px, py, x1, y1, x2, y2) {
	const dx = x2 - x1;
	const dy = y2 - y1;
	const len = dx * dx + dy * dy;

	if (len < 0.0001) { return Math.hypot(px - x1, py - y1); }

	const t = Math.max(0, Math.min(1, ((px - x1) * dx + (py - y1) * dy) / len));

	return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
}

function hitEdge2d(px, py) {
	let best = 8;
	let found = null;

	state.graph.edges.forEach((edge) => {
		const a = nodeById(edge.source);
		const b = nodeById(edge.target);
		if (!a || !b || !fallback2d.px) { return; }
		const p = fallback2d.px(a);
		const q = fallback2d.px(b);
		const d = distToSegment(px, py, p.x, p.y, q.x, q.y);
		if (d < best) { best = d; found = edge; }
	});

	return found;
}

function note(text, bad) {
	if (!els.note) { return; }
	els.note.textContent = text || '';
	els.note.className = 'n2j-hint mt-2' + (bad ? ' font-semibold text-rose-600 dark:text-rose-400' : '');
}

function paintQuota(quota) {
	if (!quota || typeof quota !== 'object') { return; }
	state.llmAllowed = !!quota.ready && !!quota.allowed;

	if (els.dailyQuota) {
		if (quota.ready) {
			els.dailyQuota.textContent = 'LLM-операции сегодня: ' + Number(quota.used || 0)
				+ ' / ' + Number(quota.limit || 0) + ', осталось ' + Number(quota.remaining || 0) + ' (UTC).';
		} else {
			els.dailyQuota.textContent = quota.error || 'Дневная квота временно недоступна.';
		}
	}

	['generateBtn', 'inpaintBtn'].forEach((key) => {
		if (els[key]) { els[key].disabled = state.busy || !state.llmAllowed; }
	});
}

// -------------------------------------------------------------- запросы

function api(path, data) {
	data = data || {};
	data.csrf = state.csrf;
	data.code = state.code;

	return fetch(API + '/' + path, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'Accept': 'application/json',
			'X-Requested-With': 'XMLHttpRequest',
		},
		body: JSON.stringify(data),
	}).then((r) => r.json().then((d) => ({ status: r.status, data: d })));
}

function failOf(res, fallback) {
	return (res.data && res.data.error) ? res.data.error : fallback;
}

// ------------------------------------------------------- патчи RFC 6902

/**
 * Применить патч к текущему графу (поддержаны add / replace / remove).
 *
 * Своя реализация, а не библиотека: операций три, а зависимостей в проекте
 * и без того хватает. Сервер считает разность тем же набором правил, поэтому
 * результат совпадает.
 */
function applyPatch(graph, ops) {
	ops.forEach((op) => {
		const parts = String(op.path || '').split('/').slice(1).map(unescapeToken);

		if (parts.length === 0) { return; }

		let node = graph;
		for (let i = 0; i < parts.length - 1; i++) {
			const key = parts[i];
			if (node === null || typeof node !== 'object') { return; }
			if (Array.isArray(node)) {
				const idx = key === '-' ? node.length : parseInt(key, 10);
				if (isNaN(idx)) { return; }
				node = node[idx];
			} else {
				if (!(key in node)) { node[key] = {}; }
				node = node[key];
			}
		}

		const last = parts[parts.length - 1];

		if (Array.isArray(node)) {
			if (op.op === 'add') {
				const idx = last === '-' ? node.length : clamp(parseInt(last, 10) || 0, 0, node.length);
				node.splice(idx, 0, op.value);
			} else {
				const idx = parseInt(last, 10);
				if (isNaN(idx) || idx < 0 || idx >= node.length) { return; }
				if (op.op === 'remove') { node.splice(idx, 1); } else { node[idx] = op.value; }
			}
			return;
		}

		if (node === null || typeof node !== 'object') { return; }

		if (op.op === 'remove') { delete node[last]; } else { node[last] = op.value; }
	});

	return normalise(graph);
}

function unescapeToken(t) { return String(t).replace(/~1/g, '/').replace(/~0/g, '~'); }

/** Обратный патч: invert подходит к историям из «replace» и «add/remove». */
function invert(ops) {
	return ops.slice().reverse().map((op) => {
		if (op.op === 'add') { return { op: 'remove', path: op.path, prev: op.value }; }
		if (op.op === 'remove') { return { op: 'add', path: op.path, value: op.prev }; }
		return { op: 'replace', path: op.path, value: op.prev };
	});
}

/**
 * Путь для удаления только что добавленного элемента.
 *
 * «add» приходит с путём `/nodes/-` (дописать в конец), а удалять по «-»
 * нельзя: для remove нужен номер. Поэтому до применения смотрим, какой длины
 * был список, — и отменяем добавление удалением по этому номеру.
 */
function removalPath(graph, path, atEndIndex) {
	const parts = String(path).split('/');

	if (parts[parts.length - 1] !== '-') { return path; }

	parts[parts.length - 1] = String(atEndIndex);

	return parts.join('/');
}

/**
 * Выполнить действие: применяем патч, кладём пару в историю, перерисовываем.
 *
 * undo-ветка берёт предыдущие значения СРАЗУ (до применения) — иначе после
 * второго такого же действия «назад» вернёт уже новое значение.
 */
function commit(ops, label) {
	if (!ops.length) { return; }

	const before = ops.map((op) => ({
		op,
		prev: readPath(state.graph, op.path),
		size: readPath(state.graph, String(op.path).split('/').slice(0, -1).join('/')),
	}));

	const undoOps = before.slice().reverse().map((row) => {
		if (row.op.op === 'add') {
			const list = Array.isArray(row.size) ? row.size : [];

			return { op: 'remove', path: removalPath(state.graph, row.op.path, list.length) };
		}

		if (row.op.op === 'remove') { return { op: 'add', path: row.op.path, value: row.prev }; }

		return { op: 'replace', path: row.op.path, value: row.prev };
	});

	state.graph = applyPatch(state.graph, ops);
	state.undo.push({ do: ops, undo: undoOps, label: label || 'правка' });
	state.redo.length = 0;
	state.dirty = true;

	rebuild();
	paintHistory();
	markDirty();
}

function readPath(graph, path) {
	const parts = String(path || '').split('/').slice(1).map(unescapeToken);
	let node = graph;

	for (const key of parts) {
		if (node === null || node === undefined) { return undefined; }
		node = Array.isArray(node) ? node[key === '-' ? node.length - 1 : parseInt(key, 10)] : node[key];
	}

	return node;
}

function undo() {
	const step = state.undo.pop();
	if (!step) { return; }
	state.graph = applyPatch(state.graph, step.undo);
	state.redo.push(step);
	state.dirty = true;
	rebuild();
	paintHistory();
	markDirty();
	note('Отменено: ' + step.label);
}

function redo() {
	const step = state.redo.pop();
	if (!step) { return; }
	state.graph = applyPatch(state.graph, step.do);
	state.undo.push(step);
	state.dirty = true;
	rebuild();
	paintHistory();
	markDirty();
	note('Повторено: ' + step.label);
}

// --------------------------------------------------------------- действия

function pathOfNode(index) { return '/nodes/' + index; }

function moveNode(id, from, to) {
	const i = nodeIndex(id);
	if (i < 0) { return; }

	const ops = [];

	['x', 'y', 'z'].forEach((axis) => {
		if (Math.abs(from[axis] - to[axis]) > 0.0001) {
			ops.push({ op: 'replace', path: '/nodes/' + i + '/' + axis, value: round2(to[axis]) });
		}
	});

	commit(ops, 'сдвиг «' + (nodeById(id) || {}).label + '»');
}

function addNodeNear(to) {
	if (state.graph.nodes.length >= (limits.max_nodes || 500)) {
		note('Узлов уже ' + limits.max_nodes + ' — больше нельзя.', true);
		return;
	}

	const id = uid('n');

	commit([{ op: 'add', path: '/nodes/-', value: {
		id, type: 'connector',
		x: round2(clamp(to.x, -RADIUS, RADIUS)),
		y: round2(clamp(to.y, -RADIUS, RADIUS)),
		z: round2(clamp(to.z, -RADIUS, RADIUS)),
		label: '', data: {},
	} }], 'новый узел');

	select([id]);
}

function deleteSelected() {
	if (state.selectedEdge) {
		deleteSelectedEdge();
		return;
	}

	if (!state.selected.length) { return; }

	const ops = [];

	// Сначала связи (с конца, чтобы индексы не поехали), потом узлы.
	for (let i = state.graph.edges.length - 1; i >= 0; i--) {
		const e = state.graph.edges[i];
		if (state.selected.indexOf(e.source) >= 0 || state.selected.indexOf(e.target) >= 0) {
			ops.push({ op: 'remove', path: '/edges/' + i });
		}
	}

	for (let i = state.graph.nodes.length - 1; i >= 0; i--) {
		if (state.selected.indexOf(state.graph.nodes[i].id) >= 0) {
			ops.push({ op: 'remove', path: pathOfNode(i) });
		}
	}

	if (!ops.length) { return; }

	commit(ops, 'удалено узлов: ' + state.selected.length);
	select([]);
}

function deleteSelectedEdge() {
	const id = state.selectedEdge;
	if (!id) { return; }

	const i = state.graph.edges.findIndex((e) => e.id === id);
	if (i < 0) { return; }

	commit([{ op: 'remove', path: '/edges/' + i }], 'удалена связь');
	selectEdge(null);
}

function connectSelected() {
	if (state.selected.length !== 2) {
		note('Выделите ровно два узла: сначала первый, с Shift — второй.', true);
		return;
	}

	const [a, b] = state.selected;

	if (state.graph.edges.some((e) => (e.source === a && e.target === b) || (e.source === b && e.target === a))) {
		note('Эти узлы уже соединены.');
		return;
	}

	commit([{ op: 'add', path: '/edges/-', value: {
		id: uid('e'), source: a, target: b, type: 'rigid', label: 'связь', style: 'line',
	} }], 'новая связь');
}

function updateSelected(patch, label) {
	if (state.selected.length !== 1) { return; }

	const i = nodeIndex(state.selected[0]);
	if (i < 0) { return; }

	const ops = Object.keys(patch).map((field) => ({
		op: 'replace', path: '/nodes/' + i + '/' + field, value: patch[field],
	}));

	commit(ops, label || 'правка узла');
}

// ------------------------------------------------------------------- three

/** Ночь — класс dark на <html>, как у переключателя темы сайта. */
function isNight() {
	return document.documentElement.classList.contains('dark');
}

/**
 * Палитра холста: день — белый, ночь — slate-900.
 * Сетка и 2D-запас идут тем же цветом, иначе при смене темы остаётся «коробка».
 */
function themeColors() {
	return isNight()
		? { bg: '#111827', gridMajor: 0x374151, gridMinor: 0x1f2937, grid2d: '#1f2937', label2d: '#d1d5db' }
		: { bg: '#ffffff', gridMajor: 0x9ca3af, gridMinor: 0xe5e7eb, grid2d: '#e5e7eb', label2d: '#374151' };
}

function makeGrid() {
	const skin = themeColors();
	const grid = new THREE.GridHelper(RADIUS * 2, 24, skin.gridMajor, skin.gridMinor);
	grid.position.y = -RADIUS;
	grid.visible = !!state.grid;
	return grid;
}

function applyTheme() {
	const skin = themeColors();

	if (scene) {
		scene.background = new THREE.Color(skin.bg);
		if (gridHelper) {
			scene.remove(gridHelper);
			if (gridHelper.geometry && gridHelper.geometry.dispose) { gridHelper.geometry.dispose(); }
			const mat = gridHelper.material;
			if (mat) {
				(Array.isArray(mat) ? mat : [mat]).forEach((m) => { if (m && m.dispose) m.dispose(); });
			}
		}
		gridHelper = makeGrid();
		scene.add(gridHelper);
	}

	if (!state.webgl && fallback2d && fallback2d.ctx) {
		fallback2d.paint();
	}
}

let themeWatching = false;

function watchTheme() {
	if (themeWatching || typeof MutationObserver === 'undefined') { return; }
	themeWatching = true;
	new MutationObserver(applyTheme).observe(document.documentElement, {
		attributes: true, attributeFilter: ['class'],
	});
}

function initThree(host) {
	if (!host) { return false; }

	try {
		renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
	} catch (e) {
		state.webgl = false;
		return false;
	}

	// Бывает и так, что конструктор отработал, а контекста нет: проверяем.
	if (!renderer || !renderer.getContext || !renderer.getContext()) {
		state.webgl = false;
		return false;
	}

	// 1.5, не 2: на ретине орбита меньше лагает, картинка почти та же
	// (владелец, 11.10.2026).
	renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
	renderer.setSize(host.clientWidth, host.clientHeight);
	host.appendChild(renderer.domElement);

	labelRenderer = new CSS2DRenderer();
	labelRenderer.setSize(host.clientWidth, host.clientHeight);
	labelRenderer.domElement.style.position = 'absolute';
	labelRenderer.domElement.style.top = '0';
	labelRenderer.domElement.style.left = '0';
	labelRenderer.domElement.style.pointerEvents = 'none';
	host.appendChild(labelRenderer.domElement);

	scene = new THREE.Scene();
	scene.background = new THREE.Color(themeColors().bg);

	camera = new THREE.PerspectiveCamera(50, host.clientWidth / Math.max(1, host.clientHeight), 0.1, 2000);
	camera.position.set(RADIUS * 1.6, RADIUS * 1.1, RADIUS * 1.9);

	scene.add(new THREE.AmbientLight(0xffffff, 0.65));
	const key = new THREE.DirectionalLight(0xffffff, 0.9);
	key.position.set(RADIUS, RADIUS * 2, RADIUS);
	scene.add(key);

	gridHelper = makeGrid();
	scene.add(gridHelper);

	controls = new OrbitControls(camera, renderer.domElement);
	// Без damping камера 1:1 с мышью. 0.08 давал «тягучее» вращение
	// (владелец, 11.10.2026).
	controls.enableDamping = false;
	controls.rotateSpeed = 1.15;
	controls.target.set(0, 0, 0);

	gizmo = new TransformControls(camera, renderer.domElement);
	gizmo.setSize(0.9);
	// Вращение узлам не нужно: деталь — это точка, а не корпус (lockRotation).
	gizmo.showX = gizmo.showY = gizmo.showZ = true;
	scene.add(gizmo);

	raycaster = new THREE.Raycaster();
	pointer = new THREE.Vector2();

	groupNodes = new THREE.Group();
	groupEdges = new THREE.Group();
	groupGuides = new THREE.Group();
	scene.add(groupNodes, groupEdges, groupGuides);

	bindGizmo();
	bindPointer(host);

	window.addEventListener('resize', onResize);
	document.addEventListener('fullscreenchange', onResize);
	document.addEventListener('webkitfullscreenchange', onResize);
	animate();

	return true;
}

function canvasHost() {
	return els.stage || els.canvas;
}

function onResize() {
	const host = canvasHost();

	if (host && !state.webgl && fallback2d && fallback2d.canvas) {
		fallback2d.resize(fallback2d.canvas);
		fallback2d.paint();
	}

	if (!renderer || !host) { return; }
	const w = host.clientWidth;
	const h = host.clientHeight;
	camera.aspect = w / Math.max(1, h);
	camera.updateProjectionMatrix();
	renderer.setSize(w, h);
	if (labelRenderer) { labelRenderer.setSize(w, h); }
}

function animate() {
	requestAnimationFrame(animate);

	if (controls && controls.enableDamping) { controls.update(); }

	if (renderer && scene && camera) {
		renderer.render(scene, camera);
	}

	// CSS2D — отдельные DOM-ноды каждый кадр. Подписи выключены по
	// умолчанию: не гоняем второй рендер вхолостую.
	if (labelRenderer && state.labels && scene && camera) {
		labelRenderer.render(scene, camera);
	}
}

/** Пересобрать сцену из графа целиком (дешевле, чем гадать, что изменилось). */
function rebuild(animateIn) {
	if (!state.webgl) { return fallback2d.paint(); }

	clearGroup(groupNodes);
	clearGroup(groupEdges);
	nodeMesh.clear();
	edgeLine.clear();
	nodeLabel.clear();

	const sprouting = !!animateIn;

	state.graph.nodes.forEach((node, i) => {
		const shape = (types[node.type] && types[node.type].shape) || 'sphere';
		const geo = shape === 'box'
			? new THREE.BoxGeometry(1.2, 0.6, 1.2)
			: new THREE.SphereGeometry(node.type === 'hub' ? 0.85 : 0.6, 20, 16);
		const mat = new THREE.MeshStandardMaterial({
			color: new THREE.Color(typeColor(node.type)),
			roughness: 0.45, metalness: 0.15,
		});
		const mesh = new THREE.Mesh(geo, mat);
		mesh.position.set(node.x, node.y, node.z);
		mesh.userData.id = node.id;

		// «Прорастание»: новая конструкция появляется не мгновенно, а волной.
		if (sprouting) {
			mesh.scale.setScalar(0.01);
			const delay = 30 * i;
			setTimeout(() => growTo(mesh, 1), Math.min(delay, 1200));
		}

		groupNodes.add(mesh);
		nodeMesh.set(node.id, mesh);

		if (state.labels) {
			const div = document.createElement('div');
			div.className = 'nj667-label pointer-events-none whitespace-nowrap rounded-md bg-white/85 px-1.5 py-px text-xs leading-tight text-gray-900 dark:bg-gray-900/70 dark:text-gray-200';
			div.textContent = node.label || node.id;
			const label = new CSS2DObject(div);
			label.position.set(0, 1.1, 0);
			mesh.add(label);
			nodeLabel.set(node.id, label);
		}
	});

	state.graph.edges.forEach((edge, i) => {
		const line = makeEdge(edge);
		groupEdges.add(line);
		edgeLine.set(edge.id, line);

		if (sprouting) {
			line.material.opacity = 0;
			setTimeout(() => { line.material.opacity = 0.9; }, Math.min(200 + 40 * i, 1600));
		}
	});

	gridHelper.visible = state.grid;
	paintSelection();
	paintInspector();

	const nEl = document.getElementById('nj667-stat-nodes');
	const eEl = document.getElementById('nj667-stat-edges');
	if (nEl) { nEl.textContent = String(state.graph.nodes.length); }
	if (eEl) { eEl.textContent = String(state.graph.edges.length); }
}

function growTo(mesh, target) {
	const start = performance.now();

	function step(now) {
		const t = Math.min(1, (now - start) / 320);
		const e = 1 - Math.pow(1 - t, 3);
		mesh.scale.setScalar(0.01 + (target - 0.01) * e);
		if (t < 1) { requestAnimationFrame(step); }
	}

	requestAnimationFrame(step);
}

function clearGroup(group) {
	while (group.children.length) {
		const child = group.children.pop();
		if (child.geometry) { child.geometry.dispose(); }
		if (child.material) { child.material.dispose(); }
	}
}

/**
 * Ребро — прямой отрезок (владелец, 10.10.2026): дуги Безье читались как
 * «линии гнутся сами». style=curved больше не рисуем отдельно.
 */
function makeEdge(edge) {
	const a = nodeById(edge.source);
	const b = nodeById(edge.target);

	if (!a || !b) { return new THREE.Line(new THREE.BufferGeometry(), new THREE.LineBasicMaterial()); }

	const from = new THREE.Vector3(a.x, a.y, a.z);
	const to = new THREE.Vector3(b.x, b.y, b.z);
	const geo = new THREE.BufferGeometry().setFromPoints([from, to]);
	const on = state.selectedEdge === edge.id;
	const mat = new THREE.LineBasicMaterial({
		color: new THREE.Color(on ? 0x38bdf8 : edgeColor(edge.type)),
		transparent: true, opacity: on ? 1 : 0.9,
	});

	const line = new THREE.Line(geo, mat);
	line.userData.id = edge.id;

	return line;
}

/** Пересчитать геометрию рёбер, задетых сдвигом узла. */
function refreshEdges(id) {
	state.graph.edges.forEach((edge) => {
		if (edge.source !== id && edge.target !== id) { return; }

		const line = edgeLine.get(edge.id);
		if (!line) { return; }
		const fresh = makeEdge(edge);
		line.geometry.dispose();
		line.geometry = fresh.geometry;
		edgeLine.set(edge.id, line);
	});
}

// ------------------------------------------------------- умные направляющие

/**
 * Прилипание к осям соседей.
 *
 * Правило Figma, переложенное на 3D: тянем узел — и если его x (или y, или z)
 * почти совпал с таким же значением у другого узла, принимаем чужое значение
 * и показываем по этому узлу направляющую. Именно так ножки стула встают на
 * одну высоту, а колёса — на одну ось.
 */
function snapPosition(id, pos) {
	clearGroup(groupGuides);

	if (!state.snap) { return pos; }

	const out = { x: pos.x, y: pos.y, z: pos.z };
	const hits = { x: null, y: null, z: null };

	state.graph.nodes.forEach((other) => {
		if (other.id === id) { return; }

		['x', 'y', 'z'].forEach((axis) => {
			if (Math.abs(other[axis] - pos[axis]) <= SNAP_TOL
				&& (hits[axis] === null || Math.abs(other[axis] - pos[axis]) < Math.abs(hits[axis][axis] - pos[axis]))) {
				hits[axis] = other;
				out[axis] = other[axis];
			}
		});
	});

	Object.keys(hits).forEach((axis) => {
		if (hits[axis]) { guideLine(hits[axis], axis); }
	});

	// Сетка: без неё «ровно посередине» не достигается никак.
	if (STEP > 0) {
		['x', 'y', 'z'].forEach((axis) => {
			if (!hits[axis]) { out[axis] = round2(Math.round(out[axis] / STEP) * STEP); }
		});
	}

	return { x: round2(clamp(out.x, -RADIUS, RADIUS)),
		y: round2(clamp(out.y, -RADIUS, RADIUS)),
		z: round2(clamp(out.z, -RADIUS, RADIUS)) };
}

function guideLine(node, axis) {
	const from = new THREE.Vector3(node.x, node.y, node.z);
	const to = new THREE.Vector3(node.x, node.y, node.z);

	if (axis === 'x') { from.x = -RADIUS; to.x = RADIUS; }
	if (axis === 'y') { from.y = -RADIUS; to.y = RADIUS; }
	if (axis === 'z') { from.z = -RADIUS; to.z = RADIUS; }

	const geo = new THREE.BufferGeometry().setFromPoints([from, to]);
	const mat = new THREE.LineDashedMaterial({
		color: 0x38bdf8, dashSize: 0.8, gapSize: 0.5, transparent: true, opacity: 0.9,
	});
	const line = new THREE.Line(geo, mat);
	line.computeLineDistances();
	groupGuides.add(line);
}

// -------------------------------------------------------------- выделение

function select(ids) {
	state.selected = ids.slice(0, 2);
	state.selectedEdge = null;
	paintSelection();
	paintInspector();
}

function selectEdge(id) {
	state.selected = [];
	state.selectedEdge = id || null;
	paintSelection();
	paintInspector();
}

function paintSelection() {
	nodeMesh.forEach((mesh, id) => {
		const on = state.selected.indexOf(id) >= 0;
		mesh.material.emissive = new THREE.Color(on ? 0x38bdf8 : 0x000000);
		mesh.material.emissiveIntensity = on ? 0.6 : 0;
	});

	// Гизмо — только к одному узлу и только когда можно править.
	if (gizmo && CFG.readonly) {
		gizmo.detach();
		gizmo.visible = false;
	} else if (gizmo) {
		if (state.selected.length === 1 && nodeMesh.has(state.selected[0])) {
			gizmo.attach(nodeMesh.get(state.selected[0]));
			gizmo.setMode(state.mode === 'scale' ? 'scale' : 'translate');
			gizmo.visible = true;
		} else {
			gizmo.detach();
			gizmo.visible = false;
		}
	}

	edgeLine.forEach((line, id) => {
		const on = state.selectedEdge === id;
		if (line.material && line.material.color) {
			const edge = state.graph.edges.find((e) => e.id === id);
			line.material.color = new THREE.Color(on ? 0x38bdf8 : edgeColor(edge ? edge.type : 'rigid'));
			line.material.opacity = on ? 1 : 0.9;
		}
	});

	if (els.selected) {
		els.selected.textContent = state.selectedEdge
			? ('связь ' + state.selectedEdge)
			: (state.selected.length
				? state.selected.map((id) => (nodeById(id) || {}).id || id).join(' + ')
				: 'ничего не выбрано');
	}

	if (els.delNodeBtn) { els.delNodeBtn.disabled = !state.selected.length; }
	if (els.delEdgeBtn) { els.delEdgeBtn.disabled = !state.selectedEdge; }

	if (els.inpaint) {
		els.inpaint.classList.toggle('hidden', state.selected.length === 0);
	}
}

function bindPointer(host) {
	if (!renderer) { return; }

	renderer.domElement.addEventListener('pointerdown', (event) => {
		if (event.button !== 0 || !gizmo || gizmo.dragging) { return; }

		const rect = renderer.domElement.getBoundingClientRect();
		pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
		pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
		raycaster.setFromCamera(pointer, camera);

		raycaster.params.Line = raycaster.params.Line || {};
		raycaster.params.Line.threshold = 0.45;

		const hits = raycaster.intersectObjects(groupNodes.children, false);

		if (hits.length) {
			const id = hits[0].object.userData.id;

			if (event.shiftKey && state.selected.length === 1 && state.selected[0] !== id) {
				select([state.selected[0], id]);
			} else {
				select([id]);
			}
			return;
		}

		const edgeHits = raycaster.intersectObjects(groupEdges.children, false);

		if (edgeHits.length) {
			selectEdge(edgeHits[0].object.userData.id);
			return;
		}

		select([]);
		selectEdge(null);
	});
}

function bindGizmo() {
	if (!gizmo) { return; }

	let from = null;

	gizmo.addEventListener('mouseDown', () => {
		controls.enabled = false;
		const mesh = gizmo.object;
		from = mesh ? { x: mesh.position.x, y: mesh.position.y, z: mesh.position.z } : null;
	});

	gizmo.addEventListener('objectChange', () => {
		const mesh = gizmo.object;
		if (!mesh) { return; }

		if (state.mode === 'translate' && state.snap) {
			const snapped = snapPosition(mesh.userData.id, mesh.position);
			mesh.position.set(snapped.x, snapped.y, snapped.z);
		}

		refreshEdges(mesh.userData.id);
	});

	gizmo.addEventListener('mouseUp', () => {
		controls.enabled = true;

		const mesh = gizmo.object;
		if (!mesh || !from) { return; }

		const to = { x: mesh.position.x, y: mesh.position.y, z: mesh.position.z };

		if (Math.abs(to.x - from.x) > 0.001 || Math.abs(to.y - from.y) > 0.001
			|| Math.abs(to.z - from.z) > 0.001) {
			moveNode(mesh.userData.id, from, to);
		}

		clearGroup(groupGuides);
		from = null;
	});
}

// ------------------------------------------------- запасной 2D-холст (WebGL)

/**
 * Плоская проекция, если WebGL не поднялся.
 *
 * Вид сверху (x → вправо, z → вниз), узел можно выбрать и перетащить; высоту
 * (y) меняют кнопками «выше/ниже» в инспекторе. Этого хватает, чтобы работа
 * не встала, а причина видна сразу.
 */
const fallback2d = {
	ctx: null, w: 0, h: 0, drag: null,

	init(host) {
		if (!host) { return; }

		const canvas = document.createElement('canvas');
		canvas.style.width = '100%';
		canvas.style.height = '100%';
		host.appendChild(canvas);
		this.canvas = canvas;
		this.ctx = canvas.getContext('2d');

		// Бывает, что нет ни WebGL, ни 2D (старый или запертый браузер).
		// Тогда холст остаётся пустым, но страница продолжает работать.
		if (!this.ctx) { return; }

		canvas.addEventListener('pointerdown', (e) => this.down(e, canvas));
		canvas.addEventListener('pointermove', (e) => this.move(e, canvas));
		canvas.addEventListener('pointerup', () => this.up());
		this.resize(canvas);
		window.addEventListener('resize', () => { this.resize(canvas); this.paint(); });
	},

	resize(canvas) {
		if (!this.ctx) { return; }

		const dpr = Math.min(window.devicePixelRatio || 1, 2);
		this.w = canvas.clientWidth || 640;
		this.h = canvas.clientHeight || 420;
		canvas.width = Math.round(this.w * dpr);
		canvas.height = Math.round(this.h * dpr);
		this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
	},

	px(node) {
		const pad = 22;
		return {
			x: pad + (node.x + RADIUS) / (2 * RADIUS) * (this.w - 2 * pad),
			y: pad + (node.z + RADIUS) / (2 * RADIUS) * (this.h - 2 * pad),
		};
	},

	toWorld(px, py) {
		const pad = 22;
		return {
			x: (px - pad) / Math.max(1, this.w - 2 * pad) * 2 * RADIUS - RADIUS,
			z: (py - pad) / Math.max(1, this.h - 2 * pad) * 2 * RADIUS - RADIUS,
		};
	},

	down(event, canvas) {
		if (!this.ctx) { return; }

		const rect = canvas.getBoundingClientRect();
		const px = event.clientX - rect.left;
		const py = event.clientY - rect.top;

		let found = null;
		let best = 14;

		state.graph.nodes.forEach((node) => {
			const p = this.px(node);
			const d = Math.hypot(p.x - px, p.y - py);
			if (d < best) { best = d; found = node; }
		});

		if (!found) {
			const edge = hitEdge2d(px, py);
			if (edge) { selectEdge(edge.id); this.paint(); return; }
			select([]);
			this.paint();
			return;
		}

		select(event.shiftKey && state.selected.length === 1 && state.selected[0] !== found.id
			? [state.selected[0], found.id]
			: [found.id]);

		this.drag = { id: found.id, from: { x: found.x, y: found.y, z: found.z } };
		this.paint();
	},

	move(event, canvas) {
		if (!this.drag || !this.ctx) { return; }

		const rect = canvas.getBoundingClientRect();
		const world = this.toWorld(event.clientX - rect.left, event.clientY - rect.top);
		const node = nodeById(this.drag.id);
		if (!node) { return; }

		node.x = round2(clamp(world.x, -RADIUS, RADIUS));
		node.z = round2(clamp(world.z, -RADIUS, RADIUS));

		if (state.snap) {
			const snapped = snapPosition2d(this.drag.id, node);
			node.x = snapped.x;
			node.z = snapped.z;
		}

		this.paint();
	},

	up() {
		if (!this.drag) { return; }

		const node = nodeById(this.drag.id);
		const from = this.drag.from;

		if (node && (Math.abs(node.x - from.x) > 0.001 || Math.abs(node.z - from.z) > 0.001)) {
			moveNode(this.drag.id, from, { x: node.x, y: node.y, z: node.z });
		}

		this.drag = null;
		this.paint();
	},

	paint() {
		if (!this.ctx) { return; }

		const skin = themeColors();
		this.ctx.fillStyle = skin.bg;
		this.ctx.fillRect(0, 0, this.w, this.h);

		if (state.grid) {
			this.ctx.strokeStyle = skin.grid2d;
			for (let i = 0; i <= 24; i++) {
				const t = i / 24;
				this.ctx.beginPath();
				this.ctx.moveTo(22 + t * (this.w - 44), 22);
				this.ctx.lineTo(22 + t * (this.w - 44), this.h - 22);
				this.ctx.moveTo(22, 22 + t * (this.h - 44));
				this.ctx.lineTo(this.w - 22, 22 + t * (this.h - 44));
				this.ctx.stroke();
			}
		}

		state.graph.edges.forEach((edge) => {
			const a = nodeById(edge.source);
			const b = nodeById(edge.target);
			if (!a || !b) { return; }
			const p = this.px(a);
			const q = this.px(b);
			this.ctx.strokeStyle = state.selectedEdge === edge.id ? '#60a5fa' : edgeColor(edge.type);
			this.ctx.lineWidth = state.selectedEdge === edge.id ? 2.4 : 1.6;
			this.ctx.beginPath();
			this.ctx.moveTo(p.x, p.y);
			this.ctx.lineTo(q.x, q.y);
			this.ctx.stroke();
		});

		state.graph.nodes.forEach((node) => {
			const p = this.px(node);
			const on = state.selected.indexOf(node.id) >= 0;
			// Высота — насыщенностью: без третьего измерения она теряется.
			const h = clamp((node.y + RADIUS) / (2 * RADIUS), 0, 1);
			this.ctx.globalAlpha = 0.5 + 0.5 * h;
			this.ctx.fillStyle = typeColor(node.type);
			this.ctx.beginPath();
			this.ctx.arc(p.x, p.y, node.type === 'hub' ? 7 : 5.5, 0, Math.PI * 2);
			this.ctx.fill();
			this.ctx.globalAlpha = 1;

			if (on) {
				this.ctx.strokeStyle = '#60a5fa';
				this.ctx.lineWidth = 2;
				this.ctx.beginPath();
				this.ctx.arc(p.x, p.y, 11, 0, Math.PI * 2);
				this.ctx.stroke();
			}

			if (state.labels) {
				this.ctx.fillStyle = skin.label2d;
				this.ctx.font = '11px system-ui, sans-serif';
				this.ctx.fillText(node.label || node.id, p.x + 9, p.y + 4);
			}
		});
	},
};

/** Прилипание в 2D-режиме: только x и z (высоту меняет инспектор). */
function snapPosition2d(id, node) {
	let out = { x: node.x, z: node.z };

	if (state.snap) {
		state.graph.nodes.forEach((other) => {
			if (other.id === id) { return; }
			if (Math.abs(other.x - node.x) <= SNAP_TOL) { out.x = other.x; }
			if (Math.abs(other.z - node.z) <= SNAP_TOL) { out.z = other.z; }
		});

		if (STEP > 0) {
			out = { x: round2(Math.round(out.x / STEP) * STEP), z: round2(Math.round(out.z / STEP) * STEP) };
		}
	}

	return out;
}

// ------------------------------------------------------------- инспектор

function paintInspector() {
	const one = state.selected.length === 1 ? nodeById(state.selected[0]) : null;

	if (els.inspector) {
		els.inspector.classList.toggle('hidden', !one);
	}

	if (!one) { return; }

	if (els.inType) { els.inType.value = one.type; }
	if (els.inPos) {
		els.inPos.textContent = 'x ' + one.x.toFixed(1) + ' · y ' + one.y.toFixed(1)
			+ ' · z ' + one.z.toFixed(1);
	}
}

function paintHistory() {
	if (!els.history) { return; }

	const rows = state.undo.slice(-24).reverse().map((step, i) =>
		'<li class="flex items-center justify-between gap-2 py-1">'
		+ '<span class="truncate text-gray-700 dark:text-gray-300">' + escapeHtml(step.label) + '</span>'
		+ '<span class="shrink-0 text-gray-400">#' + (state.undo.length - i) + '</span></li>').join('');

	els.history.innerHTML = rows
		? '<ul class="divide-y divide-gray-100 dark:divide-gray-800">' + rows + '</ul>'
		: '<p class="text-xs text-gray-500 dark:text-gray-400">Правок ещё не было.</p>';

	if (els.undoBtn) { els.undoBtn.disabled = !state.undo.length; }
	if (els.redoBtn) { els.redoBtn.disabled = !state.redo.length; }
}

function markDirty() {
	[els.saveBtn, els.saveIconBtn].forEach((btn) => {
		if (!btn) { return; }
		btn.disabled = !state.dirty;
		btn.classList.toggle('ring-2', state.dirty);
		btn.classList.toggle('ring-blue-400', state.dirty);
	});
	paintPublish();
}

function saveNow() {
	busy(true);
	api('save', { graph: state.graph, label: 'ручная правка' })
		.then((res) => {
			if (!res.data.ok) { note(failOf(res, 'Не сохранилось.'), true); return; }
			state.dirty = false;
			if ((res.data.ops || 0) > 0) { state.review = false; }
			markDirty();
			note('Сохранено: правок ' + (res.data.ops || 0) + ', ревизия №' + (res.data.no || 0));
		})
		.catch(() => note('Сеть не отвечает.', true))
		.then(() => busy(false));
}

function canvasIsFull() {
	const box = document.fullscreenElement || document.webkitFullscreenElement;
	return !!(els.canvas && box === els.canvas);
}

function paintFull() {
	if (!els.fullBtn) { return; }
	const on = canvasIsFull();
	const icon = els.fullBtn.querySelector('i');

	if (icon) {
		icon.classList.toggle('fa-expand', !on);
		icon.classList.toggle('fa-compress', on);
	}

	els.fullBtn.title = on ? 'Окно' : 'На весь экран';
	els.fullBtn.setAttribute('aria-label', els.fullBtn.title);
}

function toggleFull() {
	if (!els.canvas) { return; }

	if (canvasIsFull()) {
		const exit = document.exitFullscreen || document.webkitExitFullscreen;
		if (exit) { exit.call(document); }
		return;
	}

	const req = els.canvas.requestFullscreen || els.canvas.webkitRequestFullscreen;
	if (req) { req.call(els.canvas); }
}

function escapeHtml(text) {
	return String(text == null ? '' : text)
		.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ----------------------------------------------------------------- поллинг

let pollTimer = 0;
let elapsedTimer = 0;
let elapsedOrigin = 0;

function formatElapsed(sec) {
	sec = Math.max(0, Math.floor(Number(sec) || 0));
	return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
}

function paintElapsed(sec) {
	const text = formatElapsed(sec);
	if (els.elapsed) { els.elapsed.textContent = text; }
	if (els.elapsedBar) { els.elapsedBar.textContent = text; }
}

function startElapsed(fromSec) {
	elapsedOrigin = Date.now() - Math.max(0, Number(fromSec) || 0) * 1000;
	stopElapsed();
	const tick = () => paintElapsed((Date.now() - elapsedOrigin) / 1000);
	tick();
	elapsedTimer = setInterval(tick, 250);
}

function stopElapsed() {
	clearInterval(elapsedTimer);
	elapsedTimer = 0;
}

function failJobText(job) {
	const raw = String(job && job.error || '').replace(/\s+/g, ' ').trim();
	const status = String(job && (job.status || job.state) || '');
	if (!raw) {
		return (status === 'failed' || status === 'error' || status === 'cancelled')
			? 'сборка не удалась' : '';
	}
	if (/не JSON|JSONDecode|Expecting |Unterminated|json/i.test(raw)) {
		return 'Модель вернула сломанный граф. Нажмите «Построить граф» ещё раз или упростите описание.';
	}
	return raw.length > 180 ? raw.slice(0, 177) + '…' : raw;
}

/**
 * Сборка ещё идёт. status=none / found=false / job_id=0 — хвост мёртвой
 * задачи: не долбить /api/status каждые 2.5 с (владелец, 11.10.2026).
 */
function jobIsLive(job, jobId) {
	const id = Number(jobId || 0);
	if (id <= 0 || !job) { return false; }
	if (Object.prototype.hasOwnProperty.call(job, 'found') && !job.found) { return false; }
	const status = String(job.status || job.state || '').toLowerCase();
	if (status === 'failed' || status === 'error' || status === 'cancelled'
		|| status === 'done' || status === 'none') {
		return false;
	}
	const err = String(job.error || '').replace(/\s+/g, ' ').trim();
	// job.error при retry (queued/pending) — уже отказ, не ждать второй заход.
	if (err && status !== 'running') { return false; }
	return status === 'queued' || status === 'running' || status === 'pending' || status === 'processing';
}

function poll() {
	clearTimeout(pollTimer);

	if (!state.job) { return; }

	fetch(API + '/status?code=' + encodeURIComponent(state.code)
		+ '&csrf=' + encodeURIComponent(state.csrf), {
		headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
	})
		.then((r) => r.json())
		.then((data) => {
			if (!data || !data.ok) {
				state.job = 0;
				busy(false);
				return;
			}

			paintQuota(data.quota);
			const job = data.job || {};
			// nj120 отдаёт status (queued/running/done/failed), не state.
			const status = job.status || job.state || '';
			const liveId = Number(data.job_id != null ? data.job_id : state.job);
			const errText = failJobText(job);
			const failed = errText !== '' || status === 'failed' || status === 'error' || status === 'cancelled';
			const live = jobIsLive(job, liveId);
			const incoming = data.graph ? normalise(data.graph) : null;

			if (typeof job.elapsed_sec === 'number' && job.elapsed_sec >= 0) {
				elapsedOrigin = Date.now() - job.elapsed_sec * 1000;
				paintElapsed(job.elapsed_sec);
			}

			if (incoming
				&& (incoming.nodes.length !== state.graph.nodes.length
					|| incoming.edges.length !== state.graph.edges.length)) {
				state.graph = incoming;
				state.dirty = false;
				state.review = false;
				rebuild(true);
				markDirty();
			}

			if (!live) {
				state.job = 0;
				if (incoming && !failed) { state.graph = incoming; state.review = false; rebuild(true); }
				busy(false);
				if (failed) {
					note('Ошибка: ' + (errText || 'сборка не удалась'), true);
				} else if (status === 'done') {
					note('Граф готов.');
				} else if (!(state.graph.nodes.length) && !(incoming && incoming.nodes.length)) {
					note('Сборка прервалась.', true);
				}
				return;
			}

			const stageText = job.stage || job.note
				|| (status === 'queued' ? 'в очереди' : (status === 'running' ? 'модель строит граф' : 'в работе'));
			if (els.workingTitle) { els.workingTitle.textContent = stageText; }

			pollTimer = setTimeout(poll, 2500);
		})
		.catch(() => { pollTimer = setTimeout(poll, 4000); });
}

function paintPublish(review) {
	if (review !== undefined) { state.review = !!review; }
	const btn = els.publishBtn;

	if (!btn) { return; }

	const pre = !!CFG.premoderate;
	const locked = pre
		? (state.review && !state.dirty)
		: (state.published && !state.dirty);
	btn.disabled = locked || state.busy;
	btn.textContent = pre
		? (locked ? 'На модерации' : 'Отправить на модерацию')
		: (locked ? 'В каталоге' : 'Опубликовать');
}

function busy(on, message) {
	state.busy = on;

	['generateBtn', 'inpaintBtn'].forEach((key) => {
		if (!els[key]) { return; }
		els[key].disabled = on || !state.llmAllowed;
	});
	paintPublish();

	if (els.spinner) { els.spinner.classList.toggle('hidden', !on); }
	if (els.elapsedBar) { els.elapsedBar.classList.toggle('hidden', !on); }
	if (els.working) { els.working.classList.toggle('hidden', !on); }
	if (on && message && els.workingTitle) { els.workingTitle.textContent = message; }

	if (on) {
		if (!elapsedTimer) { startElapsed(0); }
	} else {
		stopElapsed();
	}
}

/**
 * Прочитать выбранные фото как JPEG (сжатые на клиенте, до 4 кадров).
 * В задачу очереди они не едут: сайт кладёт файлы на диск, воркер забирает
 * их из /graph3d/w/context (урок nj646).
 */
function readPhotos(input) {
	const files = input && input.files ? Array.prototype.slice.call(input.files, 0, 4) : [];

	if (!files.length) { return Promise.resolve([]); }

	return Promise.all(files.map(fileToPhoto)).then((rows) => rows.filter(Boolean));
}

function fileToPhoto(file) {
	return new Promise((resolve, reject) => {
		if (!file) { resolve(null); return; }

		const reader = new FileReader();
		reader.onerror = () => reject(new Error('не прочиталось'));
		reader.onload = () => {
			const dataUrl = String(reader.result || '');
			shrinkDataUrl(dataUrl).then((small) => {
				if (small) { resolve(small); return; }
				const comma = dataUrl.indexOf(',');
				const header = comma >= 0 ? dataUrl.slice(0, comma) : '';
				const b64 = comma >= 0 ? dataUrl.slice(comma + 1) : '';
				const mimeMatch = /data:([^;]+)/.exec(header);
				const media = (mimeMatch && mimeMatch[1]) || file.type || 'image/jpeg';
				if (!b64) { resolve(null); return; }
				resolve({
					b64,
					media_type: media.indexOf('image/') === 0 ? media : 'image/jpeg',
				});
			});
		};
		reader.readAsDataURL(file);
	});
}

function shrinkDataUrl(dataUrl) {
	return new Promise((resolve) => {
		if (typeof Image === 'undefined') { resolve(null); return; }
		const img = new Image();
		img.onload = () => {
			try {
				const max = 1280;
				let w = img.width || 0;
				let h = img.height || 0;
				if (w < 1 || h < 1) { resolve(null); return; }
				if (Math.max(w, h) > max) {
					const s = max / Math.max(w, h);
					w = Math.max(1, Math.round(w * s));
					h = Math.max(1, Math.round(h * s));
				}
				const canvas = document.createElement('canvas');
				canvas.width = w;
				canvas.height = h;
				const ctx = canvas.getContext('2d');
				if (!ctx) { resolve(null); return; }
				ctx.fillStyle = '#fff';
				ctx.fillRect(0, 0, w, h);
				ctx.drawImage(img, 0, 0, w, h);
				const out = canvas.toDataURL('image/jpeg', 0.82);
				const comma = out.indexOf(',');
				resolve({ b64: comma >= 0 ? out.slice(comma + 1) : '', media_type: 'image/jpeg' });
			} catch (e) {
				resolve(null);
			}
		};
		img.onerror = () => resolve(null);
		img.src = dataUrl;
	});
}

// -------------------------------------------------------------- экспорт

/**
 * Имя файла без пути: из названия проекта, иначе код.
 * Слешей и двоеточий в имени быть не должно — браузер иначе уйдёт в подпапку.
 */
function fileStem() {
	const raw = String((CFG.title
		|| (state.graph.meta && state.graph.meta.title)
		|| state.code
		|| 'graph'));
	const stem = raw.replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, ' ').trim().slice(0, 80);

	return stem || 'graph';
}

function downloadBlob(blob, name) {
	const rec = {
		name: String(name || 'graph'),
		type: blob && blob.type ? String(blob.type) : '',
		size: blob && typeof blob.size === 'number' ? blob.size : 0,
	};
	window.__NJ667_DOWNLOADS = window.__NJ667_DOWNLOADS || [];
	window.__NJ667_DOWNLOADS.push(rec);

	try {
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = rec.name;
		a.rel = 'noopener';
		document.body.appendChild(a);
		a.click();
		a.remove();
		setTimeout(() => {
			try { URL.revokeObjectURL(url); } catch (e) { /* jsdom */ }
		}, 400);
	} catch (e) {
		// jsdom без createObjectURL: записи в __NJ667_DOWNLOADS тесту хватает.
	}
}

/**
 * Сцена только для GLB: узлы-меши и рёбра-цилиндры, без гизмо, сетки и CSS2D.
 * Собирается из графа, а не копируется с холста — поэтому работает и когда
 * WebGL не завёлся (экспорт не рисует кадр, только сериализует геометрию).
 */
function buildExportScene() {
	const exportScene = new THREE.Scene();
	exportScene.add(new THREE.AmbientLight(0xffffff, 0.7));
	const key = new THREE.DirectionalLight(0xffffff, 0.85);
	key.position.set(RADIUS, RADIUS * 2, RADIUS);
	exportScene.add(key);

	state.graph.nodes.forEach((node) => {
		const shape = (types[node.type] && types[node.type].shape) || 'sphere';
		const geo = shape === 'box'
			? new THREE.BoxGeometry(1.2, 0.6, 1.2)
			: new THREE.SphereGeometry(node.type === 'hub' ? 0.85 : 0.6, 20, 16);
		const mat = new THREE.MeshStandardMaterial({
			color: new THREE.Color(typeColor(node.type)),
			roughness: 0.45, metalness: 0.15,
		});
		const mesh = new THREE.Mesh(geo, mat);
		mesh.position.set(node.x, node.y, node.z);
		mesh.name = node.label || node.id;
		exportScene.add(mesh);
	});

	state.graph.edges.forEach((edge) => {
		const a = nodeById(edge.source);
		const b = nodeById(edge.target);
		if (!a || !b) { return; }

		const start = new THREE.Vector3(a.x, a.y, a.z);
		const end = new THREE.Vector3(b.x, b.y, b.z);
		const dir = end.clone().sub(start);
		const length = dir.length();
		if (length < 0.001) { return; }

		const radius = (etypes[edge.type] && etypes[edge.type].radius) || 0.16;
		const geo = new THREE.CylinderGeometry(radius, radius, length, 8);
		const mat = new THREE.MeshStandardMaterial({
			color: new THREE.Color(edgeColor(edge.type)),
			roughness: 0.55, metalness: 0.05,
		});
		const mesh = new THREE.Mesh(geo, mat);
		const mid = start.clone().add(end).multiplyScalar(0.5);
		mesh.position.copy(mid);
		mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir.normalize());
		mesh.name = edge.label || edge.id;
		exportScene.add(mesh);
	});

	return exportScene;
}

function exportJson() {
	const payload = JSON.stringify(state.graph, null, 2);
	downloadBlob(new Blob([payload], { type: 'application/json' }), fileStem() + '.json');
	note('Топология скачана: ' + fileStem() + '.json');
}

function exportGlb() {
	if (!state.graph.nodes.length) {
		note('Граф пуст — экспортировать нечего.', true);
		return;
	}

	if (typeof GLTFExporter !== 'function') {
		note('Экспорт GLB недоступен в этом браузере.', true);
		return;
	}

	const exportScene = buildExportScene();
	if (exportScene.updateMatrixWorld) { exportScene.updateMatrixWorld(true); }

	const exporter = new GLTFExporter();

	exporter.parse(exportScene, (result) => {
		const buffer = result instanceof ArrayBuffer ? result : (result && result.buffer);
		if (!buffer) {
			note('GLB не собрался.', true);
			return;
		}
		downloadBlob(new Blob([buffer], { type: 'model/gltf-binary' }), fileStem() + '.glb');
		note('Модель скачана: ' + fileStem() + '.glb');
	}, (err) => {
		note('GLB не собрался: ' + (err && err.message ? err.message : 'ошибка экспортёра'), true);
	}, { binary: true });
}

// -------------------------------------------------------------- инициализация

function boot() {
	// Холст один и тот же для редактора (/graph3d/p/<код>) и для публичного
	// просмотра (/graph3d/c/<код>): во втором случае правящих кнопок просто
	// нет в разметке, и скрипт это переживает.
	els.canvas = document.getElementById('nj667-canvas')
		|| document.getElementById('nj667-view-canvas');
	// Сцена — внутренний #nj667-stage на редакторе (тулбар не съедает высоту).
	// На /graph3d/c/ и /moderate его нет: рисуем в сам холст. Не затираем
	// els.stage общим getElementById — иначе host = null и initThree падает
	// на clientWidth (владелец, 11.10.2026).
	els.stage = document.getElementById('nj667-stage') || els.canvas;

	['note', 'spinner', 'history', 'undoBtn', 'redoBtn', 'saveBtn', 'saveIconBtn', 'fullBtn',
		'milestoneBtn', 'generateBtn', 'inpaintBtn', 'publishBtn', 'deleteBtn', 'connectBtn',
		'addBtn', 'delNodeBtn', 'delEdgeBtn', 'selected', 'inspector', 'inLabel', 'inType', 'inPos',
		'prompt', 'photos', 'rag', 'inpaintPrompt', 'inpaint', 'modeGroup', 'gridBtn', 'snapBtn', 'labelBtn',
		'statusBadge', 'exportGlbBtn', 'exportJsonBtn', 'working', 'workingTitle', 'workingNote', 'dailyQuota',
		'elapsed', 'elapsedBar',
	].forEach((key) => {
		els[key] = document.getElementById('nj667-' + key.replace(/([A-Z])/g, '-$1').toLowerCase());
	});

	paintQuota(CFG.quota);

	if (!els.canvas) { return; }

	if (!initThree(els.stage)) {
		fallback2d.init(els.stage);
		if (els.note) {
			note('WebGL в этом браузере недоступен — показан плоский вид сверху. '
				+ 'Узлы можно выбирать и перетаскивать, высоту меняет инспектор.', true);
		}
	}

	watchTheme();
	rebuild(true);
	paintHistory();

	// --- панель инструментов --------------------------------------------------
	if (els.modeGroup) {
		els.modeGroup.addEventListener('click', (event) => {
			const btn = event.target.closest('[data-mode]');
			if (!btn) { return; }
			state.mode = btn.getAttribute('data-mode');
			Array.prototype.forEach.call(els.modeGroup.querySelectorAll('[data-mode]'), (other) => {
				other.classList.toggle('is-active', other === btn);
			});
			paintSelection();
		});
	}

	if (els.gridBtn) {
		els.gridBtn.setAttribute('aria-pressed', state.grid ? 'true' : 'false');
		els.gridBtn.addEventListener('click', () => {
			state.grid = !state.grid;
			writeGridPref(state.grid);
			if (gridHelper) { gridHelper.visible = state.grid; }
			els.gridBtn.setAttribute('aria-pressed', state.grid ? 'true' : 'false');
			fallback2d.paint();
		});
	}

	if (els.snapBtn) {
		els.snapBtn.setAttribute('aria-pressed', state.snap ? 'true' : 'false');
		els.snapBtn.addEventListener('click', () => {
			state.snap = !state.snap;
			els.snapBtn.setAttribute('aria-pressed', state.snap ? 'true' : 'false');
		});
	}

	if (els.labelBtn) {
		els.labelBtn.setAttribute('aria-pressed', state.labels ? 'true' : 'false');
		els.labelBtn.addEventListener('click', () => {
			state.labels = !state.labels;
			nodeLabel.forEach((label) => { label.visible = state.labels; });
			els.labelBtn.setAttribute('aria-pressed', state.labels ? 'true' : 'false');
			fallback2d.paint();
		});
	}

	if (els.undoBtn) { els.undoBtn.addEventListener('click', undo); }
	if (els.redoBtn) { els.redoBtn.addEventListener('click', redo); }
	if (els.addBtn) {
		els.addBtn.addEventListener('click', () => {
			const base = state.selected.length ? nodeById(state.selected[0]) : { x: 0, y: 0, z: 0 };
			addNodeNear({ x: base.x + 2, y: base.y, z: base.z });
		});
	}
	if (els.connectBtn) { els.connectBtn.addEventListener('click', connectSelected); }
	if (els.delNodeBtn) { els.delNodeBtn.addEventListener('click', deleteSelected); }
	if (els.delEdgeBtn) { els.delEdgeBtn.addEventListener('click', deleteSelectedEdge); }

	if (els.deleteBtn) {
		// Необратимое действие подтверждается ВТОРЫМ нажатием той же кнопки
		// (правило проекта: confirm()/alert в браузере не используем).
		// Второе нажатие (data-n2j-arm, app.js): первое взводит кнопку и сюда не доходит.
		els.deleteBtn.addEventListener('click', () => {
			api('delete', {}).then((res) => {
				if (!res.data || !res.data.ok) {
					note(failOf(res, 'Удалить не вышло.'), true);
					return;
				}
				window.location.href = '/graph3d';
			}).catch(() => note('Сеть не отвечает.', true));
		});
	}

	// Мягкое удаление ревизии — форма POST с data-n2j-arm на иконке (app.js).

	if (els.inLabel) {
		els.inLabel.addEventListener('change', () => {
			updateSelected({ label: els.inLabel.value.slice(0, limits.max_label || 80) }, 'подпись');
		});
	}

	if (els.inType) {
		els.inType.addEventListener('change', () => {
			updateSelected({ type: els.inType.value }, 'тип узла');
		});
	}

	if (els.saveBtn) { els.saveBtn.addEventListener('click', saveNow); }
	if (els.saveIconBtn) { els.saveIconBtn.addEventListener('click', saveNow); }

	if (els.fullBtn) {
		els.fullBtn.addEventListener('click', toggleFull);
		document.addEventListener('fullscreenchange', paintFull);
		document.addEventListener('webkitfullscreenchange', paintFull);
	}

	if (els.milestoneBtn) {
		els.milestoneBtn.addEventListener('click', () => {
			// Название точки придумывает сервер: спрашивать человека посреди
			// правки нечем (confirm/prompt в браузере не используем).
			api('revision', { action: 'milestone', label: 'Контрольная точка' })
				.then((res) => note(res.data.ok ? 'Контрольная точка поставлена.' : failOf(res, 'Не вышло.'),
					!res.data.ok));
		});
	}

	Array.prototype.forEach.call(document.querySelectorAll('[data-nj667-preset]'), (btn) => {
		btn.addEventListener('click', () => {
			if (!els.prompt) { return; }
			els.prompt.value = btn.getAttribute('data-nj667-preset') || '';
			els.prompt.focus();
		});
	});

	if (els.generateBtn) {
		els.generateBtn.addEventListener('click', () => {
			const prompt = els.prompt ? els.prompt.value.trim() : '';
			const files = els.photos && els.photos.files ? els.photos.files.length : 0;
			if (!prompt && !files) { note('Опишите изделие или приложите фото.', true); return; }
			busy(true, 'модель строит граф');
			readPhotos(els.photos).then((images) => {
				const payload = { prompt };
				if (images.length) { payload.images = images; }
				payload.rag = !!(els.rag ? els.rag.checked : CFG.rag);
				return api('generate', payload);
			}).then((res) => {
				paintQuota(res.data && res.data.quota);
				if (!res.data.ok) { note(failOf(res, 'Не отправилось.'), true); busy(false); return; }
				state.job = res.data.job_id || 0;
				if (!state.job) { busy(false); return; }
				poll();
			}).catch((err) => {
				note('Фото не прочиталось: ' + (err && err.message ? err.message : 'ошибка'), true);
				busy(false);
			});
		});
	}

	if (els.inpaintBtn) {
		els.inpaintBtn.addEventListener('click', () => {
			const prompt = els.inpaintPrompt ? els.inpaintPrompt.value.trim() : '';
			if (!prompt) { note('Напишите, что сделать с выделенным.', true); return; }
			if (!state.selected.length) { note('Выделите узел на холсте.', true); return; }
			busy(true, 'модель достраивает выделенное');
			api('inpaint', { prompt, node_ids: state.selected, depth: 1 }).then((res) => {
				paintQuota(res.data && res.data.quota);
				if (!res.data.ok) { note(failOf(res, 'Не отправилось.'), true); busy(false); return; }
				state.job = res.data.job_id || 0;
				if (!state.job) { busy(false); return; }
				poll();
			});
		});
	}

	if (els.publishBtn) {
		paintPublish(!!CFG.review);
		els.publishBtn.addEventListener('click', () => {
			const pre = !!CFG.premoderate;
			if (pre && state.review) { return; }
			if (!pre && state.published && !state.dirty) { return; }
			els.publishBtn.disabled = true;
			api('publish', {}).then((res) => {
				if (!res.data || !res.data.ok) {
					note(failOf(res, 'Не отправилось.'), true);
					paintPublish(false);
					return;
				}
				if (res.data.published || res.data.status === 'published') {
					state.published = true;
					state.review = false;
					note('В каталоге. Саммари и вектор считает воркер.');
					paintPublish(false);
					return;
				}
				note('Отправлено на модерацию. Саммари и вектор считает воркер.');
				paintPublish(true);
			}).catch(() => {
				note('Сеть не отвечает.', true);
				paintPublish(false);
			});
		});
	}

	// Экспорт живёт и в просмотре: топология публичная, GLB — та же геометрия.
	if (els.exportJsonBtn) { els.exportJsonBtn.addEventListener('click', exportJson); }
	if (els.exportGlbBtn) { els.exportGlbBtn.addEventListener('click', exportGlb); }

	// Горячие клавиши: Ctrl+Z / Ctrl+Shift+Z и Delete — редактор без них
	// ощущается сломанным. В режиме просмотра их не вешаем.
	if (CFG.readonly) { return; }

	window.addEventListener('keydown', (event) => {
		if (event.target && /input|textarea/i.test(event.target.tagName)) { return; }

		if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
			event.preventDefault();
			event.shiftKey ? redo() : undo();
		}

		if ((event.key === 'Delete' || event.key === 'Backspace')
			&& (state.selected.length || state.selectedEdge)) {
			event.preventDefault();
			deleteSelected();
		}
	});

	if (state.job) {
		busy(true, 'модель строит граф');
		poll();
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', boot);
} else {
	boot();
}

// Экспорт: им пользуются и страницы (тот же граф, но только чтение), и тест
// tests/Js/nj667_graph3d.test.mjs — ему нужны чистые функции и история правок.
window.NJ667Editor = {
	state, rebuild, normalise, select, selectEdge, boot3d: boot,
	applyPatch, snapPosition, commit, undo, redo, fallback2d, CFG,
	exportGlb, exportJson, buildExportScene, fileStem,
	deleteSelected, deleteSelectedEdge, makeEdge,
	applyTheme, isNight, themeColors, jobIsLive,
};
