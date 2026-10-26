/**
 * NJ-667 «Графовый 3D-конструктор»: просмотр сборки в браузере.
 *
 * GLB — единственный формат просмотра изделия целиком. STL изделия загружается
 * отдельно только модератором; STL деталей остаются отдельными файлами.
 *
 * Зачем свои парсеры, а не STLLoader/GLTFLoader из three/addons: в локальной
 * копии three каталога addons/loaders нет (vendor у нас урезан под задачи
 * сайта), а тянуть ради разбора целую библиотеку — значит вынести часть
 * страницы на CDN. Разбор здесь — чистая функция, её видно в тесте.
 *
 * Что происходит:
 *   1) блок #nj667-stl знает, откуда взять модель (data-src), в каком она
 *      формате (data-kind) и у чего спрашивать статус (data-status); модели
 *      может не быть — это не ошибка, а «ещё не собрана»;
 *   2) GLB читаем ровно то, что умеем писать: один mesh с примитивом на каждый
 *      цвет из OpenSCAD, POSITION + NORMAL + индексы, бинарник внутри файла.
 *      Всё остальное (текстуры, анимация, внешний .bin) — честное «не могу», а
 *      не наполовину нарисованный купол;
 *   3) STL читается двоичным (84 + 50·N байт) или ASCII; грани кладутся в
 *      BufferGeometry без индексов — normals считает three, иначе плоская
 *      печать выглядит как литая;
 *   4) пока рендер в очереди, страница тихо опрашивает build_status и
 *      подхватывает модель, не требуя перезагрузки.
 *
 * Никаких «красивостей» любой ценой: нет WebGL — показываем ссылку скачать.
 */

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

const VIEW_ID = 'nj667-stl';
const WORKING_ID = 'nj667-working';
const STATE_ID = 'nj667-stl-state';
const META_ID = 'nj667-stl-meta';

const MAX_TRIANGLES = 4e6;

// Групп цвета в одном меше: столько же принимает приёмка сайта
// (build.limits.glb_prims_max) и столько же умеет генератор воркера
// (MAX_GROUPS в workers/n2j_workers/glb.py). Расходятся числа — и человек
// получает модель, которую страница отказалась рисовать.
const MAX_GROUPS = 24;

// OpenSCAD (а значит STL, VRML и наш GLB) пишет Z вверх, а three.js и glTF
// ждут Y вверх. Без поворота модель ложилась на бок, а сетка стояла не под ней
// (вопрос владельца 13.10.2026). Поворот -90° по X переводит (x, y, z) в
// (x, z, -y). Делаем его только при показе: файлы на диске не трогаем.
const Z_UP_TO_Y_UP = -Math.PI / 2;

// Галка «Сетка» под холстом и строка с размерами сетки.
const GRID_TOGGLE_ID = 'nj667-grid-toggle';
const GRID_INFO_ID = 'nj667-grid-info';

// Взгляд сверху под углом ~42° от центра модели (y — вверх). Раньше позиция
// камеры была абсолютной и у нуля: при модели вдали от начала взгляд уходил
// снизу вверх, под столешницу.
const VIEW_DIR = { x: 0.6, y: 0.8, z: 0.6 };

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

// GLB: заголовок 12 байт, дальше chunk'и [длина][тип][данные][до 4 байт мусора].
const GLB_MAGIC = 0x46546c67;	// 'glTF'
const GLB_JSON = 0x4e4f534a;	// 'JSON'
const GLB_BIN = 0x004e4942;		// 'BIN\0'
const GLB_TRIANGLES = 4;			// mode примитива, который мы умеем

// componentType glTF => (типизированный массив, ширина элемента в байтах).
const GLB_COMPONENT = {
	5120: [Int8Array, 1], 5121: [Uint8Array, 1],
	5122: [Int16Array, 2], 5123: [Uint16Array, 2],
	5125: [Uint32Array, 4], 5126: [Float32Array, 4],
};

/* Состояние блока просмотра: одна сцена на страницу и номер узла, который в ней сейчас. */
let view = null;
let shownPart = -1;
// Сетка выключена по умолчанию: включает её человек галкой под холстом.
let gridOn = readGridPref(false);

/** Узлы к печати: статус строки таблицы «Детали на печать». Текст держим тут,
 *  чтобы страница и опрос статуса не разошлись в формулировках. */
const PART_TEXT = {
	spec: 'ждёт очереди',
	queued: 'в очереди',
	done: 'STL готов',
	error: 'не собрался',
};

/**
 * Обновить строки «К печати» ответом опроса: человек крутит модель, а список
 * деталей тем временем дособирается. Перезагрузка страницы для этого не нужна.
 */
function paintParts(parts, buildId, code) {
	if (!Array.isArray(parts)) {
		return;
	}

	parts.forEach((part) => {
		const i = Number(part && part.part);

		if (!Number.isFinite(i)) {
			return;
		}

		const notes = Array.from(document.querySelectorAll('[data-part-error="' + i + '"]'));
		const status = String((part && part.status) || '');

		// Строка таблицы и плитка — две копии одного узла: обновляем обе.
		Array.from(document.querySelectorAll('[data-part-state="' + i + '"]')).forEach((state) => {
		if (state) {
			state.textContent = PART_TEXT[status] || status || 'не заказывали';
			state.className = status === 'done'
				? 'text-emerald-700 dark:text-emerald-400'
				: (status === 'error' ? 'text-rose-700 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400');

			const row = state.parentElement;

			// Сервер уже рисует ссылку и «в 3D» для готовых узлов (с data-part-dl и
			// data-part-view): дописываем только то, чего в строке нет. Вставляем
			// перед строкой ошибки — тот же порядок, что при первой загрузке.
			if (status === 'done' && !row.querySelector('[data-part-dl]')) {
				const tail = row.querySelector('[data-part-error]');
				const mb = Number(part.size || 0) / 1048576;
				const link = document.createElement('a');
				const size = document.createElement('span');

				link.href = partUrl(code, i, buildId);
				link.title = 'STL этого узла — файл для слайсера';
				link.className = 'ml-1 font-semibold text-blue-600 hover:underline dark:text-blue-400';
				link.setAttribute('data-part-dl', '');
				link.textContent = 'STL';
				size.className = 'ml-1 text-gray-400 dark:text-gray-500';
				size.textContent = mb > 0 ? mb.toFixed(2).replace('.', ',') + ' МБ' : '';
				row.insertBefore(link, tail);
				row.insertBefore(size, tail);

				if (!row.querySelector('[data-part-view]')) {
					row.insertBefore(partButton(i, buildId, Number(part.size || 0), Number(part.render_ms || 0)), tail);
				}
			}
		}
		});

		notes.forEach((note) => {
			if (typeof part.error === 'string') {
				note.textContent = part.error;
			}
		});

		// Превью узла: картинка в своей ячейке, появляется, когда узел готов.
		// Нет файла (старая сборка, сбой превью) — один раз помечаем и не дёргаем сервер.
		Array.from(document.querySelectorAll('[data-part-preview="' + i + '"]')).forEach((cell) => {
		if (cell && status === 'done' && !cell.querySelector('img') && !cell.hasAttribute('data-preview-miss')) {
			const img = document.createElement('img');

			img.src = previewUrl(code, i, buildId);
			img.width = 96;
			img.height = 96;
			img.loading = 'lazy';
			img.alt = 'Узел #' + (i + 1);
			// Фон PNG прозрачный: без белой подложки, в ночном режиме картинка на тёмном фоне.
			img.className = 'h-24 w-24 rounded border border-gray-200 object-contain dark:border-gray-700';
			img.onerror = () => {
				img.remove();
				cell.setAttribute('data-preview-miss', '1');
			};
			cell.appendChild(img);
		}
		});
	});
}

/**
 * Кнопка «посмотреть узел в 3D». Её же лепим в строку, которую дописал опрос
 * статуса: иначе «готово» появилось бы в списке, а посмотреть результат —
 * нельзя, пока не перезагрузишь страницу.
 */
function partButton(part, buildId, size, renderMs) {
	const btn = document.createElement('button');

	btn.type = 'button';
	btn.setAttribute('data-part-view', String(part));
	btn.setAttribute('data-build', String(buildId || 0));
	btn.setAttribute('data-size', String(size || 0));
	btn.setAttribute('data-render-ms', String(renderMs || 0));
	btn.title = 'Показать только этот узел в окне выше (второй клик вернёт изделие)';
	btn.setAttribute('aria-pressed', 'false');
	btn.className = PART_BTN_OFF;
	btn.textContent = 'в 3D';

	return btn;
}

/**
 * Прочитать accessor из bin-chunk'а. Выравнивание проверяем, а не молчим:
 * new Float32Array(buffer, offset) на нечётном смещении — это RangeError,
 * и человек увидел бы «что-то пошло не так» вместо внятного текста.
 */
function glbAccessor(bytes, bin, accessors, views, which, want, kind) {
	const view = views[Number((accessors[which] || {}).bufferView)];

	if (view === undefined) {
		throw new Error('В glTF сломана ссылка на бинарник: ' + kind + '.');
	}

	const acc = accessors[which];
	const item = GLB_COMPONENT[acc.componentType];

	if (item === undefined || acc.type !== want) {
		throw new Error('Неожиданный формат ' + kind + ' в glTF ('
			+ String(acc.type) + '/' + String(acc.componentType) + ').');
	}

	const stride = item[1] * (want === 'VEC3' ? 3 : 1);
	// Смещения accessor'ов считаются от начала bin-chunk'а, а не от начала
	// файла: путаница в пару байт стоит молча съехавшей геометрии.
	const offset = Number(view.byteOffset || 0) + Number(acc.byteOffset || 0);
	const need = offset + Number(acc.count) * stride;

	if (acc.componentType === 5126 && offset % 4 !== 0) {
		throw new Error('Float32 в glTF стоит не по границе 4 байт — читать нельзя.');
	}

	if (need > bin.length) {
		throw new Error('Данные ' + kind + ' ссылаются за конец файла: нужно '
			+ need + ' байт, в файле ' + bin.length + '.');
	}

	return new item[0](bytes.buffer, bytes.byteOffset + bin.byteOffset + offset,
		Number(acc.count) * (want === 'VEC3' ? 3 : 1));
}

/**
 * baseColorFactor в glTF лежит в линейном пространстве, а three.js ждёт sRGB:
 * без степени 1/2.2 «бумага» вышла бы грязно-коричневой. 0xRRGGBB или null.
 */
function glbColor(factor) {
	if (!Array.isArray(factor) || factor.length < 3) {
		return null;
	}

	let hex = 0;

	for (let i = 0; i < 3; i++) {
		const c = Number(factor[i]);
		const v = Number.isFinite(c) ? Math.min(1, Math.max(0, c)) : 0;

		hex = hex * 256 + Math.round(255 * Math.pow(v, 1 / 2.2));
	}

	return hex;
}

/**
 * Разобрать GLB в группы: на каждый примитив меша — свой материал.
 *
 * Возвращаем индексы как есть, не разворачивая в плоский список: на сборке
 * из 300 узлов это экономит два байта на грань, а GPU переваривает их нативно.
 *
 * Группы держим списком, а не сливаем в один буфер: `color()` из OpenSCAD
 * доживает до glTF ровно как отдельный примитив, и это то, за что человек
 * платит вниманием («хочу в браузере видеть раскраску по типам узлов»).
 */
function parseGlb(buffer) {
	const bytes = new Uint8Array(buffer);
	const size = bytes.byteLength;

	if (size < 20) {
		throw new Error('GLB короче заголовка: файл не догрузился.');
	}

	const view = new DataView(bytes.buffer, bytes.byteOffset, size);
	const total = view.getUint32(8, true);

	if (view.getUint32(0, true) !== GLB_MAGIC) {
		throw new Error('Это не GLB: сигнатуры glTF на месте нет.');
	}

	if (view.getUint32(4, true) !== 2) {
		throw new Error('Умеем glTF 2.0, а файл версии ' + view.getUint32(4, true) + '.');
	}

	if (total > size) {
		throw new Error('GLB обрезан: заявлено ' + total + ' байт, пришло ' + size + '.');
	}

	let at = 12;
	let json = null;
	let bin = null;
	let binAt = -1;

	while (at + 8 <= total) {
		const len = view.getUint32(at, true);
		const type = view.getUint32(at + 4, true);

		if (at + 8 + len > total) {
			throw new Error('Chunk glTF вылезает за конец файла.');
		}

		if (type === GLB_JSON && json === null) {
			json = bytes.subarray(at + 8, at + 8 + len);
		} else if (type === GLB_BIN && bin === null) {
			bin = bytes.subarray(at + 8, at + 8 + len);
			binAt = at + 8;
		}

		at += 8 + len;
	}

	if (json === null || bin === null) {
		throw new Error('В GLB нет пары: нужны chunk JSON и chunk BIN.');
	}

	let doc;

	try {
		doc = JSON.parse(new TextDecoder('utf-8').decode(json));
	} catch (e) {
		throw new Error('Описание glTF не читается: файл побится.');
	}

	if (doc.meshes === undefined || !Array.isArray(doc.meshes)) {
		throw new Error('В glTF нет meshes: это не модель.');
	}

	if (doc.meshes.length !== 1) {
		throw new Error('В сборке ' + doc.meshes.length + ' мешей, а страница рисует один.');
	}

	const buffers = doc.buffers || [];

	if (buffers.length > 1 || (buffers[0] && buffers[0].uri)) {
		throw new Error('GLB со внешними данными — страница умеет только файл целиком.');
	}

	const prims = doc.meshes[0].primitives || [];

	if ( ! prims.length) {
		throw new Error('У меша нет ни одного примитива: рисовать нечего.');
	}

	if (prims.length > MAX_GROUPS) {
		throw new Error('У меша ' + prims.length + ' групп цвета, а умеем ' + MAX_GROUPS + '.');
	}

	const accessors = doc.accessors || [];
	const views = doc.bufferViews || [];
	const materials = doc.materials || [];
	const groups = [];
	let tris = 0;

	for (let i = 0; i < prims.length; i++) {
		const prim = prims[i] || {};
		const attrs = prim.attributes || {};
		const label = prims.length > 1 ? ' (группа ' + (i + 1) + ')' : '';

		if (prim.mode !== undefined && prim.mode !== GLB_TRIANGLES) {
			throw new Error('Примитив не из треугольников (mode ' + prim.mode + ')' + label
				+ ' — не рисуем.');
		}

		if (attrs.POSITION === undefined) {
			throw new Error('В glTF нет POSITION' + label + ' — рисовать нечего.');
		}

		const positionOf = Number(attrs.POSITION);
		const positions = glbAccessor(bytes, bin, accessors, views, positionOf, 'VEC3', 'POSITION');

		if (Number(accessors[positionOf].count) < 3) {
			throw new Error('В glTF меньше трёх вершин' + label + ': модель пустая.');
		}

		// Нормали — не прихоть: без них купол из граней станет гранёным стаканом.
		const normals = Number.isFinite(Number(attrs.NORMAL))
			? glbAccessor(bytes, bin, accessors, views, Number(attrs.NORMAL), 'VEC3', 'NORMAL')
			: null;

		let index = null;
		let count = positions.length / 3;
		const indicesOf = Number(prim.indices);

		if (Number.isFinite(indicesOf)) {
			const acc = accessors[indicesOf] || {};

			if (acc.componentType !== 5123 && acc.componentType !== 5125) {
				throw new Error('Индексы glTF должны быть 16 или 32 бит, а не '
					+ acc.componentType + '.');
			}

			index = glbAccessor(bytes, bin, accessors, views, indicesOf, 'SCALAR', 'индексы');
			count = index.length / 3;
		}

		tris += count;

		if ( ! Number.isFinite(tris) || tris > MAX_TRIANGLES) {
			throw new Error('Слишком крупная сетка (' + Math.round(tris).toLocaleString('ru-RU')
				+ ' граней) — скачайте модель и откройте в слайсере.');
		}

		// Цвет читаем из файла, а не рисуем свой: у STL цвета не бывает в
		// природе, и серая модель иначе всегда выглядит так, будто что-то не
		// так. Материал — единственный носитель раскраски по типам узлов.
		const matOf = Number(prim.material);
		const pbr = ((materials[Number.isFinite(matOf) ? matOf : 0] || {}).pbrMetallicRoughness) || {};
		const factor = Array.isArray(pbr.baseColorFactor) ? pbr.baseColorFactor : [];
		const metal = Number(pbr.metallicFactor);
		const rough = Number(pbr.roughnessFactor);
		const alpha = factor.length >= 4 ? Number(factor[3]) : 1;

		groups.push({
			positions,
			normals,
			index,
			triangles: Math.floor(count),
			material: {
				color: glbColor(factor),
				metalness: Number.isFinite(metal) ? Math.min(1, Math.max(0, metal)) : null,
				roughness: Number.isFinite(rough) ? Math.min(1, Math.max(0, rough)) : null,
				opacity: Number.isFinite(alpha) ? Math.min(1, Math.max(0, alpha)) : 1,
			},
		});
	}

	return { groups, binAt, triangles: Math.floor(tris), colors: groups.length };
}

function parseStl(buffer) {
	const bytes = new Uint8Array(buffer);
	const size = bytes.byteLength;

	if (size < 84) {
		throw new Error('Файл короче заголовка STL — выгрузка оборвалась.');
	}

	const view = new DataView(bytes.buffer, bytes.byteOffset, size);
	const count = view.getUint32(80, true);

	// Бинарный STL опознаём по размеру, а не по слову «solid»: его пишет в
	// заголовок и половина экспортёров, и тогда ASCII-ветка съела бы мусор.
	if (count > 0 && count <= MAX_TRIANGLES && 84 + 50 * count <= size) {
		return parseBinary(view, count);
	}

	const text = new TextDecoder('utf-8').decode(bytes);

	if (/facet[\s\S]*vertex/i.test(text)) {
		return parseAscii(text);
	}

	throw new Error('Это не STL: ни треугольников, ни ASCII-граней.');
}

function parseBinary(view, count) {
	const pos = new Float32Array(count * 9);
	let offset = 84;

	for (let i = 0; i < count; i++) {
		offset += 12; // нормаль: своя точность, пересчитаем по граням

		for (let v = 0; v < 9; v++) {
			pos[i * 9 + v] = view.getFloat32(offset + v * 4, true);
		}

		offset += 36 + 2;
	}

	return pos;
}

function parseAscii(text) {
	const nums = [];
	const re = /vertex\s+(-?[\d.]+(?:e[-+]?\d+)?)\s+(-?[\d.]+(?:e[-+]?\d+)?)\s+(-?[\d.]+(?:e[-+]?\d+)?)/gi;
	let match;

	while ((match = re.exec(text)) !== null) {
		nums.push(Number(match[1]), Number(match[2]), Number(match[3]));
	}

	if (nums.length < 9) {
		throw new Error('В ASCII-STL нет ни одного треугольника.');
	}

	return new Float32Array(nums);
}

function note(el, text) {
	let box = el.querySelector('.nj667-stl-note');

	if (!box) {
		box = document.createElement('div');
		box.className = 'nj667-stl-note';
		el.appendChild(box);
	}

	box.textContent = text || '';
	box.style.display = text ? 'flex' : 'none';
}

function setState(text) {
	const box = document.getElementById(STATE_ID);

	if (box) {
		box.textContent = text;
	}
}

function setMeta(text) {
	const box = document.getElementById(META_ID);

	if (box) {
		box.textContent = text;
	}
}

/** Шаг сетки: ближайшее «круглое» значение (1, 2, 5, 10) × 10^n не меньше raw. */
function niceStep(raw) {
	const exp = Math.pow(10, Math.floor(Math.log10(raw)));
	const mult = [1, 2, 5, 10].find((m) => m * exp >= raw);

	return mult * exp;
}

/**
 * План сетки по габариту основания (мм). Шаг подбираем так, чтобы по большей
 * стороне было около десяти клеток. Сетка лежит на нижней грани модели
 * (y = min.y), подписи идут от угла основания, а не от нуля OpenSCAD.
 */
function gridPlan(min, max) {
	const fx = Math.max(max.x - min.x, 1);
	const fz = Math.max(max.z - min.z, 1);
	const step = niceStep(Math.max(fx, fz) / 10);

	return {
		step,
		nx: Math.ceil(fx / step - 1e-9),
		nz: Math.ceil(fz / step - 1e-9),
		x0: min.x,
		y: min.y,
		z0: min.z,
	};
}

/** Линии сетки: на одну клетку шире основания с каждой стороны. */
function gridSegments(plan) {
	const { step, nx, nz, x0, y, z0 } = plan;
	const xa = x0 - step;
	const xb = x0 + (nx + 1) * step;
	const za = z0 - step;
	const zb = z0 + (nz + 1) * step;
	const out = [];

	for (let k = -1; k <= nx + 1; k++) {
		const x = x0 + k * step;

		out.push(x, y, za, x, y, zb);
	}

	for (let k = -1; k <= nz + 1; k++) {
		const z = z0 + k * step;

		out.push(xa, y, z, xb, y, z);
	}

	return new Float32Array(out);
}

/** Число в мм для людей: до десятых, с запятой. */
function fmtMm(value) {
	return (Math.round(value * 10) / 10).toLocaleString('ru-RU', { maximumFractionDigits: 1 });
}

/**
 * Подписи шкалы в мм от угла основания. Ряд X идёт вдоль ближнего к камере
 * края (камера по умолчанию со стороны +X и +Z), ряд Z — вдоль правого края.
 */
function gridTicks(plan) {
	const { step, nx, nz, x0, y, z0 } = plan;
	const out = [];

	for (let k = 0; k <= nx; k++) {
		out.push({ text: fmtMm(k * step), x: x0 + k * step, y, z: z0 + (nz + 1) * step });
	}

	for (let k = 0; k <= nz; k++) {
		out.push({ text: fmtMm(k * step), x: x0 + (nx + 1) * step, y, z: z0 + k * step });
	}

	return out;
}

/** Строка под холстом: шаг и габарит. Ширина × глубина × высота — как человек меряет. */
function gridInfoText(plan, lo, hi) {
	return 'сетка ' + fmtMm(plan.step) + ' мм · габарит '
		+ fmtMm(hi.x - lo.x) + ' × ' + fmtMm(hi.z - lo.z) + ' × ' + fmtMm(hi.y - lo.y)
		+ ' мм (ширина × глубина × высота)';
}

/** Строка сетки: видна только пока сетка включена. */
function setGridInfo(text) {
	const box = document.getElementById(GRID_INFO_ID);

	if (box) {
		box.textContent = text || '';
		box.style.display = text ? '' : 'none';
	}
}

/**
 * Сцена: модель в центре, камера по габариту, сетка в мм.
 *
 * Габарит нужен и человеку (он видит, влезет ли в принтер), поэтому подпись
 * размеров пишем прямо под холстом, а не в заголовке.
 */
function mount(el, model) {
	// Один меш или двадцать — сцена одна: группы цвета просто получают свой
	// материал. STL, разумеется, приходит одной группой без материала.
	const groups = (model.groups && model.groups.length)
		? model.groups
		: [{ positions: model.positions, normals: model.normals, index: model.index,
			material: model.material }];
	const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
	const scene = new THREE.Scene();
	const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 100000);

	renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1));
	el.appendChild(renderer.domElement);

	const root = new THREE.Group();
	const box = new THREE.Box3();
	const junk = [];

	groups.forEach((group) => {
		const geometry = new THREE.BufferGeometry();

		geometry.setAttribute('position', new THREE.BufferAttribute(group.positions, 3));

		if (group.normals) {
			// Готовые нормали из glTF: они ровнее, чем «пересчитать по граням»,
			// и только они дают мягкое затенение на склеенных вершинах.
			geometry.setAttribute('normal', new THREE.BufferAttribute(group.normals, 3));
		} else {
			geometry.computeVertexNormals();
		}

		if (group.index) {
			geometry.setIndex(new THREE.BufferAttribute(group.index, 1));
		}

		geometry.rotateX(Z_UP_TO_Y_UP);
		geometry.computeBoundingSphere();
		geometry.computeBoundingBox();

		if (geometry.boundingBox) {
			box.union(geometry.boundingBox);
		}

		const want = group.material || {};
		const material = new THREE.MeshStandardMaterial({
			color: Number.isFinite(Number(want.color)) ? Number(want.color) : 0x8296ad,
			metalness: Number.isFinite(Number(want.metalness)) ? Number(want.metalness) : 0.05,
			roughness: Number.isFinite(Number(want.roughness)) ? Number(want.roughness) : 0.7,
			side: THREE.DoubleSide,
		});

		// Полупрозрачность из glTF трогать нельзя: без transparent = true число
		// opacity просто игнорируется, и файл со «стеклом» станет глухим.
		if (Number.isFinite(Number(want.opacity)) && Number(want.opacity) < 1) {
			material.transparent = true;
			material.opacity = Number(want.opacity);
		}

		root.add(new THREE.Mesh(geometry, material));
		junk.push(geometry, material);
	});

	scene.add(root);

	const sphere = box.isEmpty()
		? new THREE.Sphere(new THREE.Vector3(0, 0, 0), 50)
		: box.getBoundingSphere(new THREE.Sphere());
	const radius = (Number.isFinite(sphere.radius) && sphere.radius > 0) ? sphere.radius : 50;
	const center = sphere.center || new THREE.Vector3();

	scene.add(new THREE.HemisphereLight(0xffffff, 0x334155, 1.1));

	const key = new THREE.DirectionalLight(0xffffff, 1.2);
	key.position.set(center.x + radius, center.y + radius * 1.6, center.z + radius * 1.2);
	scene.add(key);

	// Сетка лежит на нижней грани модели, а не где-то под ней: шаг и подписи —
	// по габариту основания. Выключена по умолчанию (см. gridOn).
	const empty = box.isEmpty();
	const lo = empty ? { x: -50, y: 0, z: -50 } : box.min;
	const hi = empty ? { x: 50, y: 100, z: 50 } : box.max;
	const plan = gridPlan(lo, hi);
	const gridGeometry = new THREE.BufferGeometry();

	gridGeometry.setAttribute('position', new THREE.BufferAttribute(gridSegments(plan), 3));

	const lines = new THREE.LineSegments(gridGeometry, new THREE.LineBasicMaterial({ color: 0x94a3b8 }));

	scene.add(lines);
	junk.push(gridGeometry, lines.material);

	const ticksBox = document.createElement('div');

	ticksBox.className = 'nj667-stl-ticks';
	el.appendChild(ticksBox);

	const labels = gridTicks(plan).map((tick) => {
		const span = document.createElement('span');

		span.className = 'nj667-stl-tick';
		span.textContent = tick.text;
		ticksBox.appendChild(span);

		return { span, tick };
	});

	const anchor = new THREE.Vector3();
	let on = false;

	/** Подписи — DOM поверх холста: точку шкалы проецируем в пиксели кадра. */
	const placeTicks = () => {
		if (!on) {
			return;
		}

		const w = el.clientWidth || 1;
		const h = el.clientHeight || 1;

		labels.forEach(({ span, tick }) => {
			anchor.set(tick.x, tick.y, tick.z).project(camera);

			const inside = anchor.z >= -1 && anchor.z <= 1;

			span.style.display = inside ? '' : 'none';
			span.style.left = (((anchor.x + 1) / 2) * w).toFixed(1) + 'px';
			span.style.top = (((1 - anchor.y) / 2) * h).toFixed(1) + 'px';
		});
	};

	const setGrid = (flag) => {
		on = !!flag;
		lines.visible = on;
		ticksBox.style.display = on ? 'block' : 'none';
		setGridInfo(on ? gridInfoText(plan, lo, hi) : '');
		draw();
	};

	// Направление взгляда — от центра модели, сверху под углом; расстояние считает
	// fit(), иначе в широком окне модель сидит мелкой в углу, а под ней пустота.
	camera.position.set(center.x + VIEW_DIR.x, center.y + VIEW_DIR.y, center.z + VIEW_DIR.z);

	const controls = new OrbitControls(camera, renderer.domElement);
	controls.target.copy(center);
	controls.minDistance = radius * 0.2;
	controls.maxDistance = radius * 30;
	controls.update();

	const draw = () => {
		renderer.render(scene, camera);
		placeTicks();
	};

	/**
	 * Камера по факту, а не «на глаз»: модель обязана заполнить кадр и по
	 * высоте, и по ширине. Three.js меряет только вертикальный fov, поэтому в
	 * широком окне сборка превращалась в маленькую картинку вверху — ровно то,
	 * за что владелец ругал пустое место (13.10.2026). Направление при этом
	 * НЕ трогаем: человек уже мог покрутить модель, и сбрасывать вид на
	 * стартовый при каждом resize значило бы воевать с ним.
	 */
	const fit = () => {
		const w = el.clientWidth || 1;
		const h = el.clientHeight || 1;
		const vfov = (camera.fov * Math.PI) / 180;
		const fov = Math.min(vfov, 2 * Math.atan(Math.tan(vfov / 2) * (w / h)));
		const dist = (radius / Math.sin(fov / 2)) * 1.06;
		let dx = camera.position.x - center.x;
		let dy = camera.position.y - center.y;
		let dz = camera.position.z - center.z;
		let len = Math.sqrt(dx * dx + dy * dy + dz * dz);

		if (!(len > 0)) {
			dx = VIEW_DIR.x;
			dy = VIEW_DIR.y;
			dz = VIEW_DIR.z;
			len = Math.sqrt(dx * dx + dy * dy + dz * dz);
		}

		camera.aspect = w / h;
		camera.position.set(center.x + (dx / len) * dist, center.y + (dy / len) * dist,
			center.z + (dz / len) * dist);
		camera.updateProjectionMatrix();
	};

	const resize = () => {
		renderer.setSize(el.clientWidth || 1, el.clientHeight || 1, false);
		fit();
		// Иначе OrbitControls вернёт камеру на свой внутренний сферический
		// координатный снимок и весь смысл fit() обнулится при первом же кадре.
		controls.update();
		draw();
	};

	controls.addEventListener('change', draw);

	if (typeof ResizeObserver === 'function') {
		new ResizeObserver(resize).observe(el);
	} else {
		window.addEventListener('resize', draw);
	}

	resize();
	setGrid(gridOn);

	return {
		/** Камера на группу: «покажи узел №3» — это про неё, а не про новый холст. */
		inspect(list) {
			const only = Array.isArray(list) && list.length ? list : null;
			let n = 0;

			root.children.forEach((mesh) => {
				mesh.visible = !only || only.indexOf(n) !== -1;
				n++;
			});

			draw();
		},
		setGrid,
		destroy() {
			junk.forEach((thing) => thing.dispose());
			renderer.dispose();
			renderer.domElement.remove();
			ticksBox.remove();
			setGridInfo('');
		},
	};
}

/**
 * Что показывать в окне: изделие целиком или один узел из списка деталей.
 *
 * Кнопка «в 3D» в строке таблицы ставит в data-part номер группы, «Вернуть
 * изделие» снимает. Перезагрузка страницы ради этого не нужна: файл узла
 * лежит рядом, а сцена перебирается на месте.
 */
function partUrl(code, part, buildId) {
	return '/graph3d/b/' + encodeURIComponent(String(code || ''))
		+ '?part=' + Number(part) + '&file=stl&build=' + Number(buildId || 0);
}

/** Картинка узла для строки деталей (PNG 128 px, сервер отдаёт по file=preview). */
function previewUrl(code, part, buildId) {
	return '/graph3d/b/' + encodeURIComponent(String(code || ''))
		+ '?part=' + Number(part) + '&file=preview&build=' + Number(buildId || 0);
}

/**
 * Тело ответа кусками: onProgress(получено, всего). «Всего» — из Content-Length.
 * Сжатый ответ длину не даёт (браузер распаковывает на лету), тогда всего = 0 и
 * показываем только полученное.
 */
async function readBody(response, onProgress) {
	const headers = response.headers;
	const encoded = headers && headers.get('Content-Encoding');
	const declared = !encoded && headers ? (Number(headers.get('Content-Length')) || 0) : 0;

	if (!response.body || typeof response.body.getReader !== 'function') {
		const whole = await response.arrayBuffer();

		onProgress(whole.byteLength, whole.byteLength);

		return whole;
	}

	const reader = response.body.getReader();
	const pieces = [];
	let got = 0;

	// Размер уже известен из заголовков: показываем «0 из 3 КБ», а не голое «0 КБ».
	onProgress(0, declared);

	for (;;) {
		const chunk = await reader.read();

		if (chunk.done) {
			break;
		}

		pieces.push(chunk.value);
		got += chunk.value.length;
		onProgress(got, declared);
	}

	const out = new Uint8Array(got);
	let at = 0;

	pieces.forEach((piece) => {
		out.set(piece, at);
		at += piece.length;
	});

	return out.buffer;
}

async function load(url, el, onProgress) {
	const response = await fetch(url, { credentials: 'same-origin' });

	if (!response.ok) {
		throw new Error('Модель не отдалась: HTTP ' + response.status + '.');
	}

	const buffer = await readBody(response, onProgress || (() => {}));
	// Формат угадываем по первым байтам, а не по data-kind: после пересборки
	// страница могла остаться с прошлой подписью, а гадать на глаз — верный
	// способ однажды показать STL как битый glTF.
	const isGlb = buffer.byteLength >= 4
		&& new DataView(buffer, 0, 4).getUint32(0, true) === GLB_MAGIC;
	let requestedPart = false;

	try {
		requestedPart = new URL(url, window.location.href).searchParams.has('part');
	} catch (e) {
		// URL строится только из data-src; ниже загрузчик всё равно сообщит об ошибке.
	}

	if (!isGlb && !requestedPart) {
		throw new Error('Для изделия целиком доступен только GLB. Запросите новую 3D-сборку.');
	}

	let model;
	let tris;

	if (isGlb) {
		model = parseGlb(buffer);
		tris = model.triangles;
	} else {
		const positions = parseStl(buffer);

		// STL приводим к той же форме, что и GLB: одна группа без материала.
		// Иначе у сцены было бы два разных пути и «узел в 3D» ломался бы на
		// половине сборок.
		model = { groups: [{ positions, normals: null, index: null, material: null }],
			triangles: positions.length / 9, colors: 0 };
		tris = model.triangles;
	}

	const size = Number(el.dataset.stlSize || 0) || buffer.byteLength;
	const mb = (size / 1048576).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
	el.dataset.kind = isGlb ? 'glb' : 'stl';

	// Только размер и время рендера. Формат и число красок человеку не нужны;
	// одну краску в glTF честно предупреждает вьюха.
	const renderMs = Number(el.dataset.renderMs || 0);
	setMeta((size ? mb + ' МБ' : '')
		+ (renderMs > 0 ? (size ? ', ' : '') + 'рендер ' + Math.round(renderMs / 100) / 10 + ' с' : ''));

	return model;
}

/**
 * На что показывать после ответа опроса: только на готовый GLB изделия.
 * Возвращает false, если GLB не создан — STL всего изделия не является fallback.
 */
function pointAt(el, code, row) {
	if (Number(row.glb_size || 0) <= 0) {
		return false;
	}

	const buildId = Number(row.build_id || 0);
	const url = '/graph3d/b/' + encodeURIComponent(String(code || ''))
		+ '?file=glb&build=' + buildId;

	el.dataset.kind = 'glb';
	// Размер изделия пишем, только когда показано оно само: при открытом узле
	// этот опрос иначе подменил бы размер узла размером модели.
	if (shownPart < 0) {
		el.dataset.stlSize = String(row.glb_size);
		if (Number(row.render_ms || 0) > 0) {
			el.dataset.renderMs = String(Number(row.render_ms));
		}
	}
	el.dataset.src = url;

	const download = document.querySelector('[data-glb-download]');

	if (download) {
		download.href = url;
		download.style.display = 'inline-flex';
	}

	const request = document.querySelector('[data-request-files-form]');

	if (request) {
		request.style.display = 'none';
	}

	return true;
}

/**
 * Текст ожидания: «Ждём модель: 1 мин 20 с, узлы: 3 из 12…».
 *
 * Отдельной строкой — потому что владелец (13.10.2026) увидел молчаливое
 * «Ждём модель из воркера…» и решил, что страница зависла. Секундомер тут
 * приклеен к словам, а не уезжает в противоположный край блока — «правило
 * неразрывного статуса» из эталона оформления.
 */
function waitNote(seconds, parts) {
	const s = Math.max(0, Math.round(Number(seconds) || 0));
	const list = Array.isArray(parts) ? parts : [];
	const done = list.filter((part) => (part && part.status) === 'done').length;
	const spent = s >= 60
		? Math.floor(s / 60) + ' мин ' + (s % 60) + ' с'
		: s + ' с';

	return 'Ждём модель: ' + spent + (list.length ? ', узлы: ' + done + ' из ' + list.length : '') + '…';
}

/** Строка статуса во время рендера: только узлы (сам ход показан над моделью). */
function partsLine(parts) {
	const list = Array.isArray(parts) ? parts : [];

	return list.length ? 'узлы: ' + partsReady(list).length + ' из ' + list.length : '';
}

/** Сколько узлов уже на диске — по ответу опроса статуса. */
function partsReady(parts) {
	return (Array.isArray(parts) ? parts : [])
		.filter((part) => (part && part.status) === 'done')
		.map((part) => Number(part.part))
		.filter((part) => Number.isFinite(part));
}

/**
 * Загрузить и показать модель в этом же блоке. Один холст на страницу: узел
 * «в 3D» и всё изделие меняются местами без перезагрузки и без второго
 * WebGL-контекста (их браузер считает, и пятый контекст — уже отказ).
 */
/** «Читаю модель: 412 из 1 236 КБ · 0:03» — число и секундомер, без полосы. */
function readingText(got, total, began) {
	const kb = (n) => Math.round(n / 1024).toLocaleString('ru-RU');
	const size = total > 0 && got <= total ? kb(got) + ' из ' + kb(total) + ' КБ' : kb(got) + ' КБ';

	return 'Читаю модель: ' + size + ' · ' + formatClock((Date.now() - began) / 1000);
}

/** Строка чтения: спиннер перед текстом (CSS-анимация страницы сборки). */
function paintReading(el, text) {
	note(el, text);

	const box = el.querySelector('.nj667-stl-note');

	if (box) {
		const ring = document.createElement('span');

		ring.className = 'nj667-spin-ring n2j-spin inline-block h-3 w-3 shrink-0 rounded-full border-2 border-blue-600 border-r-transparent dark:border-blue-400 dark:border-r-transparent mr-2 align-middle';
		ring.setAttribute('aria-hidden', 'true');
		box.prepend(ring);
	}
}

async function show(el, url, label) {
	const began = Date.now();
	let got = 0;
	let total = 0;
	const paint = () => paintReading(el, readingText(got, total, began));
	const clock = setInterval(paint, 1000);

	paint();

	let model;

	try {
		model = await load(url, el, (g, t) => {
			got = g;
			total = t;
			paint();
		});
	} catch (e) {
		clearInterval(clock);
		el.dataset.src = '';
		note(el, e.message);
		setState('GLB не читается — скачайте файл и проверьте его отдельно.');

		return false;
	}

	clearInterval(clock);

	try {
		if (view) {
			view.destroy();
		}

		view = mount(el, model);
		note(el, '');
		setState(label);
	} catch (e) {
		note(el, 'WebGL недоступен (' + e.message + ') — скачайте GLB-файл.');

		return false;
	}

	return true;
}

/**
 * Кнопки «в 3D» в таблице деталей: показать один узел, второй клик — вернуть
 * изделие. Держимся за делегирование: строки дописывает `paintParts`, и
 * вешать слушателя на каждую был бы гонка с опросом статуса.
 */
// Размер и время рендера изделия целиком. Снимаем в момент ухода к узлу: до этого
// момента статус мог обновить размер (ожидание рендера), а узел перезапишет оба.
let wholeState = null;
let partsBound = false;

function bindParts(el, cfg) {
	// Вызывают дважды (ожидание рендера и готовая модель): второй обработчик клика
	// переключил бы узел обратно в изделие сразу.
	if (partsBound) {
		return;
	}

	partsBound = true;

	document.addEventListener('click', (event) => {
		const target = event.target;

		if (target && target.closest && target.closest('[data-show-whole]')) {
			event.preventDefault();
			showWhole(el);

			return;
		}

		const btn = target && target.closest ? target.closest('[data-part-view]') : null;

		if (!btn) {
			return;
		}

		event.preventDefault();

		const part = Number(btn.dataset.partView);
		const back = shownPart === part || !Number.isFinite(part);

		if (back) {
			showWhole(el);

			return;
		}

		if (shownPart < 0) {
			wholeState = { size: el.dataset.stlSize || '', render: el.dataset.renderMs || '' };
		}

		// «в 3D» из таблицы: список остаётся внизу, а сцена — выше. Прокручиваем к ней.
		if (typeof el.scrollIntoView === 'function') {
			el.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		shownPart = part;
		el.dataset.stlSize = String(Number(btn.dataset.size || 0));
		el.dataset.renderMs = String(Number(btn.dataset.renderMs || 0));

		show(el, partUrl(cfg.code, part, Number(btn.dataset.build || cfg.build_id || 0)),
			'Узел #' + (part + 1))
			.then((ok) => {
				if (!ok) {
					shownPart = -1;
				}

				markPart();
			});
	});
}

/** Изделие целиком: и «вернуть изделие», и кнопка под списком деталей. */
function showWhole(el) {
	shownPart = -1;

	if (wholeState) {
		el.dataset.stlSize = wholeState.size;
		el.dataset.renderMs = wholeState.render;
	}

	if (el.dataset.src) {
		show(el, el.dataset.src, 'Изделие целиком:');
	}

	markPart();
}

/** Подсветка активной строки: человек должен видеть, что в окне — не изделие. */
function markPart() {
	document.querySelectorAll('[data-part-view]').forEach((btn) => {
		const on = Number(btn.dataset.partView) === shownPart;

		// Целиком набор классов, а не toggle: старый bg-white + text-white давал
		// белое на белом в дневном режиме (кнопка «вернуть изделие» не читалась).
		btn.textContent = on ? 'вернуть изделие' : 'в 3D';
		btn.className = on ? PART_BTN_ON : PART_BTN_OFF;
		btn.setAttribute('aria-pressed', on ? 'true' : 'false');
	});
}

/** Классы кнопки «в 3D» в обоих состояниях; тема dark — отдельно, чтобы текст читался. */
const PART_BTN_OFF = 'ml-1 rounded-lg border border-blue-300 bg-white px-2 py-0.5 text-xs font-semibold '
	+ 'text-blue-700 hover:bg-blue-50 dark:border-gray-700 dark:bg-gray-800 dark:text-blue-300';
const PART_BTN_ON = 'ml-1 rounded-lg border border-blue-600 bg-blue-600 px-2 py-0.5 text-xs font-semibold '
	+ 'text-white hover:bg-blue-700 dark:border-blue-500 dark:bg-blue-600 dark:text-white';

/** Ждём рендер: шаг 3 с, после пяти минут — 8 с (модель минутная, не секундная). */
async function waitBuild(el, cfg) {
	if (!el.dataset.status) {
		return null;
	}

	let delay = 3000;
	const started = Date.now();
	let parts = [];

	for (let i = 0; i < 260; i++) {
		await new Promise((resolve) => setTimeout(resolve, delay));

		const row = await pollStatus(el, cfg);

		if (row && row.poll_error) {
			setBusy('render', false);

			return row;
		}

		if (!row || !row.ok) {
			continue; // сеть моргнула — попробуем ещё: терять ожидание глупо
		}

		// Пачку узлов пишем на каждое сообщение: она доделывается по одному
		// файлу, и «12 из 19» человек должен видеть, а не гадать.
		parts = Array.isArray(row.parts) ? row.parts : parts;
		paintParts(row.parts, row.build_id, cfg.code);

		if (row.status === 'done' || row.status === 'error') {
			setBusy('render', false);
			// id берём из ответа: страница могла ждать и «ту» сборку, и новую.
			// При статусе error показываем только GLB, если он действительно готов.
			pointAt(el, cfg.code, row);

			return row;
		}

		if (row.status === 'queued' || row.status === 'running') {
			setBusy('render', true, Number(row.job_elapsed) || 0, row.job === 'running' ? 'идёт' : 'в очереди');
		} else {
			setBusy('render', false);
		}

		const spent = (Date.now() - started) / 1000;

		// Узлы капают на диск раньше всей модели, и человек должен это видеть,
		// иначе «ожидание» выглядит зависшим (вопрос владельца, 13.10.2026).
		// Счётчик живёт в строке статуса: отдельная плашка поверх холста
		// загораживала модель, а кнопки «в 3D» и так видны в списке деталей.
		setState(row.error ? row.error : partsLine(parts));

		if (spent > 300) {
			delay = 8000;
		}
	}

	setBusy('render', false);

	return null;
}

/* ---- Задачи сборки: кнопка занята, рядом спиннер и секундомер ----------- */

// Момент старта задачи по видам кнопок (мс); 0 — кнопка свободна.
const busy = { render: 0, parts: 0 };
let busyTimer = 0;

/** m:ss — тот же формат, что секундомер в редакторе. */
function formatClock(sec) {
	const s = Math.max(0, Math.floor(Number(sec) || 0));

	return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

function paintBusy() {
	Object.keys(busy).forEach((kind) => {
		if (!busy[kind]) {
			return;
		}

		const text = formatClock((Date.now() - busy[kind]) / 1000);

		document.querySelectorAll('[data-busy-clock="' + kind + '"]').forEach((box) => {
			box.textContent = text;
		});
	});
}

function busyBox(kind) {
	const box = document.createElement('span');

	box.className = 'nj667-busy inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400';
	box.setAttribute('data-busy-box', kind);
	box.innerHTML = '<span class="nj667-spin-ring n2j-spin inline-block h-3 w-3 shrink-0 rounded-full border-2 border-blue-600 border-r-transparent dark:border-blue-400 dark:border-r-transparent" aria-hidden="true"></span>'
		+ '<span data-busy-word>в очереди</span>'
		+ '<span class="nj667-busy-clock n2j-clock" data-busy-clock="' + kind + '">0:00</span>';

	return box;
}

/**
* Кнопка задачи: on — заблокирована, рядом спиннер, слово и секундомер;
* off — снова активна, плашка убрана. fromSec (с сервера) ставит секундомер
* по настоящему старту задачи, а не по моменту загрузки страницы.
*/
function setBusy(kind, on, fromSec, word) {
	if (on) {
		const sec = Number(fromSec) || 0;

		if (sec > 0) {
			busy[kind] = Date.now() - sec * 1000;
		} else if (!busy[kind]) {
			busy[kind] = Date.now();
		}
	} else {
		busy[kind] = 0;
	}

	// Слой поверх 3D-области — тот же вид, что на странице проекта: спиннер,
	// «Модель строит граф», секундомер. Включается ровно на время рендера.
	if (kind === 'render') {
		if (!on) {
			setSettingsLocked(false);
		}

		const layer = document.getElementById(WORKING_ID);

		if (layer) {
			layer.style.display = on ? 'flex' : 'none';
		}
	}

	document.querySelectorAll('[data-job-btn="' + kind + '"]').forEach((btn) => {
		const next = btn.nextElementSibling;
		const has = !!next && next.getAttribute('data-busy-box') === kind;

		btn.disabled = !!on;
		btn.dataset.busy = on ? '1' : '0';
		btn.setAttribute('aria-busy', on ? 'true' : 'false');

		if (on && !has) {
			btn.insertAdjacentElement('afterend', busyBox(kind));
		} else if (!on && has) {
			next.remove();
		}
	});

	if (on && word) {
		document.querySelectorAll('[data-busy-box="' + kind + '"] [data-busy-word]').forEach((el) => {
			el.textContent = word;
		});
	}

	if (busy.render || busy.parts) {
		if (!busyTimer) {
			busyTimer = setInterval(paintBusy, 250);
		}
	} else if (busyTimer) {
		clearInterval(busyTimer);
		busyTimer = 0;
	}

	paintBusy();
}

/** Подпись «Собрать узлы» по итогу пачки — в том же виде, что и сервер. */
/** Подпись занятой кнопки пачки: «идёт · 12 из 14» — число, без полосы. */
function partsWord(row) {
	// Считаем по таблице: плитки дублируют те же узлы, и счёт стал бы вдвое больше.
	const total = document.querySelectorAll('.nj667-parts-table [data-part-state]').length;
	const done = partsReady(row.parts).length;
	const job = row.parts_job || row.job;
	const what = job === 'running' ? 'идёт' : 'в очереди';

	return total > 0 ? what + ' · ' + done + ' из ' + total : what;
}

function relabelParts() {
	const rows = Array.from(document.querySelectorAll('.nj667-parts-table [data-part-state]'));
	const total = rows.length;
	const done = rows.filter((el) => el.textContent === PART_TEXT.done).length;
	const label = done > 0 && done < total
		? 'Доделать узлы (осталось ' + (total - done) + ' из ' + total + ')'
		: 'Собрать узлы к печати (' + total + ')';

	document.querySelectorAll('[data-job-btn="parts"]').forEach((btn) => {
		const text = Array.prototype.find.call(btn.childNodes,
			(node) => node.nodeType === 3 && node.textContent.trim() !== '');

		if (text) {
			text.textContent = label;
		} else {
			btn.appendChild(document.createTextNode(label));
		}
	});
}

async function pollStatus(el, cfg) {
	try {
		const sep = el.dataset.status.indexOf('?') === -1 ? '?' : '&';
		const response = await fetch(el.dataset.status + sep + 'csrf='
			+ encodeURIComponent(cfg.csrf || ''), { credentials: 'same-origin' });
		const row = await response.json();

		if (!response.ok || !row || !row.ok) {
			return {
				poll_error: true,
				http_status: response.status,
				error: (row && row.error) || '',
			};
		}

		return row;
	} catch (e) {
		return null; // сеть моргнула — следующий опрос
	}
}

/**
* Пачка узлов: пока задача жива, кнопка занята, строки деталей обновляются;
* когда задачи нет — кнопка снова активна и подпись пересчитана. После загрузки
* страницы запускается только по отдельному parts_job_id.
*/
let partsWatching = false;

async function watchParts(el, cfg) {
	if (partsWatching) {
		return;
	}

	partsWatching = true;

	try {
		await watchPartsLoop(el, cfg);
	} finally {
		partsWatching = false;
	}
}

async function watchPartsLoop(el, cfg) {
	if (!el.dataset.status) {
		return;
	}

	let delay = 3000;
	let sawLive = false;
	const started = Date.now();

	let misses = 0;

	for (let i = 0; i < 260; i++) {
		const row = await pollStatus(el, cfg);

		// HTTP-отказ — не «пропала связь»: покажем ответ API (например, что
		// сессия устарела). Общую сетевую ошибку считаем только по трём промахам.
		if (row && row.poll_error) {
			setBusy('parts', false);
			setState(row.error || 'Сервер ответил ошибкой '
				+ (Number(row.http_status) || '?') + ' при проверке хода узлов.');

			return;
		}

		// Три подряд сетевые неудачи — не ждём вечно: кнопка снова активна,
		// а человек видит, что хода больше не показываем, и может обновить страницу.
		if (!row) {
			misses += 1;

			if (misses >= 3) {
				setBusy('parts', false);
				setState('Ход узлов не виден: связь с сервером пропала. Обновите страницу.');

				return;
			}
		} else {
			misses = 0;
			paintParts(row.parts, row.build_id, cfg.code);

			// Цепочка ставит следующую пачку сама: между ними задача уже «done», а
			// узлы ещё «в очереди» — тогда страница продолжает ждать, а не сдаётся.
			// Живая задача пачки — отдельный сигнал: строки могут её ещё не отразить
			// (или отражать не так), а задача идёт — кнопка занята.
			const live = row.parts_job === 'queued' || row.parts_job === 'running';
			const queued = live || (Array.isArray(row.parts) ? row.parts : [])
				.some((part) => part && part.status === 'queued');

			// Кнопка «узлов» занята, только пока стоят в очереди ЕЁ узлы: живая
			// задача может быть сборкой изделия, и тогда кнопка врала бы «идёт».
			if (!queued) {
				setBusy('parts', false);

				if (sawLive) {
					relabelParts();
				}

				return;
			}

			sawLive = true;
			setBusy('parts', true, Number(row.parts_job ? row.parts_job_elapsed : row.job_elapsed) || 0,
				partsWord(row));
		}

		await new Promise((resolve) => setTimeout(resolve, delay));

		if (Date.now() - started > 300000) {
			delay = 8000;
		}
	}

	setBusy('parts', false);
}

/** Кнопки задач блокируем сразу после отправки: форма уже собрана, повтора не будет. */
/**
* Панель настроек целиком неактивна (включая кнопку), пока идёт задача.
* Сервер ставит то же состояние при загрузке страницы.
*/
function setSettingsLocked(on) {
	document.querySelectorAll('[data-lock-panel]').forEach((fs) => {
		fs.disabled = !!on;

		const form = fs.closest('form');

		if (form) {
			if (on) {
				form.setAttribute('data-locked', '1');
			} else {
				form.removeAttribute('data-locked');
			}
		}
	});
}

/** Ключ sessionStorage: момент нажатия «Пересчитать» (мс). */
const RENDER_CLICK_KEY = 'nj667-render-click';

/**
* Сколько секунд задача уже идёт. Сначала — время с сервера (от старта задачи).
* Если сервер не дал (0), — время нажатия, если оно было недавно.
*/
function renderElapsed(el) {
	const fromServer = Number(el.dataset.jobElapsed) || 0;

	if (fromServer > 0) {
		return fromServer;
	}

	let clicked = 0;

	try {
		clicked = Number(sessionStorage.getItem(RENDER_CLICK_KEY)) || 0;
	} catch (e) {
		clicked = 0;
	}

	const span = Date.now() - clicked;

	return clicked > 0 && span >= 0 && span < 600000 ? Math.floor(span / 1000) : 0;
}

function bindJobButtons() {
	document.addEventListener('submit', (event) => {
		// Старые браузеры не отдают submitter: берём единственную задачную кнопку формы.
		const btn = event.submitter || (event.target && event.target.querySelector
			? event.target.querySelector('[data-job-btn]:not([disabled])') : null);
		const kind = btn && btn.dataset ? btn.dataset.jobBtn : '';

		if (!kind) {
			return;
		}

		if (busy[kind]) {
			event.preventDefault();

			return;
		}

		// Пачку шлём без перехода: переход перезагружал страницу целиком.
		if (kind === 'parts' && event.target && event.target.tagName === 'FORM') {
			event.preventDefault();
			sendParts(event.target, btn);

			return;
		}

		// «Пересчитать» — обычный переход: до него спиннер и блокировку не запускаем,
		// иначе они мигают и перезапускаются после перезагрузки. Отмечаем только время клика.
		if (kind === 'render') {
			try {
				sessionStorage.setItem(RENDER_CLICK_KEY, String(Date.now()));
			} catch (e) {
				// без хранилища секундомер возьмёт время задачи с сервера
			}

			// После сбора полей формы (иначе кнопка «render» не уйдёт на сервер).
			setTimeout(() => setSettingsLocked(true), 0);

			return;
		}

		// Блокируем после того, как браузер соберёт поля формы: кнопка своим
		// именем должна уйти на сервер.
		setTimeout(() => setBusy(kind, true, 0, 'в очереди'), 0);
	});
}

/**
 * Клик по «Собрать узлы» перехватываем раньше отправки формы: переход отменяем
 * сразу, а submit-обработчик выше остаётся страховкой для Enter и прочих путей.
 */
function bindPartsClick() {
	document.addEventListener('click', (event) => {
		const target = event.target && event.target.closest
			? event.target.closest('[data-job-btn="parts"]') : null;

		if (!target || !target.form) {
			return;
		}

		event.preventDefault();

		if (busy.parts) {
			return;
		}

		sendParts(target.form, target);
	}, true);
}

/** Рендер модели, поставленный вместе с пачкой: слой поверх 3D, потом сама модель. */
async function followRender(el, cfg) {
	const row = await waitBuild(el, cfg);

	if (!row) {
		return;
	}

	if (row.poll_error) {
		setState(row.error || 'Сервер отказал в проверке состояния сборки.');

		return;
	}

	if (!el.dataset.src) {
		setState(row.error || 'Файла модели на диске нет.');

		return;
	}

	await show(el, el.dataset.src, 'Изделие целиком:');
}

/**
 * «Собрать узлы» без перехода. Сервер отвечает JSON; дальше ход задачи ведёт
 * тот же опрос, что и при загрузке страницы. Ошибку пишем в строку статуса.
 */
async function sendParts(form, btn) {
	const el = document.getElementById(VIEW_ID);

	setBusy('parts', true, 0, 'в очереди');

	try {
		const body = new FormData(form);

		body.append(btn.name, btn.value);

		const response = await fetch(form.action, {
			method: 'POST',
			body,
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
		});

		if (!response.ok) {
			setBusy('parts', false);
			setState(response.status === 403
				? 'Сессия устарела: обновите страницу (F5) и нажмите ещё раз.'
				: 'Сервер ответил ошибкой ' + response.status + ': узлы не поставлены. Нажмите ещё раз.');

			return;
		}

		const row = await response.json();

		if (!row || !row.ok) {
			setBusy('parts', false);
			setState((row && row.error) || 'Узлы не встали в очередь.');

			return;
		}

		// Сборка могла появиться только сейчас (её не было при загрузке страницы):
		// опрашиваем её по id из ответа, иначе прогресса не видно до перезагрузки.
		const cfg = window.NJ667_BUILD || {};

		if (row.build_id > 0) {
			cfg.build_id = row.build_id;
			el.dataset.status = '/graph3d/api/build_status?code=' + encodeURIComponent(cfg.code || '')
				+ '&build_id=' + row.build_id;
		}

		cfg.job_id = row.job_id;
		watchParts(el, cfg);

		if (row.render) {
			followRender(el, cfg);
		}
	} catch (e) {
		setBusy('parts', false);
		setState('Узлы не поставились: нет связи или сервер ответил не то. Нажмите ещё раз.');
	}
}

/** Полноэкранный режим: сам блок просмотра, холст подстраивается через ResizeObserver. */
function bindFullscreen(el) {
	const btn = document.getElementById('nj667-stl-full');

	if (!btn) {
		return;
	}

	if (!el.requestFullscreen) {
		btn.hidden = true;

		return;
	}

	btn.addEventListener('click', () => {
		if (document.fullscreenElement === el) {
			document.exitFullscreen();
		} else {
			el.requestFullscreen().catch(() => {});
		}
	});

	document.addEventListener('fullscreenchange', () => {
		const on = document.fullscreenElement === el;

		btn.innerHTML = on ? '<i class="fas fa-compress"></i>' : '<i class="fas fa-expand"></i>';
		btn.title = on ? 'Свернуть' : 'На весь экран';
		btn.setAttribute('aria-label', btn.title);
	});
}

/** Галка «Сетка»: состояние живёт в gridOn, чтобы смена узла его не сбрасывала. */
function bindGrid() {
	const box = document.getElementById(GRID_TOGGLE_ID);

	if (!box) {
		return;
	}

	box.checked = gridOn;
	box.addEventListener('change', () => {
		gridOn = box.checked;
		writeGridPref(gridOn);

		if (view) {
			view.setGrid(gridOn);
		}
	});
}

/**
 * Вид списка деталей: «Плитки» (по умолчанию) или «Таблица» со всеми колонками.
 * Класс nj667-pview-table на <html> и кука nj667_pview для всего модуля
 * (path=/graph3d). Начальный класс ставит скрипт в вьюхе до разметки.
 */
function initPartsView() {
	const buttons = document.querySelectorAll('[data-pview]');
	const root = document.documentElement;

	if (!buttons.length) {
		return;
	}

	const sync = () => {
		const table = root.classList.contains('nj667-pview-table');

		buttons.forEach((btn) => {
			btn.setAttribute('aria-pressed', (btn.dataset.pview === 'table') === table ? 'true' : 'false');
		});
	};

	buttons.forEach((btn) => btn.addEventListener('click', () => {
		const table = btn.dataset.pview === 'table';

		root.classList.toggle('nj667-pview-table', table);
		document.cookie = 'nj667_pview=' + (table ? 'table' : 'tiles') + '; path=/graph3d; max-age=31536000; SameSite=Lax';
		sync();
	}));

	sync();
}

async function start() {
	initPartsView();
	bindGrid();
	const el = document.getElementById(VIEW_ID);

	if (!el) {
		return;
	}

	const cfg = window.NJ667_BUILD || {};
	const state = el.dataset.state || '';

	el.dataset.kind = el.dataset.kind || 'glb';

	const waiting = state === 'queued' || (state === 'spec' && cfg.job_id);
	let warn = '';

	bindFullscreen(el);
	// У рендера GLB и подготовки узлов разные job_id. Запускаем наблюдение
	// деталей только если именно их очередь жива, а не на любой сборке модели.
	if (Number(cfg.parts_job_id || 0) > 0) {
		watchParts(el, cfg);
	}

	if (!el.dataset.src && !waiting) {
		const requestForm = document.querySelector('[data-request-files-form]');

		note(el, requestForm
			? 'Нажмите «Собрать 3D файлы сборки», чтобы запросить GLB.'
			: 'Модели пока нет: GLB-модель отсутствует.');
		setState('Модели пока нет.');
		bindParts(el, cfg);

		return;
	}

	if (waiting) {
		bindParts(el, cfg);
		if (state === 'queued') {
			setBusy('render', true, renderElapsed(el), 'в очереди');
		}

		const row = await waitBuild(el, cfg);

		if (row && row.poll_error) {
			note(el, row.error || 'Сервер отказал в проверке состояния сборки.');
			setState(row.error || 'Сервер отказал в проверке состояния сборки.');

			return;
		}

		if (!row) {
			note(el, 'Рендер всё ещё идёт. Страница не ждёт: обновите позже.');
			setState('Ожидание прервано: страница больше не опрашивает воркера.');

			return;
		}

		if (!el.dataset.src) {
			// GLB не создан: STL всего изделия намеренно не является запасным
			// файлом просмотра. Узлы к печати при этом могли быть собраны отдельно.
			const ready = partsReady(row.parts);

			const requestForm = document.querySelector('[data-request-files-form]');
			const requestHint = requestForm
				? 'Нажмите «Собрать 3D файлы сборки», чтобы запросить GLB.'
				: 'GLB не создан; обновите страницу и попробуйте запросить сборку ещё раз.';

			note(el, (ready.length
				? 'GLB изделия не создан; готовы ' + ready.length + ' STL-узлов — «в 3D» в списке деталей. '
				: '') + (row.error || requestHint));
			setState(row.status === 'error'
				? 'Рендер не удался — спецификация ниже верна.'
				: 'Файла модели на диске нет.');

			return;
		}

		if (row.status === 'error') {
			// Если GLB готов, его можно показать и при дополнительной ошибке
			// рендера. Заметка остаётся в строке под холстом.
			warn = row.error || 'воркер сообщил о проблеме';
		}
	}

	const ok = await show(el, el.dataset.src, warn
		? 'Изделие целиком. Воркер добавил: ' + warn
		: 'Изделие целиком:');

	if (ok) {
		bindParts(el, cfg);
	}
}

bindJobButtons();
bindPartsClick();

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', start);
} else {
	start();
}

// Тесты (tests/Js/nj667_stl.test.mjs) проверяют разбор без браузера.
window.NJ667_STL = { parseStl, parseGlb, mount, start, paintParts, partUrl, partButton, waitNote, partsReady,
	niceStep, gridPlan, gridSegments, gridTicks, gridInfoText, setBusy, formatClock, load, readingText };
