/* global THREE */
(function () {
    'use strict';

    const root = document.getElementById('nj655-orbit');

    if (!root) {
        return;
    }

    const elements = {
        stage: document.getElementById('orbit-stage'),
        canvas: document.getElementById('orbit-canvas'),
        loading: document.getElementById('orbit-loading'),
        tooltip: document.getElementById('orbit-tooltip'),
        groups: document.getElementById('orbit-groups'),
        search: document.getElementById('orbit-search'),
        reset: document.getElementById('orbit-reset'),
        count: document.getElementById('orbit-count'),
        panel: document.getElementById('orbit-panel'),
        close: document.getElementById('orbit-close'),
        detailKind: document.getElementById('orbit-kind'),
        detailTitle: document.getElementById('orbit-detail-title'),
        detailText: document.getElementById('orbit-detail-text'),
        detailMeta: document.getElementById('orbit-detail-meta'),
        detailApi: document.getElementById('orbit-detail-api'),
        open: document.getElementById('orbit-open'),
        fallback: document.getElementById('orbit-fallback'),
        fallbackNote: document.getElementById('orbit-fallback-note'),
        fallbackList: document.getElementById('orbit-fallback-list'),
        accessibleList: document.getElementById('orbit-accessible-list'),
    };
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const colors = ['#67e8f9', '#a5b4fc', '#f0abfc', '#86efac', '#fcd34d', '#fdba74', '#5eead4', '#93c5fd'];
    const state = {
        items: [],
        groups: [],
        group: '',
        query: '',
        visibleItems: [],
        selected: null,
        interactive: [],
        groupNodes: new Map(),
        serviceNodes: new Map(),
        renderer: null,
        scene: null,
        camera: null,
        raycaster: null,
        pointer: null,
        target: null,
        selectionRing: null,
        scanWave: null,
        scanRequest: null,
        focusRequest: null,
        yaw: 0.52,
        pitch: 0.34,
        distance: 20,
        dragging: false,
        pointerDown: false,
        pointerStart: { x: 0, y: 0 },
    };

    function asText(value) {
        return value === undefined || value === null ? '' : String(value);
    }

    function normalized(value) {
        return asText(value).toLocaleLowerCase('ru').replace(/ё/g, 'е').trim();
    }

    function safeHref(value) {
        const href = asText(value).trim();
        return href.charAt(0) === '/' && href.slice(0, 2) !== '//' ? href : '/services';
    }

    function safeApi(value) {
        return (Array.isArray(value) ? value : []).filter((endpoint) => {
            const path = asText(endpoint).trim();
            return path.charAt(0) === '/' && path.slice(0, 2) !== '//';
        });
    }

    function colorFor(index) {
        return colors[index % colors.length];
    }

    function itemMatches(item) {
        const query = normalized(state.query);
        const groupMatches = !state.group || item.gslug === state.group;

        if (!groupMatches) {
            return false;
        }

        if (!query) {
            return true;
        }

        const haystack = normalized([item.title, item.short, item.keywords, item.group].join(' '));
        return query.split(/\s+/).filter(Boolean).every((term) => haystack.indexOf(term) !== -1);
    }

    function itemKey(item) {
        return `${item.href}|${item.title}`;
    }

    function groupsFrom(payload, items) {
        const known = new Map();
        const sourceGroups = Array.isArray(payload.groups) ? payload.groups : [];

        sourceGroups.forEach((group) => {
            const slug = asText(group.slug);

            if (slug) {
                known.set(slug, {
                    slug,
                    title: asText(group.title) || slug,
                    count: Number(group.count) || 0,
                    icon: asText(group.icon),
                });
            }
        });

        items.forEach((item) => {
            const wasKnown = known.has(item.gslug);
            if (!wasKnown) {
                known.set(item.gslug, {
                    slug: item.gslug,
                    title: item.group || 'Сервисы',
                    count: 0,
                    icon: '',
                });
            }
            // Обычно count уже пришёл в groups. Если API добавило новый раздел
            // в items раньше summary, всё равно не показываем ему нулевой счётчик.
            if (!sourceGroups.length || !wasKnown) known.get(item.gslug).count += 1;
        });

        return Array.from(known.values()).filter((group) => items.some((item) => item.gslug === group.slug));
    }

    function makeElement(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function renderLinkList(target, items) {
        target.replaceChildren();

        items.forEach((item) => {
            const link = document.createElement('a');
            link.href = safeHref(item.href);
            link.textContent = item.title;
            link.title = item.group ? `${item.title} — ${item.group}` : item.title;
            target.appendChild(link);
        });
    }

    function showFallback(note, items) {
        root.classList.add('is-fallback');
        if (elements.panel) elements.panel.hidden = true;
        elements.loading.hidden = true;
        elements.fallback.hidden = false;
        elements.fallbackNote.textContent = note;
        renderLinkList(elements.fallbackList, items || state.items);
        elements.stage.setAttribute('aria-disabled', 'true');
    }

    function showInitialDetail() {
        state.selected = null;
        if (elements.panel) elements.panel.hidden = true;
        elements.detailKind.textContent = 'карта';
        elements.detailTitle.textContent = 'Начните с маяка';
        elements.detailText.textContent = 'Кликните по крупному узлу раздела или маленькой точке сервиса. Поиск подсветит подходящие маршруты.';
        elements.detailMeta.replaceChildren();
        elements.detailApi.hidden = true;
        elements.detailApi.replaceChildren();
        elements.open.hidden = true;
        updateSelectionRing();
    }

    function addMeta(text) {
        const chip = makeElement('span', '', text);
        elements.detailMeta.appendChild(chip);
    }

    function selectGroup(group) {
        if (elements.panel) elements.panel.hidden = false;
        state.group = group.slug;
        state.query = '';
        elements.search.value = '';
        filterNodes();
        state.selected = { type: 'group', group };
        elements.detailKind.textContent = 'раздел';
        elements.detailTitle.textContent = group.title;
        elements.detailText.textContent = `В этом разделе ${group.count} ${plural(group.count, 'сервис', 'сервиса', 'сервисов')}. Выберите точку на карте или используйте стрелки клавиатуры.`;
        elements.detailMeta.replaceChildren();
        addMeta(`${group.count} ${plural(group.count, 'сервис', 'сервиса', 'сервисов')}`);
        elements.detailApi.hidden = true;
        elements.detailApi.replaceChildren();
        elements.open.hidden = true;
        const node = state.groupNodes.get(group.slug);
        setViewTarget(node?.mesh.position, 14);
        playScan(node?.mesh.position, node?.color);
        updateSelectionRing();
    }

    function selectService(item) {
        if (elements.panel) elements.panel.hidden = false;
        const group = state.groups.find((candidate) => candidate.slug === item.gslug);
        state.selected = { type: 'service', item };
        elements.detailKind.textContent = 'сервис';
        elements.detailTitle.textContent = item.title;
        elements.detailText.textContent = item.short || `Сервис из раздела «${item.group}».`;
        elements.detailMeta.replaceChildren();
        addMeta(item.group);
        if (item.status && item.status !== 'stable') {
            addMeta(item.status === 'beta' ? 'бета' : item.status);
        }

        const api = safeApi(item.api);
        elements.detailApi.replaceChildren();
        elements.detailApi.hidden = api.length === 0;
        if (api.length) {
            const heading = makeElement('strong', '', 'Доступные API-маршруты');
            elements.detailApi.appendChild(heading);
            api.forEach((endpoint) => {
                elements.detailApi.appendChild(makeElement('code', '', endpoint));
            });
        }

        elements.open.href = safeHref(item.href);
        elements.open.hidden = false;
        const node = state.serviceNodes.get(itemKey(item));
        setViewTarget(node?.mesh.position, 10);
        playScan(node?.mesh.position, node?.color);
        updateSelectionRing();
    }

    function plural(number, one, few, many) {
        const remainder10 = number % 10;
        const remainder100 = number % 100;
        if (remainder10 === 1 && remainder100 !== 11) return one;
        if (remainder10 >= 2 && remainder10 <= 4 && (remainder100 < 10 || remainder100 >= 20)) return few;
        return many;
    }

    function renderGroups() {
        elements.groups.replaceChildren();
        const all = makeGroupButton({ slug: '', title: 'Все сервисы', count: state.items.length }, '#67e8f9');
        elements.groups.appendChild(all);
        state.groups.forEach((group, index) => elements.groups.appendChild(makeGroupButton(group, colorFor(index))));
    }

    function makeGroupButton(group, color) {
        const button = makeElement('button', 'nj655-orbit__group');
        button.type = 'button';
        button.style.setProperty('--nj655-group', color);
        button.dataset.group = group.slug;
        button.textContent = `${group.title} · ${group.count}`;
        button.addEventListener('click', () => {
            elements.stage.focus({ preventScroll: true });
            if (!group.slug) {
                resetView();
                return;
            }
            selectGroup(group);
        });
        return button;
    }

    function updateGroupButtons() {
        elements.groups.querySelectorAll('.nj655-orbit__group').forEach((button) => {
            const active = button.dataset.group === state.group;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function filterNodes() {
        state.visibleItems = state.items.filter(itemMatches);
        const visibleKeys = new Set(state.visibleItems.map(itemKey));

        state.serviceNodes.forEach((node, key) => {
            const visible = visibleKeys.has(key);
            node.mesh.visible = visible || !state.query && !state.group;
            node.line.visible = node.mesh.visible;
            node.halo.visible = node.mesh.visible;
            node.mesh.material.opacity = visible ? 0.98 : 0.10;
            node.mesh.scale.set(visible ? node.scale : node.scale * .45, visible ? node.scale : node.scale * .45, 1);
        });

        state.groupNodes.forEach((node, slug) => {
            const relevant = !state.group || state.group === slug;
            node.mesh.material.opacity = relevant ? 0.96 : 0.25;
            node.mesh.scale.setScalar(relevant ? 1 : 0.82);
        });

        elements.count.textContent = state.visibleItems.length === state.items.length
            ? `${state.items.length} ${plural(state.items.length, 'сервис', 'сервиса', 'сервисов')} на карте`
            : `${state.visibleItems.length} из ${state.items.length} ${plural(state.visibleItems.length, 'сервис', 'сервиса', 'сервисов')}`;
        updateGroupButtons();
        updateSelectionRing();
        render();
    }

    function createScene() {
        if (!window.THREE) {
            throw new Error('Three.js не загрузился');
        }

        state.scene = new THREE.Scene();
        state.camera = new THREE.PerspectiveCamera(46, 1, 0.1, 100);
        state.target = new THREE.Vector3(0, 0, 0);
        state.raycaster = new THREE.Raycaster();
        state.pointer = new THREE.Vector2();
        state.renderer = new THREE.WebGLRenderer({ canvas: elements.canvas, alpha: true, antialias: true, powerPreference: 'high-performance' });
        state.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        state.renderer.setClearColor(0x000000, 0);

        state.scene.add(new THREE.HemisphereLight(0xbde8ff, 0x09111f, 1.8));
        const pointLight = new THREE.PointLight(0x67e8f9, 1.6, 34);
        pointLight.position.set(0, 3, 7);
        state.scene.add(pointLight);

        addStarField();
        addCenterpiece();
        buildNodes();

        state.selectionRing = new THREE.Mesh(
            new THREE.TorusGeometry(0.72, 0.045, 8, 30),
            new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.92 })
        );
        state.selectionRing.visible = false;
        state.scene.add(state.selectionRing);

        state.scanWave = new THREE.Mesh(
            new THREE.RingGeometry(.18, .25, 48),
            new THREE.MeshBasicMaterial({ color: 0x67e8f9, transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false })
        );
        state.scanWave.visible = false;
        state.scene.add(state.scanWave);

        updateCamera();
        resize();
        bindSceneEvents();
        if (window.ResizeObserver) {
            new ResizeObserver(resize).observe(elements.stage);
        } else {
            window.addEventListener('resize', resize);
        }
    }

    function addStarField() {
        const positions = [];
        for (let index = 0; index < 260; index += 1) {
            const seed = index * 17.71;
            positions.push(Math.sin(seed) * 18, Math.cos(seed * 1.7) * 11, Math.sin(seed * .47) * 8 - 4);
        }
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
        const stars = new THREE.Points(geometry, new THREE.PointsMaterial({ color: 0xbfe9ff, size: 0.045, transparent: true, opacity: 0.65, sizeAttenuation: true }));
        state.scene.add(stars);
    }

    function addCenterpiece() {
        const core = new THREE.Mesh(
            new THREE.SphereGeometry(0.55, 28, 18),
            new THREE.MeshPhongMaterial({ color: 0x164e63, emissive: 0x0e7490, emissiveIntensity: 0.7, transparent: true, opacity: 0.92 })
        );
        core.userData.orbitNode = { type: 'signal' };
        state.scene.add(core);
        state.interactive.push(core);

        const caption = new THREE.Sprite(new THREE.SpriteMaterial({
            map: textTexture(['N2J ORBIT', 'ядро ищет следующий сигнал'], '#67e8f9'),
            transparent: true,
            depthWrite: false,
        }));
        caption.position.set(0, -1.15, 0);
        caption.scale.set(3.5, .76, 1);
        caption.userData.orbitNode = { type: 'signal' };
        state.scene.add(caption);
        state.interactive.push(caption);

        [1.15, 1.55].forEach((radius, index) => {
            const ring = new THREE.Mesh(
                new THREE.TorusGeometry(radius, 0.018, 6, 64),
                new THREE.MeshBasicMaterial({ color: index ? 0x818cf8 : 0x67e8f9, transparent: true, opacity: 0.38 })
            );
            ring.rotation.x = index ? 1.06 : 0.52;
            ring.rotation.y = index ? .22 : -.42;
            state.scene.add(ring);
        });
    }

    function textTexture(lines, color) {
        const canvas = document.createElement('canvas');
        canvas.width = 640;
        canvas.height = 140;
        const context = canvas.getContext('2d');
        const rows = Array.isArray(lines) ? lines : [lines];
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.fillStyle = 'rgba(2, 6, 23, .72)';
        context.fillRect(8, 8, canvas.width - 16, canvas.height - 16);
        context.strokeStyle = color;
        context.lineWidth = 3;
        context.strokeRect(8, 8, canvas.width - 16, canvas.height - 16);
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        rows.forEach((row, index) => {
            context.fillStyle = index === 0 ? '#f0fdfa' : '#a5f3fc';
            context.font = index === 0 ? '800 35px system-ui, sans-serif' : '600 21px system-ui, sans-serif';
            context.fillText(asText(row), canvas.width / 2, rows.length === 1 ? 70 : 49 + index * 42);
        });
        const texture = new THREE.CanvasTexture(canvas);
        texture.minFilter = THREE.LinearFilter;
        return texture;
    }

    function glyphTexture(title, color) {
        const canvas = document.createElement('canvas');
        canvas.width = 128;
        canvas.height = 128;
        const context = canvas.getContext('2d');
        const letters = asText(title).split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word.charAt(0)).join('').toUpperCase() || 'N2';

        context.clearRect(0, 0, 128, 128);
        context.save();
        context.translate(64, 64);
        context.beginPath();
        for (let point = 0; point < 6; point += 1) {
            const angle = Math.PI / 3 * point - Math.PI / 2;
            const x = Math.cos(angle) * 48;
            const y = Math.sin(angle) * 48;
            if (point) context.lineTo(x, y); else context.moveTo(x, y);
        }
        context.closePath();
        context.fillStyle = '#07111f';
        context.fill();
        context.lineWidth = 5;
        context.strokeStyle = color;
        context.shadowBlur = 18;
        context.shadowColor = color;
        context.stroke();
        context.shadowBlur = 0;
        context.fillStyle = '#e0f2fe';
        context.font = '800 31px system-ui, sans-serif';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText(letters, 0, 3);
        context.restore();

        const texture = new THREE.CanvasTexture(canvas);
        texture.minFilter = THREE.LinearFilter;
        return texture;
    }

    function buildNodes() {
        const byGroup = new Map();
        state.items.forEach((item) => {
            if (!byGroup.has(item.gslug)) byGroup.set(item.gslug, []);
            byGroup.get(item.gslug).push(item);
        });

        const activeGroups = state.groups.filter((group) => byGroup.has(group.slug));
        const orbitRadius = Math.max(7.1, activeGroups.length * .58);
        activeGroups.forEach((group, groupIndex) => {
            const angle = (Math.PI * 2 * groupIndex / activeGroups.length) - Math.PI / 2;
            const vertical = groupIndex % 2 ? .65 : -.65;
            const center = new THREE.Vector3(Math.cos(angle) * orbitRadius, vertical + Math.sin(angle * 2.3) * 1.25, Math.sin(angle) * orbitRadius * .37);
            const color = colorFor(groupIndex);
            const groupMesh = new THREE.Mesh(
                new THREE.OctahedronGeometry(.52, 0),
                new THREE.MeshPhongMaterial({ color, emissive: color, emissiveIntensity: .42, transparent: true, opacity: .96, shininess: 95 })
            );
            groupMesh.position.copy(center);
            groupMesh.userData.orbitNode = { type: 'group', group };
            state.scene.add(groupMesh);
            state.interactive.push(groupMesh);
            state.groupNodes.set(group.slug, { mesh: groupMesh, group, color });

            const label = new THREE.Sprite(new THREE.SpriteMaterial({
                map: textTexture(group.title, color),
                transparent: true,
                depthWrite: false,
            }));
            label.position.copy(center);
            label.position.y += .85;
            label.scale.set(2.35, .5, 1);
            label.userData.orbitNode = { type: 'group', group };
            state.scene.add(label);
            state.interactive.push(label);

            const beaconRing = new THREE.Mesh(
                new THREE.TorusGeometry(.7, .025, 6, 40),
                new THREE.MeshBasicMaterial({ color, transparent: true, opacity: .42 })
            );
            beaconRing.position.copy(center);
            beaconRing.rotation.x = Math.PI / 2.3;
            beaconRing.rotation.y = groupIndex * .5;
            state.scene.add(beaconRing);

            const connection = new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(0, 0, 0), center]);
            state.scene.add(new THREE.Line(connection, new THREE.LineBasicMaterial({ color, transparent: true, opacity: .18 })));

            const items = byGroup.get(group.slug);
            const localRadius = 1.3 + Math.sqrt(items.length) * .34;
            items.forEach((item, itemIndex) => {
                const itemAngle = (Math.PI * 2 * itemIndex / items.length) + groupIndex * .63;
                const elevation = ((itemIndex % 3) - 1) * .38 + Math.sin(itemIndex * 1.9) * .12;
                const position = new THREE.Vector3(
                    center.x + Math.cos(itemAngle) * localRadius,
                    center.y + elevation,
                    center.z + Math.sin(itemAngle) * localRadius * .58
                );
                // Сервис — не безликий шарик, а шестигранный «маяк» с
                // инициалами. Sprite всегда повёрнут к камере и читается даже
                // на плотной карте; клик всё равно ловит его реальный 3D-узел.
                const mesh = new THREE.Sprite(new THREE.SpriteMaterial({
                    map: glyphTexture(item.title, color),
                    transparent: true,
                    opacity: .98,
                    depthWrite: false,
                }));
                mesh.position.copy(position);
                mesh.scale.set(.58, .58, 1);
                mesh.userData.orbitNode = { type: 'service', item };
                state.scene.add(mesh);
                state.interactive.push(mesh);

                const halo = new THREE.Mesh(
                    new THREE.TorusGeometry(.25, .015, 5, 24),
                    new THREE.MeshBasicMaterial({ color, transparent: true, opacity: .27 })
                );
                halo.position.copy(position);
                halo.rotation.x = Math.PI / 2;
                state.scene.add(halo);

                const lineGeometry = new THREE.BufferGeometry().setFromPoints([center, position]);
                const line = new THREE.Line(lineGeometry, new THREE.LineBasicMaterial({ color, transparent: true, opacity: .32 }));
                state.scene.add(line);
                state.serviceNodes.set(itemKey(item), { mesh, line, halo, item, group, color, scale: .58 });
            });
        });
    }

    function updateCamera() {
        if (!state.camera) return;
        const horizontal = Math.cos(state.pitch) * state.distance;
        state.camera.position.set(
            state.target.x + Math.sin(state.yaw) * horizontal,
            state.target.y + Math.sin(state.pitch) * state.distance,
            state.target.z + Math.cos(state.yaw) * horizontal
        );
        state.camera.lookAt(state.target);
    }

    function setViewTarget(position, distance) {
        if (!position || !state.target) return;
        const nextDistance = distance || state.distance;

        if (state.focusRequest) window.cancelAnimationFrame(state.focusRequest);
        if (reducedMotion) {
            state.target.copy(position);
            state.distance = nextDistance;
            updateCamera();
            updateSelectionRing();
            render();
            return;
        }

        const fromTarget = state.target.clone();
        const toTarget = position.clone();
        const fromDistance = state.distance;
        const started = window.performance.now();
        const tick = (now) => {
            const progress = Math.min(1, (now - started) / 420);
            const eased = 1 - Math.pow(1 - progress, 3);
            state.target.copy(fromTarget).lerp(toTarget, eased);
            state.distance = fromDistance + (nextDistance - fromDistance) * eased;
            updateCamera();
            updateSelectionRing();
            render();
            if (progress < 1) {
                state.focusRequest = window.requestAnimationFrame(tick);
            } else {
                state.focusRequest = null;
            }
        };
        state.focusRequest = window.requestAnimationFrame(tick);
    }

    function playScan(position, color) {
        if (!position || !state.scanWave || reducedMotion) return;
        if (state.scanRequest) window.cancelAnimationFrame(state.scanRequest);
        const started = window.performance.now();
        state.scanWave.position.copy(position);
        state.scanWave.material.color.set(color || '#67e8f9');
        state.scanWave.visible = true;
        const tick = (now) => {
            const progress = Math.min(1, (now - started) / 780);
            const ease = 1 - Math.pow(1 - progress, 2);
            state.scanWave.scale.setScalar(.4 + ease * 5.2);
            state.scanWave.material.opacity = (1 - ease) * .72;
            state.scanWave.lookAt(state.camera.position);
            render();
            if (progress < 1) {
                state.scanRequest = window.requestAnimationFrame(tick);
            } else {
                state.scanWave.visible = false;
                state.scanRequest = null;
                render();
            }
        };
        state.scanRequest = window.requestAnimationFrame(tick);
    }

    function updateSelectionRing() {
        if (!state.selectionRing) return;
        let mesh = null;
        if (state.selected?.type === 'service') mesh = state.serviceNodes.get(itemKey(state.selected.item))?.mesh;
        if (state.selected?.type === 'group') mesh = state.groupNodes.get(state.selected.group.slug)?.mesh;
        state.selectionRing.visible = Boolean(mesh);
        if (mesh) {
            state.selectionRing.position.copy(mesh.position);
            state.selectionRing.lookAt(state.camera.position);
        }
    }

    function resize() {
        if (!state.renderer) return;
        const width = Math.max(1, elements.stage.clientWidth);
        const height = Math.max(1, elements.stage.clientHeight);
        state.camera.aspect = width / height;
        state.camera.updateProjectionMatrix();
        state.renderer.setSize(width, height, false);
        updateSelectionRing();
        render();
    }

    function render() {
        if (!state.renderer || !state.scene || !state.camera) return;
        state.renderer.render(state.scene, state.camera);
    }

    function eventPointer(event) {
        const rect = elements.stage.getBoundingClientRect();
        state.pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        state.pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
        return rect;
    }

    function pickedNode(event) {
        eventPointer(event);
        state.raycaster.setFromCamera(state.pointer, state.camera);
        const hit = state.raycaster.intersectObjects(state.interactive, false)[0];
        return hit?.object?.userData?.orbitNode || null;
    }

    function hideTooltip() {
        elements.tooltip.classList.remove('is-visible');
        elements.tooltip.setAttribute('aria-hidden', 'true');
    }

    function showTooltip(event, node) {
        const rect = eventPointer(event);
        const label = node.type === 'group'
            ? node.group.title
            : node.type === 'signal' ? 'Найти случайный сервис' : node.item.title;
        elements.tooltip.textContent = label;
        elements.tooltip.style.left = `${Math.max(8, Math.min(rect.width - 145, event.clientX - rect.left + 12))}px`;
        elements.tooltip.style.top = `${Math.max(8, event.clientY - rect.top + 12)}px`;
        elements.tooltip.classList.add('is-visible');
        elements.tooltip.setAttribute('aria-hidden', 'false');
    }

    function activateNode(node) {
        if (!node) return;
        if (node.type === 'group') {
            selectGroup(node.group);
        } else if (node.type === 'signal') {
            catchSignal();
        } else {
            selectService(node.item);
        }
    }

    function bindSceneEvents() {
        elements.stage.addEventListener('pointerdown', (event) => {
            elements.stage.focus({ preventScroll: true });
            if (state.focusRequest) {
                window.cancelAnimationFrame(state.focusRequest);
                state.focusRequest = null;
            }
            state.pointerDown = true;
            state.dragging = false;
            state.pointerStart = { x: event.clientX, y: event.clientY };
            elements.stage.setPointerCapture?.(event.pointerId);
            hideTooltip();
        });
        elements.stage.addEventListener('pointermove', (event) => {
            if (state.pointerDown) {
                const deltaX = event.clientX - state.pointerStart.x;
                const deltaY = event.clientY - state.pointerStart.y;
                if (Math.abs(deltaX) + Math.abs(deltaY) > 4) state.dragging = true;
                if (state.dragging) {
                    state.yaw -= deltaX * .008;
                    state.pitch = Math.max(-.62, Math.min(.82, state.pitch + deltaY * .006));
                    state.pointerStart = { x: event.clientX, y: event.clientY };
                    updateCamera();
                    updateSelectionRing();
                    render();
                }
                return;
            }
            const node = pickedNode(event);
            if (node) {
                elements.stage.style.cursor = 'pointer';
                showTooltip(event, node);
            } else {
                elements.stage.style.cursor = 'grab';
                hideTooltip();
            }
        });
        elements.stage.addEventListener('pointerup', (event) => {
            if (state.pointerDown && !state.dragging) activateNode(pickedNode(event));
            state.pointerDown = false;
            state.dragging = false;
        });
        elements.stage.addEventListener('pointerleave', () => {
            if (!state.pointerDown) hideTooltip();
        });
        elements.stage.addEventListener('wheel', (event) => {
            event.preventDefault();
            state.distance = Math.max(8, Math.min(31, state.distance + event.deltaY * .016));
            updateCamera();
            updateSelectionRing();
            render();
        }, { passive: false });
        elements.stage.addEventListener('keydown', (event) => {
            if (event.key === '/' || event.key.toLowerCase() === 'q') {
                event.preventDefault();
                elements.search.focus({ preventScroll: true });
            } else if (event.key.toLowerCase() === 'r') {
                event.preventDefault();
                resetView();
            } else if (event.key.toLowerCase() === 's') {
                event.preventDefault();
                catchSignal();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
                event.preventDefault();
                moveSelection(1);
            } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
                event.preventDefault();
                moveSelection(-1);
            } else if (event.key === 'Enter' && state.selected?.type === 'service') {
                event.preventDefault();
                window.location.assign(safeHref(state.selected.item.href));
            } else if (event.key === 'Escape') {
                event.preventDefault();
                resetView();
            }
        });
    }

    function moveSelection(direction) {
        const choices = state.visibleItems;
        if (!choices.length) return;
        const selectedIndex = state.selected?.type === 'service'
            ? choices.findIndex((item) => itemKey(item) === itemKey(state.selected.item))
            : -1;
        const nextIndex = (selectedIndex + direction + choices.length) % choices.length;
        selectService(choices[nextIndex]);
    }

    function resetView() {
        state.group = '';
        state.query = '';
        elements.search.value = '';
        if (state.focusRequest) window.cancelAnimationFrame(state.focusRequest);
        if (state.target) state.target.set(0, 0, 0);
        state.distance = 20;
        state.yaw = .52;
        state.pitch = .34;
        updateCamera();
        showInitialDetail();
        filterNodes();
        playScan(state.target, '#67e8f9');
    }

    function catchSignal() {
        const candidates = state.visibleItems.length ? state.visibleItems : state.items;
        if (!candidates.length) return;
        const item = candidates[Math.floor(Math.random() * candidates.length)];
        selectService(item);
        elements.stage.focus({ preventScroll: true });
    }

    function bindControls() {
        elements.close?.addEventListener('click', () => {
            showInitialDetail();
            elements.stage.focus({ preventScroll: true });
        });
        elements.search.addEventListener('input', () => {
            state.query = elements.search.value;
                if (state.selected?.type === 'service' && !itemMatches(state.selected.item)) showInitialDetail();
            filterNodes();
        });
        elements.search.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                elements.search.value = '';
                state.query = '';
                filterNodes();
                elements.stage.focus({ preventScroll: true });
            }
        });
        elements.reset.addEventListener('click', () => {
            resetView();
            elements.stage.focus({ preventScroll: true });
        });
    }

    function normalizeItems(payload) {
        return (Array.isArray(payload.items) ? payload.items : []).map((item) => ({
            title: asText(item.title),
            short: asText(item.short),
            href: safeHref(item.href),
            icon: asText(item.icon),
            group: asText(item.group) || 'Сервисы',
            gslug: asText(item.gslug) || 'services',
            keywords: asText(item.keywords),
            status: asText(item.status),
            api: safeApi(item.api),
        })).filter((item) => item.title && item.href !== '/services');
    }

    function loadCatalog() {
        // Единственный источник данных: nj110_catalog уже применил правила
        // видимости для текущей аудитории. Список сервисов здесь не дублируем.
        return fetch(root.dataset.catalogUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        }).then((response) => {
            if (!response.ok) throw new Error(`Каталог вернул ${response.status}`);
            return response.json();
        });
    }

    bindControls();
    loadCatalog().then((payload) => {
        state.items = normalizeItems(payload);
        state.groups = groupsFrom(payload, state.items);
        renderGroups();
        renderLinkList(elements.accessibleList, state.items);

        if (!state.items.length) {
            showFallback('В каталоге сейчас нет доступных сервисов. Откройте обычный каталог, чтобы попробовать позже.', []);
            elements.count.textContent = 'Нет доступных сервисов';
            return;
        }

        try {
            createScene();
            elements.loading.hidden = true;
            filterNodes();
            // Короткий стартовый скан создаёт ощущение входа в пространство,
            // но не двигает камеру и отключается при reduced-motion.
            playScan(state.target, '#67e8f9');
            // В reduced-motion карта остаётся статичной и рендерится только по
            // действию пользователя. В обычном режиме камера также не движется
            // сама: исследование полностью под контролем посетителя.
            if (reducedMotion) render();
        } catch (error) {
            showFallback('3D-режим недоступен в этом браузере. Ниже — те же сервисы обычными ссылками.', state.items);
            elements.count.textContent = `${state.items.length} ${plural(state.items.length, 'сервис', 'сервиса', 'сервисов')} в списке`;
        }
    }).catch(() => {
        showFallback('Не удалось получить актуальный каталог. Перейдите в обычный каталог и повторите попытку позже.', []);
        elements.count.textContent = 'Каталог временно недоступен';
    });
}());
