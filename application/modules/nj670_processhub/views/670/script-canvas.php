<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
(function () {
	'use strict';

	var cfg = window.NJ670 || {};
	var svg = document.getElementById('nj670-canvas');

	if (!svg) { return; }

	var state = {
		lanes: (cfg.graph && cfg.graph.lanes) || [],
		nodes: (cfg.graph && cfg.graph.nodes) || [],
		edges: (cfg.graph && cfg.graph.edges) || [],
		meta: (cfg.graph && cfg.graph.meta) || {}
	};

	var view = {x: 0, y: 0, w: 1100, h: 680};
	var selected = '';
	var selectedEdge = '';

	// «Шустрый токен»: проигрывание пути по схеме. Токен живёт в координатах
	// графа, поэтому панорама и масштаб двигают его вместе со схемой.
	var tokenRun = null;
	var tokenRaf = 0;
	var tokenPos = {x: 0, y: 0, ready: false};
	var linkMode = false;
	var linkFrom = '';
	var dirty = false;
	var saving = false;
	var undoStack = [];
	var redoStack = [];
	var drag = null;
	var pan = null;
	var raf = 0;
	var pollTimer = 0;
	var cfgVersionId = parseInt(cfg.versionId || 0, 10);

	var els = {
		save: document.getElementById('nj670-save-btn'),
		saveText: document.getElementById('nj670-save-text'),
		undo: document.getElementById('nj670-undo-btn'),
		redo: document.getElementById('nj670-redo-btn'),
		status: document.getElementById('nj670-status'),
		conflict: document.getElementById('nj670-conflict'),
		versionNote: document.getElementById('nj670-version-note'),
		worker: document.getElementById('nj670-worker'),
		workerText: document.getElementById('nj670-worker-text'),
		workerTime: document.getElementById('nj670-worker-time'),
		insp: document.getElementById('nj670-insp'),
		inspEmpty: document.getElementById('nj670-insp-empty'),
		title: document.getElementById('nj670-insp-title'),
		type: document.getElementById('nj670-insp-type'),
		role: document.getElementById('nj670-insp-role'),
		system: document.getElementById('nj670-insp-system'),
		input: document.getElementById('nj670-insp-input'),
		output: document.getElementById('nj670-insp-output'),
		duration: document.getElementById('nj670-insp-duration'),
		wait: document.getElementById('nj670-insp-wait'),
		cond: document.getElementById('nj670-insp-cond'),
		overlay: document.getElementById('nj670-insp-overlay'),
		comment: document.getElementById('nj670-insp-comment'),
		process: document.getElementById('nj670-insp-process'),
		apply: document.getElementById('nj670-insp-apply'),
		del: document.getElementById('nj670-del-btn'),
		meta: document.getElementById('nj670-insp-meta'),
		link: document.getElementById('nj670-link-btn'),
		copilot: document.getElementById('nj670-copilot-run'),
		copilotText: document.getElementById('nj670-copilot-text'),
		copilotNote: document.getElementById('nj670-copilot-note'),
		elapsed: document.getElementById('nj670-stat-elapsed'),
		work: document.getElementById('nj670-stat-work'),
		waitStat: document.getElementById('nj670-stat-wait'),
		cost: document.getElementById('nj670-stat-cost'),
		nodesStat: document.getElementById('nj670-stat-nodes'),
		loops: document.getElementById('nj670-stat-loops'),
		shadow: document.getElementById('nj670-stat-shadow'),
		bottleneck: document.getElementById('nj670-bottleneck-box'),
		play: document.getElementById('nj670-play-btn')
	};

	function clone(v) { return JSON.parse(JSON.stringify(v)); }

	function esc(v) {
		return String(v == null ? '' : v)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function byId(id) {
		for (var i = 0; i < state.nodes.length; i++) {
			if (state.nodes[i].id === id) { return state.nodes[i]; }
		}
		return null;
	}

	function nodeType(type) { return type || 'user_task'; }

	function sizeOf(type) {
		type = nodeType(type);
		if (type === 'start' || type === 'end' || type === 'timer') { return [86, 60]; }
		if (type === 'gateway_xor' || type === 'gateway_and') { return [92, 92]; }
		if (type === 'subprocess') { return [200, 92]; }
		return [196, 86];
	}

	function defaultTitle(type) {
		return (cfg.nodeTypes && cfg.nodeTypes[type]) || 'Новый шаг';
	}

	function setStatus(text) {
		if (els.status) { els.status.textContent = text; }
	}

	function markDirty() {
		dirty = true;
		if (els.save) { els.save.disabled = !cfg.canSave; }
		if (els.saveText) { els.saveText.textContent = 'Сохранить •'; }
	}

	function cleanDirty() {
		dirty = false;
		if (els.save) { els.save.disabled = !cfg.canSave; }
		if (els.saveText) { els.saveText.textContent = 'Сохранено'; }
	}

	function pushUndo() {
		undoStack.push(clone({lanes: state.lanes, nodes: state.nodes, edges: state.edges, meta: state.meta}));
		if (undoStack.length > 60) { undoStack.shift(); }
		redoStack = [];
		if (els.undo) { els.undo.disabled = false; }
		if (els.redo) { els.redo.disabled = true; }
	}

	function restore(snap) {
		state.lanes = snap.lanes;
		state.nodes = snap.nodes;
		state.edges = snap.edges;
		state.meta = snap.meta;
		selected = '';
		selectedEdge = '';
		fillInspector();
		render();
		markDirty();
	}

	function undo() {
		if (!undoStack.length) { return; }
		redoStack.push(clone({lanes: state.lanes, nodes: state.nodes, edges: state.edges, meta: state.meta}));
		restore(undoStack.pop());
		if (els.redo) { els.redo.disabled = false; }
		if (els.undo) { els.undo.disabled = undoStack.length === 0; }
	}

	function redo() {
		if (!redoStack.length) { return; }
		undoStack.push(clone({lanes: state.lanes, nodes: state.nodes, edges: state.edges, meta: state.meta}));
		restore(redoStack.pop());
		if (els.undo) { els.undo.disabled = false; }
		if (els.redo) { els.redo.disabled = redoStack.length === 0; }
	}

	// ------------------------------------------------------------------ отрисовка

	function worldBounds() {
		var maxX = 900, maxY = 500;

		for (var i = 0; i < state.nodes.length; i++) {
			var n = state.nodes[i];
			maxX = Math.max(maxX, (n.x || 0) + (n.w || 196) + 90);
			maxY = Math.max(maxY, (n.y || 0) + (n.h || 86) + 80);
		}

		return {w: maxX, h: maxY};
	}

	function laneBands() {
		var byLane = {};

		for (var i = 0; i < state.nodes.length; i++) {
			var n = state.nodes[i];
			var id = n.lane || 'default';
			if (!byLane[id]) { byLane[id] = {id: id, min: n.y || 0, max: (n.y || 0) + (n.h || 86), title: ''}; }
			byLane[id].min = Math.min(byLane[id].min, n.y || 0);
			byLane[id].max = Math.max(byLane[id].max, (n.y || 0) + (n.h || 86));
		}

		for (var j = 0; j < state.lanes.length; j++) {
			var lane = state.lanes[j];
			if (byLane[lane.id]) { byLane[lane.id].title = lane.title || lane.role_code || lane.id; }
		}

		var out = [];
		for (var key in byLane) {
			if (byLane.hasOwnProperty(key)) { out.push(byLane[key]); }
		}

		out.sort(function (a, b) { return a.min - b.min; });
		return out;
	}

	function wrapText(text, max) {
		var words = String(text || '').split(/\s+/);
		var lines = [];
		var line = '';

		for (var i = 0; i < words.length; i++) {
			var test = line ? line + ' ' + words[i] : words[i];
			if (test.length > max && line) { lines.push(line); line = words[i]; }
			else { line = test; }
			if (lines.length >= 3) { break; }
		}

		if (line && lines.length < 3) { lines.push(line); }
		if (lines.length === 3 && words.join(' ').length > lines.join(' ').length + 2) {
			lines[2] = lines[2].replace(/.{0,2}$/, '') + '…';
		}

		return lines;
	}

	function minutesText(min) {
		min = parseInt(min || 0, 10);
		if (min <= 0) { return '0 мин'; }
		if (min < 60) { return min + ' мин'; }
		if (min < 480) {
			return Math.floor(min / 60) + ' ч' + (min % 60 ? ' ' + (min % 60) + ' мин' : '');
		}
		var d = Math.floor(min / 480);
		var rest = min % 480;
		var h = Math.floor(rest / 60);
		var m = rest % 60;
		return d + ' раб. дн.' + (h ? ' ' + h + ' ч' : '') + (m ? ' ' + m + ' мин' : '');
	}

	function money(v) {
		v = parseFloat(v || 0);
		var kop = Math.abs(v - Math.round(v)) > 0.004;
		return v.toLocaleString('ru-RU', {minimumFractionDigits: kop ? 2 : 0, maximumFractionDigits: kop ? 2 : 0})
			.replace(/\u00A0/g, ' ') + ' ₽';
	}

	function nodeMarkup(n, laneTop) {
		var type = nodeType(n.type);
		var w = n.w || sizeOf(type)[0];
		var h = n.h || sizeOf(type)[1];
		var x = n.x || 0;
		var y = n.y || 0;
		var cls = 'nj670-node' + (selected === n.id ? ' is-selected' : '');
		var html = '<g class="' + cls + '" data-id="' + esc(n.id) + '" data-type="' + esc(type) + '" transform="translate(' + x + ',' + y + ')">';
		var comment = (n.properties && n.properties.comment) || '';
		html += '<title>' + esc(n.title) + (comment ? '\n\n💬 ' + esc(comment) : '') + '</title>';

		if (type === 'start' || type === 'end') {
			html += '<circle class="nj670-shape" cx="' + (w / 2) + '" cy="' + (h / 2) + '" r="24"/>';
			html += '<text class="nj670-title" x="' + (w / 2) + '" y="' + (h + 14) + '" text-anchor="middle">' + esc(n.title) + '</text>';
		} else if (type === 'gateway_xor' || type === 'gateway_and') {
			html += '<polygon class="nj670-shape" points="' + (w / 2) + ',0 ' + w + ',' + (h / 2) + ' ' + (w / 2) + ',' + h + ' 0,' + (h / 2) + '"/>';
			html += '<text class="nj670-title" x="' + (w / 2) + '" y="' + (h / 2 + 4) + '" text-anchor="middle">' + (type === 'gateway_xor' ? 'XOR' : 'AND') + '</text>';
			html += '<text class="nj670-meta" x="' + (w / 2) + '" y="' + (h + 13) + '" text-anchor="middle">' + esc((n.title || '').substring(0, 26)) + '</text>';
		} else if (type === 'timer') {
			html += '<circle class="nj670-shape" cx="' + (w / 2) + '" cy="' + (h / 2) + '" r="26"/>';
			html += '<text class="nj670-title" x="' + (w / 2) + '" y="' + (h / 2 + 4) + '" text-anchor="middle">T</text>';
			html += '<text class="nj670-meta" x="' + (w / 2) + '" y="' + (h + 13) + '" text-anchor="middle">' + esc(n.title) + '</text>';
		} else {
			html += '<rect class="nj670-shape" width="' + w + '" height="' + h + '" rx="10" ry="10"/>';
			if (type === 'subprocess') {
				// Подпроцесс рисуем «двойной рамкой», как в BPMN: видно, что
				// это ссылка/вложенная схема, а не обычная задача.
				html += '<rect x="4" y="4" width="' + (w - 8) + '" height="' + (h - 8) + '" rx="7" fill="none" stroke="#7186a3" stroke-width="1.2"/>';
			}

			if (n.properties && n.properties.shadow) {
				html += '<rect x="3" y="3" width="' + (w - 6) + '" height="' + (h - 6) + '" rx="8" fill="none" stroke="#99803c" stroke-width="1.5" stroke-dasharray="5 3"/>';
			}
			var lines = wrapText(n.title, 26);
			for (var i = 0; i < lines.length; i++) {
				html += '<text class="nj670-title" x="10" y="' + (20 + i * 14) + '">' + esc(lines[i]) + '</text>';
			}
			if (type === 'subprocess' && n.properties && n.properties.process_code) {
				html += '<text class="nj670-meta" x="10" y="' + (h - 26) + '" fill="#7186a3">↳ ' + esc((n.properties.process_title || n.properties.process_code).substring(0, 30)) + '</text>';
			}

			var role = (n.properties && n.properties.role_label) || n.role_code || '';
			var sys = (n.properties && n.properties.system_label) || n.system_code || '';
			var meta = [role, sys].filter(Boolean).join(' · ');
			if (meta) {
				html += '<text class="nj670-meta" x="10" y="' + (h - 11) + '">' + esc(meta.substring(0, 34)) + '</text>';
			}
			if (parseInt(n.duration_minutes || 0, 10) > 0) {
				html += '<text class="nj670-meta" x="' + (w - 8) + '" y="' + (h - 11) + '" text-anchor="end">' + esc(minutesText(n.duration_minutes)) + '</text>';
			}
		}

		if (parseInt(n.wait_time_minutes || 0, 10) > 0 && type !== 'start' && type !== 'end') {
			var wl = minutesText(n.wait_time_minutes);
			var bw = Math.max(42, 10 + wl.length * 5.2);
			html += '<rect x="' + (w - bw + 8) + '" y="-10" width="' + bw + '" height="18" rx="9" fill="#f8f3e9" stroke="#d8c8a6"/>';
			html += '<text class="nj670-badge" x="' + (w - bw / 2 + 8) + '" y="2.5" text-anchor="middle">⏳ ' + esc(wl) + '</text>';
		}

		// Аудит-оверлей: цветная метка на шаге + «облачко» комментария.
		// Обе видны без наведения; полный текст — в <title> и инспекторе.
		var overlay = (n.properties && n.properties.overlay) || '';
		var overlay_style = {
			risk: ['#be123c', '!'],
			control: ['#047857', '✓'],
			question: ['#b45309', '?'],
			idea: ['#6b7280', 'i']
		}[overlay];
		var badge_x = 2;
		if (overlay_style) {
			html += '<circle cx="' + badge_x + '" cy="2" r="9" fill="' + overlay_style[0] + '" stroke="#fff" stroke-width="1.5"/>';
			html += '<text class="nj670-overlay-mark" x="' + badge_x + '" y="5.5" text-anchor="middle" fill="#fff" font-size="11" font-weight="700">' + overlay_style[1] + '</text>';
			badge_x += 18;
		}
		if (comment) {
			html += '<g class="nj670-comment-badge" data-comment="' + esc(comment) + '">';
			html += '<circle cx="' + badge_x + '" cy="2" r="9" fill="#f9fafb" stroke="#6b7280" stroke-width="1.3"/>';
			html += '<text x="' + badge_x + '" y="5.5" text-anchor="middle" font-size="10" fill="#374151">💬</text>';
			html += '</g>';
		}

		if (linkMode) {
			html += '<circle class="nj670-port" cx="' + (w - 3) + '" cy="' + (h / 2) + '" r="5"/>';
		}

		html += '</g>';
		return html;
	}

	// ---------------------------------------------------------------- рёбра
	//
	// Порты выбираются по взаимному положению рамок. Раньше линия всегда
	// выходила из правой грани и входила в левую — из-за этого стрелка к
	// шагу выше или левее шла сквозь блоки, а петли накладывались друг на
	// друга. Теперь у каждого ребра своя пара граней и свой слот на грани.

	function r2(v) {
		return Math.round(v * 10) / 10;
	}

	function rectOf(n) {
		var size = sizeOf(n.type);
		return {
			id: n.id,
			x: n.x || 0,
			y: n.y || 0,
			w: n.w || size[0],
			h: n.h || size[1]
		};
	}

	function portAt(r, side, t) {
		t = Math.max(0.12, Math.min(0.88, t));
		if (side === 'left')  { return {x: r.x, y: r.y + r.h * t}; }
		if (side === 'right') { return {x: r.x + r.w, y: r.y + r.h * t}; }
		if (side === 'top')   { return {x: r.x + r.w * t, y: r.y}; }
		return {x: r.x + r.w * t, y: r.y + r.h};
	}

	function chooseSides(A, B) {
		var dx = (B.x + B.w / 2) - (A.x + A.w / 2);
		var dy = (B.y + B.h / 2) - (A.y + A.h / 2);

		// Вертикаль доминирует — входим в ближнюю горизонтальную грань.
		if (Math.abs(dy) > Math.abs(dx) * 1.15) {
			return dy >= 0 ? ['bottom', 'top'] : ['top', 'bottom'];
		}

		// Цель справа: классическая стрелка «слева направо».
		if (B.x >= A.x + A.w + 16) {
			return ['right', 'left'];
		}

		// Цель слева: обход петлёй снизу (сверху, если снизу тесно).
		if (B.x + B.w <= A.x - 16) {
			return ['bottom', 'bottom'];
		}

		// Рамки пересекаются: выходим в самую свободную сторону.
		var gaps = {
			right: B.x - (A.x + A.w),
			left: A.x - (B.x + B.w),
			bottom: B.y - (A.y + A.h),
			top: A.y - (B.y + B.h)
		};
		var best = 'right';

		for (var k in gaps) {
			if (gaps[k] > gaps[best]) { best = k; }
		}

		if (best === 'bottom') { return ['bottom', 'top']; }
		if (best === 'top')    { return ['top', 'bottom']; }
		if (best === 'left')   { return ['bottom', 'bottom']; }

		return ['right', 'left'];
	}

	// Прямая с горизонтальными/вертикальными касательными: читается как
	// провод, а не как диагональ через пол-схемы.
	function curvePath(a, b, horizontal) {
		var c1x, c1y, c2x, c2y;

		if (horizontal) {
			var mid = a.x + (b.x - a.x) / 2;
			c1x = mid; c1y = a.y; c2x = mid; c2y = b.y;
		} else {
			var midy = a.y + (b.y - a.y) / 2;
			c1x = a.x; c1y = midy; c2x = b.x; c2y = midy;
		}

		return 'M ' + r2(a.x) + ' ' + r2(a.y)
			+ ' C ' + r2(c1x) + ' ' + r2(c1y) + ', ' + r2(c2x) + ' ' + r2(c2y)
			+ ', ' + r2(b.x) + ' ' + r2(b.y);
	}

	// Ортогональный обход с мягкими углами: петли назад и обходы препятствий.
	function elbowPath(points, radius) {
		var d = 'M ' + r2(points[0].x) + ' ' + r2(points[0].y);

		for (var i = 1; i < points.length - 1; i++) {
			var p = points[i], prev = points[i - 1], next = points[i + 1];
			var v1x = p.x - prev.x, v1y = p.y - prev.y;
			var v2x = next.x - p.x, v2y = next.y - p.y;
			var l1 = Math.sqrt(v1x * v1x + v1y * v1y);
			var l2 = Math.sqrt(v2x * v2x + v2y * v2y);
			var r = Math.min(radius, l1 / 2, l2 / 2);

			if (r < 1) {
				d += ' L ' + r2(p.x) + ' ' + r2(p.y);
				continue;
			}

			var ax = p.x - v1x / l1 * r, ay = p.y - v1y / l1 * r;
			var bx = p.x + v2x / l2 * r, by = p.y + v2y / l2 * r;
			d += ' L ' + r2(ax) + ' ' + r2(ay);
			d += ' Q ' + r2(p.x) + ' ' + r2(p.y) + ' ' + r2(bx) + ' ' + r2(by);
		}

		var last = points[points.length - 1];
		return d + ' L ' + r2(last.x) + ' ' + r2(last.y);
	}

	// Прямоугольник коридора между рамками: если в нём стоит третий шаг,
	// прямую вести нельзя — уводим под/над препятствием.
	function blockersInCorridor(A, B, margin) {
		var x0 = Math.min(A.x + A.w, B.x) - margin;
		var x1 = Math.max(A.x + A.w, B.x) + margin;
		var y0 = Math.min(A.y + A.h / 2, B.y + B.h / 2);
		var y1 = Math.max(A.y + A.h / 2, B.y + B.h / 2);
		var hit = [];

		for (var i = 0; i < state.nodes.length; i++) {
			var n = state.nodes[i];

			if (n.id === A.id || n.id === B.id) { continue; }

			var r = rectOf(n);

			if (r.x < x1 && r.x + r.w > x0 && r.y < y1 && r.y + r.h > y0) { hit.push(r); }
		}

		return hit;
	}

	// Самопетля: шаг возвращается в себя (доработка, повторная проверка).
	// Дуга выходит из верхней грани и в неё же входит, поэтому стрелка не
	// режет блок и не сливается с соседними связями.
	function loopPath(out, into, dir, reach) {
		return 'M ' + r2(out.x) + ' ' + r2(out.y)
			+ ' C ' + r2(out.x + 34) + ' ' + r2(out.y + dir * reach)
			+ ', ' + r2(into.x - 34) + ' ' + r2(into.y + dir * reach)
			+ ', ' + r2(into.x) + ' ' + r2(into.y);
	}

	// Тот же коридор, но для вертикальной связи: если между верхней и
	// нижней рамкой стоит третий шаг, прямой провод его перечеркнёт.
	function blockersVertical(A, B, margin) {
		var x0 = Math.min(A.x + A.w / 2, B.x + B.w / 2) - margin;
		var x1 = Math.max(A.x + A.w / 2, B.x + B.w / 2) + margin;
		var y0 = Math.min(A.y + A.h, B.y + B.h) - margin;
		var y1 = Math.max(A.y, B.y) + margin;
		var hit = [];

		for (var i = 0; i < state.nodes.length; i++) {
			var n = state.nodes[i];

			if (n.id === A.id || n.id === B.id) { continue; }

			var r = rectOf(n);

			if (r.x < x1 && r.x + r.w > x0 && r.y < y1 && r.y + r.h > y0) { hit.push(r); }
		}

		return hit;
	}

	// Сколько чужих блоков пересекает вертикальный канал x = chx.
	function channelHits(chx, y0, y1) {
		var hits = 0;

		for (var i = 0; i < state.nodes.length; i++) {
			var r = rectOf(state.nodes[i]);

			if (r.x - 6 < chx && r.x + r.w + 6 > chx && r.y < y1 && r.y + r.h > y0) { hits++; }
		}

		return hits;
	}

	function edgeGeometry(a, b, slotA, slotB) {
		var A = rectOf(a), B = rectOf(b);
		var slot = (slotA + slotB) / 2;

		// Самопетля: у выхода и входа одна и та же рамка.
		if (A.id === B.id) {
			var up = A.y > 90;
			var dir = up ? -1 : 1;
			var side = up ? 'top' : 'bottom';
			var reach = 46 + Math.abs(slot) * 16;
			var loop_out = portAt(A, side, 0.66 + slotA * 0.05);
			var loop_in = portAt(A, side, 0.34 + slotB * 0.05);

			return {
				d: loopPath(loop_out, loop_in, dir, reach),
				lx: (loop_out.x + loop_in.x) / 2,
				ly: loop_out.y + dir * (reach * 0.72),
				ax: 'middle',
				sides: ['right', 'right']
			};
		}

		var sides = chooseSides(A, B);

		// Петля назад/вбок: обе точки на одной горизонтальной грани, обход
		// идёт ниже (или выше) обеих рамок и не режет блоки.
		if (sides[0] === sides[1]) {
			var below = sides[0] === 'bottom';
			var out = portAt(A, sides[0], (B.x + B.w / 2 - A.x) / A.w + slotA * 0.12);
			var into = portAt(B, sides[1], (A.x + A.w / 2 - B.x) / B.w + slotB * 0.12);
			var channel = below
				? Math.max(A.y + A.h, B.y + B.h) + 30 + Math.abs(slot) * 18
				: Math.min(A.y, B.y) - 30 - Math.abs(slot) * 18;
			var pts = [out, {x: out.x, y: channel}, {x: into.x, y: channel}, into];

			return {
				d: elbowPath(pts, 14),
				lx: (out.x + into.x) / 2,
				ly: channel - (below ? 7 : -7),
				ax: 'middle',
				sides: sides
			};
		}

		var p1 = portAt(A, sides[0], 0.5 + slotA * 0.14);
		var p2 = portAt(B, sides[1], 0.5 + slotB * 0.14);
		var horizontal = (sides[0] === 'right' || sides[0] === 'left');

		// Прямой коридор перекрыт — уводим линию под или над препятствием,
		// выбирая более короткий манёвр.
		var blocked = horizontal
			? blockersInCorridor(A, B, 8)
			: blockersVertical(A, B, 8);

		if (blocked.length) {
			if (horizontal) {
				var bottoms = [A.y + A.h, B.y + B.h];
				var tops = [A.y, B.y];

				for (var i = 0; i < blocked.length; i++) {
					bottoms.push(blocked[i].y + blocked[i].h);
					tops.push(blocked[i].y);
				}

				var under = Math.max.apply(null, bottoms) + 26 + Math.abs(slot) * 18;
				var over = Math.min.apply(null, tops) - 26 - Math.abs(slot) * 18;
				var useUnder = (under - (A.y + A.h)) <= (A.y - over);
				var ch = useUnder ? under : over;
				var side2 = useUnder ? 'bottom' : 'top';
				var from2 = portAt(A, side2, 0.5 + slotA * 0.1);
				var to2 = portAt(B, side2, 0.5 + slotB * 0.1);
				var pts2 = [from2, {x: from2.x, y: ch}, {x: to2.x, y: ch}, to2];

				return {
					d: elbowPath(pts2, 14),
					lx: (from2.x + to2.x) / 2,
					ly: ch - (useUnder ? 7 : -7),
					ax: 'middle',
					sides: sides
				};
			}

			// Вертикаль перекрыта: обходим сбоку, по свободному каналу.
			var rightX = Math.max(A.x + A.w, B.x + B.w) + 34 + Math.abs(slot) * 16;
			var leftX  = Math.min(A.x, B.x) - 34 - Math.abs(slot) * 16;
			var span0 = Math.min(A.y, B.y, A.y + A.h, B.y + B.h);
			var span1 = Math.max(A.y, B.y, A.y + A.h, B.y + B.h);
			var useRight = channelHits(rightX, span0, span1) <= channelHits(leftX, span0, span1);
			var chx = useRight ? rightX : leftX;
			var portSide = useRight ? 'right' : 'left';
			var from3 = portAt(A, portSide, 0.5 + slotA * 0.1);
			var to3 = portAt(B, portSide, 0.5 + slotB * 0.1);
			var pts3 = [from3, {x: chx, y: from3.y}, {x: chx, y: to3.y}, to3];

			return {
				d: elbowPath(pts3, 14),
				lx: chx + (useRight ? 7 : -7),
				ly: (from3.y + to3.y) / 2,
				ax: useRight ? 'start' : 'end',
				sides: sides
			};
		}

		// Подпись кладём рядом с линией, а не на неё: у горизонтальной связи
		// сверху, у вертикальной — справа.
		return {
			d: curvePath(p1, p2, horizontal),
			lx: (p1.x + p2.x) / 2 + (horizontal ? 0 : 9),
			ly: (p1.y + p2.y) / 2 - (horizontal ? 7 : 0),
			ax: horizontal ? 'middle' : 'start',
			sides: sides
		};
	}

	function edgesMarkup() {
		var html = '';
		var geometries = [];
		var groups = {};
		var sidesByEdge = {};
		var i, e, a, b;

		// Первый проход: считаем грани и раскладываем порты по слотам, чтобы
		// две стрелки из одного шага не ложились одна на другую.
		for (i = 0; i < state.edges.length; i++) {
			e = state.edges[i];
			a = byId(e.from);
			b = byId(e.to);

			if (!a || !b) { continue; }

			var sides = chooseSides(rectOf(a), rectOf(b));
			sidesByEdge[i] = sides;
			var ka = e.from + '|' + sides[0];
			var kb = e.to + '|' + sides[1];
			(groups[ka] = groups[ka] || []).push(i);
			(groups[kb] = groups[kb] || []).push(i);
		}

		function slotOf(key, idx) {
			var list = groups[key] || [];
			var at = list.indexOf(idx);

			return at < 0 ? 0 : at - (list.length - 1) / 2;
		}

		for (i = 0; i < state.edges.length; i++) {
			e = state.edges[i];
			a = byId(e.from);
			b = byId(e.to);

			if (!a || !b) { continue; }

			var sides = sidesByEdge[i];
			geometries[i] = edgeGeometry(a, b,
				slotOf(e.from + '|' + sides[0], i),
				slotOf(e.to + '|' + sides[1], i));
		}

		for (i = 0; i < state.edges.length; i++) {
			e = state.edges[i];

			if (!geometries[i]) { continue; }

			var p = geometries[i];
			var cls = 'nj670-edge nj670-link';

			if (e.type === 'conditional') { cls += ' is-conditional'; }
			if (e.type === 'loop') { cls += ' is-loop'; }
			if (parseInt(e.avg_minutes || 0, 10) >= 480) { cls += ' is-hot'; }

			var sw = 1.5 + Math.min(3, (parseFloat(e.weight || 1) || 1) / 20);
			var sel = selectedEdge === (e.id || (e.from + '>' + e.to));
			html += '<g data-edge="' + esc(e.id || (e.from + '>' + e.to)) + '" class="nj670-edge-group">';
			html += '<path class="' + cls + '" d="' + p.d + '" stroke-width="' + sw + '" marker-end="url(#nj670-arrow)"' + (sel ? ' stroke="#1e40af" stroke-width="3"' : '') + '/>';

			if (e.condition) {
				var label = String(e.condition).substring(0, 40);
				html += '<text class="nj670-edge-label" x="' + r2(p.lx) + '" y="' + r2(p.ly) + '" text-anchor="' + (p.ax || 'middle') + '">' + esc(label) + '</text>';
			}

			if (parseInt(e.weight || 1, 10) > 1 && cfg.kind === 'fact') {
				html += '<text class="nj670-edge-label" x="' + r2(p.lx) + '" y="' + r2(p.ly + (e.condition ? 13 : 0)) + '" text-anchor="' + (p.ax || 'middle') + '">' + parseInt(e.weight, 10) + '×</text>';
			}

			html += '</g>';
		}

		return html;
	}

	// Держим окно просмотра в пределах схемы. Вызывается после изменения
	// view — но не из render(): кадр, который сам правит координаты, начинает
	// спорить с мышью и схема дрожит.
	function clampView() {
		if (view.w < 400) { view.w = 400; }
		if (view.h < 300) { view.h = 300; }

		var bounds = worldBounds();
		view.x = Math.max(0, Math.min(view.x, Math.max(0, bounds.w + 200 - view.w)));
		view.y = Math.max(0, Math.min(view.y, Math.max(0, bounds.h + 200 - view.h)));
	}

	// Только окно просмотра. Во время панорамирования этого достаточно:
	// содержимое уже в DOM и лежит в мировых координатах. Полная перерисовка
	// (innerHTML) на каждом кадре давала мерцание и лишнюю работу — схема
	// «дрожала» даже после того, как формула сдвига стала точной.
	function paintViewBox() {
		svg.setAttribute('viewBox', view.x + ' ' + view.y + ' ' + view.w + ' ' + view.h);
	}

	// ------------------------------------------------------------------ проверки

	// Валидация BPMN «как в Storm»: связность, старт/конец, роли, условия.
	// Считаем на клиенте — схема меняется каждым перетаскиванием, и гонять
	// её на сервер ради подсказки дороже, чем проверить здесь же.
	function validate(graph) {
		var nodes = (graph && graph.nodes) || state.nodes || [];
		var edges = (graph && graph.edges) || state.edges || [];
		var by = {};
		var i, n;

		for (i = 0; i < nodes.length; i++) { by[nodes[i].id] = nodes[i]; }

		var incoming = {};
		var outgoing = {};

		for (i = 0; i < edges.length; i++) {
			var e = edges[i];
			if (!by[e.from] || !by[e.to]) { continue; }
			(outgoing[e.from] = outgoing[e.from] || []).push(e);
			(incoming[e.to] = incoming[e.to] || []).push(e);
		}

		var issues = [];
		var starts = 0;
		var ends = 0;
		var labels = function (node) { return String(node.title || node.id || 'шаг'); };

		for (i = 0; i < nodes.length; i++) {
			if (nodes[i].type === 'start') { starts++; }
			if (nodes[i].type === 'end') { ends++; }
		}

		if (nodes.length && !starts) { issues.push({severity: 'error', code: 'no_start', text: 'Нет стартового события'}); }
		if (nodes.length && !ends) { issues.push({severity: 'error', code: 'no_end', text: 'Нет конечного события'}); }
		if (starts > 1) { issues.push({severity: 'warning', code: 'many_starts', text: 'Стартовых событий больше одного: непонятно, где начинается процесс'}); }

		for (i = 0; i < nodes.length; i++) {
			n = nodes[i];
			var inb = incoming[n.id] || [];
			var out = outgoing[n.id] || [];
			var isStart = n.type === 'start';
			var isEnd = n.type === 'end';

			if (!isStart && !inb.length) {
				issues.push({severity: 'warning', code: 'unreachable', node_id: n.id, text: '«' + labels(n) + '» недостижим: нет входящей связи'});
			}
			if (!isEnd && !out.length) {
				issues.push({severity: 'warning', code: 'dead_end', node_id: n.id, text: '«' + labels(n) + '» — тупик: нет исходящей связи'});
			}
			if ((n.type === 'user_task' || n.type === 'manual_task' || n.type === 'service_task') && !n.role_code && !n.lane) {
				issues.push({severity: 'warning', code: 'no_role', node_id: n.id, text: '«' + labels(n) + '» без роли и дорожки'});
			}
			if ((n.type === 'gateway_xor' || n.type === 'gateway_and') && out.length < 2) {
				issues.push({severity: 'warning', code: 'gateway_branch', node_id: n.id, text: 'Шлюз «' + labels(n) + '»: нужно минимум две исходящие ветки'});
			}
			if ((n.type === 'gateway_xor') && !inb.length) {
				issues.push({severity: 'info', code: 'gateway_entry', node_id: n.id, text: 'В шлюз «' + labels(n) + '» не входит ни одна связь'});
			}
		}

		for (i = 0; i < edges.length; i++) {
			var edge = edges[i];
			var from = by[edge.from];

			if (edge.from === edge.to) {
				issues.push({severity: 'error', code: 'self_loop', node_id: edge.from, text: 'Связь «' + (from ? labels(from) : edge.from) + '» ведёт сама в себя'});
			}

			if (from && from.type === 'gateway_xor' && !String(edge.condition || '').trim()) {
				issues.push({severity: 'warning', code: 'no_condition', node_id: from.id, text: 'У XOR-шлюза «' + labels(from) + '» не задано условие перехода'});
			}
		}

		return issues.slice(0, 40);
	}

	function renderIssues() {
		var list = document.getElementById('nj670-issues');
		var count = document.getElementById('nj670-issue-count');

		if (!list) { return; }

		var issues = validate();
		var errors = 0;
		for (var i = 0; i < issues.length; i++) { if (issues[i].severity === 'error') { errors++; } }

		if (count) {
			count.textContent = issues.length ? String(issues.length) : 'ок';
			count.className = 'n2j-badge ' + (errors ? 'n2j-badge-err' : (issues.length ? 'n2j-badge-run' : 'n2j-badge-ok'));
		}

		if (!issues.length) {
			list.innerHTML = '<li class="text-emerald-600 dark:text-emerald-400"><i class="fas fa-circle-check mr-1"></i>Ошибок нет: схема связная.</li>';
			return;
		}

		var html = '';

		for (var j = 0; j < issues.length; j++) {
			var issue = issues[j];
			var tone = issue.severity === 'error' ? 'text-rose-600 dark:text-rose-400'
				: (issue.severity === 'info' ? 'text-gray-400' : 'text-amber-700 dark:text-amber-400');
			var icon = issue.severity === 'error' ? 'fa-circle-xmark'
				: (issue.severity === 'info' ? 'fa-circle-info' : 'fa-triangle-exclamation');
			var attrs = issue.node_id
				? ' data-focus="' + esc(issue.node_id) + '" class="cursor-pointer rounded px-1 -mx-1 hover:bg-gray-100 dark:hover:bg-gray-800 ' + tone + '"'
				: ' class="' + tone + '"';

			html += '<li' + attrs + '><i class="fas ' + icon + ' mr-1"></i>' + esc(issue.text) + '</li>';
		}

		list.innerHTML = html;
	}

	function focusNode(id) {
		var n = byId(id);

		if (!n) { return; }

		view.x = (n.x || 0) + (n.w || 196) / 2 - view.w / 2;
		view.y = (n.y || 0) + (n.h || 86) / 2 - view.h / 2;
		clampView();
		render();
	}

	function render() {
		var bounds = worldBounds();

		// Пока тянем мышь или узел, координаты окна не трогаем: иначе
		// ограничение сдвигает кадр под курсором и даёт дрожание.
		if (!pan && !drag) { clampView(); }

		paintViewBox();

		var html = '<defs><marker id="nj670-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path class="nj670-arrow" d="M 0 0 L 10 5 L 0 10 z"/></marker></defs>';

		var bands = laneBands();
		for (var i = 0; i < bands.length; i++) {
			var top = bands[i].min - 34;
			var height = (bands[i].max - bands[i].min) + 74;
			html += '<rect class="nj670-lane" x="18" y="' + top + '" width="' + Math.max(760, bounds.w - 36) + '" height="' + height + '" rx="8"/>';
			html += '<text class="nj670-lane-label" x="30" y="' + (top + 18) + '">' + esc(bands[i].title || 'Дорожка') + '</text>';
		}

		html += edgesMarkup();

		for (var j = 0; j < state.nodes.length; j++) {
			html += nodeMarkup(state.nodes[j]);
		}

		if (!state.nodes.length) {
			html += '<text class="nj670-empty" x="40" y="80">Схема пуста. Добавьте шаг из палитры слева.</text>';
		}

		svg.innerHTML = html;

		if (tokenPos.ready) {
			paintTokenAt(tokenPos.x, tokenPos.y);
		}

		renderIssues();
	}

	// ------------------------------------------------------------ токен-анимация

	function tokenCenter(node) {
		return {
			x: (node.x || 0) + (node.w || sizeOf(node.type)[0]) / 2,
			y: (node.y || 0) + (node.h || sizeOf(node.type)[1]) / 2
		};
	}

	/** Путь токена: от старта по первой исходящей связи, без повторных узлов. */
	function tokenPath() {
		var start = null;
		var i;

		for (i = 0; i < state.nodes.length; i++) {
			if (state.nodes[i].type === 'start') { start = state.nodes[i]; break; }
		}

		if (!start) {
			for (i = 0; i < state.nodes.length; i++) {
				var incoming = false;
				for (var e = 0; e < state.edges.length; e++) {
					if (state.edges[e].to === state.nodes[i].id) { incoming = true; break; }
				}
				if (!incoming) { start = state.nodes[i]; break; }
			}
		}

		if (!start) { start = state.nodes[0]; }
		if (!start) { return []; }

		var path = [];
		var seen = {};
		var current = start;

		while (current && !seen[current.id] && path.length < 200) {
			path.push(current);
			seen[current.id] = true;

			if (current.type === 'end') { break; }

			var next = null;
			for (var j = 0; j < state.edges.length; j++) {
				var edge = state.edges[j];
				if (edge.from !== current.id || seen[edge.to]) { continue; }
				next = byId(edge.to);
				if (next) { break; }
			}

			current = next || null;
		}

		return path;
	}

	/** Токен в координатах графа: слой переживает render() и пересоздаётся. */
	function paintTokenAt(x, y) {
		var layer = svg.querySelector('#nj670-token-layer');

		if (!layer) {
			var ns = 'http://www.w3.org/2000/svg';
			layer = document.createElementNS(ns, 'g');
			layer.setAttribute('id', 'nj670-token-layer');
			layer.setAttribute('pointer-events', 'none');

			var ring = document.createElementNS(ns, 'circle');
			ring.setAttribute('class', 'nj670-token-ring');
			ring.setAttribute('r', '13');
			layer.appendChild(ring);

			var dot = document.createElementNS(ns, 'circle');
			dot.setAttribute('class', 'nj670-token-dot');
			dot.setAttribute('r', '6.5');
			layer.appendChild(dot);

			svg.appendChild(layer);
		}

		layer.setAttribute('transform', 'translate(' + x.toFixed(1) + ',' + y.toFixed(1) + ')');
	}

	function setPlayState(on) {
		if (!els.play) { return; }
		els.play.setAttribute('aria-pressed', on ? 'true' : 'false');
		els.play.innerHTML = '<i class="fas fa-' + (on ? 'stop' : 'play') + '"></i>';
	}

	function hideToken() {
		tokenPos = {x: 0, y: 0, ready: false};
		var layer = svg.querySelector('#nj670-token-layer');

		if (layer) { layer.parentNode.removeChild(layer); }
	}

	function stopToken(manual) {
		if (tokenRaf) { window.cancelAnimationFrame(tokenRaf); }
		tokenRaf = 0;
		tokenRun = null;
		setPlayState(false);

		if (manual) {
			setStatus('Токен остановлен.');
			return;
		}

		// Точка «доехала» — через пару секунд убираем, чтобы не заслонять шаг.
		window.setTimeout(function () {
			if (!tokenRun) { hideToken(); }
		}, 2600);
	}

	function stepToken(ts) {
		if (!tokenRun) { return; }

		var dt = tokenRun.last ? Math.min(90, Math.max(0, ts - tokenRun.last)) : 16;
		tokenRun.last = ts;

		var path = tokenRun.path;
		// Схему могли перерисовать или удалить шаг — не падаем.
		var index = Math.min(tokenRun.i, path.length - 1);
		var from = tokenCenter(path[index]);

		if (index >= path.length - 1) {
			tokenPos = {x: from.x, y: from.y, ready: true};
			paintTokenAt(from.x, from.y);
			setStatus('Токен прошёл схему: ' + path.length + ' шагов.');
			stopToken(false);
			return;
		}

		var to = tokenCenter(path[index + 1]);
		var distance = Math.max(1, Math.hypot(to.x - from.x, to.y - from.y));
		// 260 единиц схемы в секунду: спокойно читается на любом масштабе.
		tokenRun.t += (dt / 1000) * 260 / distance;

		while (tokenRun.t >= 1 && tokenRun.i < path.length - 1) {
			tokenRun.t -= 1;
			tokenRun.i += 1;

			if (tokenRun.i >= path.length - 1) {
				var last = tokenCenter(path[path.length - 1]);
				tokenPos = {x: last.x, y: last.y, ready: true};
				paintTokenAt(last.x, last.y);
				setStatus('Токен прошёл схему: ' + path.length + ' шагов.');
				stopToken(false);
				return;
			}

			from = tokenCenter(path[tokenRun.i]);
			to = tokenCenter(path[tokenRun.i + 1]);
			var title = path[tokenRun.i].title || path[tokenRun.i].id;
			setStatus('Токен: ' + title + '…');
		}

		var x = from.x + (to.x - from.x) * tokenRun.t;
		var y = from.y + (to.y - from.y) * tokenRun.t;
		tokenPos = {x: x, y: y, ready: true};
		paintTokenAt(x, y);
		tokenRaf = window.requestAnimationFrame(stepToken);
	}

	function playToken() {
		if (tokenRun) {
			stopToken(true);
			return;
		}

		var path = tokenPath();

		if (path.length < 2) {
			setStatus('Токену некуда идти: нужен старт и хотя бы один шаг.');
			return;
		}

		var first = tokenCenter(path[0]);
		tokenPos = {x: first.x, y: first.y, ready: true};
		tokenRun = {path: path, i: 0, t: 0, last: 0};
		setPlayState(true);
		setStatus('Токен: ' + (path[0].title || 'старт') + '…');
		paintTokenAt(first.x, first.y);
		tokenRaf = window.requestAnimationFrame(stepToken);
	}

	function scheduleRender() {
		if (raf) { return; }
		raf = window.requestAnimationFrame(function () {
			raf = 0;
			render();
		});
	}

	// ------------------------------------------------------------------ метрики

	// Ход воркера: этап + секундомер, без полосы прогресса (правило UI).
	var workerBase = 0, workerAt = 0, workerTimer = null;

	function clockText(sec) {
		sec = Math.max(0, parseInt(sec, 10) || 0);
		var m = Math.floor(sec / 60), s = sec % 60;
		return m + ':' + (s < 10 ? '0' : '') + s;
	}

	function stopWorkerTicker() {
		if (workerTimer) { window.clearInterval(workerTimer); workerTimer = null; }
	}

	function paintWorker(text, elapsed, stages) {
		if (!els.worker) { return; }
		els.worker.classList.remove('hidden');
		if (els.workerText) { els.workerText.textContent = text; }

		if (stages && stages.length) {
			var trail = [];
			for (var i = 0; i < stages.length; i++) { trail.push(stages[i].message); }
			els.worker.title = trail.join(' → ');
		}

		workerBase = Math.max(0, parseInt(elapsed, 10) || 0);
		workerAt = Date.now();
		if (els.workerTime) { els.workerTime.textContent = clockText(workerBase); }

		if (!workerTimer) {
			workerTimer = window.setInterval(function () {
				if (els.workerTime) {
					els.workerTime.textContent = clockText(workerBase + Math.round((Date.now() - workerAt) / 1000));
				}
			}, 1000);
		}
	}

	function hideWorker() {
		if (els.worker) { els.worker.classList.add('hidden'); }
		stopWorkerTicker();
	}

	function updateWorker(jobs) {
		var active = null;

		for (var key in jobs) {
			var job = jobs[key];

			if (!job || !job.found) { continue; }
			if (job.status !== 'running' && job.status !== 'queued') { continue; }

			if (!active || (job.elapsed_sec || 0) > (active.elapsed_sec || 0)) { active = job; }
		}

		if (!active) { hideWorker(); return; }

		paintWorker(
			active.stage || (active.status === 'queued' ? 'ждёт воркера' : 'воркер работает'),
			active.elapsed_sec || 0,
			active.stages
		);
	}

	function updateStats(metrics) {
		if (!metrics) { return; }
		if (els.elapsed) { els.elapsed.textContent = minutesText(metrics.elapsed_minutes); }
		if (els.work) { els.work.textContent = minutesText(metrics.work_minutes); }
		if (els.waitStat) { els.waitStat.textContent = minutesText(metrics.wait_minutes); }
		if (els.cost) { els.cost.textContent = money(metrics.cost_rub); }
		if (els.nodesStat) { els.nodesStat.textContent = (metrics.nodes_count || 0) + ' / ' + (metrics.edges_count || 0); }
		if (els.loops) { els.loops.textContent = metrics.loop_count || 0; }
		if (els.shadow) { els.shadow.textContent = metrics.shadow_count || 0; }
		if (els.bottleneck) {
			var b = metrics.bottleneck;
			els.bottleneck.innerHTML = b
				? '<i class="fas fa-hourglass-half mr-1 text-rose-500"></i>Узкое место: <span class="font-semibold">' + esc(b.title) + '</span> — ' + (Math.round((b.share || 0) * 1000) / 10) + ' % срока.'
				: 'Узких мест по ожиданию нет.';
		}
	}

	// ------------------------------------------------------------------ инспектор

	function fillInspector() {
		var n = selected ? byId(selected) : null;
		if (!n) {
			if (els.insp) { els.insp.classList.add('hidden'); }
			if (els.inspEmpty) { els.inspEmpty.classList.remove('hidden'); }
			if (els.apply) { els.apply.disabled = true; }
			if (els.del) { els.del.disabled = true; }
			return;
		}

		if (els.insp) { els.insp.classList.remove('hidden'); }
		if (els.inspEmpty) { els.inspEmpty.classList.add('hidden'); }
		if (els.apply) { els.apply.disabled = false; }
		if (els.del) { els.del.disabled = false; }

		if (els.title) { els.title.value = n.title || ''; }
		if (els.type) { els.type.value = nodeType(n.type); }
		if (els.role) { els.role.value = n.role_code || ''; }
		if (els.system) { els.system.value = n.system_code || ''; }
		if (els.input) { els.input.value = n.input_code || ''; }
		if (els.output) { els.output.value = n.output_code || ''; }
		if (els.duration) { els.duration.value = parseInt(n.duration_minutes || 0, 10); }
		if (els.wait) { els.wait.value = parseInt(n.wait_time_minutes || 0, 10); }
		if (els.cond) { els.cond.value = (n.properties && (n.properties.condition || n.properties.description)) || ''; }
		if (els.overlay) { els.overlay.value = (n.properties && n.properties.overlay) || ''; }
		if (els.comment) { els.comment.value = (n.properties && n.properties.comment) || ''; }
		if (els.process) {
			els.process.value = (n.properties && n.properties.process_code) || '';
			// Ссылка осмысленна только у подпроцесса: у задачи поле неактивно.
			els.process.disabled = n.type !== 'subprocess';
		}

		if (els.meta) {
			var inCount = 0, outCount = 0;
			for (var i = 0; i < state.edges.length; i++) {
				if (state.edges[i].to === n.id) { inCount++; }
				if (state.edges[i].from === n.id) { outCount++; }
			}
			els.meta.textContent = 'ID ' + n.id + ' · входящих ' + inCount + ' · исходящих ' + outCount;
		}
	}

	function selectNode(id) {
		selected = id;
		selectedEdge = '';
		fillInspector();
		render();
	}

	function applyInspector() {
		var n = selected ? byId(selected) : null;
		if (!n) { return; }

		pushUndo();

		var type = els.type ? els.type.value : n.type;
		var oldSize = [n.w, n.h];
		var newSize = sizeOf(type);

		n.type = type;
		n.title = (els.title && els.title.value.trim()) || defaultTitle(type);
		n.role_code = els.role ? els.role.value : '';
		n.system_code = els.system ? els.system.value : '';
		n.input_code = els.input ? els.input.value : '';
		n.output_code = els.output ? els.output.value : '';
		n.duration_minutes = Math.max(0, parseInt(els.duration && els.duration.value, 10) || 0);
		n.wait_time_minutes = Math.max(0, parseInt(els.wait && els.wait.value, 10) || 0);
		n.w = newSize[0];
		n.h = newSize[1];

		if (oldSize[0] !== newSize[0]) { n.x = (n.x || 0); }

		if (n.type === 'subprocess' && els.process && els.process.value) {
			n.properties = n.properties || {};
			n.properties.process_code = els.process.value;
			var chosen = null;
			for (var s = 0; s < (cfg.siblings || []).length; s++) {
				if (cfg.siblings[s].code === els.process.value) { chosen = cfg.siblings[s]; break; }
			}
			if (chosen) { n.properties.process_title = chosen.title; }
		} else if (n.properties) {
			delete n.properties.process_code;
			delete n.properties.process_title;
		}

		if (els.cond && els.cond.value.trim() !== '') {
			n.properties = n.properties || {};
			n.properties.condition = els.cond.value.trim();
		} else if (n.properties) {
			delete n.properties.condition;
		}

		if (els.overlay && els.overlay.value !== '') {
			n.properties = n.properties || {};
			n.properties.overlay = els.overlay.value;
		} else if (n.properties) {
			delete n.properties.overlay;
		}

		var comment_text = els.comment ? els.comment.value.trim() : '';
		if (comment_text !== '') {
			n.properties = n.properties || {};
			n.properties.comment = comment_text;
		} else if (n.properties) {
			delete n.properties.comment;
		}

		markDirty();
		fillInspector();
		render();
		setStatus('Шаг обновлён. Не забудьте сохранить схему.');
	}

	function deleteSelected() {
		if (selectedEdge) {
			pushUndo();
			state.edges = state.edges.filter(function (e) {
				return (e.id || (e.from + '>' + e.to)) !== selectedEdge;
			});
			selectedEdge = '';
			markDirty();
			render();
			setStatus('Связь удалена.');
			return;
		}

		if (!selected) { return; }

		pushUndo();
		var id = selected;
		state.nodes = state.nodes.filter(function (n) { return n.id !== id; });
		state.edges = state.edges.filter(function (e) { return e.from !== id && e.to !== id; });
		selected = '';
		fillInspector();
		markDirty();
		render();
		setStatus('Шаг и его связи удалены.');
	}

	// ------------------------------------------------------------------ добавление

	function newNodeId() {
		var base = 'n' + Date.now().toString(36).slice(-5);
		var id = base, k = 2;
		while (byId(id)) { id = base + k; k++; }
		return id;
	}

	function addNode(type) {
		pushUndo();

		var size = sizeOf(type);
		var id = newNodeId();
		var n = {
			id: id,
			step_id: id,
			type: type,
			title: defaultTitle(type),
			lane: '',
			role_code: '',
			system_code: '',
			input_code: '',
			output_code: '',
			duration_minutes: type === 'start' || type === 'end' ? 0 : 30,
			wait_time_minutes: 0,
			x: Math.round(view.x + view.w / 2 - size[0] / 2 + (Math.random() * 40 - 20)),
			y: Math.round(view.y + view.h / 2 - size[1] / 2 + (Math.random() * 40 - 20)),
			w: size[0],
			h: size[1],
			properties: {}
		};

		// Новая задача встаёт в дорожку по роли, если роль уже есть у соседей.
		state.nodes.push(n);
		markDirty();
		selected = id;
		fillInspector();
		render();
		setStatus('Шаг «' + n.title + '» добавлен. Выберите роль и систему справа.');
	}

	function addEdge(from, to) {
		if (!from || !to || from === to) { return; }

		for (var i = 0; i < state.edges.length; i++) {
			if (state.edges[i].from === from && state.edges[i].to === to) {
				setStatus('Такая связь уже есть.');
				return;
			}
		}

		pushUndo();
		var a = byId(from), b = byId(to);

		state.edges.push({
			id: 'e' + Date.now().toString(36).slice(-4),
			from: from,
			to: to,
			type: 'sequence',
			condition: '',
			weight: 1,
			avg_minutes: 0,
			properties: {}
		});

		if (a && b && !b.lane && a.lane) { b.lane = a.lane; }

		markDirty();
		render();
		setStatus('Связь добавлена.');
	}

	// ------------------------------------------------------------------ события

	function point(evt) {
		var pt = svg.createSVGPoint();
		pt.x = evt.clientX;
		pt.y = evt.clientY;
		return pt.matrixTransform(svg.getScreenCTM().inverse());
	}

	// Сколько единиц схемы в одном экранном пикселе. За время перетаскивания
	// view.w/view.h не меняются, поэтому масштаб постоянен: измеряем его один
	// раз при захвате и дальше работаем со смещением курсора в пикселях.
	function screenScale() {
		var m = svg.getScreenCTM();

		return {
			x: (m && isFinite(m.a) && m.a > 0) ? m.a : 1,
			y: (m && isFinite(m.d) && m.d > 0) ? m.d : 1
		};
	}

	function nodeFromEvent(evt) {
		var el = evt.target;
		while (el && el !== svg) {
			if (el.getAttribute && el.getAttribute('data-id')) { return el.getAttribute('data-id'); }
			el = el.parentNode;
		}
		return '';
	}

	function edgeFromEvent(evt) {
		var el = evt.target;
		while (el && el !== svg) {
			if (el.getAttribute && el.getAttribute('data-edge')) { return el.getAttribute('data-edge'); }
			el = el.parentNode;
		}
		return '';
	}

	svg.addEventListener('pointerdown', function (evt) {
		if (evt.button !== 0) { return; }

		var id = nodeFromEvent(evt);
		var scale = screenScale();

		if (linkMode) {
			if (!id) { return; }
			if (!linkFrom) {
				linkFrom = id;
				selectNode(id);
				setStatus('Источник выбран. Теперь кликните шаг-приёмник.');
				return;
			}
			addEdge(linkFrom, id);
			linkFrom = '';
			setStatus('Связь создана. Esc — выйти из режима связей.');
			render();
			return;
		}

		if (id) {
			var n = byId(id);
			selectNode(id);
			// Запоминаем точку захвата в экранных пикселях: при движении
			// переводим смещение курсора в единицы схемы через масштаб.
			// Так узел не «плывёт» от того, что viewBox перерисовался.
			drag = {
				id: id, cx: evt.clientX, cy: evt.clientY,
				ox: n.x || 0, oy: n.y || 0,
				sx: scale.x, sy: scale.y, moved: false
			};
			svg.classList.add('is-dragging');
			try { svg.setPointerCapture(evt.pointerId); } catch (err) {}
			evt.preventDefault();
			return;
		}

		var edgeId = edgeFromEvent(evt);
		if (edgeId) {
			selectedEdge = edgeId;
			selected = '';
			fillInspector();
			render();
			setStatus('Связь выбрана. Delete — удалить.');
			return;
		}

		selected = '';
		selectedEdge = '';
		fillInspector();
		// Якорь — тоже экранная точка. Раньше сюда попадала координата,
		// уже включавшая view.x, и формула «pan.vx - (p.x - pan.x)»
		// подмешивала сдвиг окна в собственный расчёт: схема дрожала.
		pan = {
			cx: evt.clientX, cy: evt.clientY, vx: view.x, vy: view.y,
			sx: scale.x, sy: scale.y, moved: false
		};
		svg.classList.add('is-dragging');
		try { svg.setPointerCapture(evt.pointerId); } catch (err) {}
		evt.preventDefault();
		render();
	});

	svg.addEventListener('pointermove', function (evt) {
		if (!drag && !pan) { return; }

		if (drag) {
			var n = byId(drag.id);
			if (!n) { return; }

			var adx = evt.clientX - drag.cx;
			var ady = evt.clientY - drag.cy;

			// Мелкое дрожание руки не превращаем в правку схемы и undo.
			if (Math.abs(adx) + Math.abs(ady) <= 3) { return; }
			if (!drag.moved) { pushUndo(); drag.moved = true; }

			var nx = Math.round((drag.ox + adx / drag.sx) / 5) * 5;
			var ny = Math.round((drag.oy + ady / drag.sy) / 5) * 5;
			n.x = Math.max(0, nx);
			n.y = Math.max(0, ny);
			scheduleRender();
			return;
		}

		if (pan) {
			var sdx = evt.clientX - pan.cx;
			var sdy = evt.clientY - pan.cy;

			// Браузер не должен начинать выделение текста или нативный drag
			// картинки под курсором — это даёт мигание поверх схемы.
			evt.preventDefault();

			// Мёртвая зона в экранных пикселях: пока рука дрогнула на пару
			// пикселей, холст стоит на месте (раньше он «шевелился» на 1–2 px).
			if (!pan.moved) {
				if (Math.abs(sdx) + Math.abs(sdy) <= 3) { return; }
				pan.moved = true;
			}

			// Смещение считается от точки захвата, а не от прошлого кадра:
			// ошибка округления не накапливается и не раскачивает схему.
			view.x = pan.vx - sdx / pan.sx;
			view.y = pan.vy - sdy / pan.sy;
			clampView();
			// Пересобирать DOM не нужно — двигаем окно просмотра.
			paintViewBox();
		}
	});

	function endPointer(evt, cancelled) {
		if (drag) {
			if (drag.moved) {
				markDirty();
				setStatus(cancelled ? 'Перетаскивание прервано.' : 'Положение шага изменено.');
			}
			drag = null;
			svg.classList.remove('is-dragging');
			try { svg.releasePointerCapture(evt.pointerId); } catch (err) {}
			render();
		}

		if (pan) {
			if (pan.moved && !cancelled) { setStatus('Холст сдвинут. «Показать всю схему» вернёт обзор.'); }
			pan = null;
			svg.classList.remove('is-dragging');
			try { svg.releasePointerCapture(evt.pointerId); } catch (err) {}
		}
	}

	svg.addEventListener('pointerup', function (evt) { endPointer(evt, false); });
	// Потеря захвата (Alt+Tab, звонок, смена окна) не должна оставлять холст
	// в «зажатом» состоянии: иначе следующее движение мыши уводит схему.
	svg.addEventListener('pointercancel', function (evt) { endPointer(evt, true); });

	svg.addEventListener('dblclick', function (evt) {
		var id = nodeFromEvent(evt);

		if (!id) { return; }

		var node = byId(id);

		if (node && node.type === 'subprocess' && node.properties && node.properties.process_code) {
			window.location.href = '/process/' + encodeURIComponent(node.properties.process_code);
			return;
		}

		selectNode(id);
		if (els.title) { els.title.focus(); els.title.select(); }
	});

	// Колесо — масштаб от курсора, без Ctrl. Shift+колесо — сдвиг по
	// горизонтали. Ctrl/Cmd+колесо оставляем для тачпада (pinch): браузер
	// присылает такие события с ctrlKey, и они тоже должны масштабировать.
	svg.addEventListener('wheel', function (evt) {
		evt.preventDefault();

		var unit = evt.deltaMode === 1 ? 16 : (evt.deltaMode === 2 ? 400 : 1);
		var dx = evt.deltaX * unit;
		var dy = evt.deltaY * unit;

		if (evt.shiftKey && !evt.ctrlKey && !evt.metaKey) {
			var scale = screenScale();
			view.x += (dy || dx) / (scale.x || 1);
			clampView();
			paintViewBox();
			return;
		}

		var p = point(evt);
		var step = Math.max(-240, Math.min(240, dy));
		var factor = Math.exp(step * 0.0016);
		var nw = Math.max(420, Math.min(3200, view.w * factor));
		var nh = Math.max(300, Math.min(2200, view.h * factor));

		// Точка под курсором остаётся на месте: иначе при приближении
		// схема «уезжает» и её приходится догонять мышью.
		view.x = p.x - (p.x - view.x) * (nw / view.w);
		view.y = p.y - (p.y - view.y) * (nh / view.h);
		view.w = nw;
		view.h = nh;
		clampView();
		render();
	}, {passive: false});

	// ------------------------------------------------------------------ кнопки

	document.querySelectorAll('[data-add]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			addNode(btn.getAttribute('data-add'));
		});
	});

	if (els.apply) { els.apply.addEventListener('click', applyInspector); }
	if (els.play) { els.play.addEventListener('click', playToken); }

	if (els.type && els.process) {
		els.type.addEventListener('change', function () {
			els.process.disabled = els.type.value !== 'subprocess';
		});
	}
	if (els.del) { els.del.addEventListener('click', deleteSelected); }
	if (els.undo) { els.undo.addEventListener('click', undo); }
	if (els.redo) { els.redo.addEventListener('click', redo); }

	if (els.link) {
		els.link.addEventListener('click', function () {
			linkMode = !linkMode;
			linkFrom = '';
			els.link.setAttribute('aria-pressed', linkMode ? 'true' : 'false');
			setStatus(linkMode ? 'Режим связей: кликните источник, затем приёмник.' : 'Режим связей выключен.');
			render();
		});
	}

	function zoomBy(factor) {
		var cx = view.x + view.w / 2, cy = view.y + view.h / 2;
		var nw = Math.max(420, Math.min(3200, view.w * factor));
		var nh = Math.max(300, Math.min(2200, view.h * factor));
		view.x = cx - nw / 2;
		view.y = cy - nh / 2;
		view.w = nw;
		view.h = nh;
		clampView();
		render();
	}

	var zo = document.getElementById('nj670-zoom-out');
	var zi = document.getElementById('nj670-zoom-in');
	var fit = document.getElementById('nj670-fit-btn');
	var full = document.getElementById('nj670-full-btn');
	var shell = svg.parentNode;

	// Вписываем схему в реальные пропорции окна. Иначе при смене размера
	// (полный экран) viewBox остаётся книжным и по краям появляются поля.
	function fitAll() {
		var b = worldBounds();
		var rect = svg.getBoundingClientRect();
		var aspect = (rect.width > 0 && rect.height > 0) ? rect.width / rect.height : 16 / 9;
		var w = Math.max(700, b.w);
		var h = Math.max(460, b.h);

		if (w / h > aspect) { h = w / aspect; } else { w = h * aspect; }

		view = {x: 0, y: 0, w: w, h: h};
		clampView();
		render();
	}

	if (zo) { zo.addEventListener('click', function () { zoomBy(1.2); }); }
	if (zi) { zi.addEventListener('click', function () { zoomBy(0.83); }); }
	if (fit) { fit.addEventListener('click', fitAll); }

	function fullscreenOn() {
		return document.body.classList.contains('nj670-fullscreen');
	}

	function toggleFullscreen(force) {
		var on = typeof force === 'boolean' ? force : !fullscreenOn();

		document.body.classList.toggle('nj670-fullscreen', on);
		if (shell && shell.classList) { shell.classList.toggle('is-fullscreen', on); }
		if (full) {
			full.setAttribute('aria-pressed', on ? 'true' : 'false');
			full.setAttribute('title', on ? 'Выйти из полноэкранного режима (F)' : 'На весь экран (F)');
			var icon = full.querySelector('i');
			if (icon) { icon.className = 'fas ' + (on ? 'fa-compress' : 'fa-expand'); }
		}

		// Класс меняет размеры контейнера; читаем их после пересчёта стилей,
		// иначе fitAll() видит старые width/height и вписывает неправильно.
		window.requestAnimationFrame(fitAll);
	}

	if (full) {
		full.addEventListener('click', function () { toggleFullscreen(); });
	}

	var issuesList = document.getElementById('nj670-issues');
	if (issuesList) {
		issuesList.addEventListener('click', function (evt) {
			var el = evt.target;
			while (el && el !== issuesList) {
				if (el.getAttribute && el.getAttribute('data-focus')) {
					selectNode(el.getAttribute('data-focus'));
					focusNode(el.getAttribute('data-focus'));
					return;
				}
				el = el.parentNode;
			}
		});
	}

	var fullExit = document.getElementById('nj670-full-exit');
	if (fullExit) {
		fullExit.addEventListener('click', function () { toggleFullscreen(false); });
	}

	// /process/<code>?full=1 — сразу открыть холст на весь экран.
	if (/[?&]full=1(&|$)/.test(window.location.search)) {
		toggleFullscreen(true);
	}

	document.addEventListener('keydown', function (evt) {
		var tag = (evt.target && evt.target.tagName) || '';
		var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';

		if (!typing && (evt.key === 'f' || evt.key === 'F' || evt.key === 'а' || evt.key === 'А')) {
			evt.preventDefault();
			toggleFullscreen();
			return;
		}

		if (evt.key === 'Escape' && fullscreenOn()) {
			toggleFullscreen(false);
			return;
		}

		if ((evt.ctrlKey || evt.metaKey) && evt.key.toLowerCase() === 's') {
			evt.preventDefault();
			save();
			return;
		}

		if (typing) { return; }

		if ((evt.ctrlKey || evt.metaKey) && evt.key.toLowerCase() === 'z') {
			evt.preventDefault();
			if (evt.shiftKey) { redo(); } else { undo(); }
			return;
		}

		if ((evt.ctrlKey || evt.metaKey) && evt.key.toLowerCase() === 'y') {
			evt.preventDefault();
			redo();
			return;
		}

		// Масштаб с клавиатуры: «+»/«−» — вокруг центра, «0» — вписать схему.
		if (evt.key === '+' || evt.key === '=') {
			evt.preventDefault();
			zoomBy(0.83);
			return;
		}

		if (evt.key === '-' || evt.key === '_') {
			evt.preventDefault();
			zoomBy(1.2);
			return;
		}

		if (evt.key === '0') {
			evt.preventDefault();
			fitAll();
			return;
		}

		if (evt.key === 'Delete' || evt.key === 'Backspace') {
			evt.preventDefault();
			deleteSelected();
			return;
		}

		if (evt.key === 'Escape') {
			linkMode = false;
			linkFrom = '';
			selectedEdge = '';
			if (els.link) {
				els.link.classList.remove('ring-2', 'bg-blue-700', 'text-white');
			}
			render();
			setStatus('Режим связей выключен.');
		}
	});

	// ------------------------------------------------------------------ сохранение

	function save() {
		if (!cfg.canSave) {
			setStatus('Эта версия недоступна для правки (очередь или архив).');
			return;
		}

		if (saving) { return; }

		saving = true;
		if (els.saveText) { els.saveText.textContent = 'Сохраняю…'; }

		post('save', {
			code: cfg.code,
			version_id: cfgVersionId,
			kind: cfg.kind,
			graph: {lanes: state.lanes, nodes: state.nodes, edges: state.edges, meta: state.meta}
		}, function (data) {
			saving = false;

			if (!data.ok) {
				setStatus('Не сохранено: ' + (data.error || 'неизвестная ошибка'));
				if (els.saveText) { els.saveText.textContent = 'Сохранить'; }
				return;
			}

			state.lanes = data.graph.lanes || state.lanes;
			state.nodes = data.graph.nodes || state.nodes;
			state.edges = data.graph.edges || state.edges;
			state.meta = data.graph.meta || state.meta;

			cfgVersionId = parseInt(data.version.id || cfgVersionId, 10);
			cfg.updatedAt = data.version.updated_at || cfg.updatedAt;
			cleanDirty();
			updateStats(data.metrics);
			render();
			setStatus('Сохранено. Версия ' + (data.version.status ? '— ' + (cfg.statuses[data.version.status] || data.version.status) : ''));
		});
	}

	if (els.save) { els.save.addEventListener('click', save); }

	function post(action, body, done) {
		body.csrf = cfg.csrf;

		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.api + '/' + action, true);
		xhr.setRequestHeader('Content-Type', 'application/json; charset=UTF-8');
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) { return; }
			var data;
			try { data = JSON.parse(xhr.responseText); }
			catch (e) { data = {ok: false, error: 'Сервер вернул не JSON (HTTP ' + xhr.status + ').'}; }
			done(data);
		};
		xhr.onerror = function () { done({ok: false, error: 'Сеть недоступна.'}); };
		xhr.send(JSON.stringify(body));
	}

	// ------------------------------------------------------------------ copilot

	if (els.copilot) {
		els.copilot.addEventListener('click', function () {
			var text = els.copilotText ? els.copilotText.value.trim() : '';

			if (text.length < 5) {
				if (els.copilotNote) { els.copilotNote.textContent = 'Опишите правку: что добавить или изменить.'; }
				return;
			}

			els.copilot.disabled = true;
			if (els.copilotNote) { els.copilotNote.textContent = 'Ставлю задачу в очередь…'; }

			post('synth', {
				code: cfg.code,
				kind: cfg.kind,
				instruction: text,
				node_id: selected || '',
				text: ''
			}, function (data) {
				els.copilot.disabled = false;

				if (!data.ok) {
					if (els.copilotNote) { els.copilotNote.textContent = data.error || 'Не удалось поставить задачу.'; }
					return;
				}

				if (els.copilotNote) {
					els.copilotNote.textContent = 'AI дорабатывает схему. Страница обновится сама, когда версия будет готова.';
				}
				setStatus('Copilot: задача в очереди (job #' + (data.job_id || '?') + ').');
				window.setTimeout(poll, 2500);
			});
		});
	}

	// ------------------------------------------------------------------ поллинг

	function poll() {
		if (dirty || document.hidden) {
			window.clearTimeout(pollTimer);
			pollTimer = window.setTimeout(poll, 9000);
			return;
		}

		post('status', {
			code: cfg.code,
			kind: cfg.kind,
			version_id: cfgVersionId,
			updated_at: cfg.updatedAt || ''
		}, function (data) {
			window.clearTimeout(pollTimer);

			if (data.ok) {
				if (data.version_id && parseInt(data.version_id, 10) !== cfgVersionId) {
					cfgVersionId = parseInt(data.version_id, 10);
					if (els.versionNote) { els.versionNote.textContent = 'Версия ' + (data.plan && data.plan.version_tag ? data.plan.version_tag : ''); }
				}

				if (data.graph) {
					var incoming = JSON.stringify({nodes: data.graph.nodes, edges: data.graph.edges, lanes: data.graph.lanes});
					var current = JSON.stringify({nodes: state.nodes, edges: state.edges, lanes: state.lanes});

					if (incoming !== current) {
						state.nodes = data.graph.nodes || [];
						state.edges = data.graph.edges || [];
						state.lanes = data.graph.lanes || [];
						state.meta = data.graph.meta || {};
						selected = '';
						selectedEdge = '';
						fillInspector();
						render();
						setStatus('Схема обновлена на сервере и перерисована.');
					}
				}

				updateStats(data.metrics);
				updateWorker(data.jobs);

				if (data.version_status === 'queued' || data.version_status === 'draft') {
					setStatus('AI собирает схему… Можно продолжать смотреть, правка заблокирована до готовности.');
				}

				if (data.version_status === 'error') {
					setStatus('Сборка завершилась ошибкой. Проверьте журнал задач или соберите схему заново.');
				}
			}

			pollTimer = window.setTimeout(poll, 9000);
		});
	}

	// ------------------------------------------------------------------ старт

	if (!state.nodes.length) {
		var b = worldBounds();
		view = {x: 0, y: 0, w: Math.max(900, b.w), h: Math.max(520, b.h)};
	}

	// Открываем проверку наружу: её использует jsdom-тест и консоль отладки.
	window.NJ670 = window.NJ670 || {};
	window.NJ670.validate = function (graph) { return validate(graph); };

	if (els.undo) { els.undo.disabled = true; }
	if (els.redo) { els.redo.disabled = true; }
	render();
	updateStats((cfg.metrics && cfg.metrics.nodes_count != null) ? cfg.metrics : null);

	// Первый опрос — почти сразу: если воркер уже собирает схему, владелец
	// должен увидеть этап и секундомер, а не ждать девять секунд.
	if (els.worker && !els.worker.classList.contains('hidden')) {
		paintWorker(els.workerText ? els.workerText.textContent : 'воркер работает',
			parseInt(els.worker.getAttribute('data-elapsed'), 10) || 0, []);
	}

	pollTimer = window.setTimeout(poll, 1500);
})();
</script>
