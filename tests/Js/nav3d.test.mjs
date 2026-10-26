import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const js = readFileSync(new URL('../../public_html/assets/nj655/nav3d.js', import.meta.url), 'utf8');
const view = readFileSync(new URL('../../application/modules/nj655_nav3d/views/655/index.php', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../../application/modules/nj655_nav3d/config/nj_module.php', import.meta.url), 'utf8');
const controller = readFileSync(new URL('../../application/modules/nj655_nav3d/classes/Controller/Nj655/Nav3d.php', import.meta.url), 'utf8');
const template = readFileSync(new URL('../../application/modules/nj655_nav3d/views/655/template_orbit.php', import.meta.url), 'utf8');

test('3D-карта берёт каталог через единый API, а не хранит список сервисов', () => {
    assert.match(js, /fetch\(root\.dataset\.catalogUrl/);
    assert.match(view, /data-catalog-url/);
    assert.doesNotMatch(js, /Калькулятор НДС|Сумма прописью/);
    // Без href nj110 не добавит новую точку входа в прежнее меню и /services.
    assert.doesNotMatch(manifest, /'href'\s*=>/);
});

test('3D-навигация имеет собственный минимальный каркас и фолбэк', () => {
    assert.match(controller, /public \$template = '655\/template_orbit'/);
    assert.doesNotMatch(template, /nj655-topbar|nj655-footer/);
    assert.match(js, /new THREE\.WebGLRenderer/);
    assert.match(js, /new THREE\.Raycaster/);
    assert.match(js, /new THREE\.Sprite/);
    assert.match(js, /type: 'signal'/);
    assert.match(js, /playScan/);
    assert.match(js, /prefers-reduced-motion/);
    assert.match(js, /keydown/);
    assert.doesNotMatch(view, /orbit-mission|orbit-toolbar|orbit-groups" class="nj655-orbit__groups/);
    assert.match(view, /orbit-fallback/);
    assert.match(view, /Открыть сервис/);
});

test('без WebGL карта показывает те же API-данные обычными безопасными ссылками', async () => {
    const renderedView = view.replace('<?php echo HTML::chars($catalog_api); ?>', '/nj110/api/catalog');
    const dom = new JSDOM(renderedView, {
        runScripts: 'outside-only',
        url: 'https://n2j.test/orbit',
    });
    const { window } = dom;
    const requested = [];
    window.matchMedia = () => ({ matches: true });
    window.fetch = async (url, options) => {
        requested.push([url, options]);
        return {
            ok: true,
            json: async () => ({
                groups: [{ slug: 'tools', title: 'Инструменты', count: 1, icon: 'fas fa-cube' }],
                items: [{
                    title: 'Безопасный сервис',
                    short: 'Проверка фолбэка',
                    href: '/safe-tool',
                    group: 'Инструменты',
                    gslug: 'tools',
                    keywords: 'проверка',
                    status: 'stable',
                    api: ['/safe-tool/api'],
                }],
            }),
        };
    };

    window.eval(js);
    await new Promise((resolve) => window.setTimeout(resolve, 0));

    assert.deepEqual(requested[0][0], '/nj110/api/catalog');
    assert.equal(requested[0][1].credentials, 'same-origin');
    assert.equal(requested[0][1].cache, 'no-store');
    assert.equal(window.document.getElementById('orbit-fallback').hidden, false);
    assert.equal(window.document.getElementById('nj655-orbit').classList.contains('is-fallback'), true);
    assert.equal(window.document.querySelector('#orbit-fallback-list a').getAttribute('href'), '/safe-tool');
    assert.equal(window.document.querySelector('#orbit-accessible-list a').textContent, 'Безопасный сервис');
    dom.window.close();
});
