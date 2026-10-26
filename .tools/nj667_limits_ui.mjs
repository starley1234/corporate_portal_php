#!/usr/bin/env node
/** Регрессия: память форм не подменяет квоты всех пользователей последним вводом.
 * node --test .tools/nj667_limits_ui.mjs
 * Нужен PHP-wasm: N2J_PHPWASM или /tmp/phpwasm/phpwasm_stand.mjs.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { JSDOM } from 'jsdom';

const html = execFileSync('node', [process.env.N2J_PHPWASM || '/tmp/phpwasm/phpwasm_stand.mjs', '.tools/nj667_limits_dump.php'], {
    encoding: 'utf8', env: { ...process.env, N2J_ROOT: process.cwd() }, maxBuffer: 4 * 1024 * 1024,
});

test('текущие квоты каждой строки сохраняются после восстановления памяти форм', async () => {
    const errors = [];
    const dom = new JSDOM(html, {
        url: 'https://n2j.ru/graph3d/limits', runScripts: 'dangerously',
        beforeParse(window) {
            window.addEventListener('error', e => errors.push(e.error));
            window.localStorage.setItem('n2j:pref:/graph3d/limits|/graph3d/limits|active_projects', '7');
            window.localStorage.setItem('n2j:pref:/graph3d/limits|/graph3d/limits|llm_operations_per_day', '11');
        },
    });
    await new Promise(resolve => dom.window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    const { document, FormData, Event, localStorage } = dom.window;
    assert.deepEqual(errors, []);
    for (const [id, expected] of Object.entries({ 6: [5, 5], 2: [7, 11], 1: [999, 30], 7: [0, 0] })) {
        const projects = document.getElementById(`nj667-p-${id}`);
        const llm = document.getElementById(`nj667-l-${id}`);
        assert.equal(projects.value, String(expected[0]), `проекты пользователя ${id}`);
        assert.equal(llm.value, String(expected[1]), `LLM пользователя ${id}`);
        const post = new FormData(projects.form);
        assert.equal(post.get('user_id'), id);
        assert.equal(post.get('active_projects'), String(expected[0]));
        assert.equal(post.get('llm_operations_per_day'), String(expected[1]));
    }
    const first = document.getElementById('nj667-p-6');
    first.value = '42';
    first.dispatchEvent(new Event('change', { bubbles: true }));
    assert.equal(localStorage.getItem('n2j:pref:/graph3d/limits|/graph3d/limits|active_projects'), '7');
    assert.equal(document.getElementById('nj667-p-2').value, '7');
    first.value = '';
    assert.equal(new FormData(first.form).get('active_projects'), '', 'очистка отправляет наследование плана');
    dom.window.close();
});
