/**
 * Витрина графа на главной (карточка nj667): интерактивный образец «клон с палочками».
 *
 * Без importmap и без OrbitControls: three.module.js самодостаточен, поэтому грузим
 * его динамическим import(). Вращение — перетаскиванием; авто-вращение, пока карточка
 * в экране и пользователь не трогает её. Если WebGL нет — остаётся текст-заглушка.
 */
(function () {
	'use strict';

	var THREE_URL = '/assets/vendor/three/mod/three.module.js';
	var roots = document.querySelectorAll('[data-nj667-home3d]');

	if (!roots.length) {
		return;
	}

	function clamp(v, lo, hi) {
		return Math.max(lo, Math.min(hi, v));
	}

	function boot(root, THREE) {
		var stage = root.querySelector('[data-home3d-stage]');
		var note = root.querySelector('[data-home3d-note]');
		var graph = JSON.parse(root.getAttribute('data-graph') || '{}');
		var nodes = graph.nodes || [];
		var edges = graph.edges || [];
		var byId = {};
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var renderer;

		nodes.forEach(function (n) { byId[n.id] = n; });

		try {
			renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
		} catch (e) {
			if (note) { note.textContent = '3D недоступно в этом браузере — откройте конструктор.'; }
			return;
		}

		renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
		renderer.domElement.style.width = '100%';
		renderer.domElement.style.height = '100%';
		renderer.domElement.style.display = 'block';
		stage.appendChild(renderer.domElement);

		var scene = new THREE.Scene();
		var camera = new THREE.PerspectiveCamera(35, 1, 0.1, 1000);
		scene.add(new THREE.AmbientLight(0xffffff, 0.75));

		var sun = new THREE.DirectionalLight(0xffffff, 0.9);
		sun.position.set(2, 3, 4);
		scene.add(sun);

		// Центр и радиус образца: камеру ставим так, чтобы граф целиком влез в кадр.
		var center = new THREE.Vector3();
		nodes.forEach(function (n) { center.x += n.x; center.y += n.y; center.z += n.z; });
		center.multiplyScalar(1 / Math.max(1, nodes.length));

		var radius = 1;
		nodes.forEach(function (n) {
			radius = Math.max(radius, Math.hypot(n.x - center.x, n.y - center.y, n.z - center.z));
		});

		var group = new THREE.Group();
		scene.add(group);

		var nodeGeo = new THREE.SphereGeometry(radius * 0.07, 24, 16);
		var stickGeo = new THREE.CylinderGeometry(radius * 0.025, radius * 0.025, 1, 12);
		var colors = { connector: 0x4f46e5, endpoint: 0x0ea5e9 };
		var stickMat = new THREE.MeshStandardMaterial({ color: 0xf59e0b, roughness: 0.4, metalness: 0.1 });
		var up = new THREE.Vector3(0, 1, 0);

		nodes.forEach(function (n) {
			var mat = new THREE.MeshStandardMaterial({
				color: colors[n.type] || 0x64748b, roughness: 0.4, metalness: 0.1
			});
			var mesh = new THREE.Mesh(nodeGeo, mat);
			mesh.position.set(n.x - center.x, n.y - center.y, n.z - center.z);
			group.add(mesh);
		});

		edges.forEach(function (e) {
			var a = byId[e.source];
			var b = byId[e.target];

			if (!a || !b) {
				return;
			}

			var pa = new THREE.Vector3(a.x - center.x, a.y - center.y, a.z - center.z);
			var pb = new THREE.Vector3(b.x - center.x, b.y - center.y, b.z - center.z);
			var dir = new THREE.Vector3().subVectors(pb, pa);
			var len = dir.length();

			if (len < 1e-6) {
				return;
			}

			var stick = new THREE.Mesh(stickGeo, stickMat);
			stick.position.copy(pa).add(pb).multiplyScalar(0.5);
			stick.quaternion.setFromUnitVectors(up, dir.normalize());
			stick.scale.set(1, len, 1);
			group.add(stick);
		});

		camera.position.set(0, 0, radius * 3.6);
		camera.lookAt(0, 0, 0);

		var rot = { x: -0.35, y: 0.6 };
		var drag = null;
		var visible = false;
		var raf = 0;

		function resize() {
			var w = stage.clientWidth || 300;
			var h = stage.clientHeight || 192;
			renderer.setSize(w, h, false);
			camera.aspect = w / h;
			camera.updateProjectionMatrix();
		}

		function frame() {
			raf = 0;

			if (!visible) {
				return;
			}

			if (!drag && !reduce) {
				rot.y += 0.004;
			}

			group.rotation.set(rot.x, rot.y, 0);
			renderer.render(scene, camera);
			raf = window.requestAnimationFrame(frame);
		}

		stage.style.touchAction = 'none';
		stage.style.cursor = 'grab';

		stage.addEventListener('pointerdown', function (ev) {
			drag = { x: ev.clientX, y: ev.clientY };
			stage.style.cursor = 'grabbing';
			stage.setPointerCapture(ev.pointerId);
		});

		stage.addEventListener('pointermove', function (ev) {
			if (!drag) {
				return;
			}

			rot.y += (ev.clientX - drag.x) * 0.01;
			rot.x = clamp(rot.x + (ev.clientY - drag.y) * 0.01, -1.2, 1.2);
			drag = { x: ev.clientX, y: ev.clientY };
		});

		function endDrag() {
			drag = null;
			stage.style.cursor = 'grab';
		}

		stage.addEventListener('pointerup', endDrag);
		stage.addEventListener('pointercancel', endDrag);

		window.addEventListener('resize', resize);

		new IntersectionObserver(function (entries) {
			visible = entries[0].isIntersecting;

			if (visible && !raf) {
				raf = window.requestAnimationFrame(frame);
			}
		}).observe(stage);

		resize();
		renderer.render(scene, camera);

		// Плашку убираем из DOM: атрибут hidden не работает у узла с классом flex
		// (Tailwind перебивает display), и текст оставался поверх холста.
		if (note && note.parentNode) {
			note.parentNode.removeChild(note);
		}
	}

	import(THREE_URL).then(function (THREE) {
		roots.forEach(function (root) {
			try {
				boot(root, THREE);
			} catch (e) {
				var note = root.querySelector('[data-home3d-note]');
				if (note) { note.textContent = '3D-образец не загрузился — откройте конструктор.'; }
			}
		});
	}).catch(function () {
		roots.forEach(function (root) {
			var note = root.querySelector('[data-home3d-note]');
			if (note) { note.textContent = '3D-образец не загрузился — откройте конструктор.'; }
		});
	});
})();
