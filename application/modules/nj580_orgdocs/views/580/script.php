<script>
/* nj580 «Документы организации»:
   1) парсер «сырых данных» о компании → поля формы (страница /docs);
   2) баннер прогресса (колёсико + секундомер рядом) и ЖИВОЕ обновление
      страницы по /docs/api/status — AJAX, без перезагрузок;
   3) защита форм от двойной отправки. */
(function () {

    'use strict';

    /* ---------- 1. Сырые данные → поля формы ---------- */
    (function () {
        var btn = document.getElementById('docs-raw-parse');
        var raw = document.getElementById('docs-raw');
        if (!btn || !raw) return;

        function field(name) { return document.querySelector('#docs-create [name="' + name + '"]'); }

        function grab(lines, re) {
            for (var i = 0; i < lines.length; i++) {
                var m = lines[i].match(re);
                if (m && m[1]) return m[1].trim();
            }
            return '';
        }

        btn.addEventListener('click', function () {
            var text = raw.value || '';
            var lines = text.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
            if (!lines.length) { raw.focus(); return; }

            // организация: явная метка либо «ООО/ИП/АО …» в тексте
            var org = grab(lines, /^(?:организация|название|компания)\s*[:—-]\s*(.+)$/i);
            if (!org) {
                var m = text.match(/(?:ООО|ИП|АО|ЗАО|ОАО|ПАО)\s*[«"][^»"\n]{2,80}[»"]/i);
                if (m) org = m[0];
            }
            var industry = grab(lines, /^(?:сфера(?:\s+деятельности)?|деятельность|род\s+деятельности)\s*[:—-]\s*(.+)$/i);
            var size = grab(lines, /^(?:численность|размер|штат|персонал)\s*[:—-]?\s*(.+)$/i);
            if (!size) {
                var ms = text.match(/\b\d{1,4}\s*(?:чел|человек|сотрудник\w*)\b/i);
                if (ms) size = ms[0];
            }
            // структура: строка с меткой (+ следующая, если она без другой метки)
            var structure = '';
            var si = -1;
            for (var j = 0; j < lines.length; j++) {
                if (/^(?:структура|отделы|должности|персонал\s*\(отделы?\))/i.test(lines[j])) { si = j; break; }
            }
            if (si >= 0) {
                structure = lines[si].replace(/^[^:—-]*[:—-]\s*/, '');
                if (lines[si + 1] && !/^(?:сфера|деятельность|численность|размер|штат|особ|требован|организация|название|компания)/i.test(lines[si + 1])) {
                    structure += (structure ? '; ' : '') + lines[si + 1];
                }
            }
            // всё остальное, что похоже на пожелания — в «Особые требования»
            var needs = grab(lines, /^(?:особ(?:ые)?\s*(?:требования|пожелания)|примечания?)\s*[:—-]\s*(.+)$/i);
            if (!needs) {
                var leftovers = lines.filter(function (l) {
                    return !/^(?:организация|название|компания|сфера|деятельность|численность|размер|штат|структура|отделы|должности|особ|требован)\b/i.test(l)
                        && l !== org && l !== industry && l !== size && l.indexOf(structure) === -1;
                });
                if (leftovers.length === 1 && leftovers[0].length > 15) needs = leftovers[0];
            }

            var f = { org_name: org, industry: industry, size: size, structure: structure, needs: needs };
            Object.keys(f).forEach(function (k) {
                var el = field(k);
                if (el && f[k]) { el.value = f[k]; el.dispatchEvent(new Event('input', { bubbles: true })); }
            });
            if (!org) { field('org_name').focus(); }
        });
    })();

    /* ---------- 1.6. ОГРН — точка входа: сначала номер, поля после проверки ---------- */
    (function () {
        var form = document.getElementById('docs-create');
        if (!form) return;
        var ogrn = form.querySelector('[name="ogrn"]'),
            btn = document.getElementById('docs-ogrn-find'),
            res = document.getElementById('docs-ogrn-result'),
            card = document.getElementById('docs-org-card'),
            rest = document.getElementById('docs-rest'),
            nameWrap = document.getElementById('docs-name-wrap'),
            t = null;
        if (!ogrn || !btn || !res) return;

        var OK = /^((\d{10})|(\d{12})|(\d{13})|(\d{15}))$/;

        function showRest(manual) {
            if (rest) rest.classList.remove('hidden');
            if (nameWrap) {
                nameWrap.classList.toggle('hidden', !manual);
                if (manual) { var ne = nameWrap.querySelector('input'); if (ne) ne.focus(); }
            }
        }

        function render(o) {
            var lines = [o.full_name].filter(Boolean);
            if (o.inn) lines.push('ИНН ' + o.inn);
            if (o.okved) lines.push('ОКВЭД: ' + o.okved);
            if (o.director) lines.push(o.director);
            if (o.address) lines.push(o.address);
            if (card) { card.textContent = lines.join(' · '); card.classList.remove('hidden'); }
            if (res) res.textContent = 'Организация найдена — можно собирать пакет.';
            var v0 = (ogrn.value || '').replace(/\D/g, '');
            var cl = document.getElementById('docs-custom-link');
            if (cl && v0) cl.href = '/docs/custom?ogrn=' + v0;
            showRest(false);
        }

        function notFound() {
            if (card) card.classList.add('hidden');
            if (res) res.textContent = 'В реестре карточек пока нет — впишите название вручную: воркер проверит номер и подтянет официальные данные при сборке.';
            var v0 = (ogrn.value || '').replace(/\D/g, '');
            var cl = document.getElementById('docs-custom-link');
            if (cl && v0) cl.href = '/docs/custom?ogrn=' + v0;
            showRest(true);
        }

        // сброс этапа 2: пока ищем/номер меняется — форма скрыта и чиста
        // (данные могли относиться к предыдущей организации)
        function resetStage() {
            if (rest) {
                rest.classList.add('hidden');
                rest.querySelectorAll('input[type="text"], textarea').forEach(function (el) { el.value = ''; });
            }
            if (card) card.classList.add('hidden');
            if (nameWrap) {
                nameWrap.classList.add('hidden');
                var ne = nameWrap.querySelector('input');
                if (ne) ne.value = '';
            }
        }

        function find() {
            var v = ogrn.value.replace(/\D/g, '');
            if (!OK.test(v)) { res.textContent = 'ОГРН — 13 цифр, ОГРНИП — 15, ИНН — 10 или 12'; return; }
            resetStage();
            res.textContent = 'Смотрю в реестре сервиса…';
            fetch('/docs/api/org/' + v, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { (d.found && d.org) ? render(d.org) : notFound(); })
                .catch(function () {
                    res.textContent = 'Реестр недоступен — заполните поля вручную, воркер всё проверит при сборке';
                    showRest(true);
                });
        }

        btn.addEventListener('click', function () { resetStage(); find(); });
        ogrn.addEventListener('input', function () {
            clearTimeout(t);
            resetStage();
            res.textContent = 'Смотрю в реестре сервиса…';
            var v = ogrn.value.replace(/\D/g, '');
            if (OK.test(v)) t = setTimeout(find, 600);
        });
    })();

    /* ---------- 2. Статус пакета: баннер + живое обновление страницы ---------- */
    var root = document.querySelector('[data-docs-code]');

    if (root) {
        var code = root.getAttribute('data-docs-code');
        var pkgId = root.getAttribute('data-pkg-id') || '0';
        var pkgStatus = root.getAttribute('data-pkg-status') || '';

        if (/^[0-9a-z]+$/.test(code)) {
            var timer = null, delay = 4000, lastSig = '';

            /* Свой баннер статуса больше НЕ создаём: верхний ряд рисует общий
             * механизм (partials/live_status + live_status_js) — он показывает
             * этап воркера, секундомер от фактического времени и останавливает
             * застрявшую задачу. Здесь остаётся живое обновление ТАБЛИЦЫ
             * документов (прогресс по каждому файлу) — для него баннер не нужен. */

            function esc(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            var KIND_LABEL = { docx: 'Word', pdf: 'PDF', xlsx: 'Excel', zip: 'ZIP' };
            var KIND_ICON = { docx: 'fa-file-word', pdf: 'fa-file-pdf', xlsx: 'fa-file-excel', zip: 'fa-file-zipper' };
            function fmtSize(b) { return b >= 1048576 ? (Math.round(b / 1048576 * 10) / 10) + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ'; }
            function fileLink(code_, f) {
                return '<a href="/docs/' + code_ + '/file/' + f.id + '" class="rounded-xl border border-blue-200 px-2 py-0.5 text-xs font-semibold text-blue-600 hover:bg-blue-50 dark:border-blue-800">'
                    + '<i class="fas ' + (KIND_ICON[f.kind] || 'fa-file') + ' mr-1"></i>' + (KIND_LABEL[f.kind] || f.kind.toUpperCase())
                    + ' (' + fmtSize(f.size) + ')</a>';
            }

            function renderDocs(docs) {
                var list = document.getElementById('docs-list');
                if (!list || !docs) return;
                if (!docs.length) {
                    list.innerHTML = '<li class="text-xs text-gray-400">Документы появятся здесь, когда воркер начнёт сборку.</li>';
                    return;
                }
                var cnt = document.getElementById('docs-count');
                if (cnt) cnt.textContent = docs.length + ' шт.';
                list.innerHTML = docs.map(function (d) {
                    var cls = d.status === 'done' ? 'bg-emerald-50 text-emerald-600'
                        : (d.status === 'error' ? 'bg-rose-50 text-rose-500'
                            : (d.status === 'canceled' ? 'bg-gray-100 text-gray-500 dark:bg-gray-800'
                                : 'bg-blue-50 text-blue-600 animate-pulse dark:bg-blue-950/60'));
                    var label = d.status === 'done' ? 'готово' : (d.status === 'error' ? 'ошибка'
                        : (d.status === 'canceled' ? 'отменён' : (d.status === 'writing' ? 'пишется' : 'в очереди')));
                    return '<li class="rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-700">'
                        + '<div class="flex flex-wrap items-center gap-x-2 gap-y-1">'
                        + '<span class="min-w-0 flex-1 truncate text-xs font-semibold text-gray-800 dark:text-gray-200">' + esc(d.title || d.doc_key) + '</span>'
                        + '<span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase ' + cls + '">' + label + '</span>'
                        + '</div>'
                        + (d.error ? '<p class="mt-1 break-words text-xs text-rose-400">' + esc(String(d.error).slice(0, 300)) + '</p>' : '')
                        + '<div class="mt-1.5 flex flex-wrap gap-1.5">'
                        + (d.files || []).map(function (f) { return fileLink(code, f); }).join('')
                        + '</div></li>';
                }).join('');
            }

            function finishPage(data) {
                var badge = document.getElementById('pkg-badge');
                if (badge) {
                    badge.textContent = data.status === 'done' ? 'готово' : (data.status === 'canceled' ? 'отменено' : 'ошибка');
                    badge.className = 'n2j-badge uppercase '
                        + (data.status === 'done' ? 'n2j-badge-ok' : (data.status === 'canceled' ? 'n2j-badge-wait' : 'n2j-badge-err'));
                }
                renderDocs(data.documents);
                var errBox = document.getElementById('pkg-error');
                var errText = document.getElementById('pkg-error-text');
                if (errBox && errText) {
                    if (data.status === 'error' && data.error) { errText.textContent = data.error; errBox.hidden = false; }
                    else { errBox.hidden = true; }
                }
                var files = document.getElementById('pkg-files');
                if (files) {
                    files.innerHTML = (data.package_files || []).map(function (f) { return fileLink(code, f); }).join('')
                        || (data.status === 'done'
                            ? '<p class="text-xs text-gray-400">Архив ZIP не сформирован.</p>'
                            : '<p class="text-xs text-gray-400">Архив ZIP появится после сборки всех документов.</p>');
                }
                var sum = document.getElementById('pkg-summary');
                if (sum && data.summary) sum.textContent = data.summary;
                var regen = document.getElementById('pkg-regen');
                if (regen) regen.hidden = false;
                clearTimeout(timer);
            }

            function tick() {
                fetch('/docs/api/status/' + code, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data || !data.documents) {
                            delay = Math.min(delay * 1.5, 15000);
                            timer = setTimeout(tick, delay);
                            return;
                        }
                        var sig = JSON.stringify([data.status, data.progress,
                            (data.documents || []).map(function (d) { return [d.status, d.error, (d.files || []).map(function (f) { return f.id; })]; })]);
                        if (sig !== lastSig) {
                            lastSig = sig;
                            renderDocs(data.documents);
                            var errBox = document.getElementById('pkg-error');
                            var errText = document.getElementById('pkg-error-text');
                            if (errBox && errText) {
                                if (data.error) { errText.textContent = data.error; errBox.hidden = false; }
                                else { errBox.hidden = true; }
                            }
                        }
                        if (data.status === 'done' || data.status === 'error' || data.status === 'canceled') {
                            finishPage(data);
                            return;
                        }
                        delay = Math.min(delay * 1.2, 12000);
                        timer = setTimeout(tick, delay);
                    })
                    .catch(function () {
                        delay = Math.min(delay * 1.5, 15000);
                        timer = setTimeout(tick, delay);
                    });
            }

            if (pkgStatus === 'done' || pkgStatus === 'error') {
                // страница уже с результатом — но через 5 с уточним одним запросом (могло измениться)
                timer = setTimeout(tick, 5000);
            } else {
                timer = setTimeout(tick, 1500);
            }
        }
    }

    /* ---------- 1.5. «выбрать всё / снять выделение» в каталоге типов ---------- */
    var docsForm = document.getElementById('docs-create');
    if (docsForm) {
        docsForm.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-check]');
            if (!btn) return;
            docsForm.querySelectorAll('input[name="types[]"]').forEach(function (cb) {
                cb.checked = (btn.getAttribute('data-check') === 'all');
            });
        });
    }

    /* ---------- 2.5. Flash-сообщение гаснет само ---------- */
    var flash = document.querySelector('[data-nj580-autohide]');
    if (flash) { setTimeout(function () { flash.hidden = true; }, 10000); }

    /* ---------- 3. Формы nj580: защита от двойной отправки ---------- */
    document.querySelectorAll('form[action^="/docs"], form[action^="/nj580/"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"]').forEach(function (b) {
                b.disabled = true;
                b.classList.add('opacity-60');
            });
        });
    });
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form button[type="submit"]').forEach(function (b) {
            if (b.disabled) { b.disabled = false; b.classList.remove('opacity-60'); }
        });
    });
})();
</script>
