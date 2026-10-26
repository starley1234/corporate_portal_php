<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
(function () {
	var cfg = window.NJ668 || {};
	var scene = cfg.scene || {w: 960, h: 640, bg: '#eef6ff', shapes: []};
	var tool = 'select';
	var selected = '';
	var dirty = false;
	var undo = [];
	var redo = [];
	var drawing = null;
	var drag = null;
	var pollTimer = 0;
	var elapsedTimer = 0;
	var elapsedAt = 0;

	var canvas = document.getElementById('nj668-canvas');
	var stage = document.getElementById('nj668-stage');
	var working = document.getElementById('nj668-working');
	var failBox = document.getElementById('nj668-fail');
	var failText = document.getElementById('nj668-fail-text');
	var selectedEl = document.getElementById('nj668-selected');
	var shapesEl = document.getElementById('nj668-stat-shapes');
	var saveBtn = document.getElementById('nj668-save-icon-btn');
	var undoBtn = document.getElementById('nj668-undo-btn');
	var redoBtn = document.getElementById('nj668-redo-btn');
	var delShapeBtn = document.getElementById('nj668-del-shape-btn');
	var fillInp = document.getElementById('nj668-fill');
	var strokeInp = document.getElementById('nj668-stroke');
	var promptEl = document.getElementById('nj668-prompt');

	function clone(v) { return JSON.parse(JSON.stringify(v)); }

	function shapes() { return scene.shapes || (scene.shapes = []); }

	function byId(id) {
		var list = shapes();
		for (var i = 0; i < list.length; i++) {
			if (list[i].id === id) { return list[i]; }
		}
		return null;
	}

	function nid() {
		var n = shapes().length + 1;
		while (byId('s' + n)) { n++; }
		return 's' + n;
	}

	function markDirty() {
		dirty = true;
		if (saveBtn) { saveBtn.disabled = false; }
	}

	function snap() {
		undo.push(clone(scene));
		if (undo.length > 40) { undo.shift(); }
		redo = [];
		if (undoBtn) { undoBtn.disabled = false; }
		if (redoBtn) { redoBtn.disabled = true; }
	}

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
	}

	function attr(s) {
		var fill = s.fill || 'none';
		var stroke = s.stroke || 'none';
		var a = ' fill="' + esc(fill) + '" stroke="' + esc(stroke) + '"';
		if (s.sw > 0 && s.stroke) {
			a += ' stroke-width="' + s.sw + '" stroke-linejoin="round" stroke-linecap="round"';
		}
		if (s.opacity != null && s.opacity < 0.999) {
			a += ' opacity="' + s.opacity + '"';
		}
		return a;
	}

	function cubicD(pts, closed) {
		if (!pts || pts.length < 4) { return ''; }
		var d = 'M ' + pts[0][0] + ' ' + pts[0][1];
		var i = 1;
		while (i + 2 < pts.length) {
			d += ' C ' + pts[i][0] + ' ' + pts[i][1] + ' ' + pts[i + 1][0] + ' ' + pts[i + 1][1] + ' ' + pts[i + 2][0] + ' ' + pts[i + 2][1];
			i += 3;
		}
		if (closed) { d += ' Z'; }
		return d;
	}

	function svgShape(s) {
		var k = s.kind;
		var a = ' data-id="' + esc(s.id) + '"' + attr(s);
		if (k === 'rect') {
			var r = s.rx ? ' rx="' + s.rx + '" ry="' + s.rx + '"' : '';
			return '<rect x="' + s.x + '" y="' + s.y + '" width="' + s.w + '" height="' + s.h + '"' + r + a + '/>';
		}
		if (k === 'circle') {
			return '<circle cx="' + s.x + '" cy="' + s.y + '" r="' + s.r + '"' + a + '/>';
		}
		if (k === 'ellipse') {
			return '<ellipse cx="' + s.x + '" cy="' + s.y + '" rx="' + s.rx + '" ry="' + s.ry + '"' + a + '/>';
		}
		if (k === 'path') {
			return '<path d="' + esc(s.d || '') + '"' + a + '/>';
		}
		var pts = s.pts || [];
		var pair = [];
		for (var i = 0; i < pts.length; i++) { pair.push(pts[i][0] + ',' + pts[i][1]); }
		if (k === 'poly') {
			return '<polygon points="' + pair.join(' ') + '"' + a + '/>';
		}
		if (k === 'line') {
			var line = ' data-id="' + esc(s.id) + '" fill="none" stroke="' + esc(s.stroke || '#1e293b') + '"';
			if (s.sw > 0) { line += ' stroke-width="' + s.sw + '" stroke-linejoin="round" stroke-linecap="round"'; }
			if (s.opacity != null && s.opacity < 0.999) { line += ' opacity="' + s.opacity + '"'; }
			return '<polyline points="' + pair.join(' ') + '"' + line + '/>';
		}
		if (k === 'cubic') {
			var d = cubicD(pts, !!s.closed);
			return d ? '<path d="' + d + '"' + a + '/>' : '';
		}
		return '';
	}

	function paint() {
		var w = scene.w || 960;
		var h = scene.h || 640;
		var html = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="100%" role="img">';
		html += '<rect width="100%" height="100%" fill="' + esc(scene.bg || '#eef6ff') + '" data-bg="1"/>';
		var list = shapes();
		for (var i = 0; i < list.length; i++) {
			html += svgShape(list[i]);
		}
		if (drawing && drawing.preview) {
			html += drawing.preview;
		}
		if (selected) {
			var box = bounds(byId(selected));
			if (box) {
				html += '<rect x="' + (box.x - 4) + '" y="' + (box.y - 4) + '" width="' + (box.w + 8) + '" height="' + (box.h + 8) + '" fill="none" stroke="#4f46e5" stroke-width="2" stroke-dasharray="4 3" pointer-events="none"/>';
			}
		}
		html += '</svg>';
		stage.innerHTML = html;
		if (shapesEl) { shapesEl.textContent = String(list.length); }
		if (selectedEl) {
			var sh = byId(selected);
			selectedEl.textContent = sh ? (sh.kind + ' · ' + sh.id) : 'ничего не выбрано';
		}
		if (delShapeBtn) { delShapeBtn.disabled = !selected; }
	}

	function bounds(s) {
		if (!s) { return null; }
		if (s.kind === 'rect') { return {x: +s.x, y: +s.y, w: +s.w, h: +s.h}; }
		if (s.kind === 'circle') { return {x: s.x - s.r, y: s.y - s.r, w: s.r * 2, h: s.r * 2}; }
		if (s.kind === 'ellipse') { return {x: s.x - s.rx, y: s.y - s.ry, w: s.rx * 2, h: s.ry * 2}; }
		var pts = s.pts || [];
		if (!pts.length) { return null; }
		var minx = pts[0][0], miny = pts[0][1], maxx = minx, maxy = miny;
		for (var i = 1; i < pts.length; i++) {
			if (pts[i][0] < minx) minx = pts[i][0];
			if (pts[i][1] < miny) miny = pts[i][1];
			if (pts[i][0] > maxx) maxx = pts[i][0];
			if (pts[i][1] > maxy) maxy = pts[i][1];
		}
		return {x: minx, y: miny, w: Math.max(1, maxx - minx), h: Math.max(1, maxy - miny)};
	}

	function svgPt(evt) {
		var svg = stage.querySelector('svg');
		if (!svg) { return [0, 0]; }
		var pt = svg.createSVGPoint();
		pt.x = evt.clientX;
		pt.y = evt.clientY;
		var m = svg.getScreenCTM();
		if (!m) { return [0, 0]; }
		var p = pt.matrixTransform(m.inverse());
		return [Math.round(p.x * 10) / 10, Math.round(p.y * 10) / 10];
	}

	function hit(evt) {
		var el = evt.target;
		if (!el || !el.getAttribute) { return ''; }
		if (el.getAttribute('data-bg')) { return ''; }
		return el.getAttribute('data-id') || '';
	}

	function setTool(name) {
		tool = name;
		drawing = null;
		Array.prototype.forEach.call(document.querySelectorAll('#nj668-tool-group [data-tool]'), function (btn) {
			var on = btn.getAttribute('data-tool') === name;
			btn.className = 'n2j-seg-item' + (on ? ' is-active' : '');
		});
	}

	function moveShape(s, dx, dy) {
		if (!s) { return; }
		if (s.kind === 'rect' || s.kind === 'circle' || s.kind === 'ellipse') {
			s.x = Math.round((s.x + dx) * 10) / 10;
			s.y = Math.round((s.y + dy) * 10) / 10;
			return;
		}
		if (s.pts) {
			for (var i = 0; i < s.pts.length; i++) {
				s.pts[i][0] = Math.round((s.pts[i][0] + dx) * 10) / 10;
				s.pts[i][1] = Math.round((s.pts[i][1] + dy) * 10) / 10;
			}
		}
	}

	function applyColor(s) {
		if (!s) { return; }
		s.fill = fillInp ? fillInp.value : s.fill;
		s.stroke = strokeInp ? strokeInp.value : s.stroke;
		if ((s.kind === 'line' || s.kind === 'cubic') && !s.sw) { s.sw = 3; }
	}

	stage.addEventListener('mousedown', function (evt) {
		if (working && !working.classList.contains('hidden')) { return; }
		var p = svgPt(evt);
		if (tool === 'select') {
			var id = hit(evt);
			selected = id;
			if (id) {
				drag = {id: id, x: p[0], y: p[1], moved: false};
			}
			paint();
			return;
		}
		if (tool === 'rect' || tool === 'circle') {
			drawing = {kind: tool, a: p, b: p};
			return;
		}
		if (tool === 'line' || tool === 'cubic' || tool === 'poly') {
			if (!drawing || drawing.kind !== tool) {
				drawing = {kind: tool, pts: [p]};
			} else {
				drawing.pts.push(p);
			}
			if (shapes().length >= (cfg.maxShapes || 120)) { drawing = null; paint(); return; }
			if (tool === 'line' && drawing.pts.length >= 2) {
				snap();
				var line = {id: nid(), kind: 'line', pts: drawing.pts.slice(0, 2), fill: '', stroke: strokeInp.value, sw: 3};
				shapes().push(line);
				selected = line.id;
				drawing = null;
				markDirty();
			} else if (tool === 'cubic' && drawing.pts.length >= 4) {
				snap();
				var cub = {id: nid(), kind: 'cubic', pts: drawing.pts.slice(0, 4), fill: fillInp.value, stroke: strokeInp.value, sw: 2};
				shapes().push(cub);
				selected = cub.id;
				drawing = null;
				markDirty();
			}
			paint();
		}
	});

	stage.addEventListener('mousemove', function (evt) {
		var p = svgPt(evt);
		if (drag) {
			var s = byId(drag.id);
			var dx = p[0] - drag.x;
			var dy = p[1] - drag.y;
			if (!drag.moved && (Math.abs(dx) > 1 || Math.abs(dy) > 1)) {
				snap();
				drag.moved = true;
			}
			if (drag.moved) {
				moveShape(s, dx, dy);
				drag.x = p[0];
				drag.y = p[1];
				markDirty();
				paint();
			}
			return;
		}
		if (drawing && (drawing.kind === 'rect' || drawing.kind === 'circle')) {
			drawing.b = p;
			var a = drawing.a, b = drawing.b;
			var x = Math.min(a[0], b[0]), y = Math.min(a[1], b[1]);
			var w = Math.max(1, Math.abs(b[0] - a[0])), h = Math.max(1, Math.abs(b[1] - a[1]));
			if (drawing.kind === 'rect') {
				drawing.preview = '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" fill="' + esc(fillInp.value) + '" fill-opacity="0.35" stroke="' + esc(strokeInp.value) + '" stroke-width="2"/>';
			} else {
				var r = Math.max(w, h) / 2;
				drawing.preview = '<circle cx="' + (x + w / 2) + '" cy="' + (y + h / 2) + '" r="' + r + '" fill="' + esc(fillInp.value) + '" fill-opacity="0.35" stroke="' + esc(strokeInp.value) + '" stroke-width="2"/>';
			}
			paint();
		}
	});

	window.addEventListener('mouseup', function () {
		if (drag) {
			drag = null;
			return;
		}
		if (drawing && (drawing.kind === 'rect' || drawing.kind === 'circle')) {
			if (shapes().length >= (cfg.maxShapes || 120)) { drawing = null; paint(); return; }
			var a = drawing.a, b = drawing.b;
			var x = Math.min(a[0], b[0]), y = Math.min(a[1], b[1]);
			var w = Math.max(4, Math.abs(b[0] - a[0])), h = Math.max(4, Math.abs(b[1] - a[1]));
			snap();
			var sh;
			if (drawing.kind === 'rect') {
				sh = {id: nid(), kind: 'rect', x: x, y: y, w: w, h: h, fill: fillInp.value, stroke: strokeInp.value, sw: 2};
			} else {
				sh = {id: nid(), kind: 'circle', x: x + w / 2, y: y + h / 2, r: Math.max(w, h) / 2, fill: fillInp.value, stroke: strokeInp.value, sw: 2};
			}
			shapes().push(sh);
			selected = sh.id;
			drawing = null;
			markDirty();
			paint();
		}
	});

	stage.addEventListener('dblclick', function (evt) {
		evt.preventDefault();
		if (drawing && drawing.kind === 'poly' && drawing.pts && drawing.pts.length >= 3) {
			snap();
			var poly = {id: nid(), kind: 'poly', pts: drawing.pts.slice(), fill: fillInp.value, stroke: strokeInp.value, sw: 2, closed: 1};
			shapes().push(poly);
			selected = poly.id;
			drawing = null;
			markDirty();
			paint();
		}
	});

	Array.prototype.forEach.call(document.querySelectorAll('#nj668-tool-group [data-tool]'), function (btn) {
		btn.addEventListener('click', function () { setTool(btn.getAttribute('data-tool')); });
	});

	if (fillInp) {
		fillInp.addEventListener('input', function () {
			if (!selected) { return; }
			snap();
			var s = byId(selected);
			if (s) { s.fill = fillInp.value; markDirty(); paint(); }
		});
	}
	if (strokeInp) {
		strokeInp.addEventListener('input', function () {
			if (!selected) { return; }
			snap();
			var s = byId(selected);
			if (s) { s.stroke = strokeInp.value; markDirty(); paint(); }
		});
	}

	if (delShapeBtn) {
		// Второе нажатие (data-n2j-arm, app.js): первое взводит кнопку и сюда не доходит.
		delShapeBtn.addEventListener('click', function () {
			if (!selected) { return; }
			snap();
			scene.shapes = shapes().filter(function (s) { return s.id !== selected; });
			selected = '';
			markDirty();
			paint();
		});
	}

	if (undoBtn) {
		undoBtn.addEventListener('click', function () {
			if (!undo.length) { return; }
			redo.push(clone(scene));
			scene = undo.pop();
			markDirty();
			undoBtn.disabled = !undo.length;
			if (redoBtn) { redoBtn.disabled = !redo.length; }
			paint();
		});
	}
	if (redoBtn) {
		redoBtn.addEventListener('click', function () {
			if (!redo.length) { return; }
			undo.push(clone(scene));
			scene = redo.pop();
			markDirty();
			if (undoBtn) { undoBtn.disabled = !undo.length; }
			redoBtn.disabled = !redo.length;
			paint();
		});
	}

	function post(url, body) {
		body = body || {};
		body.csrf = cfg.csrf;
		body.code = cfg.code;
		return fetch(url, {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify(body)
		}).then(function (res) { return res.json(); });
	}

	function save() {
		return post('/draw2d/api/save', {scene: scene}).then(function (data) {
			if (data && data.ok) {
				dirty = false;
				if (saveBtn) { saveBtn.disabled = true; }
				if (data.scene) { scene = data.scene; paint(); }
			}
			return data;
		});
	}

	if (saveBtn) {
		saveBtn.addEventListener('click', function () { save(); });
	}

	document.getElementById('nj668-full-btn').addEventListener('click', function () {
		if (!document.fullscreenElement) {
			(canvas.requestFullscreen || canvas.webkitRequestFullscreen).call(canvas);
		} else {
			(document.exitFullscreen || document.webkitExitFullscreen).call(document);
		}
	});

	function showWorking(on) {
		if (!working) { return; }
		working.classList.toggle('hidden', !on);
		if (on) { hideFail(); startElapsed(); } else { stopElapsed(); }
	}

	function hideFail() {
		if (failBox) { failBox.classList.add('hidden'); }
	}

	function showFail(text) {
		showWorking(false);
		if (failText) { failText.textContent = text || 'Модель не нарисовала сцену.'; }
		if (failBox) { failBox.classList.remove('hidden'); }
	}

	var failClose = document.getElementById('nj668-fail-close');
	if (failClose) {
		failClose.addEventListener('click', hideFail);
	}

	function startElapsed() {
		elapsedAt = Date.now();
		var el = document.getElementById('nj668-elapsed');
		function tick() {
			var s = Math.floor((Date.now() - elapsedAt) / 1000);
			var m = Math.floor(s / 60);
			if (el) { el.textContent = m + ':' + String(s % 60).padStart(2, '0'); }
		}
		tick();
		if (elapsedTimer) { clearInterval(elapsedTimer); }
		elapsedTimer = setInterval(tick, 1000);
	}

	function stopElapsed() {
		if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = 0; }
	}

	function jobFailed(job) {
		if (!job) { return false; }
		var st = String(job.status || job.state || '').toLowerCase();
		if (['failed', 'fail', 'error', 'cancelled', 'canceled'].indexOf(st) >= 0) { return true; }
		return String(job.error || '').trim() !== '' && ['running', 'active', 'working'].indexOf(st) < 0;
	}

	function failJobText(job) {
		var t = String((job && (job.error || job.message)) || '').trim();
		return t || 'Модель не нарисовала сцену.';
	}

	/**
	 * Сборка ещё идёт. status=none / found=false / job_id=0 — хвост мёртвой
	 * задачи: не долбить /api/status каждые 1.5 с (владелец, 11.10.2026).
	 */
	function jobIsLive(job, jobId) {
		var id = Number(jobId || 0);
		if (id <= 0 || !job) { return false; }
		if (Object.prototype.hasOwnProperty.call(job, 'found') && !job.found) { return false; }
		var st = String(job.status || job.state || '').toLowerCase();
		if (['failed', 'fail', 'error', 'cancelled', 'canceled', 'done', 'none'].indexOf(st) >= 0) {
			return false;
		}
		var err = String(job.error || '').trim();
		if (err && ['running', 'active', 'working'].indexOf(st) < 0) { return false; }
		return ['queued', 'running', 'pending', 'processing', 'active', 'working'].indexOf(st) >= 0;
	}

	function stopPoll() {
		if (pollTimer) { clearInterval(pollTimer); pollTimer = 0; }
	}

	function startPoll() {
		stopPoll();
		pollTimer = setInterval(poll, 1500);
		poll();
	}

	function poll() {
		fetch('/draw2d/api/status?code=' + encodeURIComponent(cfg.code))
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (!data || !data.ok) {
					stopPoll();
					cfg.jobId = 0;
					showWorking(false);
					return;
				}
				var job = data.job || {};
				var liveId = Number(data.job_id != null ? data.job_id : cfg.jobId);
				if (jobFailed(job) || (data.error && !liveId)) {
					stopPoll();
					showFail(data.error || failJobText(job));
					cfg.jobId = 0;
					return;
				}
				if (!jobIsLive(job, liveId)) {
					stopPoll();
					cfg.jobId = 0;
					if (data.scene) { scene = data.scene; paint(); }
					showWorking(false);
				}
			})
			.catch(function () {});
	}

	document.getElementById('nj668-generate-btn').addEventListener('click', function () {
		if (!cfg.canStart) { return; }
		var prompt = promptEl ? promptEl.value.trim() : '';
		if (!prompt) { return; }
		showWorking(true);
		post('/draw2d/api/generate', {prompt: prompt}).then(function (data) {
			if (!data || !data.ok) {
				showFail((data && data.error) || 'Очередь не приняла задачу.');
				return;
			}
			cfg.jobId = data.job_id || 0;
			if (!cfg.jobId) { showWorking(false); return; }
			startPoll();
		}).catch(function () {
			showFail('Сеть не ответила.');
		});
	});

	Array.prototype.forEach.call(document.querySelectorAll('[data-nj668-preset]'), function (btn) {
		btn.addEventListener('click', function () {
			if (promptEl) { promptEl.value = btn.getAttribute('data-nj668-preset') || ''; promptEl.focus(); }
		});
	});

	document.getElementById('nj668-publish-btn').addEventListener('click', function () {
		var btn = this;
		var go = function () {
			post('/draw2d/api/publish', {}).then(function (data) {
				if (data && data.ok) {
					btn.textContent = 'В каталоге';
					btn.disabled = true;
				} else if (data && data.error) {
					showFail(data.error);
				}
			});
		};
		if (dirty) { save().then(go); } else { go(); }
	});

	document.getElementById('nj668-export-json-btn').addEventListener('click', function () {
		var blob = new Blob([JSON.stringify(scene, null, 2)], {type: 'application/json'});
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = cfg.code + '.json';
		a.click();
	});

	document.getElementById('nj668-export-svg-btn').addEventListener('click', function () {
		var svg = stage.querySelector('svg');
		if (!svg) { return; }
		var blob = new Blob([svg.outerHTML], {type: 'image/svg+xml'});
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = cfg.code + '.svg';
		a.click();
	});

	var delBtn = document.getElementById('nj668-delete-btn');
	// Удаление иллюстрации и ревизий — вторым нажатием (data-n2j-arm, app.js).
	delBtn.addEventListener('click', function () {
		post('/draw2d/api/delete', {}).then(function (data) {
			if (data && data.ok) { location.href = '/draw2d/projects'; }
		});
	});

	paint();
	cfg.jobIsLive = jobIsLive;
	if (cfg.jobId) {
		showWorking(true);
		startPoll();
	}
}());
</script>
